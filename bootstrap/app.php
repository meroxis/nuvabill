<?php

use App\Http\Controllers\ChatWebhookController;
use App\Http\Controllers\PackageAssetController;
use App\Http\Controllers\SeoController;
use App\Http\Controllers\WebhookController;
use App\Http\Middleware\AddSearchEngineTags;
use App\Http\Middleware\ApplyCouponFromLink;
use App\Http\Middleware\ApplyThemePreview;
use App\Http\Middleware\AuthenticateApiToken;
use App\Http\Middleware\EnsureAdminPermission;
use App\Http\Middleware\EnsureClientIsActive;
use App\Http\Middleware\EnsureClientTwoFactor;
use App\Http\Middleware\EnsureStaffTwoFactor;
use App\Http\Middleware\PreventRequestsDuringMaintenance;
use App\Http\Middleware\ProtectDemo;
use App\Http\Middleware\RedirectToInstaller;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\TrackAffiliateLink;
use App\Http\Middleware\VerifyCaptcha;
use App\Models\SeoRedirect;
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
                ->name('webhooks.gateway');

            // Chat apps: messages from Telegram and WhatsApp.
            Route::middleware('throttle:240,1')->group(function (): void {
                Route::post('webhooks/chat/telegram', [ChatWebhookController::class, 'telegram'])->name('webhooks.telegram');
                Route::match(['get', 'post'], 'webhooks/chat/whatsapp/{key}', [ChatWebhookController::class, 'whatsapp'])
                    ->where('key', '[A-Za-z0-9]{32,64}')
                    ->name('webhooks.whatsapp');
            });

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
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(SecurityHeaders::class);

        $middleware->web(append: [AddSearchEngineTags::class, RedirectToInstaller::class, SetLocale::class, ProtectDemo::class, ApplyThemePreview::class, ApplyCouponFromLink::class, TrackAffiliateLink::class]);

        // The proxy list comes from config/trustedproxy.php. Only the visitor IP and HTTPS
        // headers are read, so a visitor cannot fake the host name in links and emails.
        $middleware->trustProxies(headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO);

        $middleware->replace(
            Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance::class,
            PreventRequestsDuringMaintenance::class,
        );

        $middleware->alias([
            'admin.can' => EnsureAdminPermission::class,
            'admin.two-factor' => EnsureStaffTwoFactor::class,
            'client.active' => EnsureClientIsActive::class,
            'client.two-factor' => EnsureClientTwoFactor::class,
            'captcha' => VerifyCaptcha::class,
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

        // A store page that got a new web address: send visitors and search engines to it.
        $exceptions->render(function (NotFoundHttpException $exception, Request $request) {
            if (! $request->isMethod('GET') || $request->is('api/*')) {
                return null;
            }

            $redirect = rescue(fn () => SeoRedirect::target($request->path()), report: false);

            if ($redirect === null) {
                return null;
            }

            $redirect->increment('hits');
            $query = $request->getQueryString();

            return redirect(url($redirect->to_path).($query ? '?'.$query : ''), 301);
        });
    })->create();
