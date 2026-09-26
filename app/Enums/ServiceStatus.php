<?php

namespace App\Enums;

enum ServiceStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Suspended = 'suspended';
    case Terminated = 'terminated';
    case Cancelled = 'cancelled';
    case Fraud = 'fraud';

    public function label(): string
    {
        return match ($this) {
            self::Pending => __('Pending'),
            self::Active => __('Active'),
            self::Suspended => __('Suspended'),
            self::Terminated => __('Terminated'),
            self::Cancelled => __('Cancelled'),
            self::Fraud => __('Fraud'),
        };
    }

    /**
     * Visual tone used by status pills: good, warn, crit, info or muted.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Active => 'good',
            self::Pending => 'info',
            self::Suspended => 'warn',
            self::Fraud => 'crit',
            self::Terminated, self::Cancelled => 'muted',
        };
    }

    /**
     * Whether the service should keep generating renewal invoices.
     */
    public function isBillable(): bool
    {
        return in_array($this, [self::Active, self::Suspended], true);
    }
}
