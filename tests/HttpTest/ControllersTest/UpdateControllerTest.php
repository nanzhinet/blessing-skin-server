<?php

namespace Tests;

use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use App\Services\PackageManager;
use Tests\Concerns\MocksGuzzleClient;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Foundation\Testing\DatabaseTransactions;

class UpdateControllerTest extends TestCase
{
    use DatabaseTransactions;
    use MocksGuzzleClient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actAs('superAdmin');
    }

    public function testShowUpdatePage()
    {
        $this->setupGuzzleClientMock();

        // Can't connect to update source
        $this->appendToGuzzleQueue([
            new RequestException('Connection Error', new Request('GET', 'whatever')),
        ]);
        $this->get('/admin/update')->assertSee(config('app.version'));

        // Missing `spec` field
        $this->appendToGuzzleQueue([
            new Response(200, [], $this->mockFakeUpdateInfo('8.9.3', ['spec' => 0])),
            // Weird. Don't remove the following line, or the tests will fail.
            new Response(200, [], $this->mockFakeUpdateInfo('8.9.3', ['php' => '100.0.0'])),
        ]);
        $this->get('/admin/update')->assertSee(trans('admin.update.errors.spec'));

        // Low PHP version
        $this->appendToGuzzleQueue([
            new Response(200, [], $this->mockFakeUpdateInfo('8.9.3', ['php' => '100.0.0'])),
        ]);
        $this->get('/admin/update')->assertSee(trans('admin.update.errors.php', ['version' => '100.0.0']));

        // New version available
        $this->appendToGuzzleQueue([
            new Response(200, [], $this->mockFakeUpdateInfo('8.9.3')),
        ]);
        $this->get('/admin/update')->assertSee(config('app.version'))->assertSee('8.9.3');
    }

    public function testCheckUpdates()
    {
        $this->setupGuzzleClientMock();

        // Update source is unavailable
        $this->appendToGuzzleQueue([
            new RequestException('Connection Error', new Request('GET', 'whatever')),
        ]);
        $this->getJson('/admin/update/check')->assertJson(['available' => false]);

        // New version available
        $this->appendToGuzzleQueue(200, [], $this->mockFakeUpdateInfo('8.9.3'));
        $this->getJson('/admin/update/check')->assertJson(['available' => true]);
    }

    public function testDownload()
    {
        $this->setupGuzzleClientMock();

        // Already up-to-date
        $this->getJson('/admin/update/download')
            ->assertDontSee(trans('general.illegal-parameters'));

        // Download
        $this->appendToGuzzleQueue([
            new Response(200, [], $this->mockFakeUpdateInfo('8.9.3')),
            new Response(200, [], $this->mockFakeUpdateInfo('8.9.3')),
        ]);
        $this->mock(PackageManager::class, function ($mock) {
            $mock->shouldReceive('download')->andThrow(new \Exception('ddd'));
        });
        $this->getJson('/admin/update/download?action=download')
            ->assertJson(['code' => 1]);
        $this->mock(PackageManager::class, function ($mock) {
            $mock->shouldReceive('download')->andReturnSelf();
            $mock->shouldReceive('extract')->andReturn(true);
            $mock->shouldReceive('progress');
        });
        $this->mock(\Illuminate\Filesystem\Filesystem::class, function ($mock) {
            $mock->shouldReceive('delete')->with(storage_path('options/cache.php'))->once();
            $mock->shouldReceive('exists')->with(storage_path('install.lock'))->andReturn(true);
        });
        $this->getJson('/admin/update/download?action=download')
            ->assertJson(['code' => 0, 'message' => trans('admin.update.complete')]);

        // Get download progress
        $this->getJson('/admin/update/download?action=progress')
            ->assertSee('0');

        // Invalid action
        $this->appendToGuzzleQueue(200, [], $this->mockFakeUpdateInfo('8.9.3'));
        $this->getJson('/admin/update/download?action=no')
            ->assertJson([
                'code' => 1,
                'message' => trans('general.illegal-parameters'),
            ]);
    }

    protected function mockFakeUpdateInfo(string $version, $extra = [])
    {
        return json_encode(array_merge([
            'spec' => 2,
            'php' => '7.2.0',
            'latest' => $version,
            'url' => "https://whatever.test/$version/update.zip",
        ], $extra));
    }
}