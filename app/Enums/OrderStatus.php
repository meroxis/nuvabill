<?php

namespace App\Enums;

enum OrderStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Cancelled = 'cancelled';
    case Fraud = 'fraud';

    public function label(): string
    {
        return match ($this) {
            self::Pending => __('Pending'),
            self::Active => __('Active'),
            self::Cancelled => __('Cancelled'),
            self::Fraud => __('Fraud'),
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Active => 'good',
            self::Pending => 'info',
            self::Fraud => 'crit',
            self::Cancelled => 'muted',
        };
    }
}
