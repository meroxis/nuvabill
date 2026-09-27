<?php

namespace App\Http\Middleware;

use App\Billing\Affiliates;
use App\Models\Affiliate;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A link with ?ref=CODE remembers the affiliate in a cookie, so they get the referral when the
 * visitor signs up, even days later. Each visit through the link counts once.
 */
class TrackAffiliateLink
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $code = $request->query('ref');

        if (! $request->isMethod('GET') || ! is_string($code) || ! preg_match('/^[A-Za-z0-9]{4,32}$/', $code) || ! setting('affiliates.enabled')) {
            return $response;
        }

        $code = strtoupper($code);

        if ($request->cookie(Affiliates::COOKIE) === $code) {
            return $response;
        }

        $affiliate = Affiliate::query()->where('code', $code)->where('status', Affiliate::STATUS_ACTIVE)->first();

        if ($affiliate !== null && $request->user('web')?->id !== $affiliate->client_id) {
            $affiliate->increment('clicks');
            $response->headers->setCookie(cookie(Affiliates::COOKIE, $affiliate->code, (int) setting('affiliates.cookie_days') * 1440));
        }

        return $response;
    }
}
