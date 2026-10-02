<?php

namespace App\Http\Controllers\Store;

use App\Billing\Cart;
use App\Billing\Taxes;
use App\Domains\AvailabilityChecker;
use App\Domains\DomainName;
use App\Enums\BillingCycle;
use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Models\Product;
use App\Models\TldPrice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CartController extends Controller
{
    public function show(Request $request, Cart $cart, Taxes $taxes): View
    {
        $currency = $this->currency();

        $lines = $cart->lines($currency);
        $tax = $taxes->forCart($lines, $request->user('web'));

        return view('theme::cart', [
            'lines' => $lines,
            'total' => $tax['total'],
            'tax' => $tax,
            'discount' => $lines->sum(fn ($line): int => $line->discount),
            'coupon' => $cart->coupon($currency),
            'couponCode' => $cart->couponCode(),
            'couponProblem' => $cart->couponProblem($currency),
            'currency' => $currency,
        ]);
    }

    public function store(Request $request, Cart $cart, AvailabilityChecker $checker): RedirectResponse
    {
        $request->merge(['domain' => DomainName::normalize((string) $request->input('domain')) ?? (filled($request->input('domain')) ? '!' : null)]);

        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'billing_cycle' => ['required', Rule::enum(BillingCycle::class)],
            'domain' => ['nullable', 'string', 'max:190', 'not_in:!'],
            'register_domain' => ['sometimes', 'boolean'],
            'addons' => ['sometimes', 'array', 'max:20'],
            'addons.*' => ['integer'],
        ], ['domain.not_in' => __('Enter a domain like example.com.')]);

        $product = Product::query()->with('prices')->findOrFail($data['product_id']);
        $cycle = BillingCycle::from($data['billing_cycle']);

        if (! $product->is_visible || ! $product->isInStock()) {
            throw ValidationException::withMessages(['product_id' => __('This product is not available right now.')]);
        }

        $left = $product->stockLeft();

        if ($left !== null && $cart->quantityOf($product) >= $left) {
            throw ValidationException::withMessages(['product_id' => __('Your cart already has all of this product that is left.')]);
        }

        if ($product->priceFor($this->currency(), $cycle) === null) {
            throw ValidationException::withMessages(['billing_cycle' => __('Choose one of the billing options shown.')]);
        }

        if ($product->requires_domain && blank($data['domain'] ?? null)) {
            throw ValidationException::withMessages(['domain' => __('Enter the domain name for this service.')]);
        }

        $domain = $product->requires_domain ? $data['domain'] : null;

        if ($domain !== null && $request->boolean('register_domain')) {
            $this->addRegistration($cart, $checker, $domain, 1);
        }

        $cart->add($product, $cycle, $domain, array_map('intval', $data['addons'] ?? []));

        return redirect()->route('cart.show')->with('status', __(':product added to your cart.', ['product' => $product->name]));
    }

    /**
     * Add a domain registration or transfer from the domain search.
     */
    public function storeDomain(Request $request, Cart $cart, AvailabilityChecker $checker): RedirectResponse
    {
        $request->merge(['domain' => DomainName::normalize((string) $request->input('domain')) ?? '!']);

        $data = $request->validate([
            'domain' => ['required', 'string', 'max:190', 'not_in:!'],
            'action' => ['required', Rule::in([Domain::TYPE_REGISTER, Domain::TYPE_TRANSFER])],
            'years' => ['nullable', 'integer', 'between:1,10'],
            'epp_code' => ['nullable', 'string', 'max:128'],
        ], ['domain.not_in' => __('Enter a domain like example.com.')]);

        $years = (int) ($data['years'] ?? 1);

        if ($data['action'] === Domain::TYPE_TRANSFER) {
            $price = $this->priceFor($data['domain']);

            if ($price->epp_required && blank($data['epp_code'] ?? null)) {
                throw ValidationException::withMessages(['epp_code' => __('Enter the authorization (EPP) code. Your current registrar gives it to you.')]);
            }

            $cart->addDomain($data['domain'], Domain::TYPE_TRANSFER, $years, $data['epp_code'] ?? null);
        } else {
            $this->addRegistration($cart, $checker, $data['domain'], $years);
        }

        return redirect()->route('cart.show')->with('status', __(':domain added to your cart.', ['domain' => $data['domain']]));
    }

    public function coupon(Request $request, Cart $cart): RedirectResponse
    {
        $code = (string) $request->validate(['code' => ['nullable', 'string', 'max:40']])['code'];
        $cart->setCoupon($code);

        if ($code === '') {
            return back()->with('status', __('Coupon removed.'));
        }

        $problem = $cart->couponProblem($this->currency());

        if ($problem !== null) {
            $cart->setCoupon(null);

            return back()->withErrors(['code' => $problem])->withInput();
        }

        return back()->with('status', __('Coupon :code applied.', ['code' => $cart->couponCode()]));
    }

    public function destroy(int $index, Cart $cart): RedirectResponse
    {
        $cart->remove($index);

        return redirect()->route('cart.show');
    }

    private function addRegistration(Cart $cart, AvailabilityChecker $checker, string $domain, int $years): void
    {
        $price = $this->priceFor($domain);

        if ($checker->isAvailable($domain, $this->currency()) === false) {
            throw ValidationException::withMessages(['domain' => __(':domain is already taken. Choose another name, or say you already own it.', ['domain' => $domain])]);
        }

        $cart->addDomain($domain, Domain::TYPE_REGISTER, min(max($years, $price->min_years), $price->max_years));
    }

    private function priceFor(string $domain): TldPrice
    {
        $prices = TldPrice::query()->enabled($this->currency())->get();
        [, $tld] = DomainName::split($domain, $prices->pluck('tld'));

        return $prices->firstWhere('tld', $tld)
            ?? throw ValidationException::withMessages(['domain' => __('We do not sell .:tld domains.', ['tld' => $tld])]);
    }

    private function currency(): string
    {
        return auth('web')->user()?->currency ?? (string) setting('billing.currency');
    }
}
