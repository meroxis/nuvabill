<?php

namespace App\Http\Middleware;

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
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin-allow-popups');

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
     * stay allowed. Everything else loads from this site only. Forms may post to payment pages.
     */
    private function contentSecurityPolicy(Request $request): string
    {
        return implode('; ', array_filter([
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline' 'unsafe-eval'",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: https:",
            "font-src 'self' data:",
            "connect-src 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self' https:",
            "frame-ancestors 'self'",
            $request->isSecure() ? 'upgrade-insecure-requests' : null,
        ]));
    }
}
