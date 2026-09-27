<?php

namespace App\Http\Controllers\Marketplace\Admin;

use App\Http\Controllers\Controller;
use App\Models\Developer;
use App\Models\DeveloperEarning;
use App\Models\Payout;
use App\Support\Activity;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * What each developer is owed, recording payouts once the money is sent, and the default commission.
 */
class PayoutController extends Controller
{
    public function index(): View
    {
        $currency = (string) setting('billing.currency');

        $owed = DeveloperEarning::query()
            ->whereNull('payout_id')
            ->where('currency', $currency)
            ->selectRaw('developer_id, sum(developer_share) as owed, sum(fee) as fees, count(*) as sales')
            ->groupBy('developer_id')
            ->get()
            ->keyBy('developer_id');

        return view('admin.store.payouts', [
            'developers' => Developer::query()->whereIn('id', $owed->keys())->orderBy('name')->get(),
            'owed' => $owed,
            'payouts' => Payout::query()->with('developer')->latest('id')->limit(20)->get(),
            'currency' => $currency,
            'share' => (int) setting('marketplace.developer_share', 83),
            'feesThisMonth' => (int) DeveloperEarning::query()->where('currency', $currency)->where('created_at', '>=', now()->startOfMonth())->sum('fee'),
            'salesThisMonth' => (int) DeveloperEarning::query()->where('currency', $currency)->where('created_at', '>=', now()->startOfMonth())->sum('gross'),
        ]);
    }

    /**
     * Record that everything a developer was owed has been paid.
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'developer_id' => ['required', 'integer', 'exists:developers,id'],
            'reference' => ['nullable', 'string', 'max:190'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $currency = (string) setting('billing.currency');
        $developer = Developer::query()->findOrFail($data['developer_id']);

        $payout = DB::transaction(function () use ($developer, $currency, $data): ?Payout {
            $earnings = DeveloperEarning::query()->where('developer_id', $developer->id)->whereNull('payout_id')->where('currency', $currency)->lockForUpdate()->get();
            $amount = (int) $earnings->sum('developer_share');

            if ($amount <= 0) {
                return null;
            }

            $payout = $developer->payouts()->create([
                'amount' => $amount,
                'currency' => $currency,
                'status' => Payout::STATUS_PAID,
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'paid_at' => now(),
            ]);

            DeveloperEarning::query()->whereIn('id', $earnings->pluck('id'))->update(['payout_id' => $payout->id]);

            return $payout;
        });

        if ($payout === null) {
            return back()->with('error', __(':name is not owed anything.', ['name' => $developer->name]));
        }

        Activity::log('payout.recorded', 'Payout of '.money($payout->amount, $currency)." to {$developer->name} recorded");

        return back()->with('status', __('Payout of :amount to :name recorded.', ['amount' => money($payout->amount, $currency), 'name' => $developer->name]));
    }

    public function commission(Request $request, Settings $settings): RedirectResponse
    {
        $share = (int) $request->validate(['share' => ['required', 'integer', 'between:0,100']])['share'];
        $settings->set('marketplace.developer_share', $share);
        Activity::log('marketplace.commission', "Default developer share set to {$share}%");

        return back()->with('status', __('Developers now keep :share% by default. Developers with their own share keep it.', ['share' => $share]));
    }
}
