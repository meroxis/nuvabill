<?php

namespace Database\Factories;

use App\Enums\QuoteStatus;
use App\Models\Client;
use App\Models\Quote;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Quote>
 */
class QuoteFactory extends Factory
{
    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'subject' => 'Dedicated server setup',
            'status' => QuoteStatus::Draft,
            'currency' => 'USD',
            'valid_until' => today()->addDays(30),
        ];
    }
}
