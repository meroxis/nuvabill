<?php

namespace App\Enums;

enum InvoiceStatus: string
{
    case Draft = 'draft';
    case Unpaid = 'unpaid';
    case Paid = 'paid';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';

    public function label(): string
    {
        return match ($this) {
            self::Draft => __('Draft'),
            self::Unpaid => __('Unpaid'),
            self::Paid => __('Paid'),
            self::Cancelled => __('Cancelled'),
            self::Refunded => __('Refunded'),
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Paid => 'good',
            self::Unpaid => 'warn',
            self::Draft => 'info',
            self::Cancelled, self::Refunded => 'muted',
        };
    }
}
