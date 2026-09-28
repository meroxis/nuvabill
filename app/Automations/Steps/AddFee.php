<?php

namespace App\Automations\Steps;

use App\Automations\Context;
use App\Automations\StepFailed;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Support\Activity;
use App\Support\Money;

/**
 * Add a fee to an unpaid invoice, for example a late fee: a percentage of the total or a fixed
 * amount, with a lowest and highest amount.
 */
class AddFee extends Step
{
    public const ITEM_TYPE = 'late_fee';

    public function key(): string
    {
        return 'add_fee';
    }

    public function label(): string
    {
        return 'Add a fee to the invoice';
    }

    public function group(): string
    {
        return 'Billing';
    }

    public function subjects(): array
    {
        return ['invoice'];
    }

    public function fields(): array
    {
        return [
            self::field('kind', 'Fee', 'select', ['required' => true, 'default' => 'percent', 'options' => ['percent' => __('Percent of the total'), 'fixed' => __('Fixed amount')]]),
            self::field('amount', 'Amount', 'number', ['required' => true, 'min' => 0, 'max' => 100000, 'default' => '5', 'help' => 'A percent, or an amount in the invoice currency.']),
            self::field('minimum', 'At least', 'money', ['default' => '0']),
            self::field('maximum', 'At most', 'money', ['default' => '0', 'help' => '0 means no highest amount.']),
            self::field('description', 'Line on the invoice', 'text', ['required' => true, 'max' => 120, 'default' => 'Late payment fee']),
            self::field('once', 'Only once per invoice', 'checkbox', ['default' => true]),
        ];
    }

    public function summary(array $config): string
    {
        $maximum = Money::toMinor($config['maximum'] ?? 0);

        return ($config['kind'] ?? 'percent') === 'fixed'
            ? __('Add a :amount fee', ['amount' => money(Money::toMinor($config['amount'] ?? 0))])
            : ($maximum > 0
                ? __('Add a :percent% fee (at most :max)', ['percent' => $this->number($config['amount'] ?? 0), 'max' => money($maximum)])
                : __('Add a :percent% fee', ['percent' => $this->number($config['amount'] ?? 0)]));
    }

    public function preview(Context $context, array $config): string
    {
        $invoice = $context->invoice();

        if ($invoice === null || $invoice->status !== InvoiceStatus::Unpaid) {
            return __('Would add nothing: the invoice is already paid or cancelled.');
        }

        if ($this->alreadyHasFee($invoice, $config)) {
            return __('Would add nothing: the invoice already has this fee.');
        }

        $fee = $this->fee($invoice, $config);

        return $fee > 0
            ? __('Would add :amount “:line” to :invoice', ['amount' => money($fee, $invoice->currency), 'line' => $config['description'] ?? '', 'invoice' => $invoice->displayNumber()])
            : __('Would add nothing: the fee comes to zero.');
    }

    public function run(Context $context, array $config): string
    {
        $invoice = $context->invoice() ?? throw new StepFailed(__('This step only works on invoices.'));
        $invoice->refresh();

        if ($invoice->status !== InvoiceStatus::Unpaid) {
            return __('Nothing added: the invoice is already paid or cancelled.');
        }

        if ($this->alreadyHasFee($invoice, $config)) {
            return __('Nothing added: the invoice already has this fee.');
        }

        $fee = $this->fee($invoice, $config);

        if ($fee <= 0) {
            return __('Nothing added: the fee comes to zero.');
        }

        $invoice->items()->create([
            'type' => self::ITEM_TYPE,
            'description' => (string) $config['description'],
            'amount' => $fee,
            'taxed' => false,
        ]);
        $invoice->recalculate();
        $invoice->save();

        Activity::log('invoice.fee_added', "Automation added a fee of {$fee} to invoice {$invoice->displayNumber()}", $invoice);

        return __('Added :amount “:line” to :invoice', ['amount' => money($fee, $invoice->currency), 'line' => $config['description'], 'invoice' => $invoice->displayNumber()]);
    }

    /**
     * The fee in minor units: the percentage of the total before fees, or the fixed amount,
     * kept between the lowest and highest amount.
     *
     * @param  array<string, mixed>  $config
     */
    public function fee(Invoice $invoice, array $config): int
    {
        $base = $invoice->total - (int) $invoice->items()->where('type', self::ITEM_TYPE)->sum('amount');
        $fee = ($config['kind'] ?? 'percent') === 'fixed'
            ? Money::toMinor($config['amount'] ?? 0)
            : (int) round(max(0, $base) * (float) ($config['amount'] ?? 0) / 100);

        $fee = max($fee, Money::toMinor($config['minimum'] ?? 0));
        $maximum = Money::toMinor($config['maximum'] ?? 0);

        return $maximum > 0 ? min($fee, $maximum) : $fee;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function alreadyHasFee(Invoice $invoice, array $config): bool
    {
        return ($config['once'] ?? true) && $invoice->items()->where('type', self::ITEM_TYPE)->where('description', (string) ($config['description'] ?? ''))->exists();
    }

    private function number(mixed $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
    }
}
