<?php

namespace App\Extensions\Gateways;

use App\Billing\ExchangeRates;
use App\Contracts\PaymentGateway;
use App\Extensions\ExtensionManifest;
use App\Models\Invoice;
use App\Models\PaymentIntent;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Base class for payment gateway extensions. Holds the manifest and the saved settings.
 */
abstract class Gateway implements PaymentGateway
{
    /**
     * How far, in percent, a converted payment may fall short of the invoice's price at today's
     * exchange rate and still pay it in full (see resultFor()). It covers rounding and small rate
     * changes, so they leave no tiny balance behind.
     */
    protected const RATE_TOLERANCE_PERCENT = 1;

    /**
     * @param  array<string, mixed>  $settings
     */
    public function __construct(
        protected ExtensionManifest $manifest,
        protected array $settings = [],
    ) {}

    public function slug(): string
    {
        return $this->manifest->slug;
    }

    public function name(): string
    {
        $displayName = $this->setting('display_name');

        return is_string($displayName) && $displayName !== '' ? $displayName : $this->manifest->name;
    }

    public function settingsFields(): array
    {
        return [];
    }

    public function isConfigured(): bool
    {
        foreach ($this->settingsFields() as $key => $field) {
            if (($field['required'] ?? false) && blank($this->setting($key))) {
                return false;
            }
        }

        return true;
    }

    public function supportsCurrency(string $currency): bool
    {
        return true;
    }

    public function chargeCurrencyFor(string $currency): ?string
    {
        $currency = strtoupper($currency);

        if ($this->supportsCurrency($currency)) {
            return $currency;
        }

        if (! $this->convertsCurrency()) {
            return null;
        }

        $rates = app(ExchangeRates::class);

        foreach ($rates->currencies() as $other) {
            if ($other !== $currency && $this->supportsCurrency($other) && $rates->rate($currency, $other) !== null) {
                return $other;
            }
        }

        return null;
    }

    /**
     * Whether the gateway charges a converted amount (see quote()) for an invoice in a currency it
     * does not take, at the rate staff set. A gateway that always sends the invoice's own currency
     * and amount returns false, so an exchange rate never offers it for other currencies.
     */
    public function convertsCurrency(): bool
    {
        return true;
    }

    /**
     * What the client pays with this gateway for the invoice's balance: the same amount, or the
     * amount converted at the rate staff set when the gateway charges another currency.
     *
     * @return array{currency: string, amount: int, rate: float|null}
     */
    public function quote(Invoice $invoice): array
    {
        $currency = $this->chargeCurrencyFor($invoice->currency) ?? $invoice->currency;

        if ($currency === $invoice->currency) {
            return ['currency' => $currency, 'amount' => $invoice->balance(), 'rate' => null];
        }

        $rates = app(ExchangeRates::class);

        return [
            'currency' => $currency,
            'amount' => (int) $rates->convert($invoice->balance(), $invoice->currency, $currency),
            'rate' => $rates->rate($invoice->currency, $currency),
        ];
    }

    /**
     * Details to keep on a payment intent about what was charged, so the payment can be checked
     * and credited in the invoice's currency later. $chargedMinor is in the charged currency.
     *
     * @param  array{currency: string, amount: int, rate: float|null}  $quote
     * @return array{charged_amount: int, charged_currency: string, rate: float|null}
     */
    protected function chargeDetails(array $quote, int $chargedMinor): array
    {
        return ['charged_amount' => $chargedMinor, 'charged_currency' => $quote['currency'], 'rate' => $quote['rate']];
    }

