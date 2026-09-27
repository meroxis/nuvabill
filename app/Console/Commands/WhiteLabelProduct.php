<?php

namespace App\Console\Commands;

use App\Marketplace\PackageType;
use App\Marketplace\Store\ItemPublisher;
use App\Models\Developer;
use App\Models\MarketplaceItem;
use App\Support\Money;
use App\Support\WhiteLabel;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('nuvabill:white-label-product {price : Price for the first year, for example 99} {renewal? : Price for each next year; the first year price if empty}')]
#[Description('On the marketplace store: create or change the White-label license that removes the "Powered by Nuvabill" credit')]
class WhiteLabelProduct extends Command
{
    public function handle(ItemPublisher $publisher): int
    {
        if (! config('nuvabill.marketplace.store')) {
            $this->components->error('This is not the marketplace store (NUVABILL_MARKETPLACE_STORE is not true).');

            return self::FAILURE;
        }

        $price = Money::toMinor((string) $this->argument('price'));
        $renewal = $this->argument('renewal') !== null ? Money::toMinor((string) $this->argument('renewal')) : $price;

        if ($price <= 0 || $renewal <= 0 || $renewal > $price) {
            $this->components->error('Both prices must be above zero, and the renewal cannot cost more than the first year.');

            return self::FAILURE;
        }

        $developer = Developer::query()->firstOrCreate(['slug' => 'nuvabill'], [
            'name' => 'Nuvabill',
            'is_official' => true,
            'is_verified' => true,
            'status' => Developer::STATUS_ACTIVE,
        ]);

        $item = MarketplaceItem::query()->updateOrCreate(['slug' => WhiteLabel::SLUG], [
            'developer_id' => $developer->id,
            'type' => PackageType::License,
            'name' => 'White-label license',
            'summary' => 'Remove the "Powered by Nuvabill" credit from your client area, invoices and emails.',
            'price' => $price,
            'update_price' => $renewal,
            'currency' => (string) setting('billing.currency'),
            'status' => MarketplaceItem::STATUS_LIVE,
        ]);

        $publisher->syncProduct($item);
        $this->components->info('White-label license: '.money($price, $item->currency).' the first year, then '.money($renewal, $item->currency).' a year. Sold at '.route('marketplace.white-label'));

        return self::SUCCESS;
    }
}
