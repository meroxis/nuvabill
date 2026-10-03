<?php

namespace App\Health\Checks;

use App\Health\CheckGroup;
use App\Health\CheckResult;
use App\Health\CoreFiles;
use App\Marketplace\LicenseChecker;
use App\Marketplace\MarketplaceClient;
use App\Marketplace\PackageType;
use App\Models\MarketplaceInstall;
use App\Support\Demo;
use App\Support\Themes;
use Illuminate\Support\Collection;

/**
 * Themes, order forms and extensions: where they came from, their licenses and updates, and
 * themes that are no longer used.
 */
class ExtensionChecks extends CheckGroup
{
    public function __construct(
        private readonly MarketplaceClient $marketplace,
        private readonly LicenseChecker $licenses,
        private readonly Themes $themes,
        private readonly CoreFiles $coreFiles,
    ) {}

    public function key(): string
    {
        return 'extensions';
    }

    public function section(): string
    {
        return self::SECURITY;
    }

    public function title(): string
    {
        return 'Extensions and themes';
    }

    public function description(): string
    {
        return 'Where your themes and extensions came from, their licenses and their updates.';
    }

    public function icon(): string
    {
        return 'puzzle';
    }

    public function run(): array
    {
        $installs = MarketplaceInstall::query()->orderBy('name')->get();

        return [
            $this->origin($installs),
            $this->licenses($installs),
            $this->updates($installs),
            $this->unusedThemes(),
        ];
    }

    /**
     * Packages that are neither part of Nuvabill nor installed from the marketplace were copied in
     * by hand: nobody reviewed or signed them. A folder only counts as installed when both its type
     * and its name match: an add-on folder named like an installed theme was still copied by hand.
     *
     * @param  Collection<int, MarketplaceInstall>  $installs
     */
    private function origin(Collection $installs): CheckResult
    {
        $check = $this->check('extensions.origin', 'Every theme and extension came from Nuvabill or the marketplace');
        $manifest = $this->coreFiles->manifest();

        if (Demo::isEnabled()) {
            return $check->skipped('The public demo carries packages for previews on purpose.');
        }

        if ($manifest['state'] !== CoreFiles::STATE_OK) {
            return $check->skipped('This needs the signed file list that comes with release downloads of Nuvabill.');
        }

        $builtIn = [];

        foreach (array_keys($manifest['files']) as $path) {
            if (preg_match('#^(extensions/[^/]+/[^/]+|themes/[^/]+|orderforms/[^/]+)/#', $path, $match)) {
                $builtIn[$match[1]] = true;
            }
        }

        $installed = $installs->toBase()
            ->map(fn (MarketplaceInstall $install): ?string => match (true) {
                $install->type === PackageType::Theme => 'themes/'.$install->slug,
                $install->type === PackageType::OrderForm => 'orderforms/'.$install->slug,
                $install->type?->isExtension() === true => 'extensions/'.$install->type->value.'s/'.$install->slug,
                default => null,
            })
            ->filter()->flip()->all();

        $root = $this->coreFiles->root();
        $byHand = [];

        foreach ([...glob($root.'/extensions/*/*', GLOB_ONLYDIR) ?: [], ...glob($root.'/themes/*', GLOB_ONLYDIR) ?: [], ...glob($root.'/orderforms/*', GLOB_ONLYDIR) ?: []] as $folder) {
            $relative = ltrim(str_replace('\\', '/', substr($folder, strlen($root))), '/');

            if (! isset($builtIn[$relative]) && ! isset($installed[$relative])) {
                $byHand[] = $relative;
            }
        }

        if ($byHand === []) {
            return $check->passed();
        }

        return $check->warning(':count were copied in by hand', ['count' => count($byHand)],
            advice: 'Nobody reviewed or signed these, and Nuvabill cannot tell if they were changed. Keep them only if you know where they came from.',
            items: array_map(fn (string $folder): array => ['label' => $folder.'/', 'mono' => true, 'status' => 'warning'], $byHand),
        );
    }

