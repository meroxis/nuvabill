<?php

namespace Database\Factories;

use App\Models\Server;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Server>
 */
class ServerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'Server '.fake()->unique()->numberBetween(1, 999),
            'module' => 'cpanel',
            'hostname' => 'server'.fake()->unique()->numberBetween(1, 9999).'.example.test',
            'port' => 2087,
            'use_ssl' => true,
            'username' => 'root',
            'api_token' => 'TESTTOKEN123',
            'nameservers' => ['ns1.example.test', 'ns2.example.test'],
            'is_active' => true,
        ];
    }
}
