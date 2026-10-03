<?php

namespace App\Billing;

use App\Enums\InvoiceStatus;
use App\Mail\TemplateMailer;
use App\Models\Affiliate;
use App\Models\AffiliateCommission;
use App\Models\AffiliateReferral;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Order;
use App\Support\Activity;
use App\Support\Locales;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The affiliate program: clients share a link (?ref=CODE), people who sign up through it become
 * their referrals, and each paid invoice of a referral earns the affiliate a commission.
 *
 * Commissions wait for the hold period so refunds can cancel them, then become available and can
 * be moved to the affiliate's wallet. By default only the first payment (the order) earns one;
 * the "recurring" setting pays on renewals too.
 */
class Affiliates
{
    /**
     * The cookie that remembers which affiliate sent a visitor.
     */
    public const COOKIE = 'nb_ref';

    public function __construct(
        private Wallet $wallet,
        private TemplateMailer $mailer,
    ) {}

    public function enabled(): bool
    {
        return (bool) setting('affiliates.enabled');
    }

    public function join(Client $client): Affiliate
    {
        return Affiliate::query()->firstOrCreate(['client_id' => $client->id], ['code' => $this->uniqueCode()]);
    }

    /**
     * Link a new client to the affiliate whose link they came from.
     */
    public function recordReferral(Client $client, ?string $code): void
    {
        if (! $this->enabled() || ! is_string($code) || $code === '') {
            return;
        }

        $affiliate = Affiliate::query()->where('code', strtoupper($code))->where('status', Affiliate::STATUS_ACTIVE)->first();

        if ($affiliate === null || $affiliate->client_id === $client->id) {
            return;
        }

        AffiliateReferral::query()->firstOrCreate(['client_id' => $client->id], ['affiliate_id' => $affiliate->id]);
        Activity::log('affiliate.referral', "{$client->name} signed up through {$affiliate->client->name}'s affiliate link", client: $client);
    }

