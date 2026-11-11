<?php

namespace Tests\Feature\Admin;

use App\Events\OrderPlaced;
use App\Models\ActivityLog;
use App\Models\Admin;
use App\Models\ApiToken;
use App\Models\Order;
use App\Models\Passkey;
use App\Models\PushSubscription;
use App\Push\WebPush;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Password;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Passkeys and phone alert devices outlast a session, like API keys. A new staff password (a
 * reset, one set in staff settings, or the staff member's own change) removes them, and adding a
 * device asks for the password.
 */
class StaffPasswordEndsOtherAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_password_reset_removes_passkeys_and_phone_alert_devices(): void
    {
        Http::fake(['fcm.googleapis.com/*' => Http::response('', 201)]);
        $admin = Admin::factory()->create(['name' => 'Mer', 'email' => 'mer@example.test']);
        $this->addPasskey($admin, 'mer');
        $this->addDevice($admin, 'mer');
        $other = Admin::factory()->withPermissions(['support.manage'])->create(['name' => 'Las']);
        $this->addPasskey($other, 'las');
        $this->addDevice($other, 'las');

        $this->reset($admin)
            ->assertRedirect(route('admin.login'))
            ->assertSessionHas('status', 'Your password was changed. Your passkeys and phone alerts were removed. Sign in with the new password.');

        $this->assertSame([0, 0], [$admin->passkeys()->count(), $admin->pushSubscriptions()->count()]);
        $this->assertSame([1, 1], [$other->passkeys()->count(), $other->pushSubscriptions()->count()]);

        // The removed device gets no more alerts.
        event(new OrderPlaced(Order::factory()->create(['total' => 4900])));
        Http::assertNothingSent();

        $passkeys = ActivityLog::query()->where('action', 'admin.passkeys_revoked')->sole();
        $this->assertSame('1 passkey(s) of Mer deleted: password reset', $passkeys->description);
        $this->assertSame($admin->id, $passkeys->actor_id);
        $this->assertSame('1 phone alert device(s) of Mer removed: password reset', ActivityLog::query()->where('action', 'admin.push_revoked')->sole()->description);
    }

    public function test_a_password_reset_names_everything_it_removed(): void
    {
        $admin = Admin::factory()->create(['email' => 'mer@example.test']);
        ApiToken::issue($admin, 'Accounting', true);
        $this->addPasskey($admin, 'mer');

        $this->reset($admin)->assertSessionHas('status', 'Your password was changed. Your API keys, passkeys and phone alerts were removed. Sign in with the new password.');

        $this->assertSame([0, 0], [$admin->apiTokens()->count(), $admin->passkeys()->count()]);
    }

    public function test_a_password_set_in_staff_settings_removes_that_staff_members_passkeys_and_devices(): void
    {
        $owner = $this->signInAdmin(Admin::factory()->create(['name' => 'Raz']));
        $this->addPasskey($owner, 'raz');
        $this->addDevice($owner, 'raz');
        $staff = Admin::factory()->create(['name' => 'Las']);
        $this->addPasskey($staff, 'las');
        $this->addDevice($staff, 'las');

        // Saving without a new password keeps them.
        $this->put(route('admin.settings.staff.update', $staff), $this->form($staff, ['name' => 'Las B']))
            ->assertSessionHasNoErrors()->assertSessionHas('status', 'Staff member saved.');
        $this->assertSame([1, 1], [$staff->passkeys()->count(), $staff->pushSubscriptions()->count()]);

        $this->put(route('admin.settings.staff.update', $staff), $this->form($staff, ['password' => 'a-new-password-1']))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Staff member saved. The new password also removed the API keys, passkeys and phone alerts of this account.');

        $this->assertSame([0, 0], [$staff->passkeys()->count(), $staff->pushSubscriptions()->count()]);
        $this->assertSame([1, 1], [$owner->passkeys()->count(), $owner->pushSubscriptions()->count()]);
        $this->assertSame($owner->id, ActivityLog::query()->where('action', 'admin.passkeys_revoked')->sole()->actor_id);
        $this->assertSame($owner->id, ActivityLog::query()->where('action', 'admin.push_revoked')->sole()->actor_id);
    }

    public function test_an_own_password_change_removes_them_when_the_box_is_ticked_or_missing(): void
    {
        foreach ([['revoke_passkeys' => '1'], []] as $box) {
            $admin = $this->signInAdmin();
            $this->addPasskey($admin, 'own-'.$admin->id);
            $this->addDevice($admin, 'own-'.$admin->id);

            $this->put(route('admin.profile.password'), ['current_password' => 'password', 'password' => 'a-new-password-1', 'password_confirmation' => 'a-new-password-1'] + $box)
                ->assertSessionHasNoErrors()->assertSessionHas('status', 'Password changed. Your passkeys and phone alerts were removed.');

            $this->assertSame([0, 0], [$admin->passkeys()->count(), $admin->pushSubscriptions()->count()]);
            $this->flushSession();
        }
    }

    public function test_an_own_password_change_keeps_them_when_the_box_is_unticked(): void
    {
        $admin = $this->signInAdmin();
        ApiToken::issue($admin, 'Accounting', true);
        $this->addPasskey($admin, 'own');
        $this->addDevice($admin, 'own');

        $this->put(route('admin.profile.password'), ['current_password' => 'password', 'password' => 'a-new-password-1', 'password_confirmation' => 'a-new-password-1', 'revoke_passkeys' => '0'])
            ->assertSessionHasNoErrors()->assertSessionHas('status', 'Password changed. Your API keys were deleted.');

        $this->assertSame([0, 1, 1], [$admin->apiTokens()->count(), $admin->passkeys()->count(), $admin->pushSubscriptions()->count()]);
        $this->assertSame(0, ActivityLog::query()->whereIn('action', ['admin.passkeys_revoked', 'admin.push_revoked'])->count());
    }

    public function test_an_own_password_change_with_both_boxes_names_everything_it_removed(): void
    {
        $admin = $this->signInAdmin();
        ApiToken::issue($admin, 'Accounting', true);
        $this->addDevice($admin, 'own');

        $this->put(route('admin.profile.password'), ['current_password' => 'password', 'password' => 'a-new-password-1', 'password_confirmation' => 'a-new-password-1', 'revoke_api_keys' => '1', 'revoke_passkeys' => '1'])
            ->assertSessionHasNoErrors()->assertSessionHas('status', 'Password changed. Your API keys, passkeys and phone alerts were removed.');

        $this->assertSame([0, 0], [$admin->apiTokens()->count(), $admin->pushSubscriptions()->count()]);
    }

    public function test_the_profile_shows_the_box_ticked(): void
    {
        $this->signInAdmin();

        $this->get(route('admin.profile.edit'))->assertOk()
            ->assertSee('Also remove my passkeys and phone alerts')
            ->assertSee('id="revoke-passkeys" type="checkbox" name="revoke_passkeys" value="1" checked', false);
    }

    public function test_adding_a_phone_alert_device_needs_the_right_password(): void
    {
        $admin = $this->signInAdmin();
        $device = ['endpoint' => 'https://fcm.googleapis.com/fcm/send/new', 'keys' => $this->keys()];

        $this->postJson(route('admin.profile.push.store'), $device)->assertStatus(422)->assertJsonPath('message', 'Your password is not right.');
        $this->postJson(route('admin.profile.push.store'), $device + ['current_password' => 'wrong-password'])->assertStatus(422);
        $this->postJson(route('admin.profile.push.store'), $device + ['current_password' => ['password']])->assertStatus(422);
        $this->assertSame(0, PushSubscription::query()->count());

        $this->postJson(route('admin.profile.push.store'), $device + ['current_password' => 'password'])->assertOk();
        $this->assertSame($admin->id, PushSubscription::query()->sole()->admin_id);

        // Both pages show the password field next to the button.
        $this->get(route('admin.profile.edit'))->assertOk()->assertSee('id="push-password"', false);
        $this->get(route('admin.today'))->assertOk()->assertSee('id="push-password"', false);
    }

    public function test_adding_a_phone_alert_device_shares_the_staff_reauth_limit(): void
    {
        $this->signInAdmin();
        $device = ['endpoint' => 'https://fcm.googleapis.com/fcm/send/new', 'keys' => $this->keys()];

        foreach (range(1, 6) as $try) {
            $this->postJson(route('admin.profile.push.store'), $device + ['current_password' => 'wrong-password-'.$try])->assertStatus(422);
        }

        $this->postJson(route('admin.profile.push.store'), $device + ['current_password' => 'password'])->assertStatus(429);
        $this->postJson(route('admin.profile.passkeys.options'), ['current_password' => 'password'])->assertStatus(429);
        $this->assertSame(0, PushSubscription::query()->count());
    }

    private function addPasskey(Admin $admin, string $tag): Passkey
    {
        return $admin->passkeys()->create([
            'name' => 'Laptop',
            'credential_id' => 'cred-'.$tag,
            'credential_hash' => hash('sha256', 'cred-'.$tag),
            'public_key' => 'public-key',
            'algorithm' => -7,
            'sign_count' => 0,
        ]);
    }

    private function addDevice(Admin $admin, string $tag): PushSubscription
    {
        $endpoint = 'https://fcm.googleapis.com/fcm/send/'.$tag;

        return PushSubscription::query()->create(['admin_id' => $admin->id, 'endpoint' => $endpoint, 'endpoint_hash' => PushSubscription::hashOf($endpoint)] + [
            'public_key' => $this->keys()['p256dh'],
            'auth_token' => $this->keys()['auth'],
        ]);
    }

    /**
     * Keys shaped like a browser's: an uncompressed P-256 point and 16 secret bytes.
     *
     * @return array{p256dh: string, auth: string}
     */
    private function keys(): array
    {
        return ['p256dh' => WebPush::encode("\x04".random_bytes(64)), 'auth' => WebPush::encode(random_bytes(16))];
    }

    /**
     * Finish "Forgot password" with the link from the email, in a browser that is not signed in.
     */
    private function reset(Admin $admin): TestResponse
    {
        $token = Password::broker('admins')->createToken($admin);

        return $this->post(route('admin.password.update'), ['token' => $token, 'email' => $admin->email, 'password' => 'a-new-password-1', 'password_confirmation' => 'a-new-password-1']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function form(Admin $admin, array $overrides = []): array
    {
        return $overrides + [
            'name' => $admin->name,
            'email' => $admin->email,
            'role_id' => $admin->role_id,
            'is_active' => 1,
            'password' => '',
        ];
    }
}
