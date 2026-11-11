<?php

namespace App\Http\Controllers;

use App\Chat\WhatsAppConnectLink;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
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
    /**
     * What a Nuvabill site sends when it opens this page, and what the page sends back with Meta's code.
     */
    private const LINK_VALUES = ['origin', 'state', 'site', 'time', 'signature'];

    public function show(Request $request, WhatsAppConnectLink $link): View
    {
        $values = $request->only(self::LINK_VALUES);
        // The token goes to the site that opened this page, so only for a site this page knows.
        $valid = self::isReady() && $link->accepts($values, $request->getSchemeAndHttpHost());
        $values = $valid ? array_map(strval(...), $values) : [];
        $origin = $valid ? (string) WhatsAppConnectLink::originOf($values['origin']) : '';

        if ($valid) {
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
            'link' => $values,
            'state' => $values['state'] ?? '',
            'siteHost' => (string) parse_url($origin, PHP_URL_HOST),
            'targetOrigin' => $origin,
            'appId' => (string) config('nuvabill.whatsapp_connect.app_id'),
            'configId' => (string) config('nuvabill.whatsapp_connect.config_id'),
            'graphVersion' => (string) config('nuvabill.whatsapp_connect.graph_version'),
        ]);
    }

    /**
     * Lets a Nuvabill site check that this page can run Meta's signup before it sends the owner here.
     * The store's own staff can try the signup as soon as the keys are set; other sites only get the
     * button once Meta has approved the app and the store opened it to everyone.
     */
    public function status(): JsonResponse
    {
        return response()->json(['ready' => self::isReady() && config('nuvabill.whatsapp_connect.open')]);
    }

    /**
     * The Meta app's own webhook address. Each connected site gets its messages at its own address
     * (set per WhatsApp account when it connects), but Meta needs a checked address for the app too,
     * and sends template and account updates only here. Those are left alone: sites ask Meta for
     * their template decisions themselves.
     */
    public function webhook(Request $request): Response
    {
        $token = (string) config('nuvabill.whatsapp_connect.verify_token');
        $secret = (string) config('nuvabill.whatsapp_connect.app_secret');
        abort_if($token === '' || $secret === '', 404);

        if ($request->isMethod('GET')) {
            abort_unless($request->query('hub_mode') === 'subscribe' && hash_equals($token, (string) $request->query('hub_verify_token')), 403);

            return response((string) $request->query('hub_challenge'), 200, ['Content-Type' => 'text/plain']);
        }

        abort_unless(hash_equals('sha256='.hash_hmac('sha256', $request->getContent(), $secret), (string) $request->header('X-Hub-Signature-256')), 403);

        return response('ok', 200, ['Content-Type' => 'text/plain']);
    }

    public function exchange(Request $request, WhatsAppConnectLink $link): JsonResponse
    {
        abort_unless(self::isReady(), 404);
        $data = $request->validate(['code' => ['required', 'string', 'max:2000']]);

        // Only as the last step of a signup this page showed for a site it knows.
        if (! $link->accepts($request->only(self::LINK_VALUES), $request->getSchemeAndHttpHost(), WhatsAppConnectLink::FINISH_MINUTES)) {
            return response()->json(['message' => __('This window was open for too long. Close it and start again from Settings → Chat apps.')], 403);
        }

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
}
