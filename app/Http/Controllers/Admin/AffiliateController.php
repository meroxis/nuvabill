<?php

namespace App\Http\Controllers\Admin;

use App\Billing\Affiliates;
use App\Http\Controllers\Controller;
use App\Models\Affiliate;
use App\Models\AffiliateCommission;
use App\Models\Client;
use App\Support\Activity;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Affiliates: program settings, the affiliates and their commissions.
 */
class AffiliateController extends Controller
{
    public function index(): View
    {
        return view('admin.affiliates.index', [
            'affiliates' => Affiliate::query()
                ->with('client')
                ->withCount('referrals')
                // Only commissions in the affiliate's own currency, like the totals on their page.
                ->withSum(['commissions as earned' => fn ($query) => $query
                    ->whereIn('status', [AffiliateCommission::STATUS_PENDING, AffiliateCommission::STATUS_AVAILABLE, AffiliateCommission::STATUS_PAID])
                    ->where('affiliate_commissions.currency', Client::query()->select('currency')->whereColumn('clients.id', 'affiliates.client_id')),
                ], 'amount')
                ->latest('id')
                ->paginate(25),
            // One total per currency, never added together; the default currency comes first.
            'waiting' => AffiliateCommission::query()
                ->where('status', AffiliateCommission::STATUS_AVAILABLE)
                ->selectRaw('currency, sum(amount) as total')
                ->groupBy('currency')
                ->pluck('total', 'currency')
                ->map(fn (mixed $total): int => (int) $total)
                ->filter(fn (int $total): bool => $total > 0)
                ->sortBy(fn (int $total, string $currency): string => ($currency === setting('billing.currency') ? '0' : '1').$currency),
        ]);
    }

    public function settings(Request $request, Settings $settings): RedirectResponse
    {
        $data = $request->validate([
            'enabled' => ['boolean'],
            'recurring' => ['boolean'],
            'percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'hold_days' => ['required', 'integer', 'min:0', 'max:365'],
            'cookie_days' => ['required', 'integer', 'min:1', 'max:365'],
        ]);

        $settings->setMany([
            'affiliates.enabled' => $request->boolean('enabled'),
            'affiliates.recurring' => $request->boolean('recurring'),
            'affiliates.percent' => (float) $data['percent'],
            'affiliates.hold_days' => (int) $data['hold_days'],
            'affiliates.cookie_days' => (int) $data['cookie_days'],
        ]);

        Activity::log('settings.affiliates', 'Affiliate program '.($request->boolean('enabled') ? 'on' : 'off').", {$data['percent']}% commission");

        return back()->with('status', __('Affiliate settings saved.'));
    }

    public function show(Affiliate $affiliate, Affiliates $affiliates): View
    {
        return view('admin.affiliates.show', [
            'affiliate' => $affiliate->load('client'),
            'totals' => $affiliates->totals($affiliate),
            'referrals' => $affiliate->referrals()->with('client')->latest('id')->limit(20)->get(),
            'commissions' => $affiliate->commissions()->with('client', 'invoice')->latest('id')->paginate(20),
        ]);
    }

    public function update(Request $request, Affiliate $affiliate): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in([Affiliate::STATUS_ACTIVE, Affiliate::STATUS_SUSPENDED])],
            'percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        $affiliate->update(['status' => $data['status'], 'percent' => $data['percent'] ?? null]);
        Activity::log('affiliate.updated', "Affiliate {$affiliate->client->name}: {$affiliate->status}".($affiliate->percent !== null ? ", {$affiliate->percent}%" : ''), client: $affiliate->client);

        return back()->with('status', __('Affiliate saved.'));
    }

    /**
     * Release a commission early, cancel it, or mark it paid after paying the affiliate yourself.
     */
    public function commission(Request $request, AffiliateCommission $commission, string $action, Affiliates $affiliates): RedirectResponse
    {
        $allowed = match ($action) {
            'release' => $commission->status === AffiliateCommission::STATUS_PENDING,
            'cancel' => in_array($commission->status, [AffiliateCommission::STATUS_PENDING, AffiliateCommission::STATUS_AVAILABLE], true),
            'paid' => $commission->status === AffiliateCommission::STATUS_AVAILABLE,
            default => false,
        };

        abort_unless($allowed, 422);

        // Only a commission still in the status checked above changes, so a move to the wallet
        // that happened meanwhile is never overwritten.
        $changed = $action === 'release'
            ? $affiliates->releaseOne($commission) !== null
            : AffiliateCommission::query()->whereKey($commission->id)->where('status', $commission->status)->update(match ($action) {
                'cancel' => ['status' => AffiliateCommission::STATUS_CANCELLED],
                'paid' => ['status' => AffiliateCommission::STATUS_PAID, 'paid_at' => now()],
            }) === 1;

        if (! $changed) {
            return back()->with('error', __('This commission changed in the meantime. Reload the page and try again.'));
        }

        $commission->refresh();
        Activity::log('affiliate.commission', "Commission #{$commission->id} of ".money($commission->amount, $commission->currency).": {$action}", client: $commission->affiliate->client);

        if ($action === 'release' && $commission->status === AffiliateCommission::STATUS_CANCELLED) {
            return back()->with('status', __('The invoice was refunded or is not paid, so the commission was cancelled.'));
        }

        return back()->with('status', __('Commission updated.'));
    }
}
