<?php

namespace Tests\Feature;

use App\Support\Themes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Themes and order forms can bring their own translations (Nuvabill 0.6.11).
 */
class ThemeTranslationsTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = storage_path('framework/testing/theme-lang');
        File::deleteDirectory($this->dir);

        foreach (['themes/glow/views', 'themes/glow/lang', 'orderforms/fast/views', 'orderforms/fast/lang'] as $folder) {
            File::ensureDirectoryExists($this->dir.'/'.$folder);
        }

        File::put($this->dir.'/themes/glow/lang/ar.json', (string) json_encode(['Hello from the theme' => 'مرحبا من القالب', 'Dashboard' => 'not this one']));
        File::put($this->dir.'/orderforms/fast/lang/ar.json', (string) json_encode(['Order in one step' => 'اطلب بخطوة واحدة']));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    public function test_the_active_theme_and_order_form_add_their_own_translations(): void
    {
        $this->setSettings(['theme.active' => 'glow', 'orderform.active' => 'fast']);

        (new Themes($this->dir.'/themes', $this->dir.'/orderforms'))->register();
        app()->setLocale('ar');

        $this->assertSame('مرحبا من القالب', __('Hello from the theme'));
        $this->assertSame('اطلب بخطوة واحدة', __('Order in one step'));
        // Nuvabill's own translation wins over the theme's.
        $this->assertSame(json_decode((string) file_get_contents(lang_path('ar.json')), true)['Dashboard'], __('Dashboard'));
    }
}
