<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\ApiToken;
use App\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ApiClientPasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_key_creates_a_client_when_the_password_is_null_or_empty(): void
    {
        Mail::fake();
        [, $plain] = ApiToken::issue(Admin::factory()->create(['name' => 'Raz']), 'Accounting', true);
        $headers = ['Authorization' => "Bearer {$plain}"];

        foreach ([null, ''] as $i => $password) {
            $this->postJson('/api/v1/clients', ['first_name' => 'Mer', 'last_name' => 'Las', 'email' => "mer{$i}@example.test", 'password' => $password], $headers)
                ->assertCreated();
        }

        $this->assertSame(2, Client::query()->count());

        foreach (['mer0@example.test', 'mer1@example.test'] as $email) {
            $hash = (string) Client::query()->where('email', $email)->value('password');
            $this->assertNotSame('', $hash);
            $this->assertTrue(Hash::isHashed($hash));
        }
    }

    public function test_a_given_password_is_kept(): void
    {
        [, $plain] = ApiToken::issue(Admin::factory()->create(['name' => 'Raz']), 'Accounting', true);

        $this->postJson('/api/v1/clients', ['first_name' => 'Mer', 'last_name' => 'Las', 'email' => 'mer@example.test', 'password' => 'chosen-pass-1'], ['Authorization' => "Bearer {$plain}"])
            ->assertCreated();

        $this->assertTrue(Hash::check('chosen-pass-1', (string) Client::query()->where('email', 'mer@example.test')->value('password')));
    }
}
