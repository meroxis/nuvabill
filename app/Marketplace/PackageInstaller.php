<?php

namespace App\Marketplace;

use App\Extensions\ExtensionManager;
use App\Extensions\ExtensionManifest;
use App\Models\Extension;
use App\Models\MarketplaceInstall;
use App\Support\Activity;
use App\Support\Settings;
use App\Support\Themes;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use SplFileInfo;
use Throwable;

/**
 * Installs, updates and removes marketplace packages: download, check the checksum and the
 * store's signature, unpack into a temporary folder, then swap it into place. When anything
 * fails the old version stays (or comes back) as it was, and so does its install record.
 *
 * Database changes run after the files are in place, while the old files are still kept aside.
 * When they fail, the old files come back. Changes the database made before the error stay,
 * because MySQL cannot undo table changes; the next try of the update skips the steps that finished.
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
        private MarketplaceClient $client,
    ) {}

    /**
     * @param  array{slug: string, type: string, version: string, url: string, sha256: string, signature: string}  $download
     * @param  string|null  $requested  The slug staff asked the store for. The store's answer must be about it.
     */
    public function install(array $download, ?string $licenseKey = null, ?string $requested = null): MarketplaceInstall
    {
        $this->steps = [];
        $slug = $download['slug'];
        $type = PackageType::tryFrom($download['type']) ?? throw new RuntimeException(__('Unknown package type.'));
        // The marketplace's key, and the other keys this copy trusts while the marketplace changes its key.
        $publicKeys = array_values(array_unique(array_filter([
            (string) config('nuvabill.marketplace.public_key'),
            ...array_map('strval', (array) config('nuvabill.marketplace.public_keys')),
        ])));

        if (! preg_match('/^[a-z0-9][a-z0-9_-]{1,63}$/', $slug) || ! str_starts_with($download['url'], 'https://')) {
            throw new RuntimeException(__('The marketplace sent an invalid download.'));
        }

        if ($publicKeys === []) {
            throw new RuntimeException(__('This copy of Nuvabill has no marketplace key, so packages cannot be checked. Update Nuvabill first.'));
        }

        $this->checkAnswer($download, $requested);

        $work = storage_path('app/marketplace/'.Str::random(12));
        File::ensureDirectoryExists($work);
        $zipPath = $work.'/package.zip';
        $archive = null;

        try {
            // A site connected to the marketplace sends its key, so Cloudflare lets the download through.
            $response = $this->client->authorize(Http::timeout(180)->withOptions(['sink' => $zipPath]), $download['url'])->get($download['url']);

            if (MarketplaceClient::isChallenge($response)) {
                Cache::put(SiteConnection::BLOCKED_CACHE, true, now()->addDay());

                throw new RuntimeException(MarketplaceClient::blockedMessage());
            }

            if ($response->failed() || ! is_file($zipPath)) {
                throw new RuntimeException(__('The package could not be downloaded. Try again later.'));
            }

            $this->steps[] = __('Downloaded :name :version (:size)', ['name' => $slug, 'version' => $download['version'], 'size' => $this->size((int) filesize($zipPath))]);

            if (! hash_equals(strtolower($download['sha256']), (string) hash_file('sha256', $zipPath))
                || ! collect($publicKeys)->contains(fn (string $key): bool => PackageSignature::verify($slug, $download['version'], $download['sha256'], $download['signature'], $key))) {
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

            // From here on only the unpacked files count, because they are the ones that will run.
            $manifest = $this->unpackedManifest($unpacked, $type, $slug, $download['version']);

            // Database changes only come with extensions that say so, because staff see that before installing.
            $hasMigrations = $this->hasMigrations($unpacked);

            if ($hasMigrations && (! $type->isExtension() || ! in_array('database', (array) ($manifest['permissions'] ?? []), true))) {
                throw new RuntimeException(__('The package changes the database but does not ask for the database permission, so it was not installed.'));
            }

            if ($type->isExtension()) {
                ExtensionManifest::fromFile($unpacked.'/extension.json');
            }

            $backup = $this->swapInto($unpacked, $type->directory($slug), $work);
            $this->steps[] = __('Installed the files');
            $install = MarketplaceInstall::query()->firstOrNew(['slug' => $slug]);
            $previous = $install->exists ? $install->version : null;

            if ($hasMigrations) {
                try {
                    $this->migrate($type->directory($slug).DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations');
                } catch (Throwable $exception) {
                    report($exception);
                    $this->putBack($type->directory($slug), $backup);
                    Activity::log('marketplace.failed', "Database changes of {$slug} {$download['version']} failed, so ".($previous ? "version {$previous} was put back" : 'it was not installed').": {$exception->getMessage()}");

                    throw new RuntimeException($previous
                        ? __('The update could not change the database, so version :version was put back: :reason', ['version' => $previous, 'reason' => $exception->getMessage()])
                        : __('The package could not make its database tables, so it was not installed: :reason', ['reason' => $exception->getMessage()]));
                }
            }
        } finally {
            $archive = null;
            File::deleteDirectory($work);
        }

        $this->refreshCode();

        $install->fill([
            'type' => $type,
            'name' => $this->nameFromDisk($type, $slug),
            'version' => $download['version'],
            'license_key' => $licenseKey ?: $install->license_key,
            'license_status' => filled($licenseKey ?: $install->license_key) ? MarketplaceInstall::LICENSE_VALID : null,
            'license_checked_at' => now(),
            'file_hashes' => MarketplaceInstall::fingerprint($type->directory($slug)),
        ])->save();
        Cache::forget(LicenseChecker::UNLICENSED_CACHE_KEY);

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
        Cache::forget(LicenseChecker::UNLICENSED_CACHE_KEY);

        Activity::log('marketplace.removed', "Removed {$install->name} from the marketplace installs");
    }

    /**
     * The store's answer is not signed, only the package is, and every package the store ever
     * signed stays valid. So the answer must be about the item staff asked for, and it may
     * never take an installed item back to an older version.
     *
     * @param  array{slug: string, type: string, version: string, url: string, sha256: string, signature: string}  $download
     */
    private function checkAnswer(array $download, ?string $requested): void
    {
        $slug = $download['slug'];
        $installed = MarketplaceInstall::query()->where('slug', $slug)->first();

        // An item installed before the store renamed it keeps its old slug here, so that slug is fine too.
        // An update of an item installed under the slug that was asked for must stay that item.
        if ($requested !== null && $requested !== $slug
            && ($installed === null || MarketplaceInstall::query()->where('slug', $requested)->exists())) {
            Activity::log('marketplace.rejected', "The marketplace answered the request for {$requested} with {$slug} {$download['version']}, which was not installed");

            throw new RuntimeException(__('The marketplace sent a different package than the one you asked for, so it was not installed.'));
        }

        if ($installed !== null && version_compare($download['version'], (string) $installed->version, '<')) {
            Activity::log('marketplace.rejected', "The marketplace sent {$slug} {$download['version']}, older than the installed {$installed->version}, so it was not installed");

            throw new RuntimeException(__('The marketplace sent version :new, which is older than version :installed installed here, so nothing was changed.', ['new' => $download['version'], 'installed' => $installed->version]));
        }
    }

    /**
     * The manifest as it was unpacked. It must say the same as the package the store signed.
     *
     * @return array<string, mixed>
     */
    private function unpackedManifest(string $directory, PackageType $type, string $slug, string $version): array
    {
        $manifest = json_decode((string) @file_get_contents($directory.DIRECTORY_SEPARATOR.$type->manifestFile()), true);

        $found = match (true) {
            ! is_array($manifest) => null,
            ! $type->isExtension() => $type,
            default => PackageType::tryFrom((string) ($manifest['type'] ?? '')),
        };

        if ($found !== $type || ($manifest['slug'] ?? null) !== $slug || ($manifest['version'] ?? null) !== $version) {
            throw new RuntimeException(__('The package does not match what the marketplace said it is.'));
        }

        return $manifest;
    }

    /**
     * Whether the unpacked package brings database changes.
     */
    private function hasMigrations(string $directory): bool
    {
        $path = $directory.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations';

        return is_dir($path)
            && collect(File::allFiles($path, true))->contains(fn (SplFileInfo $file): bool => strtolower($file->getExtension()) === 'php');
    }

    /**
     * Make or change the extension's own tables. This runs after every install and update, so the
     * changes of a new version are applied too; migrations that already ran are skipped.
     */
    private function migrate(string $path): void
    {
        Artisan::call('migrate', ['--path' => $path, '--realpath' => true, '--force' => true]);

        $this->steps[] = __('Made the database tables');
    }

    /**
     * Take the new files out again and bring the old ones back, if there were any.
     */
    private function putBack(string $destination, ?string $backup): void
    {
        File::deleteDirectory($destination);

        if ($backup !== null && ! File::moveDirectory($backup, $destination)) {
            File::copyDirectory($backup, $destination);
        }

        $this->refreshCode();
    }

    private function refreshCode(): void
    {
        $this->extensions->refresh();

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
    }

    /**
     * Move the new files in. An existing folder is moved aside first and put back if the move fails.
     *
     * @return string|null Where the old folder is kept until the update is done, or null for a new install.
     */
    private function swapInto(string $source, string $destination, string $work): ?string
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

        return $backup;
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
