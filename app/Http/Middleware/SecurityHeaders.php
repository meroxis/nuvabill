<?php

namespace App\Http\Middleware;

use App\Extensions\ExtensionManager;
use App\Security\Captcha;
use App\Support\Installation;
use Closure;
use Illuminate\Foundation\Vite;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Browser security headers on every response: no framing by other sites, no MIME sniffing,
 * a strict referrer policy and a content security policy for HTML pages.
 */
class SecurityHeaders
{
    /**
     * Request attribute: extra sources for one page, as directive => list of sources.
     */
    public const PAGE_SOURCES = 'security.page_sources';

    /**
     * Request attribute: the Cross-Origin-Opener-Policy for one page.
     */
    public const OPENER_POLICY = 'security.opener_policy';

    public function __construct(private Vite $vite) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! headers_sent()) {
            header_remove('X-Powered-By');
        }

        $headers = $response->headers;
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'SAMEORIGIN');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), browsing-topics=()');
        // A page opened as a window by another site keeps its link back to that site only without isolation.
        $headers->set('Cross-Origin-Opener-Policy', $request->attributes->get(self::OPENER_POLICY, 'same-origin-allow-popups'));

        if ($request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        if ($this->isHtml($response) && ! $this->vite->isRunningHot()) {
            $headers->set('Content-Security-Policy', $this->contentSecurityPolicy($request));
        }

        return $response;
    }

    private function isHtml(Response $response): bool
    {
        return str_contains((string) $response->headers->get('Content-Type', 'text/html'), 'text/html');
    }

    /**
     * Alpine.js evaluates its attributes and pages use small inline scripts and styles, so those
     * stay allowed. Everything else loads from this site only, except the CAPTCHA provider staff
     * chose in Settings → Security, and Cloudflare Web Analytics, which Cloudflare adds to pages
     * of sites it serves. Forms may post to payment pages.
     */
    private function contentSecurityPolicy(Request $request): string
    {
        $captcha = implode(' ', app(Captcha::class)->details((string) setting('security.captcha_provider'))['hosts'] ?? []);
        $addons = Installation::isInstalled() ? app(ExtensionManager::class)->contentSecurityPolicy() : [];
        // One page can allow more, such as Meta's script on the WhatsApp connect page.
        $page = (array) $request->attributes->get(self::PAGE_SOURCES, []);
        $extra = fn (string $directive): string => implode(' ', [...($addons[$directive] ?? []), ...($page[$directive] ?? [])]);
        $frames = trim($captcha.' '.$extra('frame-src'));
        $cloudflare = $request->headers->has('CF-Ray');
        $sources = fn (string ...$parts): string => implode(' ', array_filter(array_map('trim', $parts), fn (string $part): bool => $part !== ''));

        return implode('; ', array_filter([
            "default-src 'self'",
            $sources("script-src 'self' 'unsafe-inline' 'unsafe-eval'", $captcha, $cloudflare ? 'https://static.cloudflareinsights.com' : '', $extra('script-src')),
            $sources("style-src 'self' 'unsafe-inline'", $extra('style-src')),
            "img-src 'self' data: https:",
            $sources("font-src 'self' data:", $extra('font-src')),
            $sources("connect-src 'self'", $captcha, $cloudflare ? 'https://cloudflareinsights.com' : '', $extra('connect-src')),
            $frames !== '' ? "frame-src 'self' {$frames}" : null,
            $extra('media-src') !== '' ? "media-src 'self' ".$extra('media-src') : null,
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self' https:",
            "frame-ancestors 'self'",
            $request->isSecure() ? 'upgrade-insecure-requests' : null,
        ]));
    }
}
