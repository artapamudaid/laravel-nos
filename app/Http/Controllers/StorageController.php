<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Aws\S3\S3Client;
use Aws\Exception\AwsException;

class StorageController extends Controller
{
    protected $s3;

    public function __construct()
    {
        $this->s3 = new S3Client([
            'version'     => 'latest',
            'region'      => env('NEO_REGION'),
            'endpoint'    => env('NEO_ENDPOINT'),
            'credentials' => [
                'key'    => env('NEO_ACCESS_KEY'),
                'secret' => env('NEO_SECRET_KEY'),
            ],
            'use_path_style_endpoint' => true,
            'http' => [
                'verify' => env('NEO_USE_SSL')
            ]
        ]);
    }

    private function getBucketName()
    {
        return env('NEO_BUCKET');
    }

    private function ensureBucketExists($bucket)
    {
        try {
            $exists = $this->s3->doesBucketExist($bucket);
            if (!$exists) {
                $this->s3->createBucket(['Bucket' => $bucket]);
            }
        } catch (AwsException $e) {
            throw new \Exception("Bucket check/create failed: " . $e->getMessage());
        }
    }

    public function upload(Request $request)
    {
        $request->validate([
            'file' => 'required|file',
        ]);

        $secret = $request->input('secret');

        if($secret != env('SERVER_SECRET_KEY')) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'],
                401);
        }

        $client = $request->input('client');
        $bucket = $this->getBucketName();

        try {
            $this->ensureBucketExists($bucket);

            $folder = $request->input('folder');
            $file = $request->file('file');
            $ext = $file->getClientOriginalExtension();
            $randomName = sha1(time() . $file->getClientOriginalName() . rand()) . '.' . $ext;

            $key = $client . "/uploads/" . ($folder ? $folder . "/" : "") . $randomName;


            $this->s3->putObject([
                'Bucket' => $bucket,
                'Key'    => $key,
                'SourceFile' => $file->getPathname(),
                'ACL'    => 'public-read',
            ]);

            $url = env('NEO_ENDPOINT') . "/" . $bucket . "/" . $key;

            return response()->json([
                'success' => true,
                'message' => 'File uploaded',
                'url' => $url],
                200);
        } catch (AwsException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()],
                500);
        }
    }

    public function list(Request $request)
    {

        $secret = $request->input('secret');

        if($secret != env('SERVER_SECRET_KEY')) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'],
                401);
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

            return response()->json([
                'success' => true,
                'files' => $files]
                , 200);
        } catch (AwsException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()],
                500);
        }
    }

    public function view(Request $request)
    {
        $secret = $request->input('secret');

        if ($secret !== env('SERVER_SECRET_KEY')) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'],
                401);
        }

        $bucket = $this->getBucketName();
        $url = $request->input('url');

        if (!$url) {
            return response()->json([
                'success' => false,
                'message' => 'URL file kosong'],
                400);
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
            return response()->json([
                'success' => false,
                'message' => 'File tidak ditemukan: ' . $e->getMessage()],
                404);
        }
    }

    public function delete(Request $request)
    {
        $secret = $request->input('secret');

        if ($secret !== env('SERVER_SECRET_KEY')) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'],
                401);
        }

        $bucket = $this->getBucketName();
        $url = $request->input('file');

        if (!$url) {
            return response()->json([
                'success' => false,
                'message' => 'File URL kosong'],
                400);
        }

        $parsedUrl = parse_url($url, PHP_URL_PATH);
        $key = ltrim(str_replace('/' . $bucket . '/', '', $parsedUrl), '/');

        try {
            $this->s3->deleteObject([
                'Bucket' => $bucket,
                'Key'    => $key,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'File deleted successfully'
            ],
            200);
        } catch (\Aws\Exception\AwsException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus file: ' . $e->getMessage()
            ],
            500);
        }
    }

}
