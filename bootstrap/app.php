<?php

use App\Extensions\ExtensionManager;
use App\Http\Controllers\BrandIconController;
use App\Http\Controllers\ChatWebhookController;
use App\Http\Controllers\MarketplaceConnectCallbackController;
use App\Http\Controllers\PackageAssetController;
use App\Http\Controllers\SeoController;
use App\Http\Controllers\WebhookController;
use App\Http\Controllers\WhatsAppConnectController;
use App\Http\Middleware\AddSearchEngineTags;
use App\Http\Middleware\AddSiteIcon;
use App\Http\Middleware\ApplyCouponFromLink;
use App\Http\Middleware\ApplyThemePreview;
use App\Http\Middleware\AuthenticateApiToken;
use App\Http\Middleware\CleanPublicAddress;
use App\Http\Middleware\EnsureAdminPermission;
use App\Http\Middleware\EnsureClientIsActive;
use App\Http\Middleware\EnsureClientTwoFactor;
use App\Http\Middleware\EnsureStaffTwoFactor;
use App\Http\Middleware\PreventRequestsDuringMaintenance;
use App\Http\Middleware\ProtectDemo;
use App\Http\Middleware\RedirectToInstaller;
use App\Http\Middleware\RequireStaffTwoFactor;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\ThrottleRequests;
use App\Http\Middleware\TrackAffiliateLink;
use App\Http\Middleware\VerifyCaptcha;
use App\Http\SecretInput;
use App\Models\SeoRedirect;
use App\Seo\MissingPages;
use App\Seo\OldAddresses;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::middleware('web')
                ->prefix(config('nuvabill.admin_path'))
                ->name('admin.')
                ->group(base_path('routes/admin.php'));

            Route::middleware('web')
                ->prefix('install')
                ->name('install.')
                ->group(base_path('routes/install.php'));

            // REST API: no session or cookies; a Bearer key signs in as its staff member.
            Route::middleware([AuthenticateApiToken::class, 'throttle:api-v1', SubstituteBindings::class])
                ->prefix('api/v1')
                ->name('api.')
                ->group(base_path('routes/api.php'));

            Route::post('webhooks/{gateway}', WebhookController::class)
                ->where('gateway', '[a-z0-9_-]+')
                ->middleware('throttle:gateway-webhooks')
                ->name('webhooks.gateway');

            // Chat apps: messages from Telegram and WhatsApp.
            Route::middleware('throttle:240,1')->group(function (): void {
                Route::post('webhooks/chat/telegram', [ChatWebhookController::class, 'telegram'])->name('webhooks.telegram');
                Route::match(['get', 'post'], 'webhooks/chat/whatsapp/{key}', [ChatWebhookController::class, 'whatsapp'])
                    ->where('key', '[A-Za-z0-9]{32,64}')
                    ->name('webhooks.whatsapp');
                // The Nuvabill store's Meta app (WhatsApp by QR code for every site).
                Route::match(['get', 'post'], 'webhooks/meta/whatsapp', [WhatsAppConnectController::class, 'webhook'])->name('webhooks.meta');
            });

            // Connect to marketplace: the store calls back with this site's key. No session, cookies or
            // form token: only the one-time code staff got when they clicked Connect is accepted.
            Route::post('nuvabill-marketplace/connect', MarketplaceConnectCallbackController::class)
                ->middleware('throttle:10,1')
                ->name('marketplace.connect.callback');
            Route::post('nuvabill-marketplace/verify', [MarketplaceConnectCallbackController::class, 'verify'])
                ->middleware('throttle:10,1')
                ->name('marketplace.connect.verify');

            // Add-ons that receive data from other services. No session, cookies or form token: each
            // add-on checks its own requests.
            Route::prefix('webhooks/addons')->middleware('throttle:240,1')
                ->group(fn () => rescue(fn () => app(ExtensionManager::class)->registerWebhookRoutes(), report: false));

            // Theme, order form and extension files. No session or cookies: these are static files.
            Route::get('package-assets/{kind}/{slug}/{path}', PackageAssetController::class)
                ->whereIn('kind', ['themes', 'orderforms', 'extensions'])
                ->where('slug', '[a-z0-9][a-z0-9_-]*')
                ->where('path', '[A-Za-z0-9_./-]+')
                ->name('package.asset');

            // For search engines and link previews. No session or cookies.
            Route::get('robots.txt', [SeoController::class, 'robots'])->name('seo.robots');
            Route::get('sitemap.xml', [SeoController::class, 'sitemap'])->name('seo.sitemap');
            Route::get('share-image/{name}', [SeoController::class, 'shareImage'])
                ->where('name', 'share-[a-z0-9]{10}\.(jpg|png|webp)')
                ->name('seo.share-image');

            // The site's own icon, when it has a White-label License. No session or cookies.
            Route::get('brand-icon/{name}', BrandIconController::class)
                ->where('name', 'icon-[a-z0-9]{10}\.png')
                ->name('brand.icon');

            // Visitor pages of add-ons, such as Website Builder pages. Last, so they never replace a page above.
            Route::middleware('web')->group(fn () => rescue(fn () => app(ExtensionManager::class)->registerPublicRoutes(), report: false));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(SecurityHeaders::class);

        // CleanPublicAddress comes first, so an address with a language the site does not offer is
        // sent on before any cookie is set.
        $middleware->web(
            append: [AddSearchEngineTags::class, AddSiteIcon::class, RedirectToInstaller::class, SetLocale::class, ProtectDemo::class, ApplyThemePreview::class, ApplyCouponFromLink::class, TrackAffiliateLink::class],
            prepend: [CleanPublicAddress::class],
        );

        // The proxy list comes from config/trustedproxy.php. Only the visitor IP and HTTPS headers are
        // read, never X-Forwarded-Host. Links and emails use APP_URL for any other host name a visitor
        // sends (see AppServiceProvider), so a faked Host header cannot change them either.
        $middleware->trustProxies(headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO);

        $middleware->replace(
            Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance::class,
            PreventRequestsDuringMaintenance::class,
        );

        $middleware->alias([
            'admin.can' => EnsureAdminPermission::class,
            'admin.two-factor' => EnsureStaffTwoFactor::class,
            // Two-factor login on the staff member's own account, for server module tools.
            'admin.two-factor-on' => RequireStaffTwoFactor::class,
            'client.active' => EnsureClientIsActive::class,
            'client.two-factor' => EnsureClientTwoFactor::class,
            'captcha' => VerifyCaptcha::class,
            // Each route gets its own counter, so quick price checks never use up the order or sign-in limit.
            'throttle' => ThrottleRequests::class,
        ]);

        $middleware->redirectGuestsTo(fn (Request $request): string => $request->routeIs('admin.*')
            ? route('admin.login')
            : route('client.login'));

        $middleware->redirectUsersTo(fn (Request $request): string => $request->routeIs('admin.*')
            ? route('admin.dashboard')
            : route('client.dashboard'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Secrets (passwords, API tokens, root passwords, secret order fields, API keys, client
        // secrets and more) are never kept in the session after a form error. The list is in
        // App\Http\SecretInput, so the CAPTCHA check leaves out the same fields.
        $exceptions->dontFlash(SecretInput::names());

        // An address that moved: send visitors and search engines to the new one. Otherwise an
        // old WHMCS ?rp= link, or else the address goes on the list of pages people could not open.
        $exceptions->render(function (NotFoundHttpException $exception, Request $request) {
            if (! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
                return null;
            }

            if ($request->is('api/*')) {
                return null;
            }

            $redirect = rescue(fn () => SeoRedirect::target($request->path()), report: false);

            if ($redirect === null) {
                if ($request->path() === 'index.php' && ($forward = rescue(fn () => OldAddresses::forRp($request), report: false))) {
                    return $forward;
                }

                rescue(fn () => MissingPages::record($request), report: false);

                return null;
            }

            $counted = rescue(fn () => SeoRedirect::query()->whereKey($redirect->getKey())->increment('hits', 1, ['last_hit_at' => now()]), false, report: false);

            if ($counted === false) {
                // During an update, before the new columns are there.
                rescue(fn () => $redirect->increment('hits'), report: false);
            }

            $status = in_array($redirect->status, SeoRedirect::STATUSES, true) ? $redirect->status : 301;
            $query = $redirect->keep_query === false ? null : $request->getQueryString();
            $target = url($redirect->to_path);

            return redirect($query ? $target.(str_contains($target, '?') ? '&' : '?').$query : $target, $status);
        });
    })->create();
