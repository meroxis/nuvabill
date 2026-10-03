<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * Links in emails always use the site's own address (APP_URL), whatever Host header a visitor sends.
 */
class PasswordResetHostTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_client_reset_link_uses_the_site_address_not_the_host_header(): void
    {
        $client = Client::factory()->create(['first_name' => 'Mer', 'last_name' => 'Las', 'email' => 'mer@example.test']);

        $this->post('http://evil.example/forgot-password', ['email' => $client->email])->assertSessionHasNoErrors();

        $body = $this->sentBodies();
        $this->assertStringContainsString(rtrim((string) config('app.url'), '/').'/reset-password/', $body);
        $this->assertStringNotContainsString('evil.example', $body);
    }

    public function test_a_staff_reset_link_uses_the_site_address_not_the_host_header(): void
    {
        $admin = Admin::factory()->create(['name' => 'Raz', 'email' => 'raz@example.test']);
        $adminPath = config('nuvabill.admin_path');

        $this->post("http://evil.example/{$adminPath}/forgot-password", ['email' => $admin->email])->assertSessionHasNoErrors();

        $body = $this->sentBodies();
        $this->assertStringContainsString(rtrim((string) config('app.url'), '/')."/{$adminPath}/reset-password/", $body);
        $this->assertStringNotContainsString('evil.example', $body);
    }

    public function test_the_welcome_email_uses_the_site_address_not_the_host_header(): void
    {
        $this->post('http://evil.example/register', [
            'first_name' => 'Mer',
            'last_name' => 'Las',
            'email' => 'mer@example.test',
            'country' => 'IQ',
            'password' => 'secret-pass-1',
            'password_confirmation' => 'secret-pass-1',
        ])->assertSessionHasNoErrors();

        $this->assertTrue(Client::query()->where('email', 'mer@example.test')->exists());
        $body = $this->sentBodies();
        $this->assertNotSame('', $body);
        $this->assertStringNotContainsString('evil.example', $body);
    }

    public function test_redirects_use_the_site_address_not_the_host_header(): void
    {
        $this->get('http://evil.example/client')->assertRedirect(rtrim((string) config('app.url'), '/').'/login');
    }

    public function test_the_www_form_of_the_site_address_keeps_its_own_links(): void
    {
        config(['app.url' => 'https://billing.example.test']);

        $this->get('https://www.billing.example.test/client')->assertRedirect('https://www.billing.example.test/login');
        $this->get('https://billing.example.test/client')->assertRedirect('https://billing.example.test/login');
        $this->get('https://other.example.test/client')->assertRedirect('https://billing.example.test/login');
    }

    public function test_a_port_in_the_host_header_never_reaches_links(): void
    {
        config(['app.url' => 'https://billing.example.test']);

        $this->get('https://billing.example.test:8080/client')->assertRedirect('https://billing.example.test/login');
        $this->get('https://www.billing.example.test:8080/client')->assertRedirect('https://www.billing.example.test/login');
        $this->get('https://billing.example.test:443/client')->assertRedirect('https://billing.example.test/login');
    }

    public function test_a_reset_link_never_carries_a_port_from_the_host_header(): void
    {
        config(['app.url' => 'https://billing.example.test']);
        $client = Client::factory()->create(['first_name' => 'Mer', 'last_name' => 'Las', 'email' => 'mer@example.test']);

        $this->post('https://billing.example.test:444/forgot-password', ['email' => $client->email])->assertSessionHasNoErrors();

        $body = $this->sentBodies();
        $this->assertStringContainsString('https://billing.example.test/reset-password/', $body);
        $this->assertStringNotContainsString(':444', $body);
    }

    public function test_links_keep_the_port_written_in_the_site_address(): void
    {
        config(['app.url' => 'http://localhost:8000']);

        $this->get('http://localhost:8000/client')->assertRedirect('http://localhost:8000/login');
        $this->get('http://localhost:9000/client')->assertRedirect('http://localhost:8000/login');
        $this->get('http://localhost/client')->assertRedirect('http://localhost:8000/login');
    }

    public function test_a_host_header_with_an_unreadable_port_gets_the_site_address(): void
    {
        config(['app.url' => 'https://billing.example.test']);
        $request = Request::create('https://billing.example.test/client');
        $request->headers->set('HOST', 'evil.example:99999');
        $this->app->instance('request', $request);
        URL::setRequest($request);

        $this->assertSame('https://billing.example.test/login', url('login'));
    }

    private function sentBodies(): string
    {
        return app('mailer')->getSymfonyTransport()->messages()
            ->map(fn (SentMessage $message): string => $message->getOriginalMessage() instanceof Email ? $message->getOriginalMessage()->getHtmlBody().$message->getOriginalMessage()->getTextBody() : '')
            ->implode("\n");
    }
}
