<?php

namespace App\Http\Controllers\Store;

use App\Auth\ClientRegistrar;
use App\Billing\Cart;
use App\Billing\CartLine;
use App\Billing\ExchangeRates;
use App\Billing\OrderPlacer;
use App\Billing\PaymentStarter;
use App\Billing\Taxes;
use App\Domains\AvailabilityChecker;
use App\Domains\DomainName;
use App\Domains\DomainSearch;
use App\Enums\BillingCycle;
use App\Extensions\ExtensionManager;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Domain;
use App\Models\Product;
use App\Models\TldPrice;
use App\Support\Demo;
use App\Support\Locales;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * One-page ordering for order forms such as Swift: search domains and price the order while the
 * client chooses (JSON), then sign up, place the order and start paying in one step.
 */
class QuickOrderController extends Controller
{
    public function domains(Request $request, DomainSearch $search): JsonResponse
    {
        $query = trim((string) $request->validate(['q' => ['required', 'string', 'max:190']])['q']);
        $currency = $this->currency();

        $results = $search->search($query, $currency, suggestions: 5)->map(fn (array $result): array => [
            'domain' => $result['domain'],
            'available' => $result['available'],
            'exact' => $result['exact'],
            'price' => $result['tld'] ? money($result['tld']->priceFor(Domain::TYPE_REGISTER, 1), $currency) : null,
            'transfer_price' => $result['tld'] ? money($result['tld']->priceFor(Domain::TYPE_TRANSFER, 1), $currency) : null,
        ]);

        return response()->json(['results' => $results->values()]);
    }

    /**
     * What the order costs with the choices so far, including any coupon and the amount each
     * payment method charges.
     */
    public function quote(Request $request, ExtensionManager $extensions, ExchangeRates $rates, Taxes $taxes): JsonResponse
    {
        $data = $this->validateOrder($request, forQuote: true);
        $currency = $this->currency();
        $cart = new Cart(new Store('quote', new ArraySessionHandler(1)));
        [$product, $cycle] = $this->fill($cart, $data, $currency, checkAvailability: false);

        $client = $request->user('web');
        $lines = $cart->lines($currency, $client);
        $country = preg_match('/^[A-Za-z]{2}$/', (string) $request->input('country')) ? (string) $request->input('country') : null;
        $tax = $taxes->forCart($lines, $client, $country, $request->string('state')->limit(100, '')->toString());
        $total = $tax['total'];
        $productLine = $lines->first(fn (CartLine $line): bool => ! $line->isDomain());
        $monthly = $product->priceFor($currency, BillingCycle::Monthly);
        $coupon = $cart->coupon($currency, $client);
        $saving = $monthly !== null && $cycle->months() > 1 && $productLine !== null ? max(0, $monthly->price * $cycle->months() - $productLine->price) : 0;
        $discount = (int) $lines->sum(fn (CartLine $line): int => $line->discount);

        return response()->json([
            'currency' => $currency,
            'lines' => $lines->map(fn (CartLine $line): array => [
                'title' => $line->title(),
                'summary' => $line->summary(),
                'amount' => money($line->subtotal(), $currency),
                'addons' => array_map(fn (array $addon): string => $addon['name'], $line->addons),
            ])->values(),
            'subtotal' => money((int) $lines->sum(fn (CartLine $line): int => $line->subtotal()), $currency),
            'discount' => (int) $lines->sum(fn (CartLine $line): int => $line->discount),
            'discount_label' => money((int) $lines->sum(fn (CartLine $line): int => $line->discount), $currency),
            'tax' => $tax['label'] !== null ? ['label' => $tax['inclusive'] ? __(':tax included', ['tax' => $tax['label']]) : $tax['label'], 'amount' => money($tax['tax'], $currency)] : null,
            'total' => $total,
            'total_label' => money($total, $currency),
            'saving' => $saving > 0 ? money($saving, $currency) : null,
            'saved_label' => $saving + $discount > 0 ? money($saving + $discount, $currency) : null,
            'renewal' => $productLine && $cycle->isRecurring()
                ? __(':amount :cycle', ['amount' => money($productLine->price + array_sum(array_column($productLine->addons, 'price')), $currency), 'cycle' => Locales::inSentence($cycle->label())])
                : null,
            'coupon' => $coupon ? ['code' => $coupon->code, 'label' => $coupon->describe().' · '.$coupon->paymentsLabel()] : null,
            'coupon_problem' => $cart->couponProblem($currency, $client),
            'payment' => $extensions->activeGateways($currency)->map(function ($gateway) use ($total, $currency, $rates): array {
                $charge = $gateway->chargeCurrencyFor($currency);
                $converted = $charge !== null && $charge !== $currency ? $rates->convert($total, $currency, $charge) : null;

                return [
                    'slug' => $gateway->slug(),
                    'name' => $gateway->name(),
                    'pays' => $converted !== null ? money((int) ceil($converted / 100) * 100, $charge) : null,
                ];
            })->values(),
        ]);
    }

