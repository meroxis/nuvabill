<?php

namespace App\Http\Controllers;

use App\Http\Middleware\SecurityHeaders;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\View\View;

/**
 * The WhatsApp connect page on the Nuvabill store. A Nuvabill site opens it in a window; it runs
 * Meta's Embedded Signup (the owner scans a QR code with the WhatsApp Business app), swaps Meta's
 * 30-second code for the owner's access token with the app secret only the store has, and hands
 * the token back to the site that opened it. Nothing is stored here.
 */
class WhatsAppConnectController extends Controller
{
    public function show(Request $request): View
    {
        $origin = (string) $request->query('origin');
        $state = (string) $request->query('state');
        $valid = self::isSiteOrigin($origin) && preg_match('/^[A-Za-z0-9]{32}$/', $state) === 1;

        if (self::isReady()) {
            $request->attributes->set(SecurityHeaders::PAGE_SOURCES, [
                'script-src' => ['https://connect.facebook.net'],
                'frame-src' => ['https://www.facebook.com', 'https://web.facebook.com', 'https://staticxx.facebook.com'],
                'connect-src' => ['https://graph.facebook.com', 'https://www.facebook.com'],
            ]);
            // The site that opened this window must stay reachable through window.opener.
            $request->attributes->set(SecurityHeaders::OPENER_POLICY, 'unsafe-none');
        }

        return view('connect.whatsapp', [
            'ready' => self::isReady(),
            'valid' => $valid,
            'origin' => $origin,
            'state' => $state,
            'siteHost' => (string) parse_url($origin, PHP_URL_HOST),
            'targetOrigin' => $valid ? self::originOf($origin) : '',
            'appId' => (string) config('nuvabill.whatsapp_connect.app_id'),
            'configId' => (string) config('nuvabill.whatsapp_connect.config_id'),
            'graphVersion' => (string) config('nuvabill.whatsapp_connect.graph_version'),
        ]);
    }

    /**
     * Lets a Nuvabill site check that this page can run Meta's signup before it sends the owner here.
     */
    public function status(): JsonResponse
    {
        return response()->json(['ready' => self::isReady()]);
    }

    public function exchange(Request $request): JsonResponse
    {
        abort_unless(self::isReady(), 404);
        $data = $request->validate(['code' => ['required', 'string', 'max:2000']]);

        try {
            $response = Http::timeout(15)->get('https://graph.facebook.com/'.config('nuvabill.whatsapp_connect.graph_version').'/oauth/access_token', [
                'client_id' => config('nuvabill.whatsapp_connect.app_id'),
                'client_secret' => config('nuvabill.whatsapp_connect.app_secret'),
                'code' => $data['code'],
            ]);
        } catch (ConnectionException) {
            return response()->json(['message' => __('Nuvabill could not reach Meta. Please try again.')], 502);
        }

        $token = (string) ($response->json('access_token') ?? '');

        if ($response->failed() || $token === '') {
            return response()->json(['message' => __('Meta did not accept the signup. Please start again; the code is only valid for 30 seconds.')], 422);
        }

        return response()->json(['token' => $token]);
    }

    public static function isReady(): bool
    {
        return filled(config('nuvabill.whatsapp_connect.app_id')) && filled(config('nuvabill.whatsapp_connect.app_secret')) && filled(config('nuvabill.whatsapp_connect.config_id'));
    }

    /**
     * "https://example.com/billing" becomes "https://example.com", the origin postMessage needs.
     */
    private static function originOf(string $address): string
    {
        $parts = (array) parse_url($address);

        return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    /**
     * The token only goes back to a real site address: https, or http on this computer for testing.
     */
    private static function isSiteOrigin(string $origin): bool
    {
        $parts = parse_url($origin);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host']) || isset($parts['user']) || isset($parts['query']) || isset($parts['fragment'])) {
            return false;
        }

        return $parts['scheme'] === 'https' || ($parts['scheme'] === 'http' && in_array($parts['host'], ['localhost', '127.0.0.1'], true));
    }
}