    /**
     * Turn a confirmed payment into a result in the invoice's currency. When the gateway charged a
     * converted amount (for example dinar for a dollar invoice), the invoice is credited with the
     * amount the intent was made for, but only if at least the converted amount arrived.
     *
     * The converted amount was worked out at the exchange rate of the moment the payment was
     * started. A payment page can stay open long after that, so the price is checked again at
     * today's rate: when what arrived is short of it by more than RATE_TOLERANCE_PERCENT, the
     * payment counts for what it is worth today and the rest of the invoice stays due. The money
     * that arrived is always counted.
     *
     * @param  array<string, mixed>  $meta
     */
    protected function resultFor(PaymentIntent $intent, int $paidMinor, string $paidCurrency, string $reference, array $meta = []): ?PaymentResult
    {
        $paidCurrency = strtoupper($paidCurrency);
        $currency = strtoupper((string) $intent->currency);

        if ($paidCurrency === $currency) {
            return new PaymentResult($intent->invoice_id, $paidMinor, $paidCurrency, $reference, meta: $meta);
        }

        $charged = (int) ($intent->meta['charged_amount'] ?? 0);

        if (strtoupper((string) ($intent->meta['charged_currency'] ?? '')) !== $paidCurrency || $charged <= 0 || $paidMinor < $charged) {
            Log::warning("Gateway {$this->slug()} reported {$paidMinor} {$paidCurrency} for payment {$intent->reference}, which does not match what was charged.");

            return null;
        }

        $amount = (int) $intent->amount;
        $details = ['paid_amount' => $paidMinor, 'paid_currency' => $paidCurrency, 'rate' => $intent->meta['rate'] ?? null];
        $rates = app(ExchangeRates::class);
        // What the same invoice amount costs in the charged currency today. Without a rate today
        // nothing can be compared, and the price of the day the payment was started stands.
        $priceToday = $rates->convert($amount, $currency, $paidCurrency);

        if ($priceToday !== null && $paidMinor < $priceToday - intdiv($priceToday * self::RATE_TOLERANCE_PERCENT, 100)) {
            $worth = min($amount, (int) $rates->convert($paidMinor, $paidCurrency, $currency));

            if ($worth <= 0) {
                Log::warning("Gateway {$this->slug()} reported {$paidMinor} {$paidCurrency} for payment {$intent->reference}, which is worth nothing at today's exchange rate.");

                return null;
            }

            // The rate used, and what the payment was started for, so the invoice can say why a
            // part is still due.
            $details = ['rate' => $rates->rate($currency, $paidCurrency), 'quoted_rate' => $details['rate'], 'quoted_amount' => $amount] + $details;
            $amount = $worth;
        }

        return new PaymentResult($intent->invoice_id, $amount, $currency, $reference, meta: $meta + $details);
    }

    public function handleReturn(Request $request, Invoice $invoice): ?PaymentResult
    {
        return null;
    }

    public function handleWebhook(Request $request): WebhookResult
    {
        return WebhookResult::ignored();
    }

    /**
     * The provider's events the webhook must be sent, for providers where staff choose them. They
     * are listed under the webhook URL on the gateway's settings page, also once the settings are
     * saved, so a site that set the webhook up earlier sees which events a newer version added.
     *
     * @return list<string>
     */
    public function webhookEvents(): array
    {
        return [];
    }

    /**
     * Whether the invoice page may ask this gateway every few seconds if the payment arrived,
     * for gateways where the client pays in another app (for example by scanning a QR code).
     */
    public function checksWhileWaiting(): bool
    {
        return false;
    }

    public function supportsRefunds(): bool
    {
        return false;
    }

    public function refund(Transaction $payment, int $amount): RefundResult
    {
        throw new RuntimeException("{$this->name()} cannot send refunds. Refund the client another way.");
    }

    /**
     * Invoice amounts in Iraqi dinar as whole dinars. Nuvabill stores two decimal places, and
     * Iraqi gateways only take whole dinars, so any fraction is rounded up.
     */
    protected function wholeUnits(int $minor): int
    {
        return intdiv($minor + 99, 100);
    }

    protected function setting(string $key, mixed $default = null): mixed
    {
        return $this->settings[$key] ?? $default;
    }
}
