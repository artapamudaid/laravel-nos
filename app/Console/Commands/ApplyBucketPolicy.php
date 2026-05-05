<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Aws\S3\S3Client;
use Aws\Exception\AwsException;

class ApplyBucketPolicy extends Command
{
    protected $signature = 'storage:apply-policy {--bucket= : Nama bucket (default dari .env NEO_BUCKET)}';
    protected $description = 'Apply public-read bucket policy ke NOS bucket agar object bisa diakses publik';

    public function handle()
    {
        $bucket = $this->option('bucket') ?: env('NEO_BUCKET');

        if (!$bucket) {
            $this->error('Bucket tidak ditemukan. Set NEO_BUCKET di .env atau gunakan --bucket=nama-bucket');
            return 1;
        }

        $this->info("Applying public-read policy ke bucket: {$bucket}");

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
                'verify' => env('NEO_USE_SSL', false),
                'timeout' => 30,
            ],
        ]);

        $policy = json_encode([
            'Version' => '2012-10-17',
            'Statement' => [
                [
                    'Sid'       => 'PublicReadGetObject',
                    'Effect'    => 'Allow',
                    'Principal' => '*',
                    'Action'    => 's3:GetObject',
                    'Resource'  => "arn:aws:s3:::{$bucket}/*",
                ],
            ],
        ]);

        try {
            $s3->putBucketPolicy([
                'Bucket' => $bucket,
                'Policy' => $policy,
            ]);
            $this->info("✅ Bucket policy berhasil diterapkan!");
            $this->line("   Semua object di bucket '{$bucket}' sekarang bisa diakses publik.");
        } catch (AwsException $e) {
            $this->error("❌ Gagal apply policy: " . $e->getMessage());
            $this->warn("   Kode error: " . $e->getAwsErrorCode());

            // Fallback info
            $this->newLine();
            $this->warn("Jika NOS tidak mendukung putBucketPolicy, atur akses publik melalui dashboard NOS secara manual.");
            return 1;
        }

        // Flush cache agar ensureBucketExists re-apply policy saat request berikutnya
        \Illuminate\Support\Facades\Cache::forget("s3_bucket_exists_{$bucket}");
        $this->line("   Cache bucket dihapus agar policy di-refresh.");

        return 0;
    }
}
