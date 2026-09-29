<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use Database\Seeders\DefaultDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Settings home with its grouped cards, the header of every settings page, and only what each
 * role may open.
 */
class SettingsMenuTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DefaultDataSeeder::class);
    }

    public function test_the_settings_home_shows_every_page_in_grouped_cards(): void
    {
        $this->actingAs(Admin::factory()->create(), 'admin');

        $this->get(route('admin.dashboard'))->assertSee('href="'.route('admin.settings.index').'"', false);

        $this->get(route('admin.settings.index'))
            ->assertOk()
            ->assertSee('Find a setting')
            ->assertSeeInOrder(['Business', 'General', 'Company, billing and look', 'Domains', 'Payments', 'Automatic payments', 'Saved cards pay renewals',
                'Sign-in and security', 'Security', 'Messages', 'Growth', 'Team', 'Roles', 'System', 'Activity log'])
            ->assertSee(route('admin.extensions.index', ['tab' => 'gateways']), false);
    }

    public function test_settings_pages_have_their_own_header_and_a_way_back(): void
    {
        $this->actingAs(Admin::factory()->create(), 'admin');

        $this->get(route('admin.settings.taxes.index'))
            ->assertOk()
            ->assertSee('<h1>Taxes</h1>', false)
            ->assertSee('VAT, GST or sales tax rules by country and state.')
            ->assertSee('href="'.route('admin.settings.index').'"', false)
            ->assertDontSee('Saved cards pay renewals');

        $this->get(route('admin.settings.edit'))->assertOk()->assertSee('<h1>General</h1>', false);
    }

    public function test_staff_only_see_the_settings_their_role_allows(): void
    {
        $this->actingAs(Admin::factory()->withPermissions(['staff.manage'])->create(), 'admin');

        $this->get(route('admin.settings.index'))
            ->assertOk()
            ->assertSee('What each role can do')
            ->assertDontSee('Taxes')
            ->assertDontSee('Automatic payments');

        $this->get(route('admin.settings.staff.index'))->assertOk()->assertSee('<h1>Staff</h1>', false);
        $this->get(route('admin.settings.edit'))->assertForbidden();
    }

    public function test_staff_without_a_settings_permission_cannot_open_settings(): void
    {
        $this->actingAs(Admin::factory()->withPermissions(['clients.view'])->create(), 'admin');

        $this->get(route('admin.settings.index'))->assertForbidden();
    }
}
