<?php

namespace Tests\Unit;

use App\Support\EnvFile;
use PHPUnit\Framework\TestCase;

class EnvFileTest extends TestCase
{
    public function test_a_file_saved_with_windows_line_endings_reads_and_updates_cleanly(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'env');
        file_put_contents($path, "APP_KEY=\r\nAPP_URL=https://billing.example.com\r\nNUVABILL_DEMO=false\r\n");
        $env = new EnvFile($path);

        $this->assertSame('https://billing.example.com', $env->get('APP_URL'));

        $env->set(['APP_KEY' => 'base64:abc', 'NUVABILL_DEMO' => true]);

        $this->assertSame('base64:abc', $env->get('APP_KEY'));
        $this->assertSame('true', $env->get('NUVABILL_DEMO'));
        $this->assertSame('https://billing.example.com', $env->get('APP_URL'));

        unlink($path);
    }
}
