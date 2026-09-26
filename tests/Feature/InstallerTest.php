<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InstallerTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_fresh_copy_sends_visitors_to_the_installer(): void
    {
        config(['nuvabill.installed' => false]);

        $this->get(route('store.index'))->assertRedirect(route('install.welcome'));
        $this->get(route('admin.login'))->assertRedirect(route('install.welcome'));
        $this->get(route('install.welcome'))->assertOk()->assertSee('Check your server');
    }

    public function test_the_installer_is_closed_after_installation(): void
    {
        $this->get(route('install.welcome'))->assertNotFound();
        $this->get(route('install.account'))->assertNotFound();
    }
}
