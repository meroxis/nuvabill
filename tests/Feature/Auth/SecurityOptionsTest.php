<?php

namespace Tests\Feature\Auth;

use App\Mail\TemplatedMessage;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Security\SignInLimiter;
use App\Security\Totp;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Sleep;
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

        // The fifth wrong code also ended the half-done sign-in, so the right code no longer helps.
        $last = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.99'])->post(route('client.two-factor.challenge'), ['code' => $match[1]]);
        $last->assertRedirect(route('client.login'))->assertSessionHasErrors('email');
        $this->assertGuest('web');
    }

    public function test_app_codes_are_limited_per_account_whichever_ip_they_come_from(): void
    {
        $secret = Totp::generateSecret();
        $client = $this->clientWithTwoFactor(['two_factor_method' => 'totp', 'two_factor_secret' => $secret]);
        $this->post(route('client.login'), ['email' => $client->email, 'password' => 'right-password-1']);

        foreach (range(1, 4) as $try) {
            // A new IP each time, so the rate limit for each IP never answers first.
            $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.'.$try])
                ->post(route('client.two-factor.challenge'), ['code' => $this->wrongCode($secret)])
                ->assertSessionHasErrors('code');
        }

        // The fifth wrong code ends the half-done sign-in.
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.5'])
            ->post(route('client.two-factor.challenge'), ['code' => $this->wrongCode($secret)])
            ->assertRedirect(route('client.login'))
            ->assertSessionHasErrors('email');
        $this->assertNull(session('client.two_factor'));

        // A new sign-in with the password, from yet another address, still cannot use the right code for now.
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.6'])->post(route('client.login'), ['email' => $client->email, 'password' => 'right-password-1']);
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
            ->post(route('client.two-factor.challenge'), ['code' => Totp::codeAt($secret, intdiv(time(), 30))])
            ->assertRedirect(route('client.login'));
        $this->assertGuest('web');
        $this->assertSame(5, ActivityLog::query()->where('action', 'client.two_factor_failed')->where('client_id', $client->id)->count());
        $this->assertTrue(ActivityLog::query()->where('action', 'client.two_factor_locked')->exists());

        // After the wait, the right code works again.
        $this->travel(16)->minutes();
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.8'])->post(route('client.login'), ['email' => $client->email, 'password' => 'right-password-1']);
        $this->post(route('client.two-factor.challenge'), ['code' => Totp::codeAt($secret, intdiv(time(), 30))])->assertRedirect(route('client.dashboard'));
        $this->assertAuthenticatedAs($client, 'web');
    }

    public function test_a_code_is_not_checked_while_another_code_for_the_account_is_being_checked(): void
    {
        Sleep::fake(syncWithCarbon: true);
        $secret = Totp::generateSecret();
        $client = $this->clientWithTwoFactor(['two_factor_method' => 'totp', 'two_factor_secret' => $secret]);
        $this->post(route('client.login'), ['email' => $client->email, 'password' => 'right-password-1'])->assertRedirect(route('client.two-factor.challenge'));

        // Another request (from any IP address) is checking a code for this account. Codes sent at the
        // same moment wait their turn, so each one is counted before the next is checked.
        $busy = Cache::lock('client-2fa:'.$client->id.':check', 10);
        $this->assertTrue($busy->get());

        $this->post(route('client.two-factor.challenge'), ['code' => Totp::codeAt($secret, intdiv(time(), 30))])->assertStatus(429);
        $this->assertGuest('web');

        $busy->release();
        $this->post(route('client.two-factor.challenge'), ['code' => Totp::codeAt($secret, intdiv(time(), 30))])->assertRedirect(route('client.dashboard'));
        $this->assertAuthenticatedAs($client, 'web');
    }

    public function test_a_password_is_not_checked_while_another_password_for_the_email_is_being_checked(): void
    {
        Sleep::fake(syncWithCarbon: true);
        $client = Client::factory()->create(['email' => 'raz@example.test', 'password' => 'right-password-1']);

        $busy = Cache::lock('client-login:'.sha1('raz@example.test').':check', 10);
        $this->assertTrue($busy->get());

        $this->post(route('client.login'), ['email' => 'Raz@example.test', 'password' => 'right-password-1'])->assertStatus(429);
        $this->assertGuest('web');

        $busy->release();
        $this->post(route('client.login'), ['email' => 'raz@example.test', 'password' => 'right-password-1'])->assertRedirect(route('client.dashboard'));
        $this->assertAuthenticatedAs($client, 'web');
    }

    public function test_passwords_are_limited_per_ip_address_and_per_account_whichever_ip_they_come_from(): void
    {
        Sleep::fake();
        $client = Client::factory()->create(['email' => 'raz@example.test', 'password' => 'right-password-1']);

        // Ten wrong passwords from one address stop that address, even with the right password.
        foreach (range(1, 10) as $try) {
            $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.1'])
                ->post(route('client.login'), ['email' => 'raz@example.test', 'password' => 'wrong-password-'.$try])
                ->assertSessionHasErrors('email');
        }

        $this->travel(61)->seconds(); // past the route's own limit per minute
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.1'])
            ->post(route('client.login'), ['email' => 'RAZ@example.test', 'password' => 'right-password-1'])
            ->assertSessionHasErrors(['email' => 'Too many tries. Wait 15 minutes, then try again.']);
        $this->assertGuest('web');

        // The owner at another address still gets in: one guesser cannot keep them out.
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.2'])
            ->post(route('client.login'), ['email' => 'raz@example.test', 'password' => 'right-password-1'])
            ->assertRedirect(route('client.dashboard'));
        $this->assertAuthenticatedAs($client, 'web');
        $this->post(route('client.logout'));

        // Guesses spread over many addresses stop the email address everywhere.
        $this->guessFromManyAddresses('raz@example.test', '203.0.113.');

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.99'])
            ->post(route('client.login'), ['email' => 'RAZ@example.test', 'password' => 'right-password-1'])
            ->assertSessionHasErrors(['email' => 'Too many wrong passwords for this email. Reset your password to sign in now, or wait an hour.']);
        $this->assertGuest('web');
    }

    public function test_a_password_reset_lets_the_owner_in_while_others_keep_guessing(): void
    {
        Sleep::fake();
        $client = Client::factory()->create(['email' => 'raz@example.test', 'password' => 'old-password-1']);

        $this->guessFromManyAddresses('raz@example.test', '203.0.113.');
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
            ->post(route('client.login'), ['email' => 'raz@example.test', 'password' => 'old-password-1'])
            ->assertSessionHasErrors('email');
        $this->assertGuest('web');

        // The owner resets the password with the emailed link.
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])->post(route('client.password.update'), [
            'token' => Password::broker('clients')->createToken($client),
            'email' => 'Raz@Example.test',
            'password' => 'new-password-1',
            'password_confirmation' => 'new-password-1',
        ])->assertRedirect(route('client.login'));
        $ownersBrowser = session()->all();

        // Someone else keeps guessing in another browser, which stays stopped.
        session()->flush();
        $this->guessFromManyAddresses('raz@example.test', '192.0.2.');
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.8'])
            ->post(route('client.login'), ['email' => 'raz@example.test', 'password' => 'new-password-1'])
            ->assertSessionHasErrors(['email' => 'Too many wrong passwords for this email. Reset your password to sign in now, or wait an hour.']);
        $this->assertGuest('web');

        // The browser that reset the password still gets in.
        session()->flush();
        session()->put($ownersBrowser);
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
            ->post(route('client.login'), ['email' => 'raz@example.test', 'password' => 'new-password-1'])
            ->assertRedirect(route('client.dashboard'));
        $this->assertAuthenticatedAs($client, 'web');
        $this->assertNull(session('client.reset_unlock'));
    }

    public function test_a_used_app_code_is_refused_even_with_spaces_inside(): void
    {
        $secret = Totp::generateSecret();
        $client = $this->clientWithTwoFactor(['two_factor_method' => 'totp', 'two_factor_secret' => $secret]);
        $code = Totp::codeAt($secret, intdiv(time(), 30));

        $this->post(route('client.login'), ['email' => $client->email, 'password' => 'right-password-1']);
        $this->post(route('client.two-factor.challenge'), ['code' => $code])->assertRedirect(route('client.dashboard'));
        $this->post(route('client.logout'));

        // Someone who saw the code types it again with a space or a tab inside.
        foreach ([substr($code, 0, 3).' '.substr($code, 3), substr($code, 0, 2)."\t".substr($code, 2)] as $try => $spaced) {
            $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.'.$try])->post(route('client.login'), ['email' => $client->email, 'password' => 'right-password-1']);
            $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.'.$try])->post(route('client.two-factor.challenge'), ['code' => $spaced])->assertSessionHasErrors('code');
            $this->assertGuest('web');
        }
    }

    public function test_clients_who_turned_two_factor_on_are_still_asked_when_staff_set_it_to_off(): void
    {
        $client = $this->clientWithTwoFactor(['two_factor_method' => 'email']);
        app(Settings::class)->set('security.client_two_factor', 'off');

        // Off only stops new set-ups: the account page truthfully says it is on.
        $this->actingAs($client, 'web')->get(route('client.account.edit'))
            ->assertOk()
            ->assertSee('When you sign in, we email you a code to enter after your password.');

        auth('web')->logout();
        $this->post(route('client.login'), ['email' => $client->email, 'password' => 'right-password-1'])
            ->assertRedirect(route('client.two-factor.challenge'));
        $this->assertGuest('web');
    }

    public function test_clients_without_a_password_turn_off_two_factor_with_a_code_from_their_email(): void
    {
        Mail::fake();
        $client = $this->clientWithTwoFactor(['two_factor_method' => 'totp', 'two_factor_secret' => Totp::generateSecret()]);
        $client->forceFill(['has_password' => false])->save();
        $this->actingAs($client, 'web');

        $this->delete(route('client.account.two-factor.destroy'))->assertSessionHasErrors('two_factor_email_code');
        $this->assertTrue($client->fresh()->hasTwoFactorEnabled());

        $this->post(route('client.account.email-code'))->assertSessionHas('status');
        preg_match('/\b(\d{6})\b/', Mail::sent(TemplatedMessage::class)->last()->bodyHtml, $match);

        $this->delete(route('client.account.two-factor.destroy'), ['two_factor_email_code' => $match[1]])->assertSessionHas('status');
        $this->assertFalse($client->fresh()->hasTwoFactorEnabled());
    }

    public function test_clients_turn_on_an_authenticator_app_from_their_account_with_their_password(): void
    {
        $client = Client::factory()->create(['password' => 'right-password-1']);
        $this->actingAs($client, 'web');

        $this->get(route('client.account.edit'))->assertSee('id="two-factor-on-password"', false);

        // Being signed in is not enough: without the password no key is made.
        $this->post(route('client.account.two-factor.app'), ['two_factor_current_password' => ''])->assertSessionHasErrors('two_factor_current_password');
        $this->post(route('client.account.two-factor.app'), ['two_factor_current_password' => 'wrong-password'])->assertSessionHasErrors('two_factor_current_password');
        $this->assertNull(session()->getOldInput('two_factor_current_password'));
        $this->assertNull($client->fresh()->two_factor_secret);

        $this->post(route('client.account.two-factor.app'), ['two_factor_current_password' => 'right-password-1'])->assertSessionHasNoErrors();
        $client->refresh();
        $this->get(route('client.account.edit'))->assertSee('Scan this QR code')->assertSee($client->two_factor_secret);

        $this->post(route('client.account.two-factor.app.confirm'), ['code' => Totp::codeAt($client->two_factor_secret, intdiv(time(), 30))])
            ->assertSessionHas('recovery_codes');

        $this->assertTrue($client->fresh()->hasTwoFactorEnabled());
    }

    public function test_an_app_setup_this_browser_did_not_start_is_not_shown_and_cannot_be_finished(): void
    {
        // Left unfinished in another browser, or by a version that did not ask for the password.
        $secret = Totp::generateSecret();
        $client = Client::factory()->create(['password' => 'right-password-1']);
        $client->forceFill(['two_factor_method' => 'totp', 'two_factor_secret' => $secret])->save();
        $this->actingAs($client, 'web');

        $this->get(route('client.account.edit'))->assertOk()->assertDontSee($secret)->assertDontSee('Scan this QR code')->assertSee('Use an authenticator app');
        $this->post(route('client.account.two-factor.app.confirm'), ['code' => Totp::codeAt($secret, intdiv(time(), 30))])
            ->assertSessionHas('error', 'Setting up two-factor sign-in took too long. Please start again.')
            ->assertSessionMissing('recovery_codes');
        $this->assertFalse($client->fresh()->hasTwoFactorEnabled());

        // Starting again with the password makes a new key. That setup can be finished for 15 minutes.
        $this->post(route('client.account.two-factor.app'), ['two_factor_current_password' => 'right-password-1'])->assertSessionHasNoErrors();
        $new = $client->fresh()->two_factor_secret;
        $this->assertNotSame($secret, $new);
        $this->get(route('client.account.edit'))->assertSee($new);

        $this->travel(16)->minutes();
        $this->get(route('client.account.edit'))->assertDontSee($new);
        $this->post(route('client.account.two-factor.app.confirm'), ['code' => Totp::codeAt($new, intdiv(time(), 30))])->assertSessionHas('error');
        $this->assertFalse($client->fresh()->hasTwoFactorEnabled());
    }

    public function test_clients_without_a_password_turn_on_an_authenticator_app_with_a_code_from_their_email(): void
    {
        Mail::fake();
        $client = Client::factory()->create();
        $client->forceFill(['has_password' => false])->save();
        $this->actingAs($client, 'web');

        $this->get(route('client.account.edit'))->assertSee('id="two-factor-on-code"', false)->assertDontSee('id="two-factor-on-password"', false);
        $this->post(route('client.account.two-factor.app'), ['two_factor_email_code' => ''])->assertSessionHasErrors('two_factor_email_code');
        $this->post(route('client.account.two-factor.app'), ['two_factor_email_code' => '000000'])->assertSessionHasErrors('two_factor_email_code');
        $this->assertNull($client->fresh()->two_factor_secret);

        $this->post(route('client.account.email-code'))->assertSessionHas('status');
        preg_match('/\b(\d{6})\b/', Mail::sent(TemplatedMessage::class)->last()->bodyHtml, $match);

        $this->post(route('client.account.two-factor.app'), ['two_factor_email_code' => $match[1]])->assertSessionHasNoErrors();
        $secret = $client->fresh()->two_factor_secret;
        $this->assertNotNull($secret);

        $this->post(route('client.account.two-factor.app.confirm'), ['code' => Totp::codeAt($secret, intdiv(time(), 30))])->assertSessionHas('recovery_codes');
        $this->assertTrue($client->fresh()->hasTwoFactorEnabled());
    }

    public function test_codes_by_email_are_turned_on_with_the_password_and_the_emailed_code(): void
    {
        Mail::fake();
        $client = Client::factory()->create(['password' => 'right-password-1']);
        $this->actingAs($client, 'web');

        $this->post(route('client.account.two-factor.email'), ['two_factor_current_password' => ''])->assertSessionHasErrors('two_factor_current_password');
        $this->post(route('client.account.two-factor.email'), ['two_factor_current_password' => 'wrong-password'])->assertSessionHasErrors('two_factor_current_password');
        Mail::assertNothingSent();

        $this->post(route('client.account.two-factor.email'), ['two_factor_current_password' => 'right-password-1'])->assertSessionHas('status');
        $this->assertCount(1, Mail::sent(TemplatedMessage::class));
        $this->get(route('client.account.edit'))->assertSee('Send a new code');

        // "Send a new code" in the setup this browser just started needs no password again.
        $this->travel(61)->seconds();
        $this->post(route('client.account.two-factor.email'))->assertSessionHasNoErrors()->assertSessionHas('status');
        $this->assertCount(2, Mail::sent(TemplatedMessage::class));
        preg_match('/\b(\d{6})\b/', Mail::sent(TemplatedMessage::class)->last()->bodyHtml, $match);

        $this->post(route('client.account.two-factor.email.confirm'), ['code' => $match[1]])->assertSessionHas('status');
        $this->assertTrue($client->fresh()->hasTwoFactorEnabled());
        $this->assertSame('email', $client->fresh()->two_factor_method);
    }

    public function test_a_setup_by_email_that_took_too_long_asks_for_the_password_again(): void
    {
        Mail::fake();
        $client = Client::factory()->create(['password' => 'right-password-1']);
        $this->actingAs($client, 'web');
        $this->post(route('client.account.two-factor.email'), ['two_factor_current_password' => 'right-password-1'])->assertSessionHas('status');

        $this->travel(9)->minutes();
        $this->post(route('client.account.two-factor.email'))->assertSessionHas('status');
        $this->travel(7)->minutes();

        // No new code: the password is asked for first, and the Account page is back at the choice.
        $this->post(route('client.account.two-factor.email'))->assertRedirect(route('client.account.two-factor.proof', 'email'));
        $this->assertCount(2, Mail::sent(TemplatedMessage::class));
        $this->get(route('client.account.edit'))->assertSee('id="two-factor-on-password"', false)->assertDontSee('Send a new code');
    }

    public function test_a_theme_whose_forms_do_not_ask_for_the_password_sends_clients_to_a_page_that_does(): void
    {
        Mail::fake();
        $client = Client::factory()->create(['password' => 'right-password-1']);
        $this->actingAs($client, 'web');

        // The form of an older theme: only the button. Nothing is turned on, and the client is asked on a page of its own.
        $this->post(route('client.account.two-factor.app'))->assertRedirect(route('client.account.two-factor.proof', 'app'));
        $this->post(route('client.account.two-factor.email'))->assertRedirect(route('client.account.two-factor.proof', 'email'));
        $this->assertNull($client->fresh()->two_factor_secret);
        Mail::assertNothingSent();

        $this->get(route('client.account.two-factor.proof', 'app'))->assertOk()
            ->assertSee('id="two-factor-start-password"', false)
            ->assertSee('action="'.route('client.account.two-factor.app').'"', false);
        $this->get(route('client.account.two-factor.proof', 'email'))->assertOk()->assertSee('action="'.route('client.account.two-factor.email').'"', false);
        $this->get(route('client.account.two-factor.proof', 'other'))->assertNotFound();

        $this->from(route('client.account.two-factor.proof', 'app'))
            ->post(route('client.account.two-factor.app'), ['two_factor_current_password' => 'wrong-password'])
            ->assertRedirect(route('client.account.two-factor.proof', 'app'))
            ->assertSessionHasErrors('two_factor_current_password');
        $this->from(route('client.account.two-factor.proof', 'app'))
            ->post(route('client.account.two-factor.app'), ['two_factor_current_password' => 'right-password-1'])
            ->assertRedirect(route('client.account.edit').'#two-factor');
        $this->get(route('client.account.edit'))->assertSee('Scan this QR code');

        // Clients without a password get the field for the emailed code there.
        $client->forceFill(['has_password' => false, 'two_factor_method' => null, 'two_factor_secret' => null])->save();
        $this->post(route('client.account.two-factor.app'))->assertRedirect(route('client.account.two-factor.proof', 'app'));
        $this->get(route('client.account.two-factor.proof', 'app'))->assertOk()->assertSee('id="two-factor-start-code"', false)->assertSee('Email me a code');

        // With two-factor sign-in on, or the method switched off by staff, there is nothing to ask.
        app(Settings::class)->set('security.client_two_factor_methods', ['email']);
        $this->get(route('client.account.two-factor.proof', 'app'))->assertRedirect(route('client.account.edit').'#two-factor');
    }

    public function test_clients_without_a_password_turn_on_codes_by_email_with_the_emailed_code_alone(): void
    {
        Mail::fake();
        $client = Client::factory()->create();
        $client->forceFill(['has_password' => false])->save();
        $this->actingAs($client, 'web');

        // The setup code goes to the account's own inbox, so it is the proof.
        $this->post(route('client.account.two-factor.email'))->assertSessionHasNoErrors()->assertSessionHas('status');
        preg_match('/\b(\d{6})\b/', Mail::sent(TemplatedMessage::class)->last()->bodyHtml, $match);

        $this->post(route('client.account.two-factor.email.confirm'), ['code' => $match[1] === '111111' ? '222222' : '111111'])->assertSessionHasErrors('code');
        $this->assertFalse($client->fresh()->hasTwoFactorEnabled());

        $this->post(route('client.account.two-factor.email.confirm'), ['code' => $match[1]])->assertSessionHas('status');
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
            ->delete(route('client.account.two-factor.destroy'), ['two_factor_current_password' => 'right-password-1'])
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
     * A six-digit code that is not valid around now (the app code of this, the last or the next 30 seconds).
     */
    private function wrongCode(string $secret): string
    {
        $step = intdiv(time(), 30);
        $valid = [Totp::codeAt($secret, $step - 1), Totp::codeAt($secret, $step), Totp::codeAt($secret, $step + 1)];

        foreach (['111111', '222222', '333333', '444444'] as $code) {
            if (! in_array($code, $valid, true)) {
                return $code;
            }
        }

        return '555555';
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

    /**
     * Wrong passwords for $email, each from another address, until the count for the email is full.
     */
    private function guessFromManyAddresses(string $email, string $prefix): void
    {
        foreach (range(1, SignInLimiter::ACCOUNT_PASSWORDS) as $try) {
            $this->withServerVariables(['REMOTE_ADDR' => $prefix.$try])
                ->post(route('client.login'), ['email' => $email, 'password' => 'wrong-password-'.$try])
                ->assertSessionHasErrors('email');
        }
    }
}
