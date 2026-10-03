<?php

namespace Tests\Feature\Gateways;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Forged payment notifications cannot flood the site or make it call PayPal again and again.
 */
class PayPalWebhookLimitsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enableGateway('paypal', ['mode' => 'sandbox', 'client_id' => 'client-id', 'client_secret' => 'client-secret', 'webhook_id' => 'WH-1']);
    }

    public function test_the_gateway_webhook_route_is_rate_limited(): void
    {
        for ($i = 0; $i < 240; $i++) {
            $this->postJson(route('webhooks.gateway', 'paypal'), ['event_type' => 'CHECKOUT.ORDER.APPROVED'])->assertOk();
        }

        $this->postJson(route('webhooks.gateway', 'paypal'), ['event_type' => 'CHECKOUT.ORDER.APPROVED'])->assertStatus(429);
    }

    public function test_a_webhook_without_paypal_signature_headers_makes_no_call_to_paypal(): void
    {
        Http::fake();

        $this->postJson(route('webhooks.gateway', 'paypal'), ['event_type' => 'PAYMENT.CAPTURE.COMPLETED'])->assertStatus(400);

        Http::assertNothingSent();
    }

    public function test_the_access_token_is_reused(): void
    {
        Http::fake([
            'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'token-1', 'expires_in' => 32400]),
            'api-m.sandbox.paypal.com/v1/notifications/verify-webhook-signature' => Http::response(['verification_status' => 'FAILURE']),
        ]);
        $headers = [
            'PAYPAL-AUTH-ALGO' => 'SHA256withRSA',
            'PAYPAL-CERT-URL' => 'https://api.sandbox.paypal.com/v1/notifications/certs/CERT-1',
            'PAYPAL-TRANSMISSION-ID' => 'T-1',
            'PAYPAL-TRANSMISSION-SIG' => 'sig',
            'PAYPAL-TRANSMISSION-TIME' => '2026-10-03T10:00:00Z',
        ];

        $this->postJson(route('webhooks.gateway', 'paypal'), ['event_type' => 'PAYMENT.CAPTURE.COMPLETED'], $headers)->assertStatus(400);
        $this->postJson(route('webhooks.gateway', 'paypal'), ['event_type' => 'PAYMENT.CAPTURE.COMPLETED'], $headers)->assertStatus(400);

        Http::assertSentCount(3);
    }
}
