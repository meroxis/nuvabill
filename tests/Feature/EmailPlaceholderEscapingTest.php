<?php

namespace Tests\Feature;

use App\Mail\MarkdownValue;
use App\Mail\TemplatedMessage;
use App\Mail\TemplateMailer;
use App\Models\Client;
use App\Models\Ticket;
use App\Models\TicketDepartment;
use App\Support\TicketDesk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Names, subjects and ticket text go into emails as plain text: they cannot add links, pictures
 * or headings to an email that comes from the company.
 */
class EmailPlaceholderEscapingTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_names_cannot_add_links_or_images_to_the_welcome_email(): void
    {
        Mail::fake();

        $this->post(route('client.register'), [
            'first_name' => '[Confirm here](https://evil.example/login) ![](https://evil.example/p.png)',
            'last_name' => 'Raz',
            'email' => 'raz@example.test',
            'country' => 'IQ',
            'password' => 'secret-pass-1',
            'password_confirmation' => 'secret-pass-1',
        ])->assertRedirect();

        Mail::assertSent(TemplatedMessage::class, fn (TemplatedMessage $mail): bool => $mail->hasTo('raz@example.test')
            && ! str_contains($mail->bodyHtml, 'href="https://evil.example')
            && ! str_contains($mail->bodyHtml, 'src="https://evil.example')
            && str_contains($mail->bodyHtml, 'Hi [Confirm here](https://evil.example/login) ![](https://evil.example/p.png),')
            && str_contains($mail->bodyHtml, 'href="'.e(route('client.dashboard')).'"'));
    }

    public function test_client_ticket_text_cannot_add_links_or_images_to_staff_emails(): void
    {
        Mail::fake();
        $this->setSettings(['company.email' => 'team@example.test']);
        $client = Client::factory()->create(['first_name' => '[Mer', 'last_name' => 'Las](https://evil.example)']);

        $ticket = app(TicketDesk::class)->open(
            $client,
            TicketDepartment::query()->firstOrFail(),
            '![](https://evil.example/t.png)',
            "Hi\n\n[Reply now](https://evil.example/admin/login) ![](https://evil.example/o.gif) <https://evil.example/x>\n\n# Urgent\n\n```",
        );

        Mail::assertSent(TemplatedMessage::class, fn (TemplatedMessage $mail): bool => $mail->hasTo('team@example.test')
            && ! str_contains($mail->bodyHtml, 'href="https://evil.example')
            && ! str_contains($mail->bodyHtml, '<img')
            && ! str_contains($mail->bodyHtml, '<pre>')
            && ! str_contains($mail->bodyHtml, '<h1>')
            && str_contains($mail->bodyHtml, '[Reply now](https://evil.example/admin/login)')
            && str_contains($mail->bodyHtml, '[Mer Las](https://evil.example) opened a ticket')
            && str_contains($mail->bodyHtml, '<a href="'.e(route('admin.tickets.show', $ticket)).'">Reply now</a>'));
        $this->assertInstanceOf(Ticket::class, $ticket);
    }

    public function test_the_security_alert_issue_list_still_renders_as_markdown(): void
    {
        Mail::fake();

        app(TemplateMailer::class)->sendTo('admin.security_alert', 'raz@example.test', 'Raz', [
            'staff' => ['name' => 'Raz'],
            'issues' => new MarkdownValue("- **Debug mode**: on\n- **Old PHP**"),
            'score' => 80,
            'admin_url' => 'https://billing.example.test/admin/health',
        ]);

        Mail::assertSent(TemplatedMessage::class, fn (TemplatedMessage $mail): bool => str_contains($mail->bodyHtml, '<li><strong>Debug mode</strong>: on</li>')
            && str_contains($mail->bodyHtml, '<strong>80 of 100</strong>')
            && str_contains($mail->bodyHtml, '<a href="https://billing.example.test/admin/health">Open site health</a>'));
    }

    public function test_links_in_written_messages_keep_working_and_unsafe_addresses_are_dropped(): void
    {
        $html = TemplateMailer::renderHtml("Pay here: {{ invoice.url }}\n\n[Open]({{ link }}) and **{{ name }}**", [
            'invoice' => ['url' => 'https://billing.example.test/client/invoices/5?a=1&b=2'],
            'link' => 'javascript:alert(1)',
            'name' => "Mer <b>Las</b>\nsecond line",
        ]);

        $this->assertStringContainsString('Pay here: <a href="https://billing.example.test/client/invoices/5?a=1&amp;b=2">https://billing.example.test/client/invoices/5?a=1&amp;b=2</a>', $html);
        $this->assertStringContainsString('<a href="">Open</a>', $html);
        $this->assertStringContainsString('<strong>Mer &lt;b&gt;Las&lt;/b&gt;<br />', $html);
        $this->assertStringNotContainsString('javascript:', $html);

        // Plain-text uses, such as subjects, still get the values as they are.
        $this->assertSame('Hi *Raz*', TemplateMailer::render('Hi {{ name }}', ['name' => '*Raz*']));
    }
}
