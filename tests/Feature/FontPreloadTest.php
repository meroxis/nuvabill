<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FontPreloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_two_font_weights_are_preloaded(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertSame(2, substr_count($html, 'as="font"'));
        $this->assertMatchesRegularExpression('#href="[^"]*figtree-400-normal[^"]*"[^>]*as="font"|as="font"[^>]*figtree-400-normal#', $html);
        $this->assertMatchesRegularExpression('#href="[^"]*figtree-700-normal[^"]*"[^>]*as="font"|as="font"[^>]*figtree-700-normal#', $html);
    }
}
