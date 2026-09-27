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

    public function test_every_translation_keeps_the_placeholders_of_the_english_text(): void
    {
        $arabic = json_decode(file_get_contents(lang_path('ar.json')), true, flags: JSON_THROW_ON_ERROR);
        $kurdish = json_decode(file_get_contents(lang_path('ckb.json')), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(array_keys($arabic), array_keys($kurdish), 'Arabic and Kurdish translate the same texts.');

        foreach (['ar' => $arabic, 'ckb' => $kurdish] as $locale => $lines) {
            foreach ($lines as $english => $translated) {
                preg_match_all('/:[a-z_]+/', $english, $wanted);
                preg_match_all('/:[a-z_]+/', $translated, $got);

                $this->assertSame([], array_values(array_diff($wanted[0], $got[0])), "{$locale}: \"{$translated}\" misses a placeholder of \"{$english}\"");
                $this->assertNotSame('', trim($translated), "{$locale}: \"{$english}\" is empty");
            }
        }
    }
}