    /**
     * @param  Collection<int, MarketplaceInstall>  $installs
     */
    private function licenses($installs): CheckResult
    {
        $check = $this->check('extensions.licenses', 'Every paid package has a license');
        $invalid = $installs->toBase()->filter(fn (MarketplaceInstall $install): bool => $install->hasInvalidLicense())
            ->map(fn (MarketplaceInstall $install): array => ['label' => $install->name, 'value' => (string) $install->license_message, 'status' => 'warning']);
        // The public demo shows paid themes for previews on purpose.
        $copies = Demo::isEnabled() ? collect() : $this->licenses->unlicensedCopies()
            ->map(fn (array $copy): array => ['label' => $copy['name'], 'value' => __('Not installed from the marketplace'), 'status' => 'warning']);
        $problems = $invalid->merge($copies)->values();

        if ($problems->isEmpty()) {
            return $check->passed();
        }

        return $check->warning(':count packages have no valid license', ['count' => $problems->count()],
            advice: 'Paid themes and extensions stop getting updates and security fixes without a license.',
            items: $problems->all(),
            link: $this->link('admin.marketplace.index', 'Open the marketplace'),
        );
    }

    /**
     * @param  Collection<int, MarketplaceInstall>  $installs
     */
    private function updates($installs): CheckResult
    {
        $check = $this->check('extensions.updates', 'No marketplace updates are waiting', weight: 1);
        $catalog = collect($this->marketplace->cachedCatalog() ?? [])->keyBy('slug');

        if ($catalog->isEmpty() && $installs->isNotEmpty()) {
            return $check->skipped('The marketplace has not been reached yet.');
        }

        $waiting = $installs->filter(fn (MarketplaceInstall $install): bool => isset($catalog[$install->slug]['version'])
            && version_compare((string) $catalog[$install->slug]['version'], $install->version, '>'));

        if ($waiting->isEmpty()) {
            return $check->passed();
        }

        return $check->warning(':count updates are waiting', ['count' => $waiting->count()],
            advice: 'Updates often fix problems. Install them from the marketplace.',
            items: $waiting->map(fn (MarketplaceInstall $install): array => ['label' => $install->name, 'value' => $install->version.' → '.$catalog[$install->slug]['version'], 'status' => 'warning'])->values()->all(),
            link: $this->link('admin.marketplace.index', 'Open the marketplace'),
        );
    }

    private function unusedThemes(): CheckResult
    {
        $check = $this->check('extensions.unused_themes', 'Unused themes are removed', weight: 1);
        $active = $this->themes->saved();
        $unused = $this->themes->all()->keys()
            ->reject(fn (string $slug): bool => $slug === $active || $slug === Themes::DEFAULT)
            ->values();

        if ($unused->isEmpty()) {
            return $check->passed();
        }

        $fromMarketplace = MarketplaceInstall::query()->where('type', PackageType::Theme)->whereIn('slug', $unused)->pluck('slug')->all();

        return $check->warning('Installed but not used: :names', ['names' => $unused->implode(', ')],
            advice: 'Every theme is code on your server. Remove themes from the marketplace on the Marketplace page, and move themes that were added by hand to quarantine here. You can put them back at any time.',
            items: $unused->map(fn (string $slug): array => in_array($slug, $fromMarketplace, true)
                ? ['label' => $slug, 'mono' => true, 'value' => __('From the marketplace'), 'status' => 'warning']
                : ['label' => $slug, 'mono' => true, 'value' => __('Added by hand'), 'status' => 'warning',
                    'fix' => $this->fix('themes.quarantine', 'Move to quarantine', ['slugs' => [$slug]], confirm: 'The theme folder is moved to storage/app/quarantine. Nothing is deleted; move it back to use the theme again.')])
                ->all(),
            link: $this->link('admin.marketplace.index', 'Open the marketplace'),
        );
    }
}
