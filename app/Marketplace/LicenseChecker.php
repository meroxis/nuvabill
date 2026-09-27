<?php

namespace App\Marketplace;

use App\Models\MarketplaceInstall;
use App\Support\Activity;
use App\Support\Themes;
use Illuminate\Support\Collection;

/**
 * Checks the license keys of paid themes, order forms and extensions with the marketplace store,
 * about once a day. A key that is revoked, used on another site, or missing marks the item as
 * unlicensed: staff see a warning and it gets no updates. If the store cannot be reached, the
 * last good answer counts for a grace period, so a store outage never breaks a paying site.
 */
class LicenseChecker
{
    public const GRACE_DAYS = 14;

    public function __construct(
        private MarketplaceClient $client,
        private Themes $themes,
    ) {}

    public function check(MarketplaceInstall $install): void
    {
        if (blank($install->license_key)) {
            $install->update([
                'license_status' => $this->isPaid($install->type, $install->slug) ? MarketplaceInstall::LICENSE_INVALID : null,
                'license_message' => $this->isPaid($install->type, $install->slug) ? __('No license key. Enter the key from your purchase.') : null,
                'license_checked_at' => now(),
            ]);

            return;
        }

        $answer = $this->client->checkLicense($install->slug, (string) $install->license_key, $install->version);

        if ($answer === null) {
            $expired = $install->license_checked_at === null || $install->license_checked_at->lt(now()->subDays(self::GRACE_DAYS));

            if ($expired && $install->license_status === MarketplaceInstall::LICENSE_VALID) {
                $install->update(['license_status' => MarketplaceInstall::LICENSE_UNKNOWN, 'license_message' => __('The license could not be checked for :days days.', ['days' => self::GRACE_DAYS])]);
            }

            return;
        }

        $was = $install->license_status;

        $install->update([
            'license_status' => $answer['valid'] ? MarketplaceInstall::LICENSE_VALID : MarketplaceInstall::LICENSE_INVALID,
            'license_message' => $answer['valid'] ? null : ($answer['message'] ?: __('This license key is not valid for this site.')),
            'license_checked_at' => now(),
        ]);

        if ($was !== MarketplaceInstall::LICENSE_INVALID && $install->hasInvalidLicense()) {
            Activity::log('marketplace.license', "The license for {$install->name} is not valid: {$install->license_message}");
        }
    }

    public function checkAll(): void
    {
        MarketplaceInstall::query()->each(fn (MarketplaceInstall $install) => $this->check($install));
    }

    /**
     * Paid packages on disk with no marketplace install: copied in by hand, so never licensed here.
     *
     * @return Collection<int, array{slug: string, name: string}>
     */
    public function unlicensedCopies(): Collection
    {
        $installed = MarketplaceInstall::query()->pluck('slug')->all();
        $found = collect();

        foreach ([[PackageType::Theme, $this->themes->path()], [PackageType::OrderForm, $this->themes->orderFormPath()]] as [$type, $base]) {
            foreach (glob($base.'/*/'.$type->manifestFile()) ?: [] as $file) {
                $data = json_decode((string) file_get_contents($file), true);
                $slug = basename(dirname($file));

                if (is_array($data) && ($data['paid'] ?? false) === true && ! in_array($slug, $installed, true)) {
                    $found->push(['slug' => $slug, 'name' => (string) ($data['name'] ?? $slug)]);
                }
            }
        }

        foreach (glob(config('nuvabill.extensions_path').'/*/*/extension.json') ?: [] as $file) {
            $data = json_decode((string) file_get_contents($file), true);

            if (is_array($data) && ($data['paid'] ?? false) === true && ! in_array((string) ($data['slug'] ?? ''), $installed, true)) {
                $found->push(['slug' => (string) $data['slug'], 'name' => (string) ($data['name'] ?? $data['slug'])]);
            }
        }

        return $found;
    }

    private function isPaid(PackageType $type, string $slug): bool
    {
        $data = json_decode((string) @file_get_contents($type->directory($slug).'/'.$type->manifestFile()), true);

        return is_array($data) && ($data['paid'] ?? false) === true;
    }
}
