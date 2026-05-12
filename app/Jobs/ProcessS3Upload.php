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

    protected $filePath;
    protected $fileName;
    protected $client;
    protected $folder;

    public $timeout = 300; // 5 minutes
    public $tries = 3; // Retry 3 times on failure
    public $backoff = [60, 120]; // Backoff 1 min, then 2 min

    /**
     * Create a new job instance.
     *
     * Optimized payload: hanya simpan data essential di Redis
     * - bucket: ambil dari config saat runtime
     * - mimeType: detect dari file saat diproses
     * - originalName & fileSize: opsional untuk logging
     */
    public function __construct(
        string $filePath,
        string $fileName,
        string $client,
        ?string $folder = null
    ) {
        $this->filePath = $filePath;
        $this->fileName = $fileName;
        $this->client = $client;
        $this->folder = $folder;
    }

    /**
     * Get file content from disk path.
     *
     * RAM-Safe: Read from disk only when needed in queue worker.
     */
    protected function getFileContent(): string
    {
        if (!file_exists($this->filePath)) {
            Log::warning("Temp file not found during queue processing", [
                'path'   => $this->filePath,
                'client' => $this->client,
                'file'   => $this->fileName,
            ]);
            $this->delete();
            return '';
        }

        return file_get_contents($this->filePath);
    }

    /**
     * Detect MIME type dari file yang sudah tersimpan.
     *
     * Harus dipanggil SETELAH memastikan file exists.
     */
    protected function detectMimeType(): string
    {
        if (!file_exists($this->filePath)) {
            return 'application/octet-stream';
        }

        try {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo === false) {
                return 'application/octet-stream';
            }
            $mimeType = finfo_file($finfo, $this->filePath);
            finfo_close($finfo);
            return $mimeType ?: 'application/octet-stream';
        } catch (\Exception $e) {
            Log::warning("MIME type detection failed: " . $e->getMessage());
            return 'application/octet-stream';
        }
    }

    /**
     * Get bucket name dari config (bukan dari queue payload).
     */
    protected function getBucket(): string
    {
        return env('NEO_BUCKET');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            // Verify file exists FIRST sebelum detect MIME type
            $body = $this->getFileContent();
            if ($body === '') {
                return; // File tidak ada, sudah di-delete() dari getFileContent()
            }

            $bucket = $this->getBucket();
            $mimeType = $this->detectMimeType(); // Sekarang aman, file sudah verified exist

            $s3 = new S3Client([
                'version'     => 'latest',
                'region'      => env('NEO_REGION', 'wjv-1'),
                'endpoint'    => env('NEO_ENDPOINT'),
                'credentials' => [
                    'key'    => env('NEO_ACCESS_KEY'),
                    'secret' => env('NEO_SECRET_KEY'),
                ],
                'use_path_style_endpoint' => true,
                'http' => [
                    // Cast ke boolean: env() mengembalikan string "false", bukan boolean false
                    'verify' => filter_var(env('NEO_USE_SSL', false), FILTER_VALIDATE_BOOLEAN),
                    'timeout' => 60,
                    'connect_timeout' => 10,
                ],
                'retries' => [
                    'max_attempts' => 3,
                    'mode' => 'adaptive'
                ]
            ]);

            // Ensure bucket exists (cache untuk mengurangi API calls)
            $cacheKey = "s3_bucket_exists_{$bucket}";
            if (!Cache::has($cacheKey)) {
                try {
                    $exists = $s3->doesBucketExist($bucket);
                    if (!$exists) {
                        $s3->createBucket(['Bucket' => $bucket]);
                    }
                    Cache::put($cacheKey, true, 3600);
                } catch (\Exception $e) {
                    Log::warning("Bucket check warning: " . $e->getMessage());
                }
            }

            // Upload to S3
            $key = $this->client . "/uploads/" . ($this->folder ? $this->folder . "/" : "") . $this->fileName;

            $s3->putObject([
                'Bucket' => $bucket,
                'Key'    => $key,
                'Body'   => $body,
                'ContentType' => $mimeType,
                'Metadata' => [
                    'uploaded_at' => now()->toDateTimeString(),
                ]
            ]);

            $url = env('NEO_ENDPOINT') . "/" . $bucket . "/" . $key;

            Log::info("File uploaded successfully via queue", [
                'url' => $url,
                'client' => $this->client,
                'size' => strlen($body),
            ]);

            // Hapus file temp setelah upload sukses
            if (file_exists($this->filePath)) {
                @unlink($this->filePath);
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

        // Hapus temp file jika masih ada
        if (file_exists($this->filePath)) {
            @unlink($this->filePath);
        }
    }
}
