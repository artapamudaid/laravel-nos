<?php

namespace Tests\Feature;

use App\Jobs\ProcessS3Upload;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\TestCase as BaseTestCase;
use Tests\TestCase;

class ProcessS3UploadTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    /**
     * Test: Constructor initializes properties correctly
     */
    public function test_constructor_sets_properties()
    {
        $filePath = '/tmp/test.txt';
        $fileName = 'test.txt';
        $client = 'test-client';
        $folder = 'test-folder';

        $job = new ProcessS3Upload($filePath, $fileName, $client, $folder);

        // Verify properties via reflection
        $reflection = new \ReflectionClass($job);
        $property = $reflection->getProperty('filePath');
        $property->setAccessible(true);
        $this->assertEquals($filePath, $property->getValue($job));

        $property = $reflection->getProperty('client');
        $property->setAccessible(true);
        $this->assertEquals($client, $property->getValue($job));
    }

    /**
     * Test: getFileContent returns empty string if file not exists
     */
    public function test_get_file_content_returns_empty_when_file_not_exists()
    {
        $job = new ProcessS3Upload('/non/existent/file.txt', 'test.txt', 'client', null);

        $reflection = new \ReflectionClass($job);
        $method = $reflection->getMethod('getFileContent');
        $method->setAccessible(true);

        $result = $method->invoke($job);
        $this->assertEquals('', $result);
    }

    /**
     * Test: getFileContent reads file correctly
     */
    public function test_get_file_content_reads_file_correctly()
    {
        // Create temp test file
        $testContent = 'test file content 123';
        $tempFile = tempnam(sys_get_temp_dir(), 'test_');
        file_put_contents($tempFile, $testContent);

        $job = new ProcessS3Upload($tempFile, 'test.txt', 'client', null);

        $reflection = new \ReflectionClass($job);
        $method = $reflection->getMethod('getFileContent');
        $method->setAccessible(true);

        $result = $method->invoke($job);
        $this->assertEquals($testContent, $result);

        // Cleanup
        @unlink($tempFile);
    }

    /**
     * Test: detectMimeType fallback to default when file not exists
     */
    public function test_detect_mime_type_fallback_when_not_exists()
    {
        $job = new ProcessS3Upload('/non/existent/file.txt', 'test.txt', 'client', null);

        $reflection = new \ReflectionClass($job);
        $method = $reflection->getMethod('detectMimeType');
        $method->setAccessible(true);

        $result = $method->invoke($job);
        $this->assertEquals('application/octet-stream', $result);
    }

    /**
     * Test: detectMimeType detects correct MIME type
     */
    public function test_detect_mime_type_detects_correct_type()
    {
        // Create temp text file
        $tempFile = tempnam(sys_get_temp_dir(), 'test_');
        file_put_contents($tempFile, 'test content');

        $job = new ProcessS3Upload($tempFile, 'test.txt', 'client', null);

        $reflection = new \ReflectionClass($job);
        $method = $reflection->getMethod('detectMimeType');
        $method->setAccessible(true);

        $result = $method->invoke($job);

        // Should be text/plain or application/octet-stream (depends on system)
        $this->assertTrue(
            in_array($result, ['text/plain', 'application/octet-stream']),
            "MIME type should be detected, got: {$result}"
        );

        // Cleanup
        @unlink($tempFile);
    }

    /**
     * Test: getBucket returns env value correctly
     */
    public function test_get_bucket_returns_env_value()
    {
        $job = new ProcessS3Upload('/tmp/test.txt', 'test.txt', 'client', null);

        $reflection = new \ReflectionClass($job);
        $method = $reflection->getMethod('getBucket');
        $method->setAccessible(true);

        $result = $method->invoke($job);

        // Should return the NEO_BUCKET env value
        $this->assertIsString($result);
        $this->assertNotEmpty($result, 'Bucket name should not be empty');
    }

    /**
     * Test: Constructor without optional folder parameter
     */
    public function test_constructor_without_folder_parameter()
    {
        $job = new ProcessS3Upload('/tmp/test.txt', 'test.txt', 'client');

        $reflection = new \ReflectionClass($job);
        $property = $reflection->getProperty('folder');
        $property->setAccessible(true);

        $this->assertNull($property->getValue($job));
    }

    /**
     * Test: Queue payload optimization (only 4 essential properties)
     */
    public function test_queue_payload_optimized_minimal()
    {
        $job = new ProcessS3Upload('/tmp/test.txt', 'test.txt', 'client', 'folder');

        $reflection = new \ReflectionClass($job);
        $properties = $reflection->getProperties(\ReflectionProperty::IS_PROTECTED);

        $protectedProps = [];
        foreach ($properties as $prop) {
            if (strpos($prop->getName(), 'queue') === false &&
                strpos($prop->getName(), 'timeout') === false &&
                strpos($prop->getName(), 'tries') === false &&
                strpos($prop->getName(), 'backoff') === false) {
                $protectedProps[] = $prop->getName();
            }
        }

        // Should have only 4 main properties
        $expectedProps = ['filePath', 'fileName', 'client', 'folder'];
        sort($protectedProps);
        sort($expectedProps);

        $this->assertEquals($expectedProps, $protectedProps, 'Job should have only 4 essential properties');
    }
}
