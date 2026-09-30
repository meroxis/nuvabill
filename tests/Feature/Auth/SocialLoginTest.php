<?php

namespace Tests\Feature\Auth;

use App\Models\Client;
use App\Models\SocialAccount;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class SocialLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_buttons_only_show_for_providers_that_are_switched_on(): void
    {
        $this->get(route('client.login'))->assertOk()->assertDontSee('Continue with Google');

        $this->enable('google');

        $this->get(route('client.login'))->assertSee('Continue with Google')->assertDontSee('Continue with GitHub');
        $this->get(route('client.register'))->assertSee('Continue with Google');
        $this->get(route('client.social.redirect', 'github'))->assertNotFound();
    }

    public function test_a_new_client_signs_up_with_google(): void
    {
        $this->enable('google');
        $this->fakeGoogle(['sub' => 'g-123', 'email' => 'Raz@Example.com', 'email_verified' => true, 'given_name' => 'Raz', 'family_name' => 'Las']);

        $this->completeSignIn('google')->assertRedirect(route('client.account.edit'));

        $client = Client::query()->where('email', 'raz@example.com')->firstOrFail();
        $this->assertAuthenticatedAs($client, 'web');
        $this->assertSame('Raz', $client->first_name);
        $this->assertFalse($client->has_password);
        $this->assertNotNull($client->email_verified_at);
        $this->assertTrue($client->socialAccounts()->where('provider', 'google')->where('provider_user_id', 'g-123')->exists());
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://oauth2.googleapis.com/token' && $request['code'] === 'auth-code' && filled($request['code_verifier']));

        // Next time the same Google account signs straight in.
        auth('web')->logout();
        $this->completeSignIn('google')->assertRedirect(route('client.dashboard'));
        $this->assertSame(1, Client::query()->count());
    }

    public function test_signing_in_from_an_order_form_comes_back_to_the_same_order(): void
    {
        $client = Client::factory()->create(['email' => 'raz@example.com']);
        $this->enable('google');
        $this->fakeGoogle(['sub' => 'g-9', 'email' => 'raz@example.com', 'email_verified' => true]);

        $this->completeSignIn('google', ['return' => '/store/web-hosting/starter'])->assertRedirect(url('/store/web-hosting/starter'));
        $this->assertAuthenticatedAs($client, 'web');

        // The sign-in page keeps a path on this site too, and nothing else.
        auth('web')->logout();
        $this->get(route('client.login', ['return' => '/store/web-hosting']))->assertOk()->assertSessionHas('url.intended', url('/store/web-hosting'));

        foreach (['//elsewhere.example/path', 'https://elsewhere.example/', '/\\elsewhere.example', 'store/web-hosting', "/store\n/x"] as $return) {
            $this->withSession([])->flushSession();
            $this->get(route('client.login', ['return' => $return]))->assertOk()->assertSessionMissing('url.intended');
        }
    }

    public function test_a_verified_email_signs_in_to_the_existing_account(): void
    {
        $client = Client::factory()->create(['email' => 'raz@example.com']);
        $this->enable('google');
        $this->fakeGoogle(['sub' => 'g-9', 'email' => 'raz@example.com', 'email_verified' => true]);

        $this->completeSignIn('google')->assertRedirect(route('client.dashboard'));

        $this->assertAuthenticatedAs($client, 'web');
        $this->assertSame(1, Client::query()->count());
    }

    public function test_facebook_never_takes_over_an_existing_account_by_email(): void
    {
        Client::factory()->create(['email' => 'raz@example.com']);
        $this->enable('facebook');
        Http::fake([
            'graph.facebook.com/*/oauth/access_token*' => Http::response(['access_token' => 'fb-token']),
            'graph.facebook.com/*/me*' => Http::response(['id' => 'fb-1', 'email' => 'raz@example.com', 'first_name' => 'Raz']),
        ]);

        $this->completeSignIn('facebook')
            ->assertRedirect(route('client.login'))
            ->assertSessionHas('error', 'An account with this email already exists. Sign in with your password, then connect Facebook on your Account page.');

        $this->assertGuest('web');
        $this->assertSame(0, SocialAccount::query()->count());
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/me') && $request['appsecret_proof'] === hash_hmac('sha256', 'fb-token', 'secret-facebook'));
    }

    public function test_a_wrong_state_is_refused(): void
    {
        $this->enable('google');
        Http::fake();

        $this->get(route('client.social.redirect', 'google'));

        $this->get(route('client.social.callback', ['provider' => 'google', 'state' => 'forged', 'code' => 'x']))
            ->assertRedirect(route('client.login'))
            ->assertSessionHas('error', 'The sign-in link expired. Please try again.');

        $this->assertGuest('web');
        Http::assertNothingSent();
    }

    public function test_signed_in_clients_connect_and_disconnect_github(): void
    {
        $client = Client::factory()->create();
        $other = Client::factory()->create();
        $this->enable('github');
        $this->fakeGitHub('gh-1');

        $this->actingAs($client, 'web');
        $this->get(route('client.account.edit'))->assertSee('Connected accounts')->assertSee('Connect');

        $this->completeSignIn('github')->assertRedirect(route('client.account.edit'))->assertSessionHas('status');
        $this->assertTrue($client->socialAccounts()->where('provider', 'github')->where('email', 'dev@example.com')->exists());

        $this->actingAs($other, 'web');
        $this->completeSignIn('github')->assertSessionHas('error', 'This GitHub account is already connected to another client account.');

        $this->actingAs($client, 'web');
        $this->delete(route('client.account.social.destroy', 'github'))->assertSessionHas('status');
        $this->assertSame(0, $client->socialAccounts()->count());
    }

    public function test_social_sign_ups_set_a_password_without_an_old_one(): void
    {
        $client = Client::factory()->create();
        $client->forceFill(['has_password' => false])->save();
        $client->socialAccounts()->create(['provider' => 'google', 'provider_user_id' => 'g-1']);
        $this->actingAs($client, 'web');

        $this->get(route('client.account.edit'))->assertSee('Set a password');
        $this->delete(route('client.account.social.destroy', 'google'))->assertSessionHas('error', 'Set a password first, so you can still sign in.');

        $this->put(route('client.account.password'), ['password' => 'new-password-1', 'password_confirmation' => 'new-password-1'])->assertSessionHas('status');

        $client->refresh();
        $this->assertTrue($client->has_password);
        $this->assertTrue(Hash::check('new-password-1', $client->password));
        $this->put(route('client.account.password'), ['password' => 'other-password-2', 'password_confirmation' => 'other-password-2'])->assertSessionHasErrors('current_password');
    }

    public function test_staff_save_provider_keys_encrypted(): void
    {
        $this->signInAdmin();

        $this->get(route('admin.settings.social.edit'))->assertOk()->assertSee(route('client.social.callback', 'google'));

        $this->put(route('admin.settings.social.update'), [
            'google' => ['enabled' => '1', 'client_id' => 'id-1.apps.googleusercontent.com', 'client_secret' => 'google-secret'],
            'github' => ['enabled' => '0', 'client_id' => '', 'client_secret' => ''],
            'facebook' => ['enabled' => '1', 'client_id' => '123', 'client_secret' => ''],
        ])->assertSessionHasErrors('facebook.client_secret');

        $this->put(route('admin.settings.social.update'), [
            'google' => ['enabled' => '1', 'client_id' => 'id-1.apps.googleusercontent.com', 'client_secret' => 'google-secret'],
            'github' => ['enabled' => '0'],
            'facebook' => ['enabled' => '0'],
        ])->assertSessionHas('status');

        // An empty secret keeps the saved one.
        $this->put(route('admin.settings.social.update'), ['google' => ['enabled' => '1', 'client_id' => 'id-2', 'client_secret' => '']])->assertSessionHas('status');

        $this->assertSame(['enabled' => true, 'client_id' => 'id-2', 'client_secret' => 'google-secret'], setting('social.google'));
        $this->assertStringNotContainsString('google-secret', (string) DB::table('settings')->where('key', 'social.google')->value('value'));
        $this->get(route('admin.settings.social.edit'))->assertDontSee('google-secret');
    }

    private function enable(string $provider): void
    {
        app(Settings::class)->set('social.'.$provider, ['enabled' => true, 'client_id' => 'id-'.$provider, 'client_secret' => 'secret-'.$provider]);
    }

    /**
     * Start the sign-in, then come back from the provider with the state it was given.
     */
    /**
     * @param  array<string, string>  $query
     */
    private function completeSignIn(string $provider, array $query = []): TestResponse
    {
        $location = $this->get(route('client.social.redirect', ['provider' => $provider] + $query))->assertRedirect()->headers->get('Location');
        parse_str((string) parse_url((string) $location, PHP_URL_QUERY), $query);

        return $this->get(route('client.social.callback', ['provider' => $provider, 'state' => $query['state'], 'code' => 'auth-code']));
    }

    /**
     * @param  array<string, mixed>  $profile
     */
    private function fakeGoogle(array $profile): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'g-token']),
            'openidconnect.googleapis.com/v1/userinfo' => Http::response($profile),
        ]);
    }

    private function fakeGitHub(string $id): void
    {
        Http::fake([
            'github.com/login/oauth/access_token' => Http::response(['access_token' => 'gh-token']),
            'api.github.com/user' => Http::response(['id' => $id, 'login' => 'devname', 'name' => 'Dev Person']),
            'api.github.com/user/emails' => Http::response([
                ['email' => 'unverified@example.com', 'primary' => false, 'verified' => false],
                ['email' => 'dev@example.com', 'primary' => true, 'verified' => true],
            ]),
        ]);
    }
}