    /**
     * Earn a commission when a referred client pays an invoice.
     */
    public function onInvoicePaid(Invoice $invoice): ?AffiliateCommission
    {
        if (! $this->enabled() || $invoice->total <= 0 || $this->wallet->isTopUp($invoice)) {
            return null;
        }

        $referral = AffiliateReferral::query()->with('affiliate.client')->where('client_id', $invoice->client_id)->first();
        $affiliate = $referral?->affiliate;

        if ($affiliate === null || ! $affiliate->isActive()) {
            return null;
        }

        if (! setting('affiliates.recurring') && ! Order::query()->where('invoice_id', $invoice->id)->exists()) {
            return null;
        }

        // Tax is not part of the sale: on prices with tax included, take it out.
        $base = $invoice->tax_inclusive ? $invoice->total - $invoice->tax : $invoice->subtotal;
        $amount = (int) round(max(0, $base) * $affiliate->rate() / 100);

        if ($amount <= 0 || AffiliateCommission::query()->where('invoice_id', $invoice->id)->exists()) {
            return null;
        }

        try {
            $commission = $affiliate->commissions()->create([
                'client_id' => $invoice->client_id,
                'invoice_id' => $invoice->id,
                'amount' => $amount,
                'currency' => $invoice->currency,
                'status' => AffiliateCommission::STATUS_PENDING,
                'available_at' => CarbonImmutable::today()->addDays((int) setting('affiliates.hold_days')),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Each invoice earns one commission (unique in the database); this one was just recorded.
            return null;
        }

        $this->mailer->send('affiliate.commission', $affiliate->client, [
            'commission' => ['amount' => money($amount, $invoice->currency), 'available_on' => Locales::in(Locales::forClient($affiliate->client), fn (): string => $commission->available_at->translatedFormat('d M Y'))],
            'affiliate_url' => route('client.affiliate'),
        ]);

        return $commission;
    }

    /**
     * After the hold period, commissions become available; ones for refunded invoices are cancelled.
     *
     * @return int The number of commissions released.
     */
    public function release(?CarbonInterface $today = null): int
    {
        $today = CarbonImmutable::parse($today ?? today());
        $released = 0;

        // By id, not by page: each commission leaves the "pending" list as it is handled.
        AffiliateCommission::query()
            ->with('invoice.creditNotes')
            ->where('status', AffiliateCommission::STATUS_PENDING)
            ->whereDate('available_at', '<=', $today)
            ->eachById(function (AffiliateCommission $commission) use (&$released): void {
                if ($this->releaseOne($commission) === AffiliateCommission::STATUS_AVAILABLE) {
                    $released++;
                }
            });

        return $released;
    }

    /**
     * Release one commission on hold. It becomes available for the part of the invoice the client
     * kept: credit notes that took back part of it cut it by the same share. When nothing was kept
     * (the invoice was refunded), it is cancelled.
     *
     * @return string|null The new status, or null when the commission was no longer on hold.
     */
    public function releaseOne(AffiliateCommission $commission): ?string
    {
        $commission->loadMissing('invoice.creditNotes');
        $invoice = $commission->invoice;
        $kept = $invoice?->status === InvoiceStatus::Paid && $invoice->total > 0 ? $invoice->creditableAmount() : 0;
        $amount = $kept > 0 ? (int) round($commission->amount * $kept / $invoice->total) : 0;
        $changes = $amount > 0
            ? ['status' => AffiliateCommission::STATUS_AVAILABLE, 'amount' => $amount]
            : ['status' => AffiliateCommission::STATUS_CANCELLED];

        // Only a commission that is still on hold changes, so a second run cannot release it again.
        $changed = AffiliateCommission::query()->whereKey($commission->id)
            ->where('status', AffiliateCommission::STATUS_PENDING)
            ->update($changes);

        if ($changed !== 1) {
            return null;
        }

        $commission->forceFill($changes)->syncOriginal();

        return $changes['status'];
    }

    /**
     * A refunded invoice earns nothing: cancel its commission while it is still on hold.
     */
    public function cancelForRefund(Invoice $invoice): void
    {
        AffiliateCommission::query()
            ->where('invoice_id', $invoice->id)
            ->where('status', AffiliateCommission::STATUS_PENDING)
            ->update(['status' => AffiliateCommission::STATUS_CANCELLED]);
    }

    /**
     * Move every available commission in the affiliate's currency into their wallet.
     *
     * @return int The amount moved, in minor units.
     */
    public function withdrawToWallet(Affiliate $affiliate): int
    {
        return DB::transaction(function () use ($affiliate): int {
            $client = $affiliate->client;
            $commissions = $affiliate->commissions()
                ->where('status', AffiliateCommission::STATUS_AVAILABLE)
                ->where('currency', $client->currency)
                ->lockForUpdate()
                ->get();
            $total = (int) $commissions->sum('amount');

            if ($total <= 0) {
                return 0;
            }

            $this->wallet->change($client, $total, __('Affiliate commissions'));
            AffiliateCommission::query()->whereKey($commissions->modelKeys())->update(['status' => AffiliateCommission::STATUS_PAID, 'paid_at' => now()]);

            return $total;
        });
    }

    /**
     * Totals for an affiliate, per status, in minor units.
     *
     * @return array{pending: int, available: int, paid: int}
     */
    public function totals(Affiliate $affiliate): array
    {
        $sums = $affiliate->commissions()->where('currency', $affiliate->client->currency)->selectRaw('status, sum(amount) as total')->groupBy('status')->pluck('total', 'status');

        return [
            'pending' => (int) ($sums[AffiliateCommission::STATUS_PENDING] ?? 0),
            'available' => (int) ($sums[AffiliateCommission::STATUS_AVAILABLE] ?? 0),
            'paid' => (int) ($sums[AffiliateCommission::STATUS_PAID] ?? 0),
        ];
    }

    private function uniqueCode(): string
    {
        do {
            $code = Str::upper(Str::random(8));
        } while (Affiliate::query()->where('code', $code)->exists());

        return $code;
    }
}
