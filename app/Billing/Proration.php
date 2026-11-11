<?php

namespace App\Billing;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\Service;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * The period maths that plan changes and resource changes share: which days the current billing
 * period covers, how many of them are left, and what part of a paid invoice the client still pays
 * for. Prices for the days left are worked out from these, always in integer minor units.
 */
final class Proration
{
    /**
     * The service's current billing period and the days left in it on the given day (today by
     * default). The period ends on the next due date and starts one billing cycle before it. A due
     * date on the day the service started (or that month's last day) was billed from the same day
     * before it, as BillingCycle::advance moves it: 31 Mar - 29 Apr for 30 Apr, not 30 Mar.
     *
     * @return array{start: CarbonImmutable, end: CarbonImmutable, period_days: int, days_left: int}
     *
     * @throws InvalidArgumentException When the service has no next due date.
     */
    public static function period(Service $service, ?CarbonInterface $today = null): array
    {
        $end = $service->next_due_date;

        if ($end === null) {
            throw new InvalidArgumentException('The service has no next due date, so it has no billing period.');
        }

        $today = CarbonImmutable::instance($today ?? today())->startOfDay();
        $start = $end->subMonthsNoOverflow((int) $service->billing_cycle->months());
        $anchor = $service->registration_date?->day;

        if ($anchor !== null && $end->day === min($anchor, $end->daysInMonth)) {
            $start = $start->setDay(min($anchor, $start->daysInMonth));
        }

        $periodDays = max(1, (int) round($start->diffInDays($end)));
        $daysLeft = max(0, min($periodDays, (int) round($today->diffInDays($end, false))));

        return [
            'start' => $start,
            'end' => $end,
            'period_days' => $periodDays,
            'days_left' => $daysLeft,
        ];
    }

    /**
     * The part of a paid invoice the client still pays for, from 0 to 1: less after a partial credit
     * note or after money went back without one (a refund or dispute at the payment service), and 0
     * once it was refunded. Unused time is never credited for money that already went back.
     */
    public static function keptShare(Invoice $invoice): float
    {
        if ($invoice->status === InvoiceStatus::Refunded) {
            return 0.0;
        }

        if ($invoice->total <= 0) {
            return 1.0;
        }

        return max(0.0, min(1.0, $invoice->keptAmount() / $invoice->total));
    }

    /**
     * An amount for the days left of a period, rounded to whole minor units.
     */
    public static function forDays(int $amount, int $daysLeft, int $periodDays): int
    {
        return (int) round($amount * $daysLeft / max(1, $periodDays));
    }
}
