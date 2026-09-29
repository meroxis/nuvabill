<?php

namespace Tests\Feature;

use App\Billing\InvoicePdf;
use App\Mail\TemplatedMessage;
use App\Mail\TemplateMailer;
use App\Models\Admin;
use App\Models\Client;
use App\Models\EmailTemplate;
use App\Models\Invoice;
use App\Support\Locales;
use Database\Seeders\EmailTemplateTranslations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

/**
 * Emails and PDF invoices in the language each client picked.
 */
class EmailLanguageTest extends TestCase
{
    use RefreshDatabase;

    public function test_clients_get_emails_in_the_language_they_picked(): void
    {
        Mail::fake();
        $client = Client::factory()->create(['language' => 'de']);
        $invoice = Invoice::factory()->create(['client_id' => $client->id, 'due_at' => Carbon::parse('2026-10-12'), 'number' => 'INV-0042']);

        app(TemplateMailer::class)->send('invoice.created', $client, TemplateMailer::invoiceContext($invoice));

        Mail::assertSent(TemplatedMessage::class, function (TemplatedMessage $mail): bool {
            $html = $mail->render();

            return $mail->subjectLine === 'Rechnung INV-0042 ist bereit'
                && str_contains($html, 'Okt 2026')
                && str_contains($html, 'lang="de"');
        });
    }

    public function test_right_to_left_languages_get_a_right_to_left_email(): void
    {
        Mail::fake();
        $client = Client::factory()->create(['language' => 'ar']);

        app(TemplateMailer::class)->send('client.welcome', $client);

        Mail::assertSent(TemplatedMessage::class, fn (TemplatedMessage $mail): bool => str_contains($mail->subjectLine, 'أهلًا بك')
            && str_contains($mail->render(), 'dir="rtl"'));
    }

    public function test_a_language_clients_can_no_longer_pick_falls_back_to_the_default(): void
    {
        Mail::fake();
        $this->setSettings(['locale.enabled' => ['en', 'fr'], 'locale.default' => 'fr']);
        $client = Client::factory()->create(['language' => 'de']);

        $this->assertSame('fr', Locales::forClient($client));

        app(TemplateMailer::class)->send('client.welcome', $client);

        Mail::assertSent(TemplatedMessage::class, fn (TemplatedMessage $mail): bool => str_starts_with($mail->subjectLine, 'Bienvenue'));
    }

    public function test_a_rewritten_template_keeps_the_hosts_words_in_every_language(): void
    {
        $template = EmailTemplate::query()->where('key', 'client.welcome')->firstOrFail();
        $template->translations()->delete();
        $template->update(['subject' => 'Hello from our team']);

        EmailTemplateTranslations::install(['client.welcome']);

        $this->assertSame(0, $template->translations()->count());
        $this->assertSame('Hello from our team', $template->fresh('translations')->textFor('de')[0]);
        $this->assertSame(9, EmailTemplate::query()->where('key', 'invoice.created')->firstOrFail()->translations()->count());
    }

    public function test_staff_edit_and_remove_a_translation(): void
    {
        $this->actingAs(Admin::factory()->create(), 'admin');
        $template = EmailTemplate::query()->where('key', 'client.welcome')->firstOrFail();

        $this->get(route('admin.settings.email-templates.edit', [$template, 'lang' => 'tr']))->assertOk()->assertSee('Türkçe')->assertSee('ailesine hoş geldiniz');

        $this->put(route('admin.settings.email-templates.update', $template), ['locale' => 'tr', 'subject' => 'Hoş geldin', 'body' => ''])
            ->assertRedirect(route('admin.settings.email-templates.edit', [$template, 'lang' => 'tr']));
        $this->assertSame('Hoş geldin', $template->translations()->where('locale', 'tr')->value('subject'));
        $this->assertStringContainsString('Thank you for creating an account', $template->fresh('translations')->textFor('tr')[1], 'An empty message uses the English text.');

        $this->put(route('admin.settings.email-templates.update', $template), ['locale' => 'tr', 'subject' => '', 'body' => '']);
        $this->assertSame(0, $template->translations()->where('locale', 'tr')->count());
    }

    public function test_pdf_invoices_follow_the_client_except_scripts_the_pdf_engine_cannot_draw(): void
    {
        $seen = [];
        View::composer('pdf.invoice', function () use (&$seen): void {
            $seen[] = app()->getLocale();
        });

        foreach (['de', 'ar', 'zh_CN'] as $language) {
            $invoice = Invoice::factory()->create(['client_id' => Client::factory()->create(['language' => $language])->id]);
            app(InvoicePdf::class)->render($invoice);
        }

        $this->assertSame(['de', 'en', 'en'], $seen);
        $this->assertSame('en', app()->getLocale(), 'The request language comes back afterwards.');
    }
}
