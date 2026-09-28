<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Client;
use App\Support\Locales;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_visitors_switch_to_arabic_and_the_page_reads_right_to_left(): void
    {
        $this->get(route('client.login'))->assertSee('dir="ltr"', false)->assertSee('Sign in to your account');

        $this->post(route('language.update'), ['locale' => 'ar'])->assertRedirect();

        $this->get(route('client.login'))
            ->assertSee('lang="ar" dir="rtl"', false)
            ->assertSee('سجّل الدخول إلى حسابك')
            ->assertDontSee('Sign in to your account');
    }

    public function test_the_language_is_saved_on_the_account_and_follows_the_client(): void
    {
        $client = Client::factory()->create();

        $this->actingAs($client, 'web')->post(route('language.update'), ['locale' => 'ckb']);
        $this->assertSame('ckb', $client->fresh()->language);

        $this->flushSession();
        $this->actingAs($client->fresh(), 'web')->get(route('client.dashboard'))->assertSee('lang="ckb" dir="rtl"', false);
    }

    public function test_only_languages_the_company_offers_can_be_picked(): void
    {
        $this->setSettings(['locale.enabled' => ['en', 'ckb']]);

        $this->post(route('language.update'), ['locale' => 'ar'])->assertSessionHasErrors('locale');
        $this->post(route('language.update'), ['locale' => 'xx'])->assertSessionHasErrors('locale');

        $this->setSettings(['locale.default' => 'ckb']);
        $this->flushSession();
        $this->get(route('client.login'))->assertSee('lang="ckb"', false);
    }

    public function test_staff_keep_their_own_language_apart_from_the_client_area(): void
    {
        $admin = Admin::factory()->create();

        $this->actingAs($admin, 'admin')->post(route('admin.language'), ['locale' => 'ar'])->assertRedirect();
        $this->assertSame('ar', $admin->fresh()->language);

        $this->get(route('admin.dashboard'))->assertSee('dir="rtl"', false)->assertSee('لوحة التحكم');
        $this->get(route('client.login'))->assertSee('dir="ltr"', false);
    }

    public function test_prices_keep_latin_digits_and_pdfs_stay_in_english(): void
    {
        app()->setLocale('ar');

        $this->assertSame('$1,250.00', money(125000, 'USD'));
        $this->assertSame('Invoice', Locales::inEnglish(fn (): string => __('Invoice')));
        $this->assertSame('ar', app()->getLocale());
        $this->assertSame('فاتورة', __('Invoice'));
    }

    public function test_every_language_translates_every_text_and_keeps_its_placeholders(): void
    {
        // How many plural forms Laravel picks from in each language; Kurdish uses explicit ranges instead.
        $pluralForms = ['ar' => 6, 'az' => 1, 'tr' => 1, 'zh_CN' => 1, 'cs' => 3, 'hr' => 3, 'ro' => 3, 'ru' => 3, 'uk' => 3];
        $english = null;

        foreach (array_diff(array_keys(Locales::ALL), ['en']) as $locale) {
            $lines = json_decode(file_get_contents(lang_path("{$locale}.json")), true, flags: JSON_THROW_ON_ERROR);
            $english ??= array_keys($lines);

            $this->assertSame($english, array_keys($lines), "{$locale} translates the same texts as the other languages.");

            foreach ($lines as $source => $translated) {
                preg_match_all('/:[a-z_]+/', $source, $wanted);
                preg_match_all('/:[a-z_]+/', $translated, $got);

                $this->assertSame([], array_values(array_diff($wanted[0], $got[0])), "{$locale}: \"{$translated}\" misses a placeholder of \"{$source}\"");
                $this->assertNotSame('', trim($translated), "{$locale}: \"{$source}\" is empty");

                if (str_contains($source, '|') && $locale !== 'ckb' && ! preg_match('/^[{\[]/', $translated)) {
                    $forms = substr_count($translated, '|') + 1;
                    $this->assertContains($forms, [1, $pluralForms[$locale] ?? 2], "{$locale}: \"{$translated}\" has {$forms} plural forms");
                }
            }

            foreach (['auth', 'pagination', 'passwords', 'validation'] as $file) {
                $this->assertFileExists(lang_path("{$locale}/{$file}.php"), "{$locale} translates Laravel's {$file} messages.");
            }

            $this->assertSame(array_keys(require lang_path('ar/validation.php')), array_keys(require lang_path("{$locale}/validation.php")), "{$locale}: every form error is translated.");
        }
    }

    public function test_the_language_menu_lists_every_language_by_its_own_name(): void
    {
        $this->actingAs(Admin::factory()->create(), 'admin');

        $page = $this->get(route('admin.dashboard'))->assertOk();

        foreach (Locales::ALL as $code => $locale) {
            $page->assertSee('value="'.$code.'"', false)->assertSee($locale['native']);
        }

        $this->post(route('admin.language'), ['locale' => 'he']);
        $this->get(route('admin.dashboard'))->assertSee('lang="he" dir="rtl"', false);

        $this->post(route('admin.language'), ['locale' => 'pt_BR']);
        $this->get(route('admin.dashboard'))->assertSee('lang="pt-BR" dir="ltr"', false);
    }
}
