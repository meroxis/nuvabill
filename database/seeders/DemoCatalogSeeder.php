<?php

namespace Database\Seeders;

use App\Enums\AutoSetup;
use App\Enums\BillingCycle;
use App\Enums\ProductType;
use App\Models\Product;
use App\Models\ProductGroup;
use Illuminate\Database\Seeder;

/**
 * Example hosting plans the installer can add so the store is not empty.
 */
class DemoCatalogSeeder extends Seeder
{
    public function run(string $currency = 'USD'): void
    {
        $group = ProductGroup::query()->firstOrCreate(['slug' => 'web-hosting'], [
            'name' => 'Web hosting',
            'description' => 'Fast cPanel hosting with free SSL and daily backups.',
            'is_visible' => true,
        ]);

        $plans = [
            ['Starter', 'starter', 399, "1 website\n10 GB NVMe storage\nFree SSL certificates\n5 email accounts\nDaily backups"],
            ['Business', 'business', 899, "10 websites\n50 GB NVMe storage\nFree SSL certificates\nUnlimited email accounts\nDaily backups\nPriority support"],
            ['Pro', 'pro', 1599, "Unlimited websites\n150 GB NVMe storage\nFree SSL certificates\nUnlimited email accounts\nHourly backups\nPriority support"],
        ];

        foreach ($plans as $order => [$name, $slug, $monthly, $features]) {
            $product = Product::query()->firstOrCreate(['slug' => $slug], [
                'product_group_id' => $group->id,
                'name' => $name,
                'type' => ProductType::Hosting,
                'description' => $features,
                'is_visible' => true,
                'requires_domain' => true,
                'server_module' => 'cpanel',
                'module_config' => ['package' => $slug],
                'auto_setup' => AutoSetup::OnPayment,
                'sort_order' => $order,
            ]);

            foreach ([[BillingCycle::Monthly, 1], [BillingCycle::Annually, 10]] as [$cycle, $months]) {
                $product->prices()->firstOrCreate(
                    ['currency' => $currency, 'billing_cycle' => $cycle],
                    ['price' => $monthly * $months, 'setup_fee' => 0],
                );
            }
        }
    }
}
