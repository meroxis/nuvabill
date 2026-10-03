<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Process;
use Tests\TestCase;
use Throwable;

/**
 * The browser scripts have their own tests in tests/js, for Node's built-in test runner. They run
 * here too when Node 20.4 or newer is installed.
 */
class JavaScriptTest extends TestCase
{
    public function test_the_browser_scripts_pass_their_own_tests(): void
    {
        try {
            $version = Process::run(['node', '--version']);
        } catch (Throwable) {
            $version = null;
        }

        if ($version === null || ! $version->successful() || version_compare(ltrim(trim($version->output()), 'v'), '20.4.0', '<')) {
            $this->markTestSkipped('Node 20.4 or newer is not installed.');
        }

        $files = array_map(fn (string $file): string => 'tests/js/'.basename($file), glob(base_path('tests/js/*.test.mjs')) ?: []);
        $this->assertNotEmpty($files);

        $result = Process::path(base_path())->timeout(120)->run(['node', '--test', ...$files]);

        $this->assertTrue($result->successful(), $result->output().$result->errorOutput());
    }
}
