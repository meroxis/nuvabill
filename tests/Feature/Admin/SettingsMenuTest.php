<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use Database\Seeders\DefaultDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Settings menu: grouped pages, the page header, and only what each role may open.
 */
class SettingsMenuTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DefaultDataSeeder::class);
    }

    public function test_settings_pages_show_the_grouped_menu_and_their_own_header(): void
    {
        $this->actingAs(Admin::factory()->create(), 'admin');

        $this->get(route('admin.settings.taxes.index'))
            ->assertOk()
            ->assertSeeInOrder(['Business', 'General', 'Currencies', 'Taxes', 'Domains', 'Payments', 'Automatic payments', 'Messages', 'Team', 'Staff', 'System', 'Activity log'])
            ->assertSee('VAT, GST or sales tax rules by country and state.')
            ->assertSee('<h1>Taxes</h1>', false)
            ->assertSee('Find a setting');
    }

    public function test_staff_only_see_the_settings_their_role_allows(): void
    {
        $this->actingAs(Admin::factory()->withPermissions(['staff.manage'])->create(), 'admin');

        $this->get(route('admin.settings.staff.index'))
            ->assertOk()
            ->assertSee('Roles')
            ->assertDontSee('Automatic payments')
            ->assertDontSee('Email templates');

        $this->get(route('admin.settings.index'))->assertOk()->assertSee('What each role can see and do.')->assertDontSee('Taxes');
    }

    public function test_all_settings_lists_every_page_with_what_it_is_for(): void
    {
        $this->actingAs(Admin::factory()->create(), 'admin');

        $this->get(route('admin.settings.index'))
            ->assertOk()
            ->assertSee('Charge saved cards and PayPal accounts for renewals.')
            ->assertSee(route('admin.extensions.index', ['tab' => 'gateways']), false);
    }

    public function test_staff_without_a_settings_permission_cannot_open_all_settings(): void
    {
        $this->actingAs(Admin::factory()->withPermissions(['clients.view'])->create(), 'admin');

        $this->get(route('admin.settings.index'))->assertForbidden();
    }
}
