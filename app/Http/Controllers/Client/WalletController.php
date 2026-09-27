<?php

namespace App\Http\Controllers\Client;

use App\Billing\Wallet;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Support\Activity;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * The client's wallet: balance, history, adding funds and paying invoices from it.
 */
class WalletController extends Controller
{
    public function show(Request $request, Wallet $wallet): View
    {
        $client = $request->user('web');
        [$min, $max] = $wallet->depositLimits();

        return view('theme::client.wallet', [
            'client' => $client,
            'entries' => $client->creditTransactions()->latest('id')->paginate(15),
            'canAdd' => $wallet->enabled(),
            'min' => $min,
            'max' => $max,
            'unpaid' => $client->invoices()->where('status', 'unpaid')->get()->filter(fn (Invoice $invoice): bool => $invoice->isPayable() && ! $wallet->isTopUp($invoice)),
        ]);
    }

    public function store(Request $request, Wallet $wallet): RedirectResponse
    {
        abort_unless($wallet->enabled(), 404);

        $client = $request->user('web');
        [$min, $max] = $wallet->depositLimits();
        $amount = Money::toMinor($request->validate(['amount' => ['required', 'numeric', 'min:0']])['amount']);

        if ($amount < $min || $amount > $max) {
            throw ValidationException::withMessages(['amount' => __('Add between :min and :max.', ['min' => money($min, $client->currency), 'max' => money($max, $client->currency)])]);
        }

        $invoice = $wallet->topUp($client, $amount);
        Activity::log('wallet.top_up', 'Client asked to add '.money($amount, $client->currency).' to their wallet', $invoice, $client);

        return redirect()->route('client.invoices.show', $invoice)->with('status', __('Pay this invoice and the money goes into your wallet.'));
    }

    public function pay(Request $request, Invoice $invoice, Wallet $wallet): RedirectResponse
    {
        abort_unless($invoice->client_id === $request->user('web')->id, 404);

        $paid = $wallet->pay($invoice);

        if ($paid === 0) {
            return back()->with('error', __('There is no money in your wallet for this invoice.'));
        }

        return redirect()->route('client.invoices.show', $invoice)->with('status', $invoice->fresh()->isPayable()
            ? __(':amount was paid from your wallet. Pay the rest below.', ['amount' => money($paid, $invoice->currency)])
            : __('Paid from your wallet. Thank you!'));
    }
}
