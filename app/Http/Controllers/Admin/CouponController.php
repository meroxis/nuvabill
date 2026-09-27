<?php

namespace App\Http\Controllers\Admin;

use App\Enums\BillingCycle;
use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Product;
use App\Support\Activity;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CouponController extends Controller
{
    public function index(Request $request): View
    {
        $coupons = Coupon::query()
            ->when($request->string('q')->toString(), fn ($query, string $term) => $query->where('code', 'like', '%'.Coupon::normalize($term).'%'))
            ->orderByDesc('is_active')
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        $monthStart = now()->startOfMonth();

        return view('admin.coupons.index', [
            'coupons' => $coupons,
            'products' => Product::query()->pluck('name', 'id'),
            'activeCount' => Coupon::query()->where('is_active', true)->get()->reject(fn (Coupon $coupon): bool => $coupon->isExpired())->count(),
            'usedThisMonth' => CouponRedemption::query()->where('created_at', '>=', $monthStart)->count(),
            'givenThisMonth' => (int) CouponRedemption::query()->where('created_at', '>=', $monthStart)->where('currency', setting('billing.currency'))->sum('amount'),
            'currency' => setting('billing.currency'),
        ]);
    }

    public function create(): View
    {
        return $this->form(new Coupon([
            'code' => strtoupper(Str::random(8)),
            'type' => Coupon::TYPE_PERCENT,
            'value' => 10,
            'recurring' => Coupon::RECURRING_FIRST,
            'is_active' => true,
        ]));
    }

    public function store(Request $request): RedirectResponse
    {
        $coupon = Coupon::create($this->validated($request));
        Activity::log('coupon.created', "Coupon {$coupon->code} created");

        return redirect()->route('admin.coupons.index')->with('status', __('Coupon :code created.', ['code' => $coupon->code]));
    }

    public function edit(Coupon $coupon): View
    {
        return $this->form($coupon);
    }

    public function update(Request $request, Coupon $coupon): RedirectResponse
    {
        $coupon->update($this->validated($request, $coupon));
        Activity::log('coupon.updated', "Coupon {$coupon->code} updated");

        return redirect()->route('admin.coupons.index')->with('status', __('Coupon :code saved.', ['code' => $coupon->code]));
    }

    public function destroy(Coupon $coupon): RedirectResponse
    {
        $coupon->delete();
        Activity::log('coupon.deleted', "Coupon {$coupon->code} deleted");

        return redirect()->route('admin.coupons.index')->with('status', __('Coupon deleted. Services that had it keep the discounts they already got.'));
    }

    private function form(Coupon $coupon): View
    {
        return view('admin.coupons.form', [
            'coupon' => $coupon,
            'products' => Product::query()->orderBy('name')->pluck('name', 'id')->all(),
            'cycles' => collect(BillingCycle::cases())->reject(fn (BillingCycle $cycle): bool => $cycle === BillingCycle::Free)->mapWithKeys(fn (BillingCycle $cycle): array => [$cycle->value => $cycle->label()])->all(),
            'currency' => setting('billing.currency'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Coupon $coupon = null): array
    {
        $request->merge(['code' => Coupon::normalize((string) $request->input('code'))]);

        $data = $request->validate([
            'code' => ['required', 'string', 'max:40', 'regex:/^[A-Z0-9_-]+$/', Rule::unique('coupons', 'code')->ignore($coupon)],
            'type' => ['required', Rule::in([Coupon::TYPE_PERCENT, Coupon::TYPE_FIXED])],
            'value' => ['required', 'numeric', 'gt:0', $request->input('type') === Coupon::TYPE_PERCENT ? 'max:100' : 'max:1000000'],
            'product_ids' => ['nullable', 'array'],
            'product_ids.*' => ['integer', 'exists:products,id'],
            'billing_cycles' => ['nullable', 'array'],
            'billing_cycles.*' => [Rule::enum(BillingCycle::class)],
            'applies_to_domains' => ['boolean'],
            'recurring' => ['required', Rule::in([Coupon::RECURRING_FIRST, Coupon::RECURRING_EVERY, Coupon::RECURRING_COUNT])],
            'recurring_count' => ['nullable', 'required_if:recurring,count', 'integer', 'between:2,120'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'max_uses' => ['nullable', 'integer', 'min:1'],
            'max_uses_per_client' => ['nullable', 'integer', 'min:1'],
            'new_clients_only' => ['boolean'],
            'is_active' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], ['code.regex' => __('Use only letters, numbers, dashes and underscores.')]);

        $fixed = $data['type'] === Coupon::TYPE_FIXED;

        return [
            ...$data,
            'value' => $fixed ? Money::toMinor($data['value']) : (int) round((float) $data['value']),
            'currency' => $fixed ? setting('billing.currency') : null,
            'product_ids' => array_values(array_map('intval', $data['product_ids'] ?? [])) ?: null,
            'billing_cycles' => array_values($data['billing_cycles'] ?? []) ?: null,
            'applies_to_domains' => $request->boolean('applies_to_domains'),
            'recurring_count' => $data['recurring'] === Coupon::RECURRING_COUNT ? (int) $data['recurring_count'] : null,
            'new_clients_only' => $request->boolean('new_clients_only'),
            'is_active' => $request->boolean('is_active'),
        ];
    }
}
