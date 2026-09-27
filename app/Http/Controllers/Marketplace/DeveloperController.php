<?php

namespace App\Http\Controllers\Marketplace;

use App\Http\Controllers\Controller;
use App\Models\Developer;
use App\Models\DeveloperEarning;
use App\Models\MarketplaceMessage;
use App\Models\MarketplaceVersion;
use App\Support\Activity;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The developer portal in the store's client area: join, see earnings and items, set payout details.
 */
class DeveloperController extends Controller
{
    public const PAYOUT_METHODS = ['wayl' => 'Wayl', 'fib' => 'FIB', 'bank' => 'Bank transfer', 'paypal' => 'PayPal'];

    public function create(Request $request): View|RedirectResponse
    {
        if ($request->user('web')->developer !== null) {
            return redirect()->route('developer.dashboard');
        }

        return view('theme::developer.join', [
            'client' => $request->user('web'),
            'methods' => self::PAYOUT_METHODS,
            'share' => (int) setting('marketplace.developer_share', 83),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $client = $request->user('web');

        if ($client->developer !== null) {
            return redirect()->route('developer.dashboard');
        }

        $data = $this->validated($request) + $request->validate(['agree' => ['accepted']], ['agree.accepted' => __('Please accept the developer agreement.')]);

        $developer = Developer::create([
            'client_id' => $client->id,
            'name' => $data['name'],
            'slug' => $this->uniqueSlug($data['name']),
            'website' => $data['website'] ?? null,
            'bio' => $data['bio'] ?? null,
            'payout_method' => $data['payout_method'] ?? null,
            'payout_details' => $data['payout_details'] ?? null,
            'status' => Developer::STATUS_ACTIVE,
        ]);

        $client->setRelation('developer', $developer);
        Activity::log('developer.joined', "{$developer->name} became a marketplace developer", $client);

        return redirect()->route('developer.dashboard')->with('status', __('Welcome! You can send your first item now.'));
    }

    public function dashboard(Request $request): View|RedirectResponse
    {
        $developer = $request->user('web')->developer;

        if ($developer === null) {
            return redirect()->route('developer.join');
        }

        $currency = (string) setting('billing.currency');
        $developer->load(['items.latestVersion', 'items.versions' => fn ($query) => $query->latest('id')]);
        $months = collect(range(5, 0))->map(fn (int $ago): CarbonImmutable => CarbonImmutable::now()->startOfMonth()->subMonths($ago));

        $earnings = DeveloperEarning::query()
            ->where('developer_id', $developer->id)
            ->where('currency', $currency)
            ->where('created_at', '>=', $months->first())
            ->get(['developer_share', 'created_at'])
            ->groupBy(fn (DeveloperEarning $earning): string => $earning->created_at->format('Y-m'));

        $chart = $months->map(fn (CarbonImmutable $month): array => [
            'label' => $month->translatedFormat('M'),
            'amount' => (int) ($earnings->get($month->format('Y-m'))?->sum('developer_share') ?? 0),
        ]);

        $latestMessage = MarketplaceMessage::query()
            ->where('author_type', MarketplaceMessage::FROM_STAFF)
            ->whereHas('version.item', fn ($query) => $query->where('developer_id', $developer->id))
            ->with('version.item')
            ->latest('id')
            ->first();

        return view('theme::developer.dashboard', [
            'developer' => $developer,
            'currency' => $currency,
            'thisMonth' => $chart->last()['amount'],
            'salesThisMonth' => DeveloperEarning::query()->where('developer_id', $developer->id)->where('created_at', '>=', now()->startOfMonth())->count(),
            'allTime' => (int) DeveloperEarning::query()->where('developer_id', $developer->id)->where('currency', $currency)->sum('developer_share'),
            'balance' => $developer->balance($currency),
            'chart' => $chart,
            'waiting' => $developer->items->filter(fn ($item): bool => $item->versions->first()?->status === MarketplaceVersion::STATUS_CHANGES)->count(),
            'latestMessage' => $latestMessage,
            'methods' => self::PAYOUT_METHODS,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $developer = $request->user('web')->developer ?? abort(404);
        $data = $this->validated($request);

        $developer->update([
            'name' => $data['name'],
            'website' => $data['website'] ?? null,
            'bio' => $data['bio'] ?? null,
            'payout_method' => $data['payout_method'] ?? null,
            'payout_details' => filled($data['payout_details'] ?? null) ? $data['payout_details'] : $developer->payout_details,
        ]);

        return back()->with('status', __('Developer profile saved.'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'website' => ['nullable', 'url', 'max:190'],
            'bio' => ['nullable', 'string', 'max:500'],
            'payout_method' => ['nullable', Rule::in(array_keys(self::PAYOUT_METHODS))],
            'payout_details' => ['nullable', 'string', 'max:1000'],
        ]);
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'developer';
        $slug = $base;
        $number = 2;

        while (Developer::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$number++;
        }

        return $slug;
    }
}
