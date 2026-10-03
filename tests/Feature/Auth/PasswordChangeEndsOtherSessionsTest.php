<?php

namespace Tests\Feature\Auth;

use App\Models\Client;
use App\Security\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/**
 * A client who changes or resets the password after something looked wrong must be sure every
 * other signed-in browser (for example a stolen session) is signed out.
 */
class PasswordChangeEndsOtherSessionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_changing_the_password_signs_out_other_sessions_but_not_this_one(): void
    {
        $client = Client::factory()->create(['email' => 'raz@example.test', 'password' => 'old-password-1']);
        $other = $this->signedInSession();

        // The client changes the password on their own device, which stays signed in.
        $this->actingAs($client, 'web')
            ->put(route('client.account.password'), ['current_password' => 'old-password-1', 'password' => 'new-password-1', 'password_confirmation' => 'new-password-1'])
            ->assertSessionHas('status');
        $this->get(route('client.dashboard'))->assertOk();

        // The other browser is signed out on its next request.
        $this->switchTo($other)->get(route('client.dashboard'))->assertRedirect(route('client.login'));
        $this->assertGuest('web');
    }

    public function test_resetting_the_password_signs_out_existing_sessions(): void
    {
        $client = Client::factory()->create(['email' => 'raz@example.test', 'password' => 'old-password-1']);
        $other = $this->signedInSession();

        $this->switchTo([]);
        $token = Password::broker('clients')->createToken($client);
        $this->post(route('client.password.update'), ['token' => $token, 'email' => 'raz@example.test', 'password' => 'new-password-1', 'password_confirmation' => 'new-password-1'])
            ->assertRedirect(route('client.login'));

        $this->switchTo($other)->get(route('client.invoices.index'))->assertRedirect(route('client.login'));
        $this->assertGuest('web');
    }

    public function test_password_checks_on_the_account_page_are_rate_limited(): void
    {
        $client = Client::factory()->create(['password' => 'old-password-1']);
        $this->actingAs($client, 'web');

        foreach (range(1, 6) as $try) {
            $this->put(route('client.account.password'), ['current_password' => 'wrong-password-'.$try, 'password' => 'new-password-1', 'password_confirmation' => 'new-password-1'])
                ->assertSessionHasErrors('current_password');
        }

        $this->put(route('client.account.password'), ['current_password' => 'old-password-1', 'password' => 'new-password-1', 'password_confirmation' => 'new-password-1'])
            ->assertStatus(429);
        $this->assertTrue(Hash::check('old-password-1', $client->fresh()->password));
    }

    public function test_turning_off_two_factor_is_rate_limited(): void
    {
        $client = Client::factory()->create(['password' => 'old-password-1']);
        $client->forceFill(['two_factor_method' => 'totp', 'two_factor_secret' => Totp::generateSecret(), 'two_factor_confirmed_at' => now()])->save();
        $this->actingAs($client, 'web');

        foreach (range(1, 6) as $try) {
            $this->delete(route('client.account.two-factor.destroy'), ['current_password' => 'wrong-password-'.$try])->assertSessionHasErrors('current_password');
        }

        $this->delete(route('client.account.two-factor.destroy'), ['current_password' => 'old-password-1'])->assertStatus(429);
        $this->assertTrue($client->fresh()->hasTwoFactorEnabled());
    }

    /**
     * Sign in with the password as another browser would, and keep that browser's session.
     *
     * @return array<string, mixed>
     */
    private function signedInSession(): array
    {
        $this->post(route('client.login'), ['email' => 'raz@example.test', 'password' => 'old-password-1'])->assertRedirect(route('client.dashboard'));
        $this->get(route('client.dashboard'))->assertOk();
        $session = session()->all();

        $this->switchTo([]);

        return $session;
    }

    /**
     * Continue as another browser: only its session, nobody signed in until the session says so.
     *
     * @param  array<string, mixed>  $session
     */
    private function switchTo(array $session): static
    {
        $this->flushSession();
        Auth::forgetGuards();

        return $this->withSession($session);
    }
}
