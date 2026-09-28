<?php

namespace App\Http\Controllers\Admin;

use App\Extensions\ExtensionManager;
use App\Extensions\ExtensionManifest;
use App\Extensions\ExtensionOverview;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Support\Activity;
use App\Support\Quarantine;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/**
 * Extensions: every installed payment gateway, server module, registrar and add-on in one place,
 * with its status, how much it is used, and a way into its settings.
 */
class ExtensionController extends Controller
{
    /**
     * Tab => the extension type it lists (null lists all).
     */
    public const TABS = [
        'all' => null,
        'gateways' => ExtensionManifest::TYPE_GATEWAY,
        'servers' => ExtensionManifest::TYPE_SERVER,
        'registrars' => ExtensionManifest::TYPE_REGISTRAR,
        'addons' => ExtensionManifest::TYPE_ADDON,
    ];

    /**
     * Who may change each type of extension.
     */
    public const PERMISSIONS = [
        ExtensionManifest::TYPE_GATEWAY => 'settings.manage',
        ExtensionManifest::TYPE_REGISTRAR => 'settings.manage',
        ExtensionManifest::TYPE_SERVER => 'products.manage',
        ExtensionManifest::TYPE_ADDON => 'marketplace.manage',
    ];

    public function index(Request $request, ExtensionOverview $overview): View
    {
        $admin = $this->admin($request);
        $tab = array_key_exists((string) $request->query('tab'), self::TABS) ? (string) $request->query('tab') : 'all';
        $search = trim((string) $request->query('q', ''));
        $all = $overview->all();

        $items = $all
            ->when(self::TABS[$tab] !== null, fn ($items) => $items->filter(fn (array $item): bool => $item['manifest']->type === self::TABS[$tab]))
            ->when($search !== '', fn ($items) => $items->filter(fn (array $item): bool => str_contains(mb_strtolower($item['manifest']->name.' '.$item['manifest']->description.' '.$item['manifest']->author), mb_strtolower($search))))
            // Problems first, then what is in use, then the rest by name.
            ->sortBy(fn (array $item): string => ([ExtensionOverview::STATE_NEEDS_SETTINGS => 0, ExtensionOverview::STATE_ON => 1][$item['state']] ?? 2).mb_strtolower($item['manifest']->name));

        return view('admin.extensions.index', [
            'tab' => $tab,
            'search' => $search,
            'items' => $items,
            'counts' => collect(self::TABS)->map(fn (?string $type): int => $type === null ? $all->count() : $all->filter(fn (array $item): bool => $item['manifest']->type === $type)->count()),
            'totals' => [
                'on' => $all->where('state', ExtensionOverview::STATE_ON)->count(),
                'needs' => $all->where('state', ExtensionOverview::STATE_NEEDS_SETTINGS)->count(),
                'updates' => $all->whereNotNull('update')->count(),
                'marketplace' => $all->where('origin', ExtensionOverview::ORIGIN_MARKETPLACE)->count(),
                'manual' => $all->where('origin', ExtensionOverview::ORIGIN_MANUAL)->count(),
            ],
            'may' => collect(self::PERMISSIONS)->map(fn (string $permission): bool => $admin->hasPermission($permission))->all(),
        ]);
    }

    /**
     * Switch a payment gateway, registrar or add-on on or off. One that misses required settings
     * opens its settings instead of being switched on.
     */
    public function toggle(Request $request, string $slug, ExtensionManager $extensions, ExtensionOverview $overview): RedirectResponse
    {
        $manifest = $extensions->find($slug);

        abort_if($manifest === null || $manifest->type === ExtensionManifest::TYPE_SERVER, 404);
        abort_unless($this->admin($request)->hasPermission(self::PERMISSIONS[$manifest->type]), 403);

        $enable = ! $extensions->isEnabled($slug);

        if ($enable && ! $overview->isConfigured($manifest)) {
            return redirect(self::settingsUrl($manifest))->with('error', __('Fill in the settings of :name to switch it on.', ['name' => $manifest->name]));
        }

        $extensions->saveSettings($slug, $extensions->settings($slug), $enable);
        Activity::log('extension.updated', "Extension {$manifest->name} ".($enable ? 'switched on' : 'switched off'));

        return back()->with('status', $enable ? __(':name is switched on.', ['name' => $manifest->name]) : __(':name is switched off.', ['name' => $manifest->name]));
    }

    /**
     * Move an extension added by hand to quarantine, when nothing uses it any more. Extensions from the
     * marketplace are removed there; the ones that come with Nuvabill stay.
     */
    public function destroy(Request $request, string $slug, ExtensionManager $extensions, ExtensionOverview $overview): RedirectResponse
    {
        $manifest = $extensions->find($slug);

        abort_if($manifest === null, 404);
        abort_unless($this->admin($request)->hasPermission(self::PERMISSIONS[$manifest->type]), 403);

        if (! $overview->isRemovable($manifest)) {
            return back()->with('error', __(':name is still in use or cannot be removed here. Switch it off first, and move servers, products and domain prices away from it.', ['name' => $manifest->name]));
        }

        try {
            $where = Quarantine::move($manifest->path, 'extensions/'.$manifest->type.'s/'.$manifest->slug);
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $extensions->refresh();
        Activity::log('extension.removed', "Extension {$manifest->name} ({$manifest->slug}) moved to quarantine: {$where}");

        return back()->with('status', __(':name was moved to :folder. Move the folder back to use it again.', ['name' => $manifest->name, 'folder' => $where]));
    }

    /**
     * Where an extension's settings are.
     */
    public static function settingsUrl(ExtensionManifest $manifest): string
    {
        return match ($manifest->type) {
            ExtensionManifest::TYPE_GATEWAY => route('admin.settings.gateways.edit', $manifest->slug),
            ExtensionManifest::TYPE_REGISTRAR => route('admin.settings.registrars.edit', $manifest->slug),
            ExtensionManifest::TYPE_ADDON => route('admin.marketplace.settings', $manifest->slug),
            default => route('admin.servers.index'),
        };
    }

    /**
     * Staff who may change at least one kind of extension see the page.
     */
    private function admin(Request $request): Admin
    {
        /** @var Admin $admin */
        $admin = $request->user('admin');

        abort_unless(collect(self::PERMISSIONS)->contains(fn (string $permission): bool => $admin->hasPermission($permission)), 403);

        return $admin;
    }
}
