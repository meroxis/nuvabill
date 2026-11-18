<?php

namespace Tests\Feature;

use Tests\TestCase;

class InstallScriptTest extends TestCase
{
    protected bool $seed = false;

    public function test_the_nginx_setup_sends_old_php_addresses_to_nuvabill(): void
    {
        $script = (string) file_get_contents(base_path('install.sh'));

        $this->assertStringContainsString('location = /index.php {', $script);
        $this->assertStringContainsString('rewrite ^ /index.php last;', $script);
        // No other PHP file in public/ runs, and nginx no longer answers /clientarea.php with its own 404.
        $this->assertDoesNotMatchRegularExpression('/location ~ \\\.php\\?\$ \{[^}]*fastcgi_pass/', $script);
        $this->assertSame(1, substr_count($script, 'fastcgi_pass'));
        // Design files keep their year-long cache.
        $this->assertStringContainsString('add_header Cache-Control "public, max-age=31536000, immutable";', $script);
    }
}
