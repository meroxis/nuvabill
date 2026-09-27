<?php

namespace Database\Factories;

use App\Models\TaxRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TaxRule>
 */
class TaxRuleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'VAT',
            'rate' => 2000,
            'country' => null,
            'state' => null,
        ];
    }

    public function inCountry(string $country, ?string $state = null): static
    {
        return $this->state(['country' => $country, 'state' => $state]);
    }
}
