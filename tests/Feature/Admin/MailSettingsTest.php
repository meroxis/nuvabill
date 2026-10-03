<?php

namespace Tests\Feature\Admin;

use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The saved SMTP password is never shown, so it must not be sent to a different mail server either.
 */
class MailSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setSettings([
            'mail.mailer' => 'smtp',
            'mail.host' => 'smtp.example.com',
            'mail.port' => 587,
            'mail.username' => 'billing@example.com',
            'mail.password' => 'saved-secret',
            'mail.encryption' => 'tls',
        ]);
        $this->signInAdmin();
    }

    public function test_the_saved_password_is_not_reused_when_the_host_changes(): void
    {
        $this->put(route('admin.settings.mail'), $this->form(['host' => 'mail.other.example.net', 'encryption' => 'none']))
            ->assertSessionHasErrors('password');

        $this->assertSame('smtp.example.com', $this->setting('mail.host'));
        $this->assertSame('saved-secret', $this->setting('mail.password'));
    }

    public function test_the_saved_password_is_not_reused_when_the_port_changes(): void
    {
        $this->put(route('admin.settings.mail'), $this->form(['port' => 2525]))->assertSessionHasErrors('password');

        $this->assertSame(587, (int) $this->setting('mail.port'));
    }

    public function test_the_saved_password_is_kept_when_the_server_is_the_same(): void
    {
        $this->put(route('admin.settings.mail'), $this->form(['host' => 'SMTP.example.com', 'from_name' => 'Mer Las']))
            ->assertSessionHasNoErrors();

        $this->assertSame('saved-secret', $this->setting('mail.password'));
        $this->assertSame('Mer Las', $this->setting('mail.from_name'));
    }

    public function test_a_new_password_can_go_with_a_new_host(): void
    {
        $this->put(route('admin.settings.mail'), $this->form(['host' => 'mail.other.example.net', 'password' => 'new-secret']))
            ->assertSessionHasNoErrors();

        $this->assertSame('mail.other.example.net', $this->setting('mail.host'));
        $this->assertSame('new-secret', $this->setting('mail.password'));
    }

    public function test_switching_away_from_smtp_forgets_the_password(): void
    {
        $this->put(route('admin.settings.mail'), ['mailer' => 'sendmail', 'from_address' => 'billing@example.com', 'from_name' => 'Mer Las'])
            ->assertSessionHasNoErrors();

        $this->assertSame('', $this->setting('mail.host'));
        $this->assertSame('', (string) $this->setting('mail.password'));
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function form(array $changes = []): array
    {
        return $changes + [
            'mailer' => 'smtp',
            'host' => 'smtp.example.com',
            'port' => 587,
            'username' => 'billing@example.com',
            'password' => '',
            'encryption' => 'tls',
            'from_address' => 'billing@example.com',
            'from_name' => 'Raz',
        ];
    }

    private function setting(string $key): mixed
    {
        $settings = app(Settings::class);
        $settings->flush();

        return $settings->get($key);
    }
}
