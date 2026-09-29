<?php

namespace App\Billing;

use App\Contracts\SavesPaymentMethods;
use App\Extensions\ExtensionManager;
use App\Extensions\Gateways\PaymentResult;
use App\Extensions\Gateways\SavedMethod;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\PaymentMethod;
use App\Support\Activity;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The cards and PayPal accounts clients keep for automatic renewals: saving, choosing which one
 * pays, and removing them (at the gateway too).
 */
class SavedMethods
{
    public function __construct(private ExtensionManager $extensions) {}

    /**
     * Gateways that can keep a method, for this currency, keyed by slug.
     *
     * @return Collection<string, SavesPaymentMethods>
     */
    public function gateways(?string $currency = null): Collection
    {
        return $this->extensions->activeGateways($currency)
            ->filter(fn (mixed $gateway): bool => $gateway instanceof SavesPaymentMethods);
    }

    public function gateway(string $slug): ?SavesPaymentMethods
    {
        $gateway = $this->gateways()->get($slug);

        return $gateway instanceof SavesPaymentMethods ? $gateway : null;
    }

    /**
     * A method the client just saved becomes the one that pays renewals, and automatic payments
     * are on: saving it was the client's own choice.
     */
    public function remember(Client $client, string $gateway, SavedMethod $saved): PaymentMethod
    {
        return DB::transaction(function () use ($client, $gateway, $saved): PaymentMethod {
            $client->paymentMethods()->update(['is_default' => false]);

            $method = PaymentMethod::query()->updateOrCreate(
                ['gateway' => $gateway, 'reference' => $saved->reference],
                ['client_id' => $client->id, 'is_default' => true] + array_filter($saved->toArray(), fn (mixed $value): bool => $value !== null),
            );

            if (! $client->auto_pay) {
                $client->forceFill(['auto_pay' => true])->save();
            }

            if ($method->wasRecentlyCreated) {
                Activity::log('payment_method.saved', "{$client->name} saved {$method->label()} for automatic payments", $method, $client);
            }

            return $method;
        });
    }

    /**
     * Called for every payment a gateway reports: a payment the client chose to save carries the
     * method in its meta.
     */
    public function rememberFromPayment(PaymentResult $result, string $gateway): void
    {
        $saved = SavedMethod::fromArray((array) ($result->meta['saved_method'] ?? []));
        $client = $saved ? Invoice::query()->find($result->invoiceId)?->client : null;

        if ($saved !== null && $client !== null) {
            $this->remember($client, $gateway, $saved);
        }
    }

    public function makeDefault(PaymentMethod $method): void
    {
        DB::transaction(function () use ($method): void {
            PaymentMethod::query()->where('client_id', $method->client_id)->update(['is_default' => false]);
            $method->forceFill(['is_default' => true])->save();
        });
    }

    /**
     * Delete the method here and at the gateway. A gateway that cannot be reached does not stop
     * it: the method is never used again either way.
     */
    public function forget(PaymentMethod $method, string $by): void
    {
        try {
            $this->extensions->gateway($method->gateway) instanceof SavesPaymentMethods
                && $this->extensions->gateway($method->gateway)->forgetSaved($method);
        } catch (Throwable $exception) {
            report($exception);
        }

        $client = $method->client;
        $wasDefault = $method->is_default;
        $method->delete();

        if ($wasDefault) {
            $client?->paymentMethods()->latest('id')->first()?->forceFill(['is_default' => true])->save();
        }

        Activity::log('payment_method.removed', "{$by} removed {$method->label()}", client: $client);
    }
}
