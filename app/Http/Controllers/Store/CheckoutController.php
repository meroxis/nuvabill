<?php

namespace App\Http\Controllers\Store;

use App\Billing\Cart;
use App\Billing\OrderPlacer;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CheckoutController extends Controller
{
    public function show(Request $request, Cart $cart): View|RedirectResponse
    {
        if ($cart->isEmpty()) {
            return redirect()->route('cart.show');
        }

        $client = $request->user('web');

        if ($client === null) {
            $request->session()->put('url.intended', route('checkout.show'));
        }

        $currency = $client?->currency ?? (string) setting('billing.currency');

        return view('theme::checkout', [
            'client' => $client,
            'lines' => $cart->lines($currency),
            'total' => $cart->total($currency),
            'currency' => $currency,
            'termsUrl' => setting('orders.accept_terms_url'),
        ]);
    }

    public function store(Request $request, Cart $cart, OrderPlacer $placer): RedirectResponse
    {
        if (filled(setting('orders.accept_terms_url'))) {
            $request->validate(['accept_terms' => ['accepted']], ['accept_terms.accepted' => __('Please accept the terms of service.')]);
        }

        $client = $request->user('web');
        $lines = $cart->lines($client->currency);

        if ($lines->isEmpty()) {
            return redirect()->route('cart.show')->with('error', __('Your cart is empty.'));
        }

        // Only trust the visitor's country when a trusted proxy such as Cloudflare added it.
        $ipCountry = $request->isFromTrustedProxy() ? $request->header('CF-IPCountry') : null;

        $order = $placer->place($client, $lines, $request->ip(), $ipCountry);
        $cart->clear();

        $invoice = $order->invoice;

        if ($invoice->isPayable()) {
            return redirect()->route('client.invoices.show', $invoice)->with('status', __('Thank you! Order #:number is placed. Pay the invoice below to start your service.', ['number' => $order->number]));
        }

        return redirect()->route('client.dashboard')->with('status', __('Thank you! Order #:number is placed.', ['number' => $order->number]));
    }
}
