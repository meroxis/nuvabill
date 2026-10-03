<?php

namespace App\Marketplace\Store;

use App\Enums\AutoSetup;
use App\Enums\BillingCycle;
use App\Enums\ProductType;
use App\Http\Controllers\Marketplace\MediaController;
use App\Mail\TemplateMailer;
use App\Marketplace\PackageSignature;
use App\Marketplace\PackageType;
use App\Models\Admin;
use App\Models\MarketplaceItem;
use App\Models\MarketplaceMessage;
use App\Models\MarketplaceVersion;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Support\Activity;
use App\Support\Locales;
use Illuminate\Support\Facades\DB;

/**
 * What reviewers do with a version: approve it (sign it and put it live), ask for changes, or
 * reject it. Also keeps each paid item's hidden store product in step with its prices.
 */
class ItemPublisher
{
    public const PRODUCT_GROUP = 'marketplace';

    public function __construct(
        private SigningKey $key,
        private TemplateMailer $mailer,
    ) {}

    public function approve(MarketplaceVersion $version, ?Admin $reviewer = null, ?string $message = null): void
    {
        $version->loadMissing('item.developer');
        $item = $version->item;

        DB::transaction(function () use ($version, $item, $reviewer, $message): void {
            $version->update([
                'status' => MarketplaceVersion::STATUS_APPROVED,
                // Signed with the slug inside the package, which stays the old one when the item was renamed after the upload.
                'signature' => PackageSignature::sign($version->packageSlug(), $version->version, $version->sha256, $this->key->secret()),
                'reviewer_id' => $reviewer?->id,
                'reviewed_at' => now(),
                'released_at' => now(),
            ]);

            $latest = $item->latestVersion;

            // Buyers see the permissions of the version they get, so only the newest version sets them.
            if ($latest === null || version_compare($version->version, $latest->version, '>')) {
                $item->latest_version_id = $version->id;
                $item->permissions = array_values(array_filter((array) ($version->manifest['permissions'] ?? []), 'is_string'));
            }

            if ($item->status === MarketplaceItem::STATUS_DRAFT) {
                $item->status = MarketplaceItem::STATUS_LIVE;
            }

            $item->save();
            $this->syncProduct($item);

            if (filled($message)) {
                $version->messages()->create(['author_type' => MarketplaceMessage::FROM_STAFF, 'author_id' => $reviewer?->id, 'message' => $message]);
            }
        });

        Activity::log('marketplace.approved', "Approved {$item->name} {$version->version}", actor: $reviewer);
        $this->notify($version, 'approved', $message);
    }

    public function requestChanges(MarketplaceVersion $version, Admin $reviewer, string $message): void
    {
        $version->update(['status' => MarketplaceVersion::STATUS_CHANGES, 'reviewer_id' => $reviewer->id, 'reviewed_at' => now()]);
        $version->messages()->create(['author_type' => MarketplaceMessage::FROM_STAFF, 'author_id' => $reviewer->id, 'message' => $message]);

        Activity::log('marketplace.changes', "Asked for changes to {$version->item->name} {$version->version}", actor: $reviewer);
        $this->notify($version, 'changes', $message);
    }

    public function reject(MarketplaceVersion $version, Admin $reviewer, string $message): void
    {
        $version->update(['status' => MarketplaceVersion::STATUS_REJECTED, 'reviewer_id' => $reviewer->id, 'reviewed_at' => now()]);
        $version->messages()->create(['author_type' => MarketplaceMessage::FROM_STAFF, 'author_id' => $reviewer->id, 'message' => $message]);

        Activity::log('marketplace.rejected', "Rejected {$version->item->name} {$version->version}", actor: $reviewer);
        $this->notify($version, 'rejected', $message);
    }

