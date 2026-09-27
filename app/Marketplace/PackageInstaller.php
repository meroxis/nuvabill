<?php

namespace App\Marketplace;

use App\Extensions\ExtensionManager;
use App\Extensions\ExtensionManifest;
use App\Models\Extension;
use App\Models\MarketplaceInstall;
use App\Support\Activity;
use App\Support\Settings;
use App\Support\Themes;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Installs, updates and removes marketplace packages: download, check the checksum and the
 * store's signature, unpack into a temporary folder, then swap it into place. When anything
 * fails the old version stays (or comes back) as it was.
 */
class PackageInstaller
{
    /**
     * Steps of the last install, for showing staff what happened.
     *
     * @var list<string>
     */
    private array $steps = [];

    public function __construct(
        private ExtensionManager $extensions,
        private Themes $themes,
        private Settings $settings,
    ) {}

    /**
     * @param  array{slug: string, type: string, version: string, url: string, sha256: string, signature: string}  $download
     */
    public function install(array $download, ?string $licenseKey = null): MarketplaceInstall
    {
        $this->steps = [];
        $slug = $download['slug'];
        $type = PackageType::tryFrom($download['type']) ?? throw new RuntimeException(__('Unknown package type.'));
        $publicKey = (string) config('nuvabill.marketplace.public_key');

        if (! preg_match('/^[a-z0-9][a-z0-9_-]{1,63}$/', $slug) || ! str_starts_with($download['url'], 'https://')) {
            throw new RuntimeException(__('The marketplace sent an invalid download.'));
        }

        if ($publicKey === '') {
            throw new RuntimeException(__('This copy of Nuvabill has no marketplace key, so packages cannot be checked. Update Nuvabill first.'));
        }

        $work = storage_path('app/marketplace/'.Str::random(12));
        File::ensureDirectoryExists($work);
        $zipPath = $work.'/package.zip';
        $archive = null;

        try {
            $response = Http::timeout(180)->withOptions(['sink' => $zipPath])->get($download['url']);

            if ($response->failed() || ! is_file($zipPath)) {
                throw new RuntimeException(__('The package could not be downloaded. Try again later.'));
            }

            $this->steps[] = __('Downloaded :name :version (:size)', ['name' => $slug, 'version' => $download['version'], 'size' => $this->size((int) filesize($zipPath))]);

            if (! hash_equals(strtolower($download['sha256']), (string) hash_file('sha256', $zipPath))
                || ! PackageSignature::verify($slug, $download['version'], $download['sha256'], $download['signature'], $publicKey)) {
                Activity::log('marketplace.rejected', "Package {$slug} {$download['version']} failed the signature check and was not installed");

                throw new RuntimeException(__('The package failed the security check and was not installed.'));
            }

            $this->steps[] = __('Checked the signature: signed by the marketplace and not changed');

            $archive = new PackageArchive($zipPath);

            if ($archive->slug() !== $slug || $archive->version() !== $download['version'] || $archive->type() !== $type) {
                throw new RuntimeException(__('The package does not match what the marketplace said it is.'));
            }

            $requires = (string) ($archive->manifest()['requires'] ?? '');

            if ($requires !== '' && str_starts_with($requires, '>=') && version_compare((string) config('nuvabill.version'), trim(substr($requires, 2)), '<')) {
                throw new RuntimeException(__(':name needs Nuvabill :version or newer. Update Nuvabill first.', ['name' => $archive->name(), 'version' => trim(substr($requires, 2))]));
            }

            $unpacked = $work.'/unpacked';
            $archive->extractTo($unpacked);
            $archive = null;

            if ($type->isExtension()) {
                ExtensionManifest::fromFile($unpacked.'/extension.json');
            }

            $this->swapInto($unpacked, $type->directory($slug), $work);
            $this->steps[] = __('Installed the files');
        } finally {
            $archive = null;
            File::deleteDirectory($work);
        }

        $this->extensions->refresh();

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        $install = MarketplaceInstall::query()->firstOrNew(['slug' => $slug]);
        $previous = $install->exists ? $install->version : null;
        $install->fill([
            'type' => $type,
            'name' => $this->nameFromDisk($type, $slug),
            'version' => $download['version'],
            'license_key' => $licenseKey ?: $install->license_key,
            'license_status' => filled($licenseKey ?: $install->license_key) ? MarketplaceInstall::LICENSE_VALID : null,
            'license_checked_at' => now(),
        ])->save();

        Activity::log($previous ? 'marketplace.updated' : 'marketplace.installed', $previous
            ? "Updated {$install->name} from {$previous} to {$install->version}"
            : "Installed {$install->name} {$install->version} from the marketplace");

        return $install;
    }

    /**
     * @return list<string>
     */
    public function steps(): array
    {
        return $this->steps;
    }

    /**
     * Remove a package that came from the marketplace. Built-in extensions cannot be removed.
     */
    public function uninstall(MarketplaceInstall $install): void
    {
        $type = $install->type;

        if ($type === PackageType::Theme && $this->themes->saved() === $install->slug) {
            $this->settings->set('theme.active', Themes::DEFAULT);
        }

        if ($type === PackageType::OrderForm && $this->themes->savedOrderForm() === $install->slug) {
            $this->settings->set('orderform.active', Themes::STANDARD_ORDER_FORM);
        }

        if ($type->isExtension()) {
            Extension::query()->where('slug', $install->slug)->update(['is_enabled' => false]);
        }

        File::deleteDirectory($type->directory($install->slug));
        $install->delete();
        $this->extensions->refresh();

        Activity::log('marketplace.removed', "Removed {$install->name} from the marketplace installs");
    }

    /**
     * Move the new files in. An existing folder is moved aside first and put back if the move fails.
     */
    private function swapInto(string $source, string $destination, string $work): void
    {
        File::ensureDirectoryExists(dirname($destination));
        $backup = null;

        if (is_dir($destination)) {
            $backup = $work.'/previous';

            if (! File::moveDirectory($destination, $backup)) {
                throw new RuntimeException(__('The current version could not be moved aside. Check the folder permissions.'));
            }
        }

        try {
            if (! File::moveDirectory($source, $destination) && ! File::copyDirectory($source, $destination)) {
                throw new RuntimeException(__('The new files could not be copied. Check the folder permissions.'));
            }
        } catch (Throwable $exception) {
            File::deleteDirectory($destination);

            if ($backup !== null) {
                File::moveDirectory($backup, $destination);
            }

            throw $exception;
        }
    }

    private function nameFromDisk(PackageType $type, string $slug): string
    {
        $data = json_decode((string) @file_get_contents($type->directory($slug).'/'.$type->manifestFile()), true);

        return is_array($data) && is_string($data['name'] ?? null) ? $data['name'] : $slug;
    }

    private function size(int $bytes): string
    {
        return $bytes >= 1048576 ? round($bytes / 1048576, 1).' MB' : max(1, (int) round($bytes / 1024)).' KB';
    }
}
