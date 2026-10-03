<?php

namespace App\Enums;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

enum BillingCycle: string
{
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case SemiAnnually = 'semiannually';
    case Annually = 'annually';
    case Biennially = 'biennially';
    case Triennially = 'triennially';
    case OneTime = 'onetime';
    case Free = 'free';

    /**
     * Number of months one period of this cycle lasts, or null when it never renews.
     */
    public function months(): ?int
    {
        return match ($this) {
            self::Monthly => 1,
            self::Quarterly => 3,
            self::SemiAnnually => 6,
            self::Annually => 12,
            self::Biennially => 24,
            self::Triennially => 36,
            self::OneTime, self::Free => null,
        };
    }

    public function isRecurring(): bool
    {
        return $this->months() !== null;
    }

    /**
     * Move a date forward by one period. Month ends are clamped, so 31 Jan + 1 month is 28/29 Feb.
     *
     * $anchorDay is the day of the month the service started on. A date that was clamped to a short
     * month's last day goes back to that day when the month allows it, so 31 Jan, 28 Feb, 31 Mar
     * and not 28 Mar. A date moved to another day, for example by staff, is left as it is.
     */
    public function advance(CarbonInterface $date, ?int $anchorDay = null): CarbonImmutable
    {
        $date = CarbonImmutable::instance($date);

        if (! $this->isRecurring()) {
            return $date;
        }

        $next = $date->addMonthsNoOverflow($this->months());

        if ($anchorDay !== null && $date->isLastOfMonth() && $anchorDay > $date->day && $anchorDay > $next->day) {
            $next = $next->setDay(min($anchorDay, $next->daysInMonth));
        }

        return $next;
    }

    public function label(): string
    {
        return match ($this) {
            self::Monthly => __('Monthly'),
            self::Quarterly => __('Every 3 months'),
            self::SemiAnnually => __('Every 6 months'),
            self::Annually => __('Yearly'),
            self::Biennially => __('Every 2 years'),
            self::Triennially => __('Every 3 years'),
            self::OneTime => __('One time'),
            self::Free => __('Free'),
        };
    }

    /**
     * Short suffix shown after a price, for example "/mo".
     */
    public function suffix(): string
    {
        return match ($this) {
            self::Monthly => __('/mo'),
            self::Quarterly => __('/3 mo'),
            self::SemiAnnually => __('/6 mo'),
            self::Annually => __('/yr'),
            self::Biennially => __('/2 yr'),
            self::Triennially => __('/3 yr'),
            self::OneTime, self::Free => '',
        };
    }
}
