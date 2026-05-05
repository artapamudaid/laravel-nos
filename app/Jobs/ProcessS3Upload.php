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
use Illuminate\Support\Facades\Storage;

class ProcessS3Upload implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $fileContent;
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
        string $fileContent,
        string $fileName,
        string $mimeType,
        string $client,
        ?string $folder,
        string $bucket,
        string $originalName,
        int $fileSize
    ) {
        $this->fileContent = $fileContent;
        $this->fileName = $fileName;
        $this->mimeType = $mimeType;
        $this->client = $client;
        $this->folder = $folder;
        $this->bucket = $bucket;
        $this->originalName = $originalName;
        $this->fileSize = $fileSize;
    }

    /**
     * Resolve the actual file content to upload from disk.
     * 
     * RAM-Safe: We read from disk only when needed and clear memory immediately.
     */
    protected function resolveFileContent(): string
    {
        if (!is_string($this->fileContent)) {
            Log::error("Invalid file content type", ['type' => gettype($this->fileContent)]);
            return '';
        }

        // Gunakan path langsung karena sekarang dikirim dalam bentuk absolut dari Controller
        $absolutePath = $this->fileContent;

        if (!file_exists($absolutePath)) {
            Log::warning("Temp file not found, possibly already processed or deleted", [
                'path'   => $absolutePath,
                'client' => $this->client,
                'file'   => $this->fileName,
            ]);
            // Don't retry if file is missing
            $this->delete();
            return '';
        }

        $content = file_get_contents($absolutePath);

        // JANGAN hapus di sini, hapus setelah S3 konfirmasi sukses di handle()
        return $content;
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
                try {
                    $exists = $s3->doesBucketExist($this->bucket);
                    if (!$exists) {
                        $s3->createBucket(['Bucket' => $this->bucket]);
                    }
                    Cache::put($cacheKey, true, 3600);
                } catch (\Exception $e) {
                    Log::warning("Bucket check warning: " . $e->getMessage());
                }
            }

            // Resolve file content (support legacy temp-file path & current binary mode)
            $body = $this->resolveFileContent();

            // Job sudah di-delete() dari dalam resolveFileContent() jika temp file tidak ada
            if ($body === '') {
                return;
            }

            // Upload to S3 using Body (file content)
            $key = $this->client . "/uploads/" . ($this->folder ? $this->folder . "/" : "") . $this->fileName;

            $s3->putObject([
                'Bucket' => $this->bucket,
                'Key'    => $key,
                'Body'   => $body,
                'ContentType' => $this->mimeType,
                'Metadata' => [
                    'uploaded_at' => now()->toDateTimeString(),
                    'original_name' => $this->originalName,
                ]
            ]);

            $url = env('NEO_ENDPOINT') . "/" . $this->bucket . "/" . $key;

            Log::info("File uploaded successfully via queue", [
                'url' => $url,
                'client' => $this->client,
                'size' => $this->fileSize,
            ]);

            // SEKARANG baru aman untuk menghapus file di disk
            $absolutePath = str_starts_with($this->fileContent, '/')
                ? $this->fileContent
                : storage_path('app/' . $this->fileContent);
            
            if (file_exists($absolutePath)) {
                @unlink($absolutePath);
            }

        } catch (AwsException $e) {
            Log::error("Queue upload AWS error: " . $e->getMessage(), [
                'code' => $e->getAwsErrorCode(),
                'client' => $this->client,
                'attempt' => $this->attempts(),
            ]);
            throw $e;
        } catch (\Exception $e) {
            Log::error("Queue upload error: " . $e->getMessage(), [
                'client' => $this->client,
                'attempt' => $this->attempts(),
                'trace' => $e->getTraceAsString(),
            ]);
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
            'client' => $this->client,
            'file_name' => $this->fileName,
        ]);
    }
}
