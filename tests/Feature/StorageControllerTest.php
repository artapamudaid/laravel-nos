<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Queue;

class StorageControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Queue::fake();
    }

    /**
     * Test: Upload endpoint requires secret key
     */
    public function test_upload_requires_secret_key()
    {
        $response = $this->postJson('/api/upload', [
            'secret' => 'wrong-secret',
        ]);

        $response->assertStatus(401);
        $response->assertJson(['success' => false, 'message' => 'Unauthorized']);
    }

    /**
     * Test: Upload endpoint requires file
     */
    public function test_upload_requires_file()
    {
        $response = $this->postJson('/api/upload', [
            'secret' => env('SERVER_SECRET_KEY'),
        ]);

        $response->assertStatus(400);
        $response->assertJson(['error' => 'file not detected']);
    }

    /**
     * Test: Upload endpoint accepts valid file
     */
    public function test_upload_accepts_valid_file()
    {
        Queue::fake();

        $file = UploadedFile::fake()->create('test.txt', 100);

        $response = $this->postJson('/api/upload', [
            'secret' => env('SERVER_SECRET_KEY'),
            'client' => 'test-client',
            'folder' => 'test-folder',
            'file' => $file,
        ]);

        $response->assertStatus(202);
        $response->assertJson(['success' => true]);
        $response->assertJsonStructure(['url']);
    }

    /**
     * Test: Upload rejects files over 50MB
     */
    public function test_upload_rejects_files_over_50mb()
    {
        $response = $this->postJson('/api/upload', [
            'secret' => env('SERVER_SECRET_KEY'),
            'client' => 'test-client',
            'file' => UploadedFile::fake()->create('test.txt', 51201), // 50MB + 1KB
        ]);

        $response->assertStatus(422); // Validation error
    }

    /**
     * Test: List endpoint requires secret key
     */
    public function test_list_requires_secret_key()
    {
        $response = $this->getJson('/api/list', [
            'secret' => 'wrong-secret',
        ]);

        $response->assertStatus(401);
        $response->assertJson(['success' => false]);
    }

    /**
     * Test: View endpoint requires secret key
     */
    public function test_view_requires_secret_key()
    {
        $response = $this->postJson('/api/view', [
            'secret' => 'wrong-secret',
        ]);

        $response->assertStatus(401);
        $response->assertJson(['success' => false]);
    }

    /**
     * Test: Delete endpoint requires secret key
     */
    public function test_delete_requires_secret_key()
    {
        $response = $this->postJson('/api/delete', [
            'secret' => 'wrong-secret',
        ]);

        $response->assertStatus(401);
        $response->assertJson(['success' => false]);
    }

    /**
     * Test: View requires URL parameter
     */
    public function test_view_requires_url_parameter()
    {
        $response = $this->postJson('/api/view', [
            'secret' => env('SERVER_SECRET_KEY'),
        ]);

        $response->assertStatus(400);
        $response->assertJson(['success' => false, 'message' => 'URL file kosong']);
    }

    /**
     * Test: Delete requires file URL parameter
     */
    public function test_delete_requires_file_url_parameter()
    {
        $response = $this->postJson('/api/delete', [
            'secret' => env('SERVER_SECRET_KEY'),
        ]);

        $response->assertStatus(400);
        $response->assertJson(['success' => false, 'message' => 'File URL kosong']);
    }

    /**
     * Test: Rate limit on upload route (should be 10000/min)
     */
    public function test_upload_route_has_rate_limit()
    {
        // Check if route is registered with correct middleware
        $routes = collect(\Route::getRoutes())
            ->filter(fn($route) => $route->uri === 'api/upload')
            ->first();

        $this->assertNotNull($routes, 'Upload route should exist');

        // Check if route has throttle middleware with 10000 requests
        $middleware = $routes->middleware();
        $this->assertTrue(
            collect($middleware)->contains(fn($m) => str_contains($m, 'throttle:10000')),
            'Upload route should have throttle:10000 middleware'
        );
    }
}
