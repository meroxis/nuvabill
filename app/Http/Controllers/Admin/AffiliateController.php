<?php

namespace App\Http\Controllers\Admin;

use App\Billing\Affiliates;
use App\Http\Controllers\Controller;
use App\Models\Affiliate;
use App\Models\AffiliateCommission;
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
                ->withSum(['commissions as earned' => fn ($query) => $query->whereIn('status', [AffiliateCommission::STATUS_PENDING, AffiliateCommission::STATUS_AVAILABLE, AffiliateCommission::STATUS_PAID])], 'amount')
                ->latest('id')
                ->paginate(25),
            'waiting' => AffiliateCommission::query()->where('status', AffiliateCommission::STATUS_AVAILABLE)->sum('amount'),
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
    public function commission(Request $request, AffiliateCommission $commission, string $action): RedirectResponse
    {
        $allowed = match ($action) {
            'release' => $commission->status === AffiliateCommission::STATUS_PENDING,
            'cancel' => in_array($commission->status, [AffiliateCommission::STATUS_PENDING, AffiliateCommission::STATUS_AVAILABLE], true),
            'paid' => $commission->status === AffiliateCommission::STATUS_AVAILABLE,
            default => false,
        };

        abort_unless($allowed, 422);

        $commission->update(match ($action) {
            'release' => ['status' => AffiliateCommission::STATUS_AVAILABLE],
            'cancel' => ['status' => AffiliateCommission::STATUS_CANCELLED],
            'paid' => ['status' => AffiliateCommission::STATUS_PAID, 'paid_at' => now()],
        });

        Activity::log('affiliate.commission', "Commission #{$commission->id} of ".money($commission->amount, $commission->currency).": {$action}", client: $commission->affiliate->client);

        return back()->with('status', __('Commission updated.'));
    }
}
