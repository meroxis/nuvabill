<?php

namespace App\Http\Controllers\Admin;

use App\Billing\AutoPay;
use App\Billing\SavedMethods;
use App\Extensions\ExtensionManager;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\PaymentMethod;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Settings → Automatic payments, "Charge now" on an invoice, and removing a client's saved method.
 * Staff never see or type card numbers: saving a method is always the client's own step.
 */
class AutoPayController extends Controller
{
    public function edit(AutoPay $autoPay, SavedMethods $methods, ExtensionManager $extensions): View
    {
        $tomorrow = CarbonImmutable::tomorrow();

        return view('admin.settings.autopay', [
            'gateways' => $extensions->activeGateways(),
            'savable' => $methods->gateways()->keys()->all(),
            'retryDays' => implode(', ', AutoPay::retryDays()),
            'tomorrow' => $autoPay->isOn() ? $autoPay->dueInvoices($tomorrow)
                ->map(fn (Invoice $invoice): array => ['invoice' => $invoice, 'method' => $autoPay->methodFor($invoice)])
                ->filter(fn (array $row): bool => $row['method'] !== null)
                ->values() : collect(),
            'paid' => ActivityLog::query()->where('action', 'invoice.autopay_paid')->where('created_at', '>=', now()->subDays(30))->count(),
            'failed' => ActivityLog::query()->where('action', 'invoice.autopay_failed')->where('created_at', '>=', now()->subDays(30))->count(),
            'savedCount' => PaymentMethod::query()->count(),
        ]);
    }

    public function update(Request $request, Settings $settings): RedirectResponse
    {
        $data = $request->validate([
            'autopay' => ['boolean'],
            'days_before' => ['required', 'integer', 'min:0', 'max:14'],
            'notice_days' => ['required', 'integer', 'min:0', 'max:14'],
            'retry_days' => ['nullable', 'string', 'max:40', 'regex:/^[0-9,\s]*$/'],
            'offer_save' => ['boolean'],
            'card_notice' => ['boolean'],
        ], [
            'retry_days.regex' => __('Write the days as numbers with commas, for example 3, 7.'),
        ]);

        $retries = collect(preg_split('/[\s,]+/', (string) ($data['retry_days'] ?? ''), -1, PREG_SPLIT_NO_EMPTY))
            ->map(fn (string $day): int => (int) $day)
            ->filter(fn (int $day): bool => $day > 0 && $day <= 60)
            ->unique()
            ->sort()
            ->take(5)
            ->values()
            ->all();

        $settings->setMany([
            'billing.autopay' => $request->boolean('autopay'),
            'billing.autopay_days_before' => (int) $data['days_before'],
            'billing.autopay_notice_days' => (int) $data['notice_days'],
            'billing.autopay_retry_days' => $retries,
            'billing.autopay_offer_save' => $request->boolean('offer_save'),
            'billing.autopay_card_notice' => $request->boolean('card_notice'),
        ]);

        return back()->with('status', __('Automatic payment settings saved.'));
    }

    public function charge(Request $request, Invoice $invoice, AutoPay $autoPay): RedirectResponse
    {
        $result = $autoPay->charge($invoice, by: $request->user('admin')->name);

        if ($result->isPending()) {
            return back()->with('status', __('The payment is not finished yet: :reason', ['reason' => $result->message]));
        }

        return back()->with($result->isPaid() ? 'status' : 'error', $result->isPaid()
            ? __('The invoice is paid.')
            : __('The charge did not work: :reason', ['reason' => $result->message]));
    }

    public function forget(Request $request, Client $client, PaymentMethod $paymentMethod, SavedMethods $methods): RedirectResponse
    {
        abort_unless($paymentMethod->client_id === $client->id, 404);
        $methods->forget($paymentMethod, $request->user('admin')->name);

        return back()->with('status', __(':method is removed.', ['method' => $paymentMethod->label()]));
    }
}
