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
use App\Models\PlanChange;
use App\Models\ResourceChange;
use App\Models\Transaction;
use App\Support\Activity;
use App\Support\Locales;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The affiliate program: clients share a link (?ref=CODE), people who sign up through it become
 * their referrals, and each paid invoice of a referral earns the affiliate a commission.
 *
 * A commission is worked out on real money only: the part of the invoice that was paid through a
 * payment method. Money paid from the wallet earns nothing, and the commissions for one referred
 * client never stand for more money than that client brought in.
 *
 * Commissions wait for the hold period, so money that goes back in that time cuts or cancels
 * them: a credit note, a refund or dispute at the payment service, or wallet credit for unused
 * time. Then they become available and can be moved to the affiliate's wallet. By default only
 * the first payment (the order) earns one; the "recurring" setting pays on renewals too.
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

        try {
            $commission = DB::transaction(fn (): ?AffiliateCommission => $this->earn($affiliate, $invoice));
        } catch (UniqueConstraintViolationException) {
            // Each invoice earns one commission (unique in the database); this one was just recorded.
            return null;
        }

        if ($commission === null) {
            return null;
        }

        $this->mailer->send('affiliate.commission', $affiliate->client, [
            'commission' => ['amount' => money($commission->amount, $commission->currency), 'available_on' => Locales::in(Locales::forClient($affiliate->client), fn (): string => $commission->available_at->translatedFormat('d M Y'))],
            'affiliate_url' => route('client.affiliate'),
        ]);

        return $commission;
    }

    /**
     * Work out and save the commission for a paid invoice. Call it inside a database transaction:
     * the invoice and then the referred client's row are locked until it ends (the order payments
     * lock them in), so two invoices paid at the same moment are worked out one after the other
     * and never count the same money.
     */
    private function earn(Affiliate $affiliate, Invoice $invoice): ?AffiliateCommission
    {
        $invoice = Invoice::query()->lockForUpdate()->find($invoice->id);

        if ($invoice === null || $invoice->status !== InvoiceStatus::Paid || $invoice->total <= 0) {
            return null;
        }

        Client::query()->whereKey($invoice->client_id)->lockForUpdate()->first();

        if (AffiliateCommission::query()->where('invoice_id', $invoice->id)->exists()) {
            return null;
        }

        $base = min($this->paidThroughGateways($invoice), $this->unclaimedMoney($invoice->client_id, $invoice->currency));
        // Tax is not part of the sale: on prices with tax included, take it out.
        $sale = $invoice->tax_inclusive ? $invoice->total - $invoice->tax : $invoice->subtotal;
        $amount = (int) round(max(0, $sale) * max(0, $base) / $invoice->total * $affiliate->rate() / 100);

        if ($amount <= 0) {
            return null;
        }

        return $affiliate->commissions()->create([
            'client_id' => $invoice->client_id,
            'invoice_id' => $invoice->id,
            'amount' => $amount,
            'base' => $base,
            'currency' => $invoice->currency,
            'status' => AffiliateCommission::STATUS_PENDING,
            'available_at' => CarbonImmutable::today()->addDays((int) setting('affiliates.hold_days')),
        ]);
    }

    /**
     * The part of an invoice that was paid through a payment method, in minor units with tax:
     * those payments less what went back the same way, and never more than the invoice less what
     * the wallet paid. Money from the wallet is not counted.
     */
    private function paidThroughGateways(Invoice $invoice): int
    {
        $throughGateways = (int) Transaction::query()->revenue()->where('invoice_id', $invoice->id)->sum('amount');
        $fromWallet = (int) Transaction::query()->where('invoice_id', $invoice->id)->where('gateway', Wallet::GATEWAY)->where('type', 'payment')->sum('amount');

        return max(0, min($throughGateways, $invoice->total - $fromWallet));
    }

    /**
     * The real money a referred client brought in that no commission stands for yet, in minor
     * units: what they paid through payment methods, less refunds and disputes, less the money
     * their other commissions stand for. A released commission stands for the money it was
     * released on. One still on hold stands for the part of its money the client kept, as it will
     * when it is released, so money that went back on one invoice is taken off once and not from
     * the commissions of the client's other invoices too. Older commissions, which do not keep
     * that money, are not counted.
     */
    private function unclaimedMoney(int $clientId, string $currency, ?int $exceptCommission = null): int
    {
        $broughtIn = (int) Transaction::query()->revenue()->where('client_id', $clientId)->where('currency', $currency)->sum('amount');
        $others = AffiliateCommission::query()
            ->where('client_id', $clientId)
            ->where('currency', $currency)
            ->whereNotNull('base')
            ->when($exceptCommission !== null, fn (Builder $query) => $query->whereKeyNot($exceptCommission));
        $released = (int) (clone $others)->whereIn('status', [AffiliateCommission::STATUS_AVAILABLE, AffiliateCommission::STATUS_PAID])->sum('base');
        $onHold = (int) (clone $others)->where('status', AffiliateCommission::STATUS_PENDING)->with('invoice.creditNotes')->get()
            ->sum(fn (AffiliateCommission $commission): int => $this->keptBase($commission));

        return max(0, $broughtIn - $released - $onHold);
    }

    /**
     * The money a commission on hold stands for now, in minor units: the money it was worked out
     * on, cut by the share of its invoice that went back since (see keptOf). Never more than it
     * was worked out on.
     */
    private function keptBase(AffiliateCommission $commission): int
    {
        $kept = $this->keptOf($commission->invoice);

        return $kept > 0 ? min((int) $commission->base, (int) round($commission->base * $kept / $commission->invoice->total)) : 0;
    }

    /**
     * How much of a paid invoice the client still pays for, in minor units: the total less credit
     * notes, less money that went back without one (a refund or dispute at the payment service),
     * and less wallet credit given for unused time of its services since it was paid. Nothing
     * when the invoice is not paid.
     */
    private function keptOf(?Invoice $invoice): int
    {
        if ($invoice === null || $invoice->status !== InvoiceStatus::Paid || $invoice->total <= 0) {
            return 0;
        }

        return max(0, $invoice->keptAmount() - $this->unusedTimeCredited($invoice));
    }

    /**
     * Wallet credit given for unused time of the invoice's services since it was paid, in minor
     * units: a move to a smaller plan, or extras taken off. Credit that could not go into the
     * wallet, and is settled by hand, counts too.
     */
    private function unusedTimeCredited(Invoice $invoice): int
    {
        $services = $invoice->items()->whereNotNull('service_id')->pluck('service_id')->unique()->values();

        if ($services->isEmpty()) {
            return 0;
        }

        $credited = fn (Builder $changes): int => -(int) $changes
            ->whereIn('service_id', $services)
            ->where('status', PlanChange::STATUS_APPLIED)
            ->where('mode', PlanChange::MODE_NOW)
            ->where('currency', $invoice->currency)
            ->where('difference', '<', 0)
            ->when($invoice->paid_at !== null, fn (Builder $query) => $query->where('applied_at', '>=', $invoice->paid_at))
            ->sum('difference');

        return $credited(PlanChange::query()) + $credited(ResourceChange::query());
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
     * kept: money that went back since (see keptOf) cuts it by the same share, and it never stands
     * for more real money than the referred client brought in. When nothing was kept (the invoice
     * was refunded), it is cancelled. The referred client's row is locked while it is worked out.
     *
     * @return string|null The new status, or null when the commission was no longer on hold.
     */
    public function releaseOne(AffiliateCommission $commission): ?string
    {
        $changes = DB::transaction(function () use ($commission): ?array {
            Client::query()->whereKey($commission->client_id)->lockForUpdate()->first();
            $held = AffiliateCommission::query()->with('invoice.creditNotes')->find($commission->id);

            if ($held === null || $held->status !== AffiliateCommission::STATUS_PENDING) {
                return null;
            }

            $kept = $this->keptOf($held->invoice);
            $amount = $kept > 0 ? (int) round($held->amount * $kept / $held->invoice->total) : 0;
            $money = [];

            if ($held->base !== null) {
                $base = $this->keptBase($held);
                $backed = min($base, $this->unclaimedMoney($held->client_id, $held->currency, $held->id));
                $amount = $base > 0 ? (int) round($amount * $backed / $base) : 0;
                $money = ['base' => $backed];
            }

            $changes = $amount > 0
                ? ['status' => AffiliateCommission::STATUS_AVAILABLE, 'amount' => $amount] + $money
                : ['status' => AffiliateCommission::STATUS_CANCELLED];

            // Only a commission that is still on hold changes, so a second run cannot release it again.
            $changed = AffiliateCommission::query()->whereKey($held->id)
                ->where('status', AffiliateCommission::STATUS_PENDING)
                ->update($changes);

            return $changed === 1 ? $changes : null;
        });

        if ($changes === null) {
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
     * Money paid on the invoice went back without a credit note: a refund made at the payment
     * service, or a dispute. When nothing of the invoice is kept, its commission is cancelled
     * while it is still on hold. A part that went back cuts the commission when it is released.
     */
    public function onPaymentReversed(Invoice $invoice): void
    {
        if ($this->keptOf($invoice->fresh()) <= 0) {
            $this->cancelForRefund($invoice);
        }
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
