<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Aws\S3\S3Client;
use Aws\Exception\AwsException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use App\Jobs\ProcessS3Upload;
use Illuminate\Support\Str;

class StorageController extends Controller
{
    protected $s3;
    protected static $instanceCache = null;

    public function __construct()
    {
        // Reuse S3 client instance untuk mengurangi overhead
        if (self::$instanceCache === null) {
            self::$instanceCache = new S3Client([
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
                    'timeout' => 30,
                    'connect_timeout' => 10,
                ],
                // Optimasi untuk concurrent requests
                'retries' => [
                    'max_attempts' => 2,
                    'mode' => 'adaptive'
                ]
            ]);
        }
        $this->s3 = self::$instanceCache;
    }

    private function getBucketName()
    {
        return env('NEO_BUCKET');
    }

    private function ensureBucketExists($bucket)
    {
        // Cache bucket existence selama 1 jam untuk mengurangi API calls
        $cacheKey = "s3_bucket_exists_{$bucket}";

        if (Cache::has($cacheKey)) {
            return;
        }

        try {
            $exists = $this->s3->doesBucketExist($bucket);
            if (!$exists) {
                $this->s3->createBucket(['Bucket' => $bucket]);
            }
            // Cache hasil untuk 1 jam
            Cache::put($cacheKey, true, 3600);
        } catch (AwsException $e) {
            Log::error("Bucket check/create failed: " . $e->getMessage());
            throw new \Exception("Bucket check/create failed: " . $e->getMessage());
        }
    }

    public function upload(Request $request)
    {
        // Check secret FIRST untuk early exit unauthorized requests
        $secret = $request->input('secret');
        if ($secret !== env('SERVER_SECRET_KEY')) {
            return response()->json(
                ['success' => false, 'message' => 'Unauthorized'],
                401
            );
        }

        // Quick file existence check
        if (!$request->hasFile('file')) {
            return response()->json(['error' => 'file not detected'], 400);
        }

        // Validate file
        $validated = $request->validate([
            'file' => 'required|file|max:51200', // Max 50MB
        ]);

        try {
            $bucket = $this->getBucketName();
            $this->ensureBucketExists($bucket);

            $client = $request->input('client');
            $folder = $request->input('folder');
            $file = $request->file('file');
            $ext = $file->getClientOriginalExtension();
            $originalName = $file->getClientOriginalName();
            $mimeType = $file->getMimeType();
            $fileSize = $file->getSize();

            // Generate unique filename dengan UUID
            $randomName = Str::uuid() . '.' . $ext;

            // Store file temporarily
            $tempPath = $file->store('temp');
            $fullTempPath = storage_path('app/' . $tempPath);

            // Generate URL sebelum upload (URL sudah pasti berdasarkan UUID)
            $key = $client . "/uploads/" . ($folder ? $folder . "/" : "") . $randomName;
            $url = env('NEO_ENDPOINT') . "/" . $bucket . "/" . $key;

            // Dispatch async job to queue untuk upload di background
            ProcessS3Upload::dispatch(
                $fullTempPath,
                $randomName,
                $mimeType,
                $client,
                $folder,
                $bucket,
                $originalName,
                $fileSize
            );

            Log::info("File queued for upload - instant URL returned", [
                'url' => $url,
                'client' => $client,
                'size' => $fileSize,
                'temp_path' => $tempPath,
            ]);

            // Return URL langsung ke client dengan status 202 (Accepted)
            return response()->json(
                [
                    'success' => true,
                    'message' => 'File queued for upload',
                    'url' => $url
                ],
                202
            );
        } catch (\Exception $e) {
            Log::error("Upload queue dispatch failed: " . $e->getMessage());
            return response()->json(
                [
                    'success' => false,
                    'message' => 'Failed to queue upload: ' . $e->getMessage()
                ],
                500
            );
        }
    }

    public function list(Request $request)
    {

        $secret = $request->input('secret');

        if ($secret != env('SERVER_SECRET_KEY')) {
            return response()->json(
                [
                    'success' => false,
                    'message' => 'Unauthorized'
                ],
                401
            );
        }

        $client = $request->input('client');
        $bucket = $this->getBucketName();
        $folder = $request->input('folder');

        try {
            $this->ensureBucketExists($bucket);

            $result = $this->s3->listObjectsV2([
                'Bucket' => $bucket,
                'Prefix' => $client . '/' . 'uploads/' . ($folder ? $folder . "/" : ""),
            ]);

            $files = [];
            if (isset($result['Contents'])) {
                foreach ($result['Contents'] as $object) {
                    $files[] = env('NEO_ENDPOINT') . "/" . $bucket . "/" . $object['Key'];
                }
            }

            return response()->json(
                [
                    'success' => true,
                    'files' => $files
                ],
                200
            );
        } catch (AwsException $e) {
            return response()->json(
                [
                    'success' => false,
                    'message' => $e->getMessage()
                ],
                500
            );
        }
    }

    public function view(Request $request)
    {
        $secret = $request->input('secret');

        if ($secret !== env('SERVER_SECRET_KEY')) {
            return response()->json(
                [
                    'success' => false,
                    'message' => 'Unauthorized'
                ],
                401
            );
        }

        $bucket = $this->getBucketName();
        $url = $request->input('url');

        if (!$url) {
            return response()->json(
                [
                    'success' => false,
                    'message' => 'URL file kosong'
                ],
                400
            );
        }

        // Ambil key relatif dari URL
        $parsedUrl = parse_url($url, PHP_URL_PATH);
        $key = ltrim(str_replace('/' . $bucket . '/', '', $parsedUrl), '/');

        try {
            // Ambil metadata file tanpa download seluruh file
            $head = $this->s3->headObject([
                'Bucket' => $bucket,
                'Key'    => $key
            ]);

            $data = [
                'url' => $url,
                'key' => $key,
                'size' => $head['ContentLength'],
                'type' => $head['ContentType'],
                'last_modified' => $head['LastModified']->format('Y-m-d H:i:s'),
            ];

            return response()->json($data);
        } catch (AwsException $e) {
            return response()->json(
                [
                    'success' => false,
                    'message' => 'File tidak ditemukan: ' . $e->getMessage()
                ],
                404
            );
        }
    }

    public function delete(Request $request)
    {
        $secret = $request->input('secret');

        if ($secret !== env('SERVER_SECRET_KEY')) {
            return response()->json(
                [
                    'success' => false,
                    'message' => 'Unauthorized'
                ],
                401
            );
        }

        $bucket = $this->getBucketName();
        $url = $request->input('file');

        if (!$url) {
            return response()->json(
                [
                    'success' => false,
                    'message' => 'File URL kosong'
                ],
                400
            );
        }

        $parsedUrl = parse_url($url, PHP_URL_PATH);
        $key = ltrim(str_replace('/' . $bucket . '/', '', $parsedUrl), '/');

        try {
            $this->s3->deleteObject([
                'Bucket' => $bucket,
                'Key'    => $key,
            ]);

            return response()->json(
                [
                    'success' => true,
                    'message' => 'File deleted successfully'
                ],
                200
            );
        } catch (\Aws\Exception\AwsException $e) {
            return response()->json(
                [
                    'success' => false,
                    'message' => 'Gagal menghapus file: ' . $e->getMessage()
                ],
                500
            );
        }
    }
}
