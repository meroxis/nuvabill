<?php

namespace App\Marketplace\Store;

use App\Models\DeveloperEarning;
use App\Models\Invoice;
use App\Models\License;

/**
 * When an invoice with marketplace purchases is paid, each item's developer gets their share
 * (83% by default) of what the client paid for it, including yearly update renewals.
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

            if ($item === null || DeveloperEarning::query()->where('invoice_id', $invoice->id)->where('marketplace_item_id', $item->id)->exists()) {
                continue;
            }

            $gross = (int) $items->sum('amount');

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

            $this->licenses->extendFor($service->refresh());
        }
    }
}
