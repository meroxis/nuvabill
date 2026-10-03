<?php

namespace App\Marketplace\Store;

use App\Billing\Taxes;
use App\Models\CreditNote;
use App\Models\DeveloperEarning;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\License;
use App\Support\Activity;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * When an invoice with marketplace purchases is paid, each item's developer gets their share
 * (83% by default) of what the client paid for it without tax, including yearly update renewals.
 * When a credit note gives money back, the same share of it is taken back, and a refunded
 * purchase also cancels its license key.
 */
class EarningsRecorder
{
    public function __construct(private LicenseService $licenses) {}

    public function record(Invoice $invoice): void
    {
        $invoice->loadMissing('items.service.product');

        foreach ($invoice->items->whereNotNull('service_id')->groupBy('service_id') as $items) {
            $service = $items->first()->service;
            $item = $service === null ? null : $this->licenses->itemForService($service);

            if ($item === null) {
                continue;
            }

            // A renewal that cost nothing, for example with a 100% coupon, still buys a year of updates.
            $this->licenses->extendFor($service->refresh());

            if (DeveloperEarning::query()->where('invoice_id', $invoice->id)->where('marketplace_item_id', $item->id)->exists()) {
                continue;
            }

            $gross = $this->withoutIncludedTax($invoice, $items);

            if ($gross <= 0) {
                continue;
            }

            $developer = $item->developer;
            $share = $developer->share();
            $developerShare = (int) floor($gross * $share / 100);

            DeveloperEarning::create([
                'developer_id' => $developer->id,
                'marketplace_item_id' => $item->id,
                'license_id' => License::query()->where('service_id', $service->id)->value('id'),
                'invoice_id' => $invoice->id,
                'gross' => $gross,
                'developer_share' => $developerShare,
                'fee' => $gross - $developerShare,
                'share_percent' => $share,
                'currency' => $invoice->currency,
            ]);
        }
    }

    /**
     * Take back the developer's share of what a credit note gives back. Each earning on the
     * invoice shrinks by the part of the invoice credited so far, so all credit notes together
     * take back exactly what was earned. Unpaid shares go down; a share already paid out is
     * taken from the next payout. When the whole purchase is credited, its key stops working.
     */
    public function reverse(CreditNote $creditNote): void
    {
        $invoice = Invoice::query()->find($creditNote->invoice_id);

        if ($invoice === null || $invoice->total <= 0) {
            return;
        }

        $credited = min($invoice->total, (int) CreditNote::query()->where('invoice_id', $invoice->id)->where('id', '<=', $creditNote->id)->sum('total'));

        DB::transaction(function () use ($invoice, $creditNote, $credited): void {
            $earnings = DeveloperEarning::query()->where('invoice_id', $invoice->id)->whereNull('credit_note_id')->where('gross', '>', 0)->lockForUpdate()->get();

            foreach ($earnings as $earning) {
                $taken = DeveloperEarning::query()->where('invoice_id', $invoice->id)->where('marketplace_item_id', $earning->marketplace_item_id)->whereNotNull('credit_note_id')->get();

                if ($taken->contains('credit_note_id', $creditNote->id)) {
                    continue;
                }

                $gross = intdiv($earning->gross * $credited, $invoice->total) + (int) $taken->sum('gross');
                $developerShare = intdiv($earning->developer_share * $credited, $invoice->total) + (int) $taken->sum('developer_share');

                if ($gross <= 0 && $developerShare <= 0) {
                    continue;
                }

                DeveloperEarning::create([
                    'developer_id' => $earning->developer_id,
                    'marketplace_item_id' => $earning->marketplace_item_id,
                    'license_id' => $earning->license_id,
                    'invoice_id' => $invoice->id,
                    'credit_note_id' => $creditNote->id,
                    'gross' => -$gross,
                    'developer_share' => -$developerShare,
                    'fee' => -($gross - $developerShare),
                    'share_percent' => $earning->share_percent,
                    'currency' => $earning->currency,
                ]);
            }
        });

        if ($credited >= $invoice->total) {
            $this->revokePurchasedKeys($invoice, $creditNote);
        }
    }

    /**
     * Keys bought with this invoice stop working once it is refunded in full. A refunded
     * renewal leaves the key alone: the client still owns what the first payment bought.
     */
    private function revokePurchasedKeys(Invoice $invoice, CreditNote $creditNote): void
    {
        $services = InvoiceItem::query()->where('invoice_id', $invoice->id)->whereNotNull('service_id')->distinct()->pluck('service_id');

        License::query()->whereIn('service_id', $services)->where('status', License::STATUS_ACTIVE)->get()
            ->filter(fn (License $license): bool => (int) InvoiceItem::query()->where('service_id', $license->service_id)->min('invoice_id') === $invoice->id)
            ->each(function (License $license) use ($creditNote): void {
                $license->update(['status' => License::STATUS_REVOKED, 'revoked_reason' => "Refunded (credit note {$creditNote->number})"]);
                Activity::log('license.revoked', "License {$license->publicId()} revoked: the purchase was refunded", $license->service, $license->client);
            });
    }

    /**
     * What the client paid for one service's lines, without the tax included in the prices.
     *
     * @param  Collection<int, InvoiceItem>  $items
     */
    private function withoutIncludedTax(Invoice $invoice, Collection $items): int
    {
        $gross = (int) $items->sum('amount');

        if (! $invoice->tax_inclusive || $gross <= 0) {
            return $gross;
        }

        if ($invoice->tax_rate !== null) {
            return $gross - Taxes::amount((int) $items->where('taxed', true)->sum('amount'), (int) $invoice->tax_rate, true);
        }

        // An imported invoice with a tax amount but no rate: take out its share of that tax.
        return $invoice->tax > 0 && $invoice->subtotal > 0 ? $gross - (int) round($invoice->tax * $gross / $invoice->subtotal) : $gross;
    }
}
