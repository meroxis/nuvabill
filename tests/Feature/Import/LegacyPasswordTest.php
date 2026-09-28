<?php

namespace Tests\Feature\Import;

use App\Auth\LegacyPassword;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LegacyPasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_kind_of_old_hash_checks_only_the_right_password(): void
    {
        $salt = 'zXl1BYRjrzoi8O85WiokmYvalq2v2bcT';
        $pbkdf2 = 'pbkdf2:sha256:1000:'.$salt.':'.base64_encode(hash_pbkdf2('sha256', 'secret-1', $salt, 1000, 24, true));
        app(Settings::class)->set('import.password_keys', ['blesta' => 'system-key']);

        $hashes = [
            'md5-salt:'.md5('ab1secret-1').':ab1' => 'secret-1',
            'hmac-bcrypt:blesta:'.password_hash(hash_hmac('sha256', 'secret-1', 'system-key'), PASSWORD_BCRYPT, ['cost' => 4]) => 'secret-1',
            $pbkdf2 => 'secret-1',
            'native:'.password_hash('secret-1', PASSWORD_ARGON2ID) => 'secret-1',
        ];

        foreach ($hashes as $stored => $password) {
            $this->assertTrue(LegacyPassword::check($stored, $password), $stored);
            $this->assertFalse(LegacyPassword::check($stored, 'secret-2'), $stored);
        }

        $this->assertFalse(LegacyPassword::check('hmac-bcrypt:paymenter:'.password_hash('x', PASSWORD_BCRYPT, ['cost' => 4]), 'x'), 'No key saved for that system');
        $this->assertFalse(LegacyPassword::check('unknown:abc', 'abc'));
    }

    public function test_the_import_page_offers_every_system(): void
    {
        $this->signInAdmin();

        $this->get(route('admin.settings.import.index'))->assertOk()
            ->assertSee('WHMCS')->assertSee('Blesta')->assertSee('FOSSBilling')->assertSee('Paymenter');
    }
}
