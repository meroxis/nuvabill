<?php

namespace Tests\Feature\Marketplace;

use App\Extensions\Addons\Addon;
use App\Extensions\ExtensionManager;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Add-on settings fields marked "owner": only staff with the Owner role change them. Other staff
 * who may manage add-ons keep the saved values and can still change every other field.
 */
class AddonOwnerFieldsTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'typed-secret-4f19ab';

    private ExtensionManager $extensions;

    protected function setUp(): void
    {
        parent::setUp();

        config(['nuvabill.extensions_path' => base_path('tests/Fixtures/extensions')]);
        $this->extensions = Mockery::mock(ExtensionManager::class.'[addon]', [base_path('tests/Fixtures/extensions')]);
        $this->extensions->shouldReceive('addon')->with('probe')->andReturn(new class($this->extensions->find('probe')) extends Addon
        {
            public function settingsFields(): array
            {
                return [
                    'label' => ['label' => 'Label', 'type' => 'text'],
                    'target' => ['label' => 'Where copies go', 'type' => 'text', 'required' => true, 'owner' => true],
                    'pass' => ['label' => 'Copy password', 'type' => 'password', 'owner' => true],
                ];
            }
        });
        $this->app->instance(ExtensionManager::class, $this->extensions);
        $this->extensions->saveSettings('probe', ['label' => 'First', 'target' => 'vault-one', 'pass' => 'saved-pass', 'token' => 'kept-token'], true);
    }

    public function test_an_owner_changes_owner_fields(): void
    {
        $this->signInAdmin();

        $page = $this->get(route('admin.marketplace.settings', 'probe'))->assertOk()
            ->assertDontSee('Only an owner can change this setting.');
        $this->assertDoesNotMatchRegularExpression('/name="settings\[(target|pass|label)\]"[^>]*\sdisabled/', $page->getContent());

        $this->put(route('admin.marketplace.settings.update', 'probe'), ['enabled' => '1', 'settings' => ['label' => 'Second', 'target' => 'vault-two', 'pass' => 'new-pass']])
            ->assertSessionHasNoErrors();

        $this->assertSame(['label' => 'Second', 'target' => 'vault-two', 'pass' => 'new-pass', 'token' => 'kept-token'], $this->saved());
    }

    public function test_other_staff_cannot_change_owner_fields(): void
    {
        $this->signInAdmin(Admin::factory()->withPermissions(['marketplace.manage'])->create(['name' => 'Raz']));

        $this->put(route('admin.marketplace.settings.update', 'probe'), ['enabled' => '1', 'settings' => ['label' => 'Second', 'target' => 'vault-two']])
            ->assertSessionHasErrors(['settings.target' => 'Only an owner can change this setting.']);
        $this->put(route('admin.marketplace.settings.update', 'probe'), ['enabled' => '1', 'settings' => ['label' => 'Second', 'pass' => self::SECRET]])
            ->assertSessionHasErrors(['settings.pass' => 'Only an owner can change this setting.']);
        // A secret takes no value at all, also not the saved one, so the form never confirms a guess.
        $this->put(route('admin.marketplace.settings.update', 'probe'), ['enabled' => '1', 'settings' => ['label' => 'Second', 'pass' => 'saved-pass']])
            ->assertSessionHasErrors('settings.pass');

        $this->assertSame(['label' => 'First', 'target' => 'vault-one', 'pass' => 'saved-pass', 'token' => 'kept-token'], $this->saved());
        $this->assertStringNotContainsString(self::SECRET, (string) json_encode(session()->all()));
    }

    public function test_other_staff_still_change_the_other_fields(): void
    {
        $this->signInAdmin(Admin::factory()->withPermissions(['marketplace.manage'])->create(['name' => 'Raz']));

        // The page switches the owner fields off, so the browser does not send them.
        $page = $this->get(route('admin.marketplace.settings', 'probe'))->assertOk()
            ->assertSee('Only an owner can change this setting.');
        $this->assertMatchesRegularExpression('/name="settings\[target\]"[^>]*\sdisabled/', $page->getContent());
        $this->assertMatchesRegularExpression('/name="settings\[pass\]"[^>]*\sdisabled/', $page->getContent());
        $this->assertDoesNotMatchRegularExpression('/name="settings\[label\]"[^>]*\sdisabled/', $page->getContent());

        $this->put(route('admin.marketplace.settings.update', 'probe'), ['enabled' => '1', 'settings' => ['label' => 'Second']])
            ->assertSessionHasNoErrors();
        $this->assertSame(['label' => 'Second', 'target' => 'vault-one', 'pass' => 'saved-pass', 'token' => 'kept-token'], $this->saved());

        // Sent back exactly as saved, for example by an older page, changes nothing either.
        $this->put(route('admin.marketplace.settings.update', 'probe'), ['enabled' => '1', 'settings' => ['label' => 'Third', 'target' => 'vault-one', 'pass' => '']])
            ->assertSessionHasNoErrors();
        $this->assertSame(['label' => 'Third', 'target' => 'vault-one', 'pass' => 'saved-pass', 'token' => 'kept-token'], $this->saved());
    }

    public function test_other_staff_cannot_fill_in_an_owner_field_that_is_still_empty(): void
    {
        $this->extensions->saveSettings('probe', ['label' => 'First'], false);
        $this->signInAdmin(Admin::factory()->withPermissions(['marketplace.manage'])->create(['name' => 'Raz']));

        $this->put(route('admin.marketplace.settings.update', 'probe'), ['settings' => ['label' => 'Second', 'target' => 'my-own-vault', 'pass' => self::SECRET]])
            ->assertSessionHasErrors(['settings.target', 'settings.pass']);
        // Switched on, the empty required field is missed, and only an owner can fill it in.
        $this->put(route('admin.marketplace.settings.update', 'probe'), ['enabled' => '1', 'settings' => ['label' => 'Second']])
            ->assertSessionHasErrors('settings.target');

        $this->assertSame(['label' => 'First'], $this->saved());
        $this->assertFalse($this->extensions->isEnabled('probe'));
    }

    /**
     * @return array<string, mixed>
     */
    private function saved(): array
    {
        return array_filter($this->extensions->settings('probe'), fn (mixed $value): bool => $value !== null);
    }
}
