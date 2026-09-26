<?php

namespace App\Http\Controllers;

use App\Billing\PaymentRecorder;
use App\Extensions\ExtensionManager;
use App\Extensions\ExtensionManifest;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Receives server-to-server payment notifications at /webhooks/{gateway}.
 */
class WebhookController extends Controller
{
    public function __invoke(Request $request, string $gateway, ExtensionManager $extensions, PaymentRecorder $payments): Response
    {
        $manifest = $extensions->find($gateway);

        if ($manifest === null || $manifest->type !== ExtensionManifest::TYPE_GATEWAY || ! $extensions->isEnabled($gateway)) {
            return response('Unknown gateway', 404);
        }

        try {
            $result = $extensions->gateway($gateway)->handleWebhook($request);
        } catch (Throwable $exception) {
            report($exception);

            return response('Error', 500);
        }

        if ($result->status >= 400) {
            Log::warning("Rejected {$gateway} webhook: {$result->message}");
        }

        if ($result->payment !== null) {
            $payments->recordGatewayResult($result->payment, $gateway);
        }

        return response($result->message, $result->status);
    }
}
