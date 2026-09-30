<?php

namespace App\Support;

use App\Http\Middleware\ApplyThemePreview;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\View;

/**
 * Client-area themes live in themes/{slug} and order forms in orderforms/{slug}. Views are loaded
 * with the "theme::" prefix from the active order form first (it only has store and cart pages),
 * then the active theme, then the default "nova" theme, so each only overrides what it changes.
 *
 * Staff, and everyone on the public demo, can preview a theme or order form for their own
 * session before switching it on: see {@see ApplyThemePreview}.
 */
class Themes
{
    public const DEFAULT = 'nova';

    /**
     * The built-in step-by-step cart. Not a folder: it is the store pages of the active theme.
     */
    public const STANDARD_ORDER_FORM = 'standard';

    private ?string $previewTheme = null;

    private ?string $previewOrderForm = null;

    public function __construct(private string $path, private string $orderFormsPath) {}

    public function active(): string
    {
        return $this->previewTheme ?? $this->saved();
    }

    /**
     * The theme switched on in Settings, ignoring any preview.
     */
    public function saved(): string
    {
        $slug = (string) setting('theme.active', self::DEFAULT);

        return $this->exists($slug) ? $slug : self::DEFAULT;
    }

    public function activeOrderForm(): string
    {
        return $this->previewOrderForm ?? $this->savedOrderForm();
    }

    public function savedOrderForm(): string
    {
        $slug = (string) setting('orderform.active', self::STANDARD_ORDER_FORM);

        return $this->orderFormExists($slug) ? $slug : self::STANDARD_ORDER_FORM;
    }

    public function exists(string $slug): bool
    {
        return $this->isSlug($slug) && is_dir($this->path.'/'.$slug.'/views');
    }

    public function orderFormExists(string $slug): bool
    {
        return $this->isSlug($slug) && $slug !== self::STANDARD_ORDER_FORM && is_dir($this->orderFormsPath.'/'.$slug.'/views');
    }

    /**
     * Show another theme or order form for this request only.
     */
    public function preview(?string $theme, ?string $orderForm): void
    {
        $this->previewTheme = $theme !== null && $this->exists($theme) ? $theme : null;
        $this->previewOrderForm = $orderForm !== null && ($orderForm === self::STANDARD_ORDER_FORM || $this->orderFormExists($orderForm)) ? $orderForm : null;

        $this->register();
    }

    public function isPreviewing(): bool
    {
        return ($this->previewTheme !== null && $this->previewTheme !== $this->saved())
            || ($this->previewOrderForm !== null && $this->previewOrderForm !== $this->savedOrderForm());
    }

    public function register(): void
    {
        $orderForm = $this->activeOrderForm();

        $paths = array_values(array_unique(array_filter([
            $orderForm !== self::STANDARD_ORDER_FORM ? $this->orderFormsPath.'/'.$orderForm.'/views' : null,
            $this->path.'/'.$this->active().'/views',
            $this->path.'/'.self::DEFAULT.'/views',
        ])));

        View::replaceNamespace('theme', $paths);

        // A theme or order form can bring its own translations in lang/{locale}.json. Nuvabill's own
        // texts win when both have one.
        foreach ([$orderForm !== self::STANDARD_ORDER_FORM ? $this->orderFormsPath.'/'.$orderForm : null, $this->path.'/'.$this->active()] as $folder) {
            if ($folder !== null && is_dir($folder.'/lang') && ! in_array($folder.'/lang', Lang::getLoader()->jsonPaths(), true)) {
                Lang::addJsonPath($folder.'/lang');
            }
        }
    }

    /**
     * Installed themes with the details from their theme.json.
     *
     * @return Collection<string, array{name: string, version: string, author: string, description: string}>
     */
    public function all(): Collection
    {
        return $this->read($this->path, 'theme.json');
    }

    /**
     * Installed order forms with the details from their orderform.json, keyed by slug.
     *
     * @return Collection<string, array{name: string, version: string, author: string, description: string}>
     */
    public function orderForms(): Collection
    {
        return $this->read($this->orderFormsPath, 'orderform.json');
    }

    public function path(string $slug = ''): string
    {
        return rtrim($this->path.'/'.$slug, '/');
    }

    public function orderFormPath(string $slug = ''): string
    {
        return rtrim($this->orderFormsPath.'/'.$slug, '/');
    }

    /**
     * @return Collection<string, array{name: string, version: string, author: string, description: string}>
     */
    private function read(string $path, string $file): Collection
    {
        return collect(glob($path.'/*/'.$file) ?: [])
            ->mapWithKeys(function (string $file): array {
                $data = json_decode((string) file_get_contents($file), true) ?: [];

                return [basename(dirname($file)) => [
                    'name' => (string) ($data['name'] ?? basename(dirname($file))),
                    'version' => (string) ($data['version'] ?? ''),
                    'author' => (string) ($data['author'] ?? ''),
                    'description' => (string) ($data['description'] ?? ''),
                ]];
            })
            ->filter(fn (array $details, string $slug): bool => $this->isSlug($slug));
    }

    private function isSlug(string $slug): bool
    {
        return (bool) preg_match('/^[a-z0-9][a-z0-9_-]*$/', $slug);
    }
}
