<?php

namespace App\Http\Controllers\Client;

use App\Billing\Affiliates;
use App\Http\Controllers\Controller;
use App\Models\Affiliate;
use App\Support\Activity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The affiliate program in the client area: join, share the link, see earnings, move them to the wallet.
 */
class AffiliateController extends Controller
{
    public function show(Request $request, Affiliates $affiliates): View
    {
        abort_unless($affiliates->enabled(), 404);

        $client = $request->user('web');
        $affiliate = Affiliate::query()->where('client_id', $client->id)->first();

        return view('theme::client.affiliate', [
            'client' => $client,
            'affiliate' => $affiliate,
            'totals' => $affiliate ? $affiliates->totals($affiliate) : null,
            'referrals' => $affiliate?->referrals()->count() ?? 0,
            'commissions' => $affiliate?->commissions()->latest('id')->paginate(15),
        ]);
    }

    public function join(Request $request, Affiliates $affiliates): RedirectResponse
    {
        abort_unless($affiliates->enabled(), 404);

        $client = $request->user('web');
        $affiliate = $affiliates->join($client);
        Activity::log('affiliate.joined', "{$client->name} joined the affiliate program", client: $client);

        return redirect()->route('client.affiliate')->with('status', __('Welcome! Share your link: :link', ['link' => $affiliate->link()]));
    }

    public function withdraw(Request $request, Affiliates $affiliates): RedirectResponse
    {
        $affiliate = Affiliate::query()->where('client_id', $request->user('web')->id)->firstOrFail();
        abort_unless($affiliate->isActive(), 403);

        $moved = $affiliates->withdrawToWallet($affiliate);

        if ($moved === 0) {
            return back()->with('error', __('There is nothing to move yet.'));
        }

        Activity::log('affiliate.withdrawn', 'Affiliate commissions of '.money($moved, $affiliate->client->currency).' moved to the wallet', client: $affiliate->client);

        return back()->with('status', __(':amount is now in your wallet.', ['amount' => money($moved, $affiliate->client->currency)]));
    }
}
