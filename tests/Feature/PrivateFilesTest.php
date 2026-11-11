<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Files in storage/app/private (stored packages, backups, keys) have no web address: they are
 * only read and written by Nuvabill's own code.
 */
class PrivateFilesTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        parent::setUp();

        $this->file = storage_path('app/private/private-files-test.txt');
        File::ensureDirectoryExists(dirname($this->file));
        File::put($this->file, 'private');
    }

    protected function tearDown(): void
    {
        File::delete([$this->file, storage_path('app/private/private-files-test-new.txt')]);

        parent::tearDown();
    }

    public function test_the_private_disk_has_no_routes(): void
    {
        $this->assertFalse(config('filesystems.disks.local.serve'));
        $this->assertFalse(Route::has('storage.local'));
        $this->assertFalse(Route::has('storage.local.upload'));
    }

    public function test_a_signed_link_does_not_open_a_private_file(): void
    {
        $this->get($this->signed('/storage/private-files-test.txt'))->assertNotFound();
    }

    public function test_a_signed_link_does_not_save_a_private_file(): void
    {
        $this->call('PUT', $this->signed('/storage/private-files-test-new.txt', ['upload' => 1]), content: 'changed')->assertNotFound();
        $this->call('PUT', $this->signed('/storage/private-files-test.txt', ['upload' => 1]), content: 'changed')->assertNotFound();

        $this->assertFileDoesNotExist(storage_path('app/private/private-files-test-new.txt'));
        $this->assertSame('private', File::get($this->file));
    }

    /**
     * The address with a signature made with this site's own app key, as Laravel signs links.
     *
     * @param  array<string, int|string>  $query
     */
    private function signed(string $path, array $query = []): string
    {
        $address = $path.'?'.http_build_query($query + ['expires' => now()->addMinutes(5)->getTimestamp()]);

        return $address.'&signature='.hash_hmac('sha256', $address, (string) config('app.key'));
    }
}
