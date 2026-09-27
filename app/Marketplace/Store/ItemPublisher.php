<?php

namespace App\Marketplace\Store;

use App\Enums\AutoSetup;
use App\Enums\BillingCycle;
use App\Enums\ProductType;
use App\Mail\TemplateMailer;
use App\Marketplace\PackageSignature;
use App\Models\Admin;
use App\Models\MarketplaceItem;
use App\Models\MarketplaceMessage;
use App\Models\MarketplaceVersion;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Support\Activity;
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
                'signature' => PackageSignature::sign($item->slug, $version->version, $version->sha256, $this->key->secret()),
                'reviewer_id' => $reviewer?->id,
                'reviewed_at' => now(),
                'released_at' => now(),
            ]);

            $latest = $item->latestVersion;

            if ($latest === null || version_compare($version->version, $latest->version, '>')) {
                $item->latest_version_id = $version->id;
            }

            $item->permissions = array_values(array_filter((array) ($version->manifest['permissions'] ?? []), 'is_string'));

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

        if ($item->update_price > 0 && $item->update_price <= $item->price) {
            $product->prices()->create([
                'currency' => $currency,
                'billing_cycle' => BillingCycle::Annually,
                'price' => $item->update_price,
                'setup_fee' => $item->price - $item->update_price,
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
                'outcome' => match ($outcome) {
                    'approved' => __('approved and live in the marketplace'),
                    'changes' => __('waiting for a few changes'),
                    default => __('not accepted'),
                },
                'message' => (string) $message,
            ],
            'developer_url' => route('developer.items.show', $version->item),
        ]);
    }
}
