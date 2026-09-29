<?php

namespace App\Http\Controllers\Client;

use App\Billing\AutoPay;
use App\Billing\SavedMethods;
use App\Enums\InvoiceStatus;
use App\Enums\ServiceStatus;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\PaymentMethod;
use App\Support\Activity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

/**
 * Account → Payment methods: the cards and PayPal accounts that pay renewals automatically.
 */
class PaymentMethodController extends Controller
{
    public function index(Request $request, SavedMethods $methods, AutoPay $autoPay): View
    {
        /** @var Client $client */
        $client = $request->user('web');
        $default = $client->defaultPaymentMethod();

        return view('theme::client.payment-methods', [
            'client' => $client,
            'methods' => $client->paymentMethods()->orderByDesc('is_default')->latest('id')->get(),
            'gateways' => $methods->gateways($client->currency),
            'autoPayOn' => $autoPay->isOn(),
            'invoices' => $client->invoices()
                ->where('status', InvoiceStatus::Unpaid)
                ->orderBy('due_at')
                ->limit(10)
                ->get()
                ->filter(fn ($invoice): bool => $autoPay->methodFor($invoice) !== null)
                ->map(fn ($invoice): array => ['invoice' => $invoice, 'date' => $autoPay->chargeDate($invoice)]),
            'services' => $default === null ? collect() : $client->services()
                ->with('product')
                ->where('status', ServiceStatus::Active)
                ->where('recurring_amount', '>', 0)
                ->whereNotNull('next_due_date')
                ->orderBy('next_due_date')
                ->limit(6)
                ->get(),
        ]);
    }

    /**
     * Payment methods → Add a card / Add PayPal: saving happens on the gateway's own page.
     */
    public function store(Request $request, string $gateway, SavedMethods $methods): RedirectResponse
    {
        /** @var Client $client */
        $client = $request->user('web');
        $instance = $methods->gateways($client->currency)->get($gateway);

        if ($instance === null) {
            return back()->with('error', __('Choose one of the payment methods shown.'));
        }

        try {
            $start = $instance->startSavingMethod($client, route('client.account.payment-methods.return', $gateway), route('client.account.payment-methods'));
        } catch (Throwable $exception) {
            report($exception);
            Activity::log('payment_method.failed', "{$gateway} could not start saving a payment method: ".Str::limit($exception->getMessage(), 300), client: $client);

            return back()->with('error', __('This payment method cannot be saved right now. Please try again later.'));
        }

        return redirect()->away((string) $start->redirectUrl);
    }

    public function finish(Request $request, string $gateway, SavedMethods $methods): RedirectResponse
    {
        /** @var Client $client */
        $client = $request->user('web');
        $instance = $methods->gateway($gateway);

        try {
            $saved = $instance?->finishSavingMethod($request, $client);
        } catch (Throwable $exception) {
            report($exception);
            $saved = null;
        }

        if ($saved === null) {
            return redirect()->route('client.account.payment-methods')->with('error', __('Nothing was saved. Please try again.'));
        }

        $method = $methods->remember($client, $gateway, $saved);

        return redirect()->route('client.account.payment-methods')->with('status', __(':method is saved and pays your renewals automatically.', ['method' => $method->label()]));
    }

    public function automatic(Request $request): RedirectResponse
    {
        /** @var Client $client */
        $client = $request->user('web');
        $on = $request->boolean('auto_pay');
        $client->forceFill(['auto_pay' => $on])->save();
        Activity::log('client.auto_pay', "{$client->name} turned automatic payments ".($on ? 'on' : 'off'), client: $client);

        return back()->with('status', $on ? __('Automatic payments are on.') : __('Automatic payments are off. Pay your invoices from the invoice page.'));
    }

    public function makeDefault(Request $request, PaymentMethod $paymentMethod, SavedMethods $methods): RedirectResponse
    {
        abort_unless($paymentMethod->client_id === $request->user('web')->id, 404);
        $methods->makeDefault($paymentMethod);

        return back()->with('status', __(':method now pays your renewals.', ['method' => $paymentMethod->label()]));
    }

    public function destroy(Request $request, PaymentMethod $paymentMethod, SavedMethods $methods): RedirectResponse
    {
        /** @var Client $client */
        $client = $request->user('web');
        abort_unless($paymentMethod->client_id === $client->id, 404);
        $methods->forget($paymentMethod, $client->name);

        return back()->with('status', __(':method is removed.', ['method' => $paymentMethod->label()]));
    }
}