    public function store(Request $request, ClientRegistrar $registrar, OrderPlacer $placer, PaymentStarter $payments, Cart $cart): RedirectResponse
    {
        $data = $this->validateOrder($request, forQuote: false);
        $client = $request->user('web');

        if ($client === null) {
            $client = $registrar->register($request->validate(ClientRegistrar::rules(confirmPassword: false)));
            Auth::guard('web')->login($client);
            $request->session()->regenerate();
        }

        $currency = $client->currency;
        $cart->clear();
        $this->fill($cart, $data, $currency, checkAvailability: true);

        $coupon = $cart->coupon($currency, $client);
        $lines = $cart->lines($currency, $client);

        if ($lines->isEmpty()) {
            throw ValidationException::withMessages(['product_id' => __('This product is not available right now.')]);
        }

        $ipCountry = $request->isFromTrustedProxy() ? $request->header('CF-IPCountry') : null;
        $order = $placer->place($client, $lines, $request->ip(), $ipCountry, $coupon);
        $cart->clear();

        $invoice = $order->invoice;

        if ($invoice->isPayable() && filled($data['gateway'] ?? null) && ! Demo::isEnabled()) {
            return $payments->start($invoice, (string) $data['gateway'])->with('status', __('Thank you! Order #:number is placed.', ['number' => $order->number]));
        }

        return $invoice->isPayable()
            ? redirect()->route('client.invoices.show', $invoice)->with('status', __('Thank you! Order #:number is placed. Pay the invoice below to start your service.', ['number' => $order->number]))
            : redirect()->route('client.dashboard')->with('status', __('Thank you! Order #:number is placed.', ['number' => $order->number]));
    }

    /**
     * @return array<string, mixed>
     */
    private function validateOrder(Request $request, bool $forQuote): array
    {
        $request->merge(['domain' => filled($request->input('domain')) ? (DomainName::normalize((string) $request->input('domain')) ?? '!') : null]);

        $rules = [
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'billing_cycle' => ['required', Rule::enum(BillingCycle::class)],
            'addons' => ['sometimes', 'array', 'max:20'],
            'addons.*' => ['integer'],
            'domain_action' => ['nullable', Rule::in([Domain::TYPE_REGISTER, Domain::TYPE_TRANSFER, 'own', 'none'])],
            'domain' => ['nullable', 'string', 'max:190', 'not_in:!'],
            'epp_code' => ['nullable', 'string', 'max:128'],
            'coupon' => ['nullable', 'string', 'max:40'],
        ];

        if (! $forQuote) {
            $rules['gateway'] = ['nullable', 'string', 'max:64'];

            if (filled(setting('orders.accept_terms_url'))) {
                $rules['accept_terms'] = ['accepted'];
            }
        }

        return $request->validate($rules, [
            'domain.not_in' => __('Enter a domain like example.com.'),
            'accept_terms.accepted' => __('Please accept the terms of service.'),
        ]);
    }

    /**
     * Put the product, its add-ons, the domain and the coupon in the cart.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: Product, 1: BillingCycle}
     */
    private function fill(Cart $cart, array $data, string $currency, bool $checkAvailability): array
    {
        $product = Product::query()->with('prices')->findOrFail($data['product_id']);
        $cycle = BillingCycle::from($data['billing_cycle']);

        if (! $product->is_visible || ! $product->isInStock()) {
            throw ValidationException::withMessages(['product_id' => __('This product is not available right now.')]);
        }

        if ($product->priceFor($currency, $cycle) === null) {
            throw ValidationException::withMessages(['billing_cycle' => __('Choose one of the billing options shown.')]);
        }

        $domain = $data['domain'] ?? null;
        $action = $data['domain_action'] ?? ($domain ? 'own' : 'none');

        if ($product->requires_domain && $domain === null && ! ($checkAvailability === false)) {
            throw ValidationException::withMessages(['domain' => __('Enter the domain name for this service.')]);
        }

        $cart->add($product, $cycle, $product->requires_domain ? $domain : null, array_map('intval', $data['addons'] ?? []));

        if ($domain !== null && in_array($action, [Domain::TYPE_REGISTER, Domain::TYPE_TRANSFER], true)) {
            $price = $this->tldPrice($domain, $currency);

            if ($action === Domain::TYPE_TRANSFER && $checkAvailability && $price->epp_required && blank($data['epp_code'] ?? null)) {
                throw ValidationException::withMessages(['epp_code' => __('Enter the authorization (EPP) code. Your current registrar gives it to you.')]);
            }

            if ($action === Domain::TYPE_REGISTER && $checkAvailability && app(AvailabilityChecker::class)->isAvailable($domain, $currency) === false) {
                throw ValidationException::withMessages(['domain' => __(':domain is already taken. Choose another name, or say you already own it.', ['domain' => $domain])]);
            }

            $cart->addDomain($domain, $action, $price->min_years, $data['epp_code'] ?? null);
        }

        $cart->setCoupon($data['coupon'] ?? null);

        return [$product, $cycle];
    }

    private function tldPrice(string $domain, string $currency): TldPrice
    {
        $prices = TldPrice::query()->enabled($currency)->get();
        [, $tld] = DomainName::split($domain, $prices->pluck('tld'));

        return $prices->firstWhere('tld', $tld)
            ?? throw ValidationException::withMessages(['domain' => __('We do not sell .:tld domains.', ['tld' => $tld])]);
    }

    private function currency(): string
    {
        $client = auth('web')->user();

        return $client instanceof Client ? $client->currency : (string) setting('billing.currency');
    }
}
