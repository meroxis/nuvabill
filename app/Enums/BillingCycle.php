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
     */
    public function advance(CarbonInterface $date): CarbonImmutable
    {
        $date = CarbonImmutable::instance($date);

        return $this->isRecurring() ? $date->addMonthsNoOverflow($this->months()) : $date;
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
