<?php

namespace App\Billing;

use App\Models\Admin;
use App\Models\Client;
use App\Models\CreditTransaction;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * A client's wallet: money kept on their account (clients.credit) to pay invoices with.
 *
 * Money comes in when a client adds funds (a small invoice that credits the wallet when paid),
 * from overpayments, refunds and staff. It goes out when invoices are paid from it, by the
 * client or automatically for new invoices. Every change is written to credit_transactions.
 */
class Wallet
{
    /**
     * The payment method name used for invoices paid from the wallet.
     */
    public const GATEWAY = 'credit';

    public function enabled(): bool
    {
        return (bool) setting('wallet.enabled');
    }

    /**
     * Add (positive) or take (negative) money from a client's wallet and record why.
     *
     * @throws RuntimeException When the wallet does not have enough money.
     */
    public function change(Client $client, int $amount, string $description, ?Invoice $invoice = null, ?Admin $admin = null): CreditTransaction
    {
        return DB::transaction(function () use ($client, $amount, $description, $invoice, $admin): CreditTransaction {
            $locked = Client::query()->lockForUpdate()->findOrFail($client->id);
            $balance = $locked->credit + $amount;

            if ($balance < 0) {
                throw new RuntimeException(__('The wallet does not have enough money.'));
            }

            $locked->forceFill(['credit' => $balance])->save();
            $client->credit = $balance;

            return CreditTransaction::create([
                'client_id' => $locked->id,
                'amount' => $amount,
                'balance' => $balance,
                'currency' => $locked->currency,
                'description' => $description,
                'invoice_id' => $invoice?->id,
                'admin_id' => $admin?->id,
            ]);
        });
    }

    /**
     * Pay as much of an invoice as the wallet holds. Returns the amount paid, in minor units.
     */
    public function pay(Invoice $invoice): int
    {
        $invoice->loadMissing('client');
        $client = $invoice->client;

        if ($this->isTopUp($invoice) || $client->currency !== $invoice->currency) {
            return 0;
        }

        $payments = app(PaymentRecorder::class);

        // The invoice and then the wallet are locked while the amount is worked out, taken and
        // recorded, so a double click or two runs at once cannot pay the same invoice twice.
        [$amount, $becamePaid] = DB::transaction(function () use ($invoice, $client, $payments): array {
            $locked = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            $credit = (int) Client::query()->lockForUpdate()->whereKey($client->id)->value('credit');
            $amount = $locked->isPayable() ? min($credit, $locked->balance()) : 0;

            if ($amount <= 0) {
                return [0, false];
            }

            $entry = $this->change($client, -$amount, __('Paid invoice :number', ['number' => $locked->displayNumber()]), $locked);
            [, $becamePaid] = $payments->store($locked, $amount, self::GATEWAY, 'wallet-'.$entry->id);

            return [$amount, $becamePaid];
        });

        if ($amount > 0) {
            $payments->afterRecorded($invoice, $amount, self::GATEWAY, $becamePaid);
        }

        return $amount;
    }

    /**
     * Use the wallet for a new invoice when the setting is on. Returns the amount paid.
     */
    public function applyAutomatically(Invoice $invoice): int
    {
        return setting('wallet.auto_apply') ? $this->pay($invoice) : 0;
    }

    /**
     * An invoice for adding money to the wallet. The money arrives when the invoice is paid.
     */
    public function topUp(Client $client, int $amount): Invoice
    {
        return app(InvoiceManager::class)->create($client, [[
            'type' => InvoiceItem::TYPE_CREDIT,
            'description' => __('Add funds to your wallet'),
            'amount' => $amount,
        ]]);
    }

    public function isTopUp(Invoice $invoice): bool
    {
        return $invoice->items()->where('type', InvoiceItem::TYPE_CREDIT)->exists();
    }

    /**
     * The smallest and largest amount a client can add at once, in minor units.
     *
     * @return array{0: int, 1: int}
     */
    public function depositLimits(): array
    {
        return [
            max(1, Money::toMinor(setting('wallet.min_deposit'))),
            max(1, Money::toMinor(setting('wallet.max_deposit'))),
        ];
    }
}
