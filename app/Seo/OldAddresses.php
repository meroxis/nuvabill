<?php

namespace App\Seo;

use App\Models\SeoRedirect;
use App\Models\TldPrice;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/**
 * Old WHMCS addresses, such as /clientarea.php and /cart.php, that people and search engines still
 * open after a move from WHMCS. Staff add them as redirects with one click, and ?rp= links
 * (WHMCS friendly addresses on index.php) are forwarded too.
 */
class OldAddresses
{
    /**
     * @var array<string, string> old path => route name
     */
    public const WHMCS = [
        'clientarea.php' => 'client.login',
        'cart.php' => 'store.index',
        'login.php' => 'client.login',
        'dologin.php' => 'client.login',
        'logout.php' => 'client.login',
        'register.php' => 'client.register',
        'pwreset.php' => 'client.password.request',
        'password/reset' => 'client.password.request',
        'submitticket.php' => 'client.tickets.create',
        'supporttickets.php' => 'client.tickets.index',
        'viewticket.php' => 'client.tickets.index',
        'viewinvoice.php' => 'client.invoices.index',
        'contact.php' => 'client.tickets.create',
        'domainchecker.php' => 'store.domains',
        'affiliates.php' => 'client.affiliate',
    ];

    /**
     * Where each old address of the pack goes on this site, as old path => new path. Pages that do
     * not exist here are left out.
     *
     * @return array<string, string>
     */
    public static function whmcsTargets(): array
    {
        $targets = [];

        foreach (self::WHMCS as $old => $route) {
            if ($old === 'affiliates.php' && ! setting('affiliates.enabled')) {
                continue;
            }

            if ($old === 'domainchecker.php' && ! rescue(fn (): bool => TldPrice::query()->where('is_enabled', true)->exists(), false, report: false)) {
                $route = 'store.index';
            }

            if (Route::has($route)) {
                $targets[$old] = trim(route($route, [], false), '/');
            }
        }

        return $targets;
    }

    /**
     * Add the old WHMCS addresses as redirects. An address that forwards already is never changed.
     *
     * @return int how many were added
     */
    public static function addWhmcsPack(): int
    {
        $added = 0;

        foreach (self::whmcsTargets() as $old => $target) {
            if (SeoRedirect::target($old) !== null) {
                continue;
            }

            [$redirect] = SeoRedirect::saveManual($old, '/'.$target, 301, false, 'whmcs');

            if ($redirect !== null) {
                $added++;
            }
        }

        app(Settings::class)->setMany(['seo.whmcs_addresses' => true]);

        return $added;
    }

    /**
     * WHMCS friendly addresses on index.php, such as /index.php?rp=/knowledgebase/1/x. Always a 301
     * to a page on this site; the other parts after ? are dropped.
     */
    public static function forRp(Request $request): ?RedirectResponse
    {
        if (! $request->query->has('rp') || ! setting('seo.whmcs_addresses')) {
            return null;
        }

        return redirect(SiteAddress::to(self::rpTarget($request->query('rp'))), 301);
    }

    private static function rpTarget(mixed $rp): string
    {
        if (! is_string($rp) || ! str_starts_with($rp, '/') || str_starts_with($rp, '//') || str_contains($rp, '\\')
            || mb_strlen($rp) > 191 || ! mb_check_encoding($rp, 'UTF-8') || preg_match('/[\x00-\x1F\x7F\s]/u', $rp)) {
            return '/';
        }

        $path = trim((string) preg_split('/[?#]/', $rp, 2)[0], '/');
        $lower = mb_strtolower($path);
        $redirect = $path === '' ? null : SeoRedirect::target($path);
        $under = fn (string $prefix): bool => $lower === $prefix || str_starts_with($lower, $prefix.'/');

        $target = match (true) {
            $redirect !== null => $redirect->to_path,
            $under('store') => $path,
            $under('knowledgebase') => 'knowledgebase',
            $under('announcements') => 'announcements',
            $lower === 'login' && Route::has('client.login') => route('client.login', [], false),
            $under('password/reset') && Route::has('client.password.request') => route('client.password.request', [], false),
            default => '',
        };

        // Only ever a path on this site: SiteAddress::to() puts it after the site address.
        return '/'.ltrim((string) $target, '/');
    }
}
