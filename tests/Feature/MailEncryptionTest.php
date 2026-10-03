<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Tests\TestCase;

/**
 * "TLS" in the email settings must really encrypt: a server (or someone in between) that hides
 * STARTTLS makes the send fail instead of getting the SMTP password in plain text.
 */
class MailEncryptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_tls_requires_an_encrypted_connection(): void
    {
        $transport = $this->transportFor('tls', 587);

        $this->assertInstanceOf(EsmtpTransport::class, $transport);
        $this->assertTrue($transport->isTlsRequired());
    }

    public function test_ssl_uses_an_encrypted_connection_from_the_start(): void
    {
        $transport = $this->transportFor('ssl', 465);

        $this->assertInstanceOf(EsmtpTransport::class, $transport);
        $this->assertTrue($transport->isTlsRequired());
        $this->assertTrue($transport->getStream()->isTLS());
    }

    public function test_none_still_allows_a_server_without_encryption(): void
    {
        $transport = $this->transportFor('none', 25);

        $this->assertInstanceOf(EsmtpTransport::class, $transport);
        $this->assertFalse($transport->isTlsRequired());
    }

    private function transportFor(string $encryption, int $port): EsmtpTransport
    {
        app(Settings::class)->setMany([
            'mail.mailer' => 'smtp',
            'mail.host' => 'smtp.example.com',
            'mail.port' => $port,
            'mail.username' => 'billing@example.com',
            'mail.password' => 'saved-secret',
            'mail.encryption' => $encryption,
        ]);

        config(AppServiceProvider::mailConfig(app(Settings::class)));
        Mail::purge('smtp');

        $transport = Mail::mailer('smtp')->getSymfonyTransport();
        config(['mail.default' => 'array']);

        return $transport;
    }
}
