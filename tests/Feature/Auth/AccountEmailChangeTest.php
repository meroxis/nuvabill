<?php

namespace Tests\Feature\Auth;

use App\Mail\TemplatedMessage;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The sign-in email decides where password resets and sign-in codes go, so a stolen session alone
 * must not be able to change it, and the old address is told when it changes.
 */
class AccountEmailChangeTest extends TestCase
{
    use RefreshDatabase;

    public function test_changing_the_email_needs_the_current_password(): void
    {
        $client = Client::factory()->create(['email' => 'raz@example.test', 'password' => 'old-password-1']);

        $this->actingAs($client, 'web')
            ->put(route('client.account.update'), $this->details($client, ['email' => 'mer.las@example.test']))
            ->assertSessionHasErrors('current_password');

        $this->assertSame('raz@example.test', $client->fresh()->email);
    }

    public function test_a_wrong_password_does_not_change_the_email(): void
    {
        $client = Client::factory()->create(['email' => 'raz@example.test', 'password' => 'old-password-1']);

        $this->actingAs($client, 'web')
            ->put(route('client.account.update'), $this->details($client, ['email' => 'mer.las@example.test', 'current_password' => 'wrong-password']))
            ->assertSessionHasErrors('current_password');

        $this->assertSame('raz@example.test', $client->fresh()->email);
    }

    public function test_the_email_changes_with_the_right_password_and_the_old_address_is_told(): void
    {
        Mail::fake();
        $client = Client::factory()->create(['email' => 'raz@example.test', 'password' => 'old-password-1', 'email_verified_at' => now()]);

        $this->actingAs($client, 'web')
            ->put(route('client.account.update'), $this->details($client, ['email' => 'Mer.Las@Example.test', 'current_password' => 'old-password-1']))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status');

        $client->refresh();
        $this->assertSame('mer.las@example.test', $client->email);
        $this->assertNull($client->email_verified_at);
        Mail::assertSent(TemplatedMessage::class, fn (TemplatedMessage $message): bool => $message->hasTo('raz@example.test')
            && $message->subjectLine === 'Your email address was changed'
            && str_contains($message->bodyHtml, 'mer.las@example.test'));
        $this->assertTrue(ActivityLog::query()->where('action', 'client.email_changed')->where('client_id', $client->id)->exists());
    }

    public function test_changing_only_the_name_does_not_need_the_password(): void
    {
        Mail::fake();
        $client = Client::factory()->create(['email' => 'raz@example.test']);

        // The same address in other letters is not a change either.
        $this->actingAs($client, 'web')
            ->put(route('client.account.update'), $this->details($client, ['first_name' => 'Raz', 'email' => 'Raz@Example.test']))
            ->assertSessionHasNoErrors();

        $this->assertSame('Raz', $client->fresh()->first_name);
        $this->assertSame('raz@example.test', $client->fresh()->email);
        Mail::assertNothingSent();
    }

    public function test_clients_without_a_password_confirm_a_new_email_with_a_code_sent_to_the_old_one(): void
    {
        Mail::fake();
        $client = Client::factory()->create(['email' => 'raz@example.test']);
        $client->forceFill(['has_password' => false])->save();
        $this->actingAs($client, 'web');

        $this->get(route('client.account.edit'))->assertOk()->assertSee('Email me a code')->assertDontSee('details-password');

        $this->put(route('client.account.update'), $this->details($client, ['email' => 'mer.las@example.test']))->assertSessionHasErrors('email_code');
        $this->put(route('client.account.update'), $this->details($client, ['email' => 'mer.las@example.test', 'email_code' => '000000']))->assertSessionHasErrors('email_code');
        $this->assertSame('raz@example.test', $client->fresh()->email);

        $this->post(route('client.account.email-code'))->assertSessionHas('status');
        $sent = Mail::sent(TemplatedMessage::class)->last();
        $this->assertTrue($sent->hasTo('raz@example.test'));
        preg_match('/\b(\d{6})\b/', $sent->bodyHtml, $match);

        $this->put(route('client.account.update'), $this->details($client, ['email' => 'mer.las@example.test', 'email_code' => $match[1]]))->assertSessionHasNoErrors();
        $this->assertSame('mer.las@example.test', $client->fresh()->email);
    }

    public function test_a_changed_email_is_no_longer_confirmed_so_google_cannot_take_over_the_account(): void
    {
        Mail::fake();
        $client = Client::factory()->create(['email' => 'raz@example.test', 'password' => 'old-password-1', 'email_verified_at' => now()]);

        $this->actingAs($client, 'web')
            ->put(route('client.account.update'), $this->details($client, ['email' => 'mer.las@example.test', 'current_password' => 'old-password-1']))
            ->assertSessionHasNoErrors();
        auth('web')->logout();

        app(Settings::class)->set('social.google', ['enabled' => true, 'client_id' => 'id-google', 'client_secret' => 'secret-google']);
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'g-token']),
            'openidconnect.googleapis.com/v1/userinfo' => Http::response(['sub' => 'g-9', 'email' => 'mer.las@example.test', 'email_verified' => true]),
        ]);
        $location = $this->get(route('client.social.redirect', 'google'))->headers->get('Location');
        parse_str((string) parse_url((string) $location, PHP_URL_QUERY), $query);

        $this->get(route('client.social.callback', ['provider' => 'google', 'state' => $query['state'], 'code' => 'auth-code']))
            ->assertRedirect(route('client.login'))
            ->assertSessionHas('error');
        $this->assertGuest('web');
    }

    public function test_the_email_check_is_rate_limited_for_each_client(): void
    {
        $client = Client::factory()->create(['email' => 'raz@example.test', 'password' => 'old-password-1']);
        $this->actingAs($client, 'web');

        foreach (range(1, 6) as $try) {
            $this->put(route('client.account.update'), $this->details($client, ['email' => 'mer.las@example.test', 'current_password' => 'wrong-password-'.$try]))
                ->assertSessionHasErrors('current_password');
        }

        $this->put(route('client.account.update'), $this->details($client, ['email' => 'mer.las@example.test', 'current_password' => 'old-password-1']))
            ->assertStatus(429);
        $this->assertSame('raz@example.test', $client->fresh()->email);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function details(Client $client, array $overrides = []): array
    {
        return $overrides + [
            'first_name' => $client->first_name,
            'last_name' => $client->last_name,
            'email' => $client->email,
            'country' => $client->country,
        ];
    }
}
