<?php

namespace App\Http\Controllers\Store;

use App\Billing\Cart;
use App\Enums\BillingCycle;
use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CartController extends Controller
{
    public function show(Cart $cart): View
    {
        $currency = $this->currency();

        return view('theme::cart', [
            'lines' => $cart->lines($currency),
            'total' => $cart->total($currency),
            'currency' => $currency,
        ]);
    }

    public function store(Request $request, Cart $cart): RedirectResponse
    {
        $request->merge(['domain' => Str::lower(trim((string) $request->input('domain'), " \t\n\r\0\x0B/"))]);
        $request->merge(['domain' => preg_replace('#^(https?://)?(www\.)?#', '', (string) $request->input('domain')) ?: null]);

        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'billing_cycle' => ['required', Rule::enum(BillingCycle::class)],
            'domain' => ['nullable', 'string', 'max:190', 'regex:/^(?!-)[a-z0-9-]{1,63}(?<!-)(\.[a-z0-9-]{1,63})*\.[a-z]{2,63}$/'],
        ], ['domain.regex' => __('Enter a domain like example.com.')]);

        $product = Product::query()->with('prices')->findOrFail($data['product_id']);
        $cycle = BillingCycle::from($data['billing_cycle']);

        if (! $product->is_visible || ! $product->isInStock()) {
            throw ValidationException::withMessages(['product_id' => __('This product is not available right now.')]);
        }

        if ($product->priceFor($this->currency(), $cycle) === null) {
            throw ValidationException::withMessages(['billing_cycle' => __('Choose one of the billing options shown.')]);
        }

        if ($product->requires_domain && blank($data['domain'] ?? null)) {
            throw ValidationException::withMessages(['domain' => __('Enter the domain name for this service.')]);
        }

        $cart->add($product, $cycle, $product->requires_domain ? $data['domain'] : null);

        return redirect()->route('cart.show')->with('status', __(':product added to your cart.', ['product' => $product->name]));
    }

    public function destroy(int $index, Cart $cart): RedirectResponse
    {
        $cart->remove($index);

        return redirect()->route('cart.show');
    }

    private function currency(): string
    {
        return auth('web')->user()?->currency ?? (string) setting('billing.currency');
    }
}
