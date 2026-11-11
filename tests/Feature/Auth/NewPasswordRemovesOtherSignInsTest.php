<?php

namespace Tests\Feature\Auth;

use App\Chat\ChatInbox;
use App\Mail\TemplatedMessage;
use App\Models\ActivityLog;
use App\Models\ChatLink;
use App\Models\Client;
use App\Models\Passkey;
use App\Models\SocialAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Passkeys, connected Google, GitHub or Facebook sign-ins and linked chat apps do not end with the
 * sessions. A new password removes them: always after a reset or when staff set it, and by default
 * when the client changes it.
 */
class NewPasswordRemovesOtherSignInsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_password_reset_removes_passkeys_connected_accounts_and_chat_apps(): void
    {
        $client = Client::factory()->create(['first_name' => 'Raz', 'last_name' => '', 'email' => 'raz@example.test']);
        $this->giveOtherSignIns($client, 'raz');
        $other = Client::factory()->create(['first_name' => 'Las']);
        $this->giveOtherSignIns($other, 'las');

        $this->reset($client)
            ->assertRedirect(route('client.login'))
            ->assertSessionHas('status', 'Your password was changed. Passkeys, connected accounts and chat apps were removed from your account. Sign in with the new password.');

        $this->assertSame(0, $client->passkeys()->count());
        $this->assertSame(0, $client->socialAccounts()->count());
        $this->assertSame(0, $client->chatLinks()->count());
        $this->assertSame([1, 1, 1], [$other->passkeys()->count(), $other->socialAccounts()->count(), $other->chatLinks()->count()]);

        // The chat that was linked is a stranger to the bot again.
        $answer = (string) app(ChatInbox::class)->handle(ChatLink::TELEGRAM, 'chat-raz', '/invoices', 'Raz');
        $this->assertStringContainsString('sign in to the client area', $answer);

        $log = ActivityLog::query()->where('action', 'client.sign_ins_removed')->sole();
        $this->assertSame('1 passkey(s), 1 connected account(s) and 1 chat app(s) of Raz removed: password reset', $log->description);
        $this->assertSame($client->id, $log->client_id);
        $this->assertSame($client->id, $log->actor_id);
    }

    public function test_a_client_who_only_used_a_connected_account_signs_in_with_the_new_password_after_a_reset(): void
    {
        $client = Client::factory()->create(['email' => 'mer@example.test']);
        $client->forceFill(['has_password' => false])->save();
        $client->socialAccounts()->create(['provider' => 'google', 'provider_user_id' => 'g-mer']);

        $this->reset($client)->assertRedirect(route('client.login'));

        $this->assertSame(0, SocialAccount::query()->count());
        $this->assertTrue($client->fresh()->has_password);
        $this->post(route('client.login'), ['email' => 'mer@example.test', 'password' => 'new-password-1'])->assertRedirect(route('client.dashboard'));
        $this->assertAuthenticatedAs($client, 'web');
    }

    public function test_a_reset_with_nothing_to_remove_keeps_the_usual_message(): void
    {
        $client = Client::factory()->create();

        $this->reset($client)->assertSessionHas('status', 'Your password was changed. Sign in with the new one.');

        $this->assertSame(0, ActivityLog::query()->where('action', 'client.sign_ins_removed')->count());
    }

    public function test_an_own_password_change_removes_them_when_the_box_is_ticked(): void
    {
        $this->changeOwnPasswordAndExpectThemRemoved(['remove_other_sign_ins' => '1']);
    }

    public function test_an_own_password_change_removes_them_when_the_form_has_no_box(): void
    {
        $this->changeOwnPasswordAndExpectThemRemoved([]);
    }

    public function test_an_own_password_change_keeps_them_when_the_box_is_unticked(): void
    {
        $client = Client::factory()->create(['password' => 'old-password-1']);
        $this->giveOtherSignIns($client, 'kept');

        $this->actingAs($client, 'web')
            ->put(route('client.account.password'), ['current_password' => 'old-password-1', 'password' => 'new-password-1', 'password_confirmation' => 'new-password-1', 'remove_other_sign_ins' => '0'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Password saved.');

        $this->assertSame([1, 1, 1], [$client->passkeys()->count(), $client->socialAccounts()->count(), $client->chatLinks()->count()]);
        $this->assertTrue(Hash::check('new-password-1', $client->fresh()->password));
    }

    public function test_a_first_password_keeps_the_connected_account_the_client_signs_in_with(): void
    {
        Mail::fake();
        $client = Client::factory()->create();
        $client->forceFill(['has_password' => false])->save();
        $client->socialAccounts()->create(['provider' => 'google', 'provider_user_id' => 'g-1']);
        $this->actingAs($client, 'web');

        $this->get(route('client.account.edit'))->assertOk()->assertDontSee('name="remove_other_sign_ins"', false);

        $this->post(route('client.account.email-code'))->assertSessionHas('status');
        preg_match('/\b(\d{6})\b/', Mail::sent(TemplatedMessage::class)->last()->bodyHtml, $match);
        $this->put(route('client.account.password'), ['email_code' => $match[1], 'password' => 'new-password-1', 'password_confirmation' => 'new-password-1'])
            ->assertSessionHas('status', 'Password saved.');

        $this->assertTrue($client->fresh()->has_password);
        $this->assertSame(1, $client->socialAccounts()->count());
    }

    public function test_the_account_page_shows_the_box_ticked_when_there_is_something_to_remove(): void
    {
        $client = Client::factory()->create();
        $this->actingAs($client, 'web');

        $this->get(route('client.account.edit'))->assertOk()->assertDontSee('name="remove_other_sign_ins"', false);

        $this->addPasskey($client, 'page');

        $this->get(route('client.account.edit'))->assertOk()
            ->assertSee('Also remove my passkeys, connected accounts and chat apps')
            ->assertSee('id="remove-other-sign-ins" type="checkbox" name="remove_other_sign_ins" value="1" checked', false);
    }

    public function test_a_password_set_by_staff_removes_them_and_the_client_signs_in_with_it(): void
    {
        $admin = $this->signInAdmin();
        $client = Client::factory()->create(['first_name' => 'Mer', 'last_name' => '', 'email' => 'mer@example.test']);
        $client->forceFill(['has_password' => false])->save();
        $this->giveOtherSignIns($client, 'mer');
        $form = ['first_name' => 'Mer', 'last_name' => 'B', 'email' => 'mer@example.test', 'status' => 'active'];

        // Saving without a new password keeps them.
        $this->put(route('admin.clients.update', $client), $form)->assertSessionHasNoErrors()->assertSessionHas('status', 'Client saved.');
        $this->assertSame([1, 1, 1], [$client->passkeys()->count(), $client->socialAccounts()->count(), $client->chatLinks()->count()]);

        $this->put(route('admin.clients.update', $client), $form + ['password' => 'set-by-staff-1'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Client saved. The new password also removed their passkeys, connected accounts and chat apps.');

        $this->assertSame([0, 0, 0], [$client->passkeys()->count(), $client->socialAccounts()->count(), $client->chatLinks()->count()]);
        $this->assertTrue($client->fresh()->has_password);
        $log = ActivityLog::query()->where('action', 'client.sign_ins_removed')->sole();
        $this->assertStringEndsWith('removed: password set by staff', $log->description);
        $this->assertSame([$admin->getMorphClass(), $admin->id], [$log->actor_type, $log->actor_id]);

        $this->post(route('client.login'), ['email' => 'mer@example.test', 'password' => 'set-by-staff-1'])->assertRedirect(route('client.dashboard'));
    }

    /**
     * @param  array<string, string>  $box
     */
    private function changeOwnPasswordAndExpectThemRemoved(array $box): void
    {
        $client = Client::factory()->create(['password' => 'old-password-1']);
        $this->giveOtherSignIns($client, 'own');

        $this->actingAs($client, 'web')
            ->put(route('client.account.password'), ['current_password' => 'old-password-1', 'password' => 'new-password-1', 'password_confirmation' => 'new-password-1'] + $box)
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Password saved. Passkeys, connected accounts and chat apps were removed from your account.');

        $this->assertSame([0, 0, 0], [$client->passkeys()->count(), $client->socialAccounts()->count(), $client->chatLinks()->count()]);
        $this->assertStringEndsWith('removed: own password changed', ActivityLog::query()->where('action', 'client.sign_ins_removed')->sole()->description);
        // This browser stays signed in with the new password.
        $this->get(route('client.dashboard'))->assertOk();
    }

    /**
     * A passkey, a connected Google account and a linked Telegram chat for this client.
     */
    private function giveOtherSignIns(Client $client, string $tag): void
    {
        $this->addPasskey($client, $tag);
        $client->socialAccounts()->create(['provider' => 'google', 'provider_user_id' => 'g-'.$tag]);
        ChatLink::create(['client_id' => $client->id, 'channel' => ChatLink::TELEGRAM, 'external_id' => 'chat-'.$tag]);
    }

    private function addPasskey(Client $client, string $tag): Passkey
    {
        return $client->passkeys()->create([
            'name' => 'Phone',
            'credential_id' => 'cred-'.$tag,
            'credential_hash' => hash('sha256', 'cred-'.$tag),
            'public_key' => 'public-key',
            'algorithm' => -7,
            'sign_count' => 0,
        ]);
    }

    /**
     * Finish "Forgot password" with the link from the email, in a browser that is not signed in.
     */
    private function reset(Client $client): TestResponse
    {
        $token = Password::broker('clients')->createToken($client);

        return $this->post(route('client.password.update'), ['token' => $token, 'email' => $client->email, 'password' => 'new-password-1', 'password_confirmation' => 'new-password-1']);
    }
}
