<?php

namespace App\Billing;

use App\Support\Settings;

/**
 * Exchange rates staff set in Settings → Currencies, for example 1 USD = 1,310 IQD.
 *
 * Prices and invoices stay in the billing currency. Rates are used when a client pays with a
 * gateway that only takes another currency: a dollar invoice paid with Wayl is charged in dinar.
 * Rates are stored as "units of that currency for 1 unit of the billing currency".
 */
class ExchangeRates
{
    public function __construct(private Settings $settings) {}

    public function base(): string
    {
        return strtoupper((string) $this->settings->get('billing.currency'));
    }

    /**
     * @return array<string, float> Currency code => units per 1 unit of the billing currency.
     */
    public function all(): array
    {
        $rates = [];

        foreach ((array) $this->settings->get('currency.rates', []) as $code => $rate) {
            if (is_string($code) && preg_match('/^[A-Z]{3}$/', $code) && is_numeric($rate) && (float) $rate > 0 && $code !== $this->base()) {
                $rates[$code] = (float) $rate;
            }
        }

        ksort($rates);

        return $rates;
    }

    /**
     * The billing currency and every currency with a rate.
     *
     * @return list<string>
     */
    public function currencies(): array
    {
        return array_values(array_unique([$this->base(), ...array_keys($this->all())]));
    }

    /**
     * How many units of $to one unit of $from buys, or null when there is no rate.
     */
    public function rate(string $from, string $to): ?float
    {
        $from = strtoupper($from);
        $to = strtoupper($to);

        if ($from === $to) {
            return 1.0;
        }

        $rates = $this->all() + [$this->base() => 1.0];

        if (! isset($rates[$from], $rates[$to])) {
            return null;
        }

        return $rates[$to] / $rates[$from];
    }

    /**
     * Convert minor units between currencies, rounded to the nearest minor unit.
     */
    public function convert(int $minor, string $from, string $to): ?int
    {
        $rate = $this->rate($from, $to);

        return $rate === null ? null : (int) round($minor * $rate);
    }

    /**
     * @param  array<string, float|int|string>  $rates
     */
    public function save(array $rates): void
    {
        $clean = [];

        foreach ($rates as $code => $rate) {
            $code = strtoupper((string) $code);

            if (preg_match('/^[A-Z]{3}$/', $code) && is_numeric($rate) && (float) $rate > 0 && $code !== $this->base()) {
                $clean[$code] = round((float) $rate, 6);
            }
        }

        ksort($clean);
        $this->settings->set('currency.rates', $clean);
    }
}
