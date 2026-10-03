<?php

namespace App\Console\Commands;

use App\Http\Controllers\Marketplace\MediaController;
use App\Models\MarketplaceItem;
use App\Models\Product;
use App\Support\Activity;
use App\Support\Settings;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

#[Signature('nuvabill:marketplace-rename {from : The current slug} {to : The new slug}')]
#[Description('Give a marketplace item a new slug on the store. Licenses stay with the item, and the old slug keeps working for license checks, downloads and links.')]
class MarketplaceRename extends Command
{
    public function handle(Settings $settings): int
    {
        $from = (string) $this->argument('from');
        $to = (string) $this->argument('to');

        if (! config('nuvabill.marketplace.store')) {
            $this->components->error('This only runs on the marketplace store.');

            return self::FAILURE;
        }

        if (! preg_match('/^[a-z0-9][a-z0-9_-]{1,63}$/', $to)) {
            $this->components->error('The new slug may only use a-z, 0-9, "-" and "_".');

            return self::FAILURE;
        }

        $item = MarketplaceItem::query()->where('slug', $from)->first();

        if ($item === null) {
            $this->components->error("There is no item with the slug {$from}.");

            return self::FAILURE;
        }

        if (MarketplaceItem::query()->where('slug', $to)->exists()) {
            $this->components->error("The slug {$to} is already used.");

            return self::FAILURE;
        }

        // An old slug of another item stays with that item. Renaming an item back to its own old slug is fine.
        $aliasOf = MarketplaceItem::renamedTo($to);

        if ($aliasOf !== null && $aliasOf !== $from) {
            $this->components->error("The slug {$to} is an old slug of {$aliasOf}, so sites that installed {$to} would get this item.");

            return self::FAILURE;
        }

        // This item took a slug another item had before. That slug keeps pointing to the other item.
        $keepsOldSlug = MarketplaceItem::renamedTo($from) === null;

        DB::transaction(function () use ($item, $from, $to, $settings, $keepsOldSlug): void {
            $item->update(['slug' => $to]);

            // The store's own product for the item finds it by slug.
            Product::query()->get()->each(function (Product $product) use ($from, $to): void {
                $config = (array) $product->module_config;

                if (($config['marketplace_item'] ?? null) === $from) {
                    $product->update(['module_config' => ['marketplace_item' => $to] + $config]);
                }
            });

            $renamed = (array) $settings->get('marketplace.renamed_items', []);

            // An item renamed twice: its older names point to the newest one.
            foreach ($renamed as $old => $new) {
                if ($new === $from) {
                    $renamed[$old] = $to;
                }
            }

            if ($keepsOldSlug) {
                $renamed[$from] = $to;
            }

            unset($renamed[$to]);
            $settings->set('marketplace.renamed_items', $renamed);
        });

        if (is_dir(MediaController::path($from)) && ! is_dir(MediaController::path($to))) {
            File::moveDirectory(MediaController::path($from), MediaController::path($to));
        }

        Activity::log('marketplace.renamed', "Marketplace item {$item->name}: slug {$from} is now {$to}");
        $this->components->info($keepsOldSlug
            ? "{$item->name} is now at {$to}. Licenses stay valid, and {$from} keeps working for existing sites and links."
            : "{$item->name} is now at {$to}. {$from} stays with the item that had it first.");

        return self::SUCCESS;
    }
}
