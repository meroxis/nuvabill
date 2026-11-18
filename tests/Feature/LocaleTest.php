<?php

namespace Tests\Feature;

use App\Mail\TemplatedMessage;
use App\Models\Admin;
use App\Models\Client;
use App\Models\Invoice;
use App\Support\Locales;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\View;
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

    public function test_visitors_switch_to_thai_and_the_page_reads_left_to_right(): void
    {
        $this->assertArrayHasKey('th', Locales::enabled());

        $this->post(route('language.update'), ['locale' => 'th'])->assertRedirect();

        $this->get(route('client.login'))
            ->assertOk()
            ->assertSee('lang="th" dir="ltr"', false)
            ->assertSee('ลงชื่อเข้าใช้บัญชีของคุณ')
            ->assertDontSee('Sign in to your account');
    }

    public function test_thai_has_one_plural_form_and_its_pdfs_stay_in_english(): void
    {
        app()->setLocale('th');

        $this->assertSame('ใบแจ้งหนี้ 1 ใบ', trans_choice(':count invoice|:count invoices', 1));
        $this->assertSame('ใบแจ้งหนี้ 5 ใบ', trans_choice(':count invoice|:count invoices', 5));
        $this->assertSame('ใช้แล้ว 1 ครั้ง', trans_choice('Used once|Used :count times', 1));
        $this->assertSame('ใช้แล้ว 5 ครั้ง', trans_choice('Used once|Used :count times', 5));
        $this->assertSame('en', Locales::forPdf('th'));
        $this->assertFalse(Locales::isRtl('th'));
    }

    public function test_a_site_that_updates_keeps_its_languages_and_thai_stays_off(): void
    {
        $migration = require database_path('migrations/2027_08_01_000013_keep_enabled_languages_on_update.php');

        // A new site has no staff yet: nothing is saved and Thai is on.
        $migration->up();
        $this->assertArrayHasKey('th', Locales::enabled());

        Admin::factory()->create();
        $migration->up();
        $this->assertArrayNotHasKey('th', Locales::enabled());
        $this->assertCount(10, Locales::enabled());
        $this->post(route('language.update'), ['locale' => 'th'])->assertSessionHasErrors('locale');

        // A choice staff saved before is kept as it is.
        $this->setSettings(['locale.enabled' => ['en', 'th']]);
        $migration->up();
        $this->assertSame(['en', 'th'], array_keys(Locales::enabled()));
    }

    public function test_a_new_site_offers_mongolian_only_after_staff_turn_it_on(): void
    {
        // Off on a new site: clients cannot pick it and the client language menu does not list it.
        $this->assertArrayNotHasKey('mn', Locales::enabled());
        $this->assertCount(11, Locales::enabled());
        $this->post(route('language.update'), ['locale' => 'mn'])->assertSessionHasErrors('locale');
        // A link to it goes to the address without the language (0.7.4).
        $this->get(route('client.login', ['lang' => 'mn']))->assertStatus(301)->assertRedirect(route('client.login'));
        $this->get(route('client.login'))->assertSee('lang="en"', false)->assertDontSee('value="mn"', false);
        $this->assertSame('en', Locales::forClient(Client::factory()->create(['language' => 'mn'])));

        // The 0.7.0 update step never switches it on, whether staff saved their languages or not.
        $migration = require database_path('migrations/2027_08_01_000013_keep_enabled_languages_on_update.php');
        $migration->up();
        $this->assertArrayNotHasKey('mn', Locales::enabled());
        Admin::factory()->create();
        $migration->up();
        $this->assertArrayNotHasKey('mn', Locales::enabled());

        // Staff turn it on in Settings → General.
        $this->signInAdmin();
        $this->get(route('admin.settings.edit'))->assertOk()->assertSee('value="mn"', false);
        $this->put(route('admin.settings.update'), [
            'company_name' => 'YourHost',
            'company_email' => 'billing@host.test',
            'currency' => 'USD',
            'renewal_days_before' => 7,
            'payment_terms_days' => 7,
            'suspend_days' => 5,
            'terminate_days' => 30,
            'accent' => '#0B7A70',
            'theme' => 'nova',
            'locale_default' => 'en',
            'locale_enabled' => ['en', 'mn'],
        ])->assertSessionHas('status');

        $this->assertSame(['en', 'mn'], array_keys(Locales::enabled()));
        $this->assertSame('Монгол', Locales::enabled()['mn']);

        $this->post(route('language.update'), ['locale' => 'mn'])->assertRedirect()->assertSessionHasNoErrors();
        $this->get(route('client.login'))
            ->assertOk()
            ->assertSee('lang="mn" dir="ltr"', false)
            ->assertSee(__('Sign in to your account', locale: 'mn'))
            ->assertDontSee('Sign in to your account');
        $this->assertNotSame('Sign in to your account', __('Sign in to your account', locale: 'mn'));
    }

    public function test_mongolian_texts_use_one_form_for_every_count_and_its_pdfs_stay_in_mongolian(): void
    {
        app()->setLocale('mn');

        $this->assertSame('5 нэхэмжлэх', trans_choice(':count invoice|:count invoices', 5));
        $this->assertSame('1 нэхэмжлэх', trans_choice(':count invoice|:count invoices', 1));
        $this->assertSame('mn', Locales::forPdf('mn'));
        $this->assertFalse(Locales::isRtl('mn'));

        // The PDF font has every letter the Mongolian texts use, so a Mongolian PDF has no empty boxes.
        foreach (['DejaVuSans', 'DejaVuSans-Bold'] as $font) {
            preg_match_all('/^U (\d+) ;/m', (string) file_get_contents(base_path("vendor/dompdf/dompdf/lib/fonts/{$font}.ufm")), $glyphs);
            $glyphs = array_flip(array_map('intval', $glyphs[1]));
            $letters = array_unique(mb_str_split(implode('', json_decode((string) file_get_contents(lang_path('mn.json')), true))));
            $missing = array_filter($letters, fn (string $letter): bool => mb_ord($letter) >= 32 && ! isset($glyphs[mb_ord($letter)]));

            $this->assertSame([], array_values($missing), "{$font} draws every Mongolian letter.");
        }
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

        // A client choice in this browser is kept apart: the admin area does not follow it.
        $this->post(route('language.update'), ['locale' => 'de']);
        $this->get(route('client.login'))->assertSee('lang="de" dir="ltr"', false);
        $this->get(route('admin.dashboard'))->assertSee('lang="ar" dir="rtl"', false);
    }

    public function test_a_link_to_a_language_version_wins_over_every_choice(): void
    {
        $this->withSession([Locales::SESSION_CLIENT => 'de', Locales::SESSION_ADMIN => 'ar'])
            ->get(route('client.login', ['lang' => 'fr']))
            ->assertSee('lang="fr"', false)
            ->assertSessionHas(Locales::SESSION_CLIENT, 'fr');
    }

    public function test_the_client_area_follows_the_language_staff_picked_in_the_admin_area(): void
    {
        $this->actingAs(Admin::factory()->create(), 'admin')->post(route('admin.language'), ['locale' => 'ar']);

        $this->get(route('client.login'))->assertSee('lang="ar" dir="rtl"', false)->assertSee('سجّل الدخول إلى حسابك');
    }

    public function test_a_client_choice_wins_over_the_admin_choice(): void
    {
        $this->actingAs(Admin::factory()->create(), 'admin')->post(route('admin.language'), ['locale' => 'ar']);
        $this->get(route('client.login'))->assertSee('lang="ar"', false);

        // Picking a language on the client side later wins, and the admin area keeps its own.
        $this->post(route('language.update'), ['locale' => 'de'])->assertSessionHas(Locales::SESSION_CLIENT, 'de');
        $this->get(route('client.login'))->assertSee('lang="de"', false);
        $this->get(route('admin.dashboard'))->assertSee('lang="ar"', false);
    }

    public function test_the_admin_choice_wins_over_the_language_saved_on_the_client_account(): void
    {
        $client = Client::factory()->create(['language' => 'de']);

        $this->actingAs($client, 'web')->get(route('client.dashboard'))->assertSee('lang="de"', false);

        $this->withSession([Locales::SESSION_ADMIN => 'fr'])->get(route('client.dashboard'))->assertSee('lang="fr"', false);
        $this->assertSame('de', $client->fresh()->language);
    }

    public function test_an_admin_language_clients_cannot_use_is_skipped(): void
    {
        $this->setSettings(['locale.enabled' => ['en', 'de']]);
        $client = Client::factory()->create(['language' => 'de']);

        $this->withSession([Locales::SESSION_ADMIN => 'ar'])->get(route('client.login'))->assertSee('lang="en"', false);
        $this->actingAs($client, 'web')->withSession([Locales::SESSION_ADMIN => 'ar'])
            ->get(route('client.dashboard'))
            ->assertSee('lang="de"', false);
    }

    public function test_signing_in_to_the_admin_area_does_not_pick_a_language_for_the_client_area(): void
    {
        $this->setSettings(['locale.default' => 'fr']);

        // The language saved on the staff account is used in the admin area only.
        Admin::factory()->create(['email' => 'owner@example.test', 'language' => 'ar']);
        $this->post(route('admin.login'), ['email' => 'owner@example.test', 'password' => 'password'])
            ->assertRedirect(route('admin.dashboard'))
            ->assertSessionMissing(Locales::SESSION_ADMIN);
        $this->get(route('admin.dashboard'))->assertSee('lang="ar"', false)->assertSessionMissing(Locales::SESSION_ADMIN);
        $this->get(route('client.login'))->assertSee('lang="fr"', false);
    }

    public function test_the_admin_area_never_follows_the_client_choice(): void
    {
        $this->setSettings(['locale.default' => 'fr']);

        $this->withSession([Locales::SESSION_CLIENT => 'ckb'])
            ->actingAs(Admin::factory()->create(['language' => null]), 'admin')
            ->get(route('admin.dashboard'))
            ->assertSee('lang="fr"', false);
        $this->get(route('admin.dashboard', ['lang' => 'de']))->assertSee('lang="fr"', false);
    }

    public function test_emails_and_pdfs_keep_the_client_account_language(): void
    {
        Mail::fake();
        $pdfLanguages = [];
        View::composer('pdf.invoice', function () use (&$pdfLanguages): void {
            $pdfLanguages[] = app()->getLocale();
        });
        $client = Client::factory()->create(['language' => 'de']);
        $invoice = Invoice::factory()->create(['client_id' => $client->id]);

        // Staff work in Arabic, so the requests below run in Arabic.
        $this->actingAs(Admin::factory()->create(), 'admin')->post(route('admin.language'), ['locale' => 'ar']);
        $this->get(route('client.login'))->assertSee('lang="ar"', false);

        $this->post(route('admin.invoices.email', $invoice))->assertRedirect();
        Mail::assertSent(TemplatedMessage::class, fn (TemplatedMessage $mail): bool => $mail->locale === 'de'
            && str_contains($mail->render(), 'lang="de"'));

        $this->get(route('admin.invoices.pdf', $invoice))->assertOk();
        $this->assertSame(['de'], $pdfLanguages);
    }

    public function test_pages_in_a_picked_language_are_never_kept_by_shared_caches(): void
    {
        $page = $this->withSession([Locales::SESSION_ADMIN => 'ar'])->get(route('client.login'))->assertSee('lang="ar"', false);

        $this->assertStringContainsString('private', (string) $page->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('public', (string) $page->headers->get('Cache-Control'));
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
        // How many plural forms Laravel picks from in each language (2 when not listed, as for Mongolian);
        // one form for every count is always allowed. Kurdish uses explicit ranges instead.
        $pluralForms = ['ar' => 6, 'th' => 1, 'tr' => 1, 'zh_CN' => 1, 'ru' => 3];
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

        $this->post(route('admin.language'), ['locale' => 'ar']);
        $this->get(route('admin.dashboard'))->assertSee('lang="ar" dir="rtl"', false);

        $this->post(route('admin.language'), ['locale' => 'pt_BR']);
        $this->get(route('admin.dashboard'))->assertSee('lang="pt-BR" dir="ltr"', false);
    }
}