    /**
     * Put a developer's listing changes (name, texts, links, screenshots) live once a reviewer
     * has checked them.
     */
    public function approveListing(MarketplaceItem $item, ?Admin $reviewer = null): void
    {
        $changes = array_intersect_key((array) $item->pending_listing, array_flip(MarketplaceItem::REVIEWED_FIELDS));
        $oldScreenshots = (array) $item->screenshots;

        DB::transaction(function () use ($item, $changes): void {
            $item->update($changes + ['pending_listing' => null]);
            $this->syncProduct($item);
        });

        if (array_key_exists('screenshots', $changes)) {
            $this->deleteScreenshots($item, array_diff($oldScreenshots, (array) $item->screenshots));
        }

        Activity::log('marketplace.listing', "Approved the listing changes of {$item->name}", actor: $reviewer);
    }

    /**
     * Drop a developer's listing changes, so the listing buyers see stays as it is.
     */
    public function discardListing(MarketplaceItem $item, ?Admin $reviewer = null): void
    {
        $pending = (array) $item->pending_listing;
        $item->update(['pending_listing' => null]);

        $this->deleteScreenshots($item, array_diff((array) ($pending['screenshots'] ?? []), (array) $item->screenshots));
        Activity::log('marketplace.listing', "Turned down the listing changes of {$item->name}", actor: $reviewer);
    }

    /**
     * @param  array<int, mixed>  $files
     */
    private function deleteScreenshots(MarketplaceItem $item, array $files): void
    {
        foreach ($files as $file) {
            if (is_string($file) && $file !== '') {
                @unlink(MediaController::path($item->slug, basename($file)));
            }
        }
    }

    /**
     * A paid item is sold as a hidden product: the price once, then the update price every year.
     * Its "domain" is the website the license is for.
     */
    public function syncProduct(MarketplaceItem $item): void
    {
        if ($item->isFree()) {
            if ($item->product !== null) {
                $item->product->update(['is_visible' => false]);
            }

            return;
        }

        $group = ProductGroup::query()->firstOrCreate(['slug' => self::PRODUCT_GROUP], [
            'name' => __('Marketplace'),
            'description' => __('Themes, order forms and extensions. Sold as license keys.'),
            'is_visible' => false,
            'sort_order' => 99,
        ]);

        $product = $item->product ?? new Product(['slug' => 'marketplace-'.$item->slug]);
        $product->fill([
            'product_group_id' => $group->id,
            'name' => $item->name,
            'type' => ProductType::Other,
            'description' => (string) $item->summary,
            'is_visible' => $item->status === MarketplaceItem::STATUS_LIVE,
            'requires_domain' => true,
            'server_module' => null,
            'auto_setup' => AutoSetup::OnPayment,
            'module_config' => ['marketplace_item' => $item->slug],
        ])->save();

        $currency = $item->currency;
        $product->prices()->where('currency', $currency)->delete();

        // A license for Nuvabill itself is always sold by the year. Without a renewal price, each year costs the first year's price.
        $renewal = $item->type === PackageType::License && $item->update_price <= 0 ? $item->price : $item->update_price;

        if ($renewal > 0 && $renewal <= $item->price) {
            $product->prices()->create([
                'currency' => $currency,
                'billing_cycle' => BillingCycle::Annually,
                'price' => $renewal,
                'setup_fee' => $item->price - $renewal,
            ]);
        } else {
            $product->prices()->create(['currency' => $currency, 'billing_cycle' => BillingCycle::OneTime, 'price' => $item->price, 'setup_fee' => 0]);
        }

        if ($item->product_id !== $product->id) {
            $item->update(['product_id' => $product->id]);
        }
    }

    private function notify(MarketplaceVersion $version, string $outcome, ?string $message): void
    {
        $client = $version->item->developer->client;

        if ($client === null) {
            return;
        }

        $this->mailer->send('marketplace.review', $client, [
            'client' => TemplateMailer::clientContext($client),
            'item' => ['name' => $version->item->name, 'version' => $version->version],
            'review' => [
                'outcome' => Locales::in(Locales::forClient($client), fn (): string => match ($outcome) {
                    'approved' => __('approved and live in the marketplace'),
                    'changes' => __('waiting for a few changes'),
                    default => __('not accepted'),
                }),
                'message' => (string) $message,
            ],
            'developer_url' => route('developer.items.show', $version->item),
        ]);
    }
}
