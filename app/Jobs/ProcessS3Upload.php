<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Aws\S3\S3Client;
use Aws\Exception\AwsException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class ProcessS3Upload implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $filePath;
    protected $fileName;
    protected $mimeType;
    protected $client;
    protected $folder;
    protected $bucket;
    protected $originalName;
    protected $fileSize;

    public $timeout = 300; // 5 minutes
    public $tries = 3; // Retry 3 times on failure
    public $backoff = [60, 120]; // Backoff 1 min, then 2 min

    /**
     * Create a new job instance.
     */
    public function __construct(
        string $filePath,
        string $fileName,
        string $mimeType,
        string $client,
        ?string $folder,
        string $bucket,
        string $originalName,
        int $fileSize
    ) {
        $this->filePath = $filePath;
        $this->fileName = $fileName;
        $this->mimeType = $mimeType;
        $this->client = $client;
        $this->folder = $folder;
        $this->bucket = $bucket;
        $this->originalName = $originalName;
        $this->fileSize = $fileSize;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            $s3 = new S3Client([
                'version'     => 'latest',
                'region'      => env('NEO_REGION'),
                'endpoint'    => env('NEO_ENDPOINT'),
                'credentials' => [
                    'key'    => env('NEO_ACCESS_KEY'),
                    'secret' => env('NEO_SECRET_KEY'),
                ],
                'use_path_style_endpoint' => true,
                'http' => [
                    'verify' => env('NEO_USE_SSL'),
                    'timeout' => 60,
                    'connect_timeout' => 10,
                ],
                'retries' => [
                    'max_attempts' => 3,
                    'mode' => 'adaptive'
                ]
            ]);

            // Ensure bucket exists
            $cacheKey = "s3_bucket_exists_{$this->bucket}";
            if (!Cache::has($cacheKey)) {
                $exists = $s3->doesBucketExist($this->bucket);
                if (!$exists) {
                    $s3->createBucket(['Bucket' => $this->bucket]);
                }
                Cache::put($cacheKey, true, 3600);
            }

            // Upload to S3
            $key = $this->client . "/uploads/" . ($this->folder ? $this->folder . "/" : "") . $this->fileName;

            $s3->putObject([
                'Bucket' => $this->bucket,
                'Key'    => $key,
                'SourceFile' => $this->filePath,
                'ACL'    => 'public-read',
                'ContentType' => $this->mimeType,
                'Metadata' => [
                    'uploaded_at' => now()->toDateTimeString(),
                    'original_name' => $this->originalName,
                    'job_id' => $this->job->getJobId() ?? 'unknown',
                ]
            ]);

            $url = env('NEO_ENDPOINT') . "/" . $this->bucket . "/" . $key;

            Log::info("File uploaded successfully via queue", [
                'url' => $url,
                'client' => $this->client,
                'size' => $this->fileSize,
                'job_id' => $this->job->getJobId() ?? 'unknown',
            ]);

            // Cleanup temp file
            if (file_exists($this->filePath)) {
                @unlink($this->filePath);
            }

        } catch (AwsException $e) {
            Log::error("Queue upload failed: " . $e->getMessage(), [
                'code' => $e->getAwsErrorCode(),
                'client' => $this->client,
                'attempt' => $this->attempts(),
            ]);

            // Cleanup on failure
            if (file_exists($this->filePath)) {
                @unlink($this->filePath);
            }

            throw $e;
        } catch (\Exception $e) {
            Log::error("Queue upload exception: " . $e->getMessage(), [
                'client' => $this->client,
                'attempt' => $this->attempts(),
            ]);

            // Cleanup on failure
            if (file_exists($this->filePath)) {
                @unlink($this->filePath);
            }

            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("Queue job failed after all retries", [
            'exception' => $exception->getMessage(),
            'file' => $this->filePath,
            'client' => $this->client,
        ]);

        // Cleanup temp file
        if (file_exists($this->filePath)) {
            @unlink($this->filePath);
        }
    }
}
