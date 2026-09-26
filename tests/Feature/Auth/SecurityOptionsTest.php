<?php

namespace Tests\Feature\Auth;

use App\Mail\TemplatedMessage;
use App\Models\Client;
use App\Security\Totp;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SecurityOptionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_captcha_only_runs_after_staff_checked_the_keys(): void
    {
        Http::fake(['challenges.cloudflare.com/turnstile/v0/siteverify' => Http::response(['success' => true])]);
        $this->signInAdmin();

        $this->put(route('admin.settings.security.update'), $this->securityForm([
            'captcha_provider' => 'turnstile',
            'captcha_site_key' => '0x4AAAA-site',
            'captcha_secret' => '0x4AAAA-secret',
            'captcha_forms' => ['client_login', 'client_register'],
        ]))->assertSessionHas('status');

        // Saved but not checked: forms do not ask yet, so wrong keys cannot lock anyone out.
        auth('admin')->logout();
        $this->get(route('client.login'))->assertDontSee('cf-turnstile');

        $this->signInAdmin();
        $this->get(route('admin.settings.security.edit'))
            ->assertSee('Check and turn on')
            ->assertSee('data-sitekey="0x4AAAA-site"', false)
            ->assertHeader('Content-Security-Policy');
        $this->assertStringContainsString('frame-src \'self\' https://challenges.cloudflare.com', $this->get(route('admin.settings.security.edit'))->headers->get('Content-Security-Policy'));

        $this->post(route('admin.settings.security.captcha-check'), ['cf-turnstile-response' => 'token-ok'])->assertSessionHas('status');
        Http::assertSent(fn (Request $request): bool => $request['secret'] === '0x4AAAA-secret' && $request['response'] === 'token-ok');

        auth('admin')->logout();
        $this->get(route('client.login'))->assertSee('class="cf-turnstile"', false);
        $this->get(route('client.password.request'))->assertDontSee('cf-turnstile');
    }

    public function test_protected_forms_refuse_posts_without_a_passing_captcha(): void
    {
        $this->turnOnCaptcha(['client_login']);
        Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => false, 'error-codes' => ['invalid-input-response']])]);
        $client = Client::factory()->create(['password' => 'right-password-1']);

        $this->from(route('client.login'))
            ->post(route('client.login'), ['email' => $client->email, 'password' => 'right-password-1'])
            ->assertRedirect(route('client.login'))
            ->assertSessionHasErrors('captcha');
        $this->assertGuest('web');

        $this->post(route('client.login'), ['email' => $client->email, 'password' => 'right-password-1', 'cf-turnstile-response' => 'bad'])->assertSessionHasErrors('captcha');
        $this->assertGuest('web');
    }

    public function test_a_passing_captcha_lets_the_form_through(): void
    {
        $this->turnOnCaptcha(['client_login']);
        Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => true])]);
        $client = Client::factory()->create(['password' => 'right-password-1']);

        $this->post(route('client.login'), ['email' => $client->email, 'password' => 'right-password-1', 'cf-turnstile-response' => 'good'])
            ->assertRedirect(route('client.dashboard'));
        $this->assertAuthenticatedAs($client, 'web');
    }

    public function test_clients_with_an_authenticator_app_enter_a_code_after_the_password(): void
    {
        $secret = Totp::generateSecret();
        $client = $this->clientWithTwoFactor(['two_factor_method' => 'totp', 'two_factor_secret' => $secret, 'two_factor_recovery_codes' => ['abcde-12345']]);

        $this->post(route('client.login'), ['email' => $client->email, 'password' => 'right-password-1'])
            ->assertRedirect(route('client.two-factor.challenge'));
        $this->assertGuest('web');

        $this->get(route('client.two-factor.challenge'))->assertOk()->assertSee('authenticator app');
        $this->post(route('client.two-factor.challenge'), ['code' => '000000'])->assertSessionHasErrors('code');
        $this->assertGuest('web');

        $this->post(route('client.two-factor.challenge'), ['code' => Totp::codeAt($secret, intdiv(time(), 30))])
            ->assertRedirect(route('client.dashboard'));
        $this->assertAuthenticatedAs($client, 'web');
    }

    public function test_recovery_codes_work_once(): void
    {
        $client = $this->clientWithTwoFactor(['two_factor_method' => 'totp', 'two_factor_secret' => Totp::generateSecret(), 'two_factor_recovery_codes' => ['abcde-12345', 'fghij-67890']]);

        $this->post(route('client.login'), ['email' => $client->email, 'password' => 'right-password-1']);
        $this->post(route('client.two-factor.challenge'), ['recovery_code' => 'ABCDE-12345'])->assertRedirect(route('client.dashboard'));

        $this->assertSame(['fghij-67890'], $client->fresh()->two_factor_recovery_codes);
    }

    public function test_clients_with_email_codes_get_a_code_by_email(): void
    {
        Mail::fake();
        $client = $this->clientWithTwoFactor(['two_factor_method' => 'email']);

        $this->post(route('client.login'), ['email' => $client->email, 'password' => 'right-password-1']);
        $this->get(route('client.two-factor.challenge'))->assertOk()->assertSee(substr($client->email, 0, 1).'***@');

        $code = null;
        Mail::assertSent(TemplatedMessage::class, function (TemplatedMessage $message) use (&$code): bool {
            preg_match('/\b(\d{6})\b/', $message->bodyHtml, $match);
            $code = $match[1] ?? null;

            return $code !== null;
        });

        $this->post(route('client.two-factor.challenge'), ['code' => $code])->assertRedirect(route('client.dashboard'));
        $this->assertAuthenticatedAs($client, 'web');
    }

    public function test_email_codes_stop_working_after_five_wrong_tries(): void
    {
        Mail::fake();
        $client = $this->clientWithTwoFactor(['two_factor_method' => 'email']);

        $this->post(route('client.login'), ['email' => $client->email, 'password' => 'right-password-1']);
        $this->get(route('client.two-factor.challenge'));
        preg_match('/\b(\d{6})\b/', Mail::sent(TemplatedMessage::class)->first()->bodyHtml, $match);

        foreach (range(1, 5) as $try) {
            // A new IP each time, so the rate limit (shared per IP) does not answer first.
            $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.'.$try])->post(route('client.two-factor.challenge'), ['code' => $match[1] === '111111' ? '222222' : '111111']);
        }

        $last = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.99'])->post(route('client.two-factor.challenge'), ['code' => $match[1]]);
        $last->assertSessionHasErrors('code');
        $this->assertGuest('web');
    }

    public function test_clients_turn_on_an_authenticator_app_from_their_account(): void
    {
        $client = Client::factory()->create();
        $this->actingAs($client, 'web');

        $this->post(route('client.account.two-factor.app'));
        $client->refresh();
        $this->get(route('client.account.edit'))->assertSee('Scan this QR code')->assertSee($client->two_factor_secret);

        $this->post(route('client.account.two-factor.app.confirm'), ['code' => Totp::codeAt($client->two_factor_secret, intdiv(time(), 30))])
            ->assertSessionHas('recovery_codes');

        $this->assertTrue($client->fresh()->hasTwoFactorEnabled());
    }

    public function test_required_two_factor_sends_clients_and_staff_to_set_it_up(): void
    {
        app(Settings::class)->setMany(['security.client_two_factor' => 'required', 'security.staff_two_factor' => 'required']);

        $this->actingAs(Client::factory()->create(), 'web')
            ->get(route('client.invoices.index'))
            ->assertRedirect(route('client.account.edit').'#two-factor');
        $this->get(route('client.account.edit'))->assertOk();

        $this->signInAdmin();
        $this->get(route('admin.clients.index'))->assertRedirect(route('admin.profile.edit'));
        $this->get(route('admin.profile.edit'))->assertOk();
    }

    public function test_staff_reset_two_factor_for_a_client_who_lost_their_phone(): void
    {
        $client = $this->clientWithTwoFactor(['two_factor_method' => 'totp', 'two_factor_secret' => Totp::generateSecret()]);
        $this->signInAdmin();

        $this->get(route('admin.clients.show', $client))->assertSee('Reset two-factor');
        $this->delete(route('admin.clients.two-factor.destroy', $client))->assertSessionHas('status');

        $this->assertFalse($client->fresh()->hasTwoFactorEnabled());
    }

    public function test_clients_cannot_turn_off_required_two_factor(): void
    {
        app(Settings::class)->set('security.client_two_factor', 'required');
        $client = $this->clientWithTwoFactor(['two_factor_method' => 'email']);

        $this->actingAs($client, 'web')
            ->delete(route('client.account.two-factor.destroy'), ['current_password' => 'right-password-1'])
            ->assertSessionHas('error');

        $this->assertTrue($client->fresh()->hasTwoFactorEnabled());
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function securityForm(array $overrides = []): array
    {
        return $overrides + [
            'staff_two_factor' => 'optional',
            'client_two_factor' => 'optional',
            'client_two_factor_methods' => ['totp', 'email'],
            'captcha_provider' => 'off',
            'captcha_site_key' => '',
            'captcha_secret' => '',
            'captcha_forms' => [],
        ];
    }

    /**
     * @param  list<string>  $forms
     */
    private function turnOnCaptcha(array $forms): void
    {
        app(Settings::class)->setMany([
            'security.captcha_provider' => 'turnstile',
            'security.captcha_site_key' => 'site-key',
            'security.captcha_secret' => 'secret-key',
            'security.captcha_forms' => $forms,
            'security.captcha_checked_key' => 'site-key',
        ]);
    }

    /**
     * @param  array<string, mixed>  $twoFactor
     */
    private function clientWithTwoFactor(array $twoFactor): Client
    {
        $client = Client::factory()->create(['password' => 'right-password-1']);
        $client->forceFill($twoFactor + ['two_factor_confirmed_at' => now()])->save();

        return $client;
    }
}
