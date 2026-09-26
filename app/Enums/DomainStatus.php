<?php

namespace App\Enums;

enum DomainStatus: string
{
    case Pending = 'pending';
    case PendingTransfer = 'pending_transfer';
    case Active = 'active';
    case Expired = 'expired';
    case Cancelled = 'cancelled';
    case TransferredAway = 'transferred_away';
    case Fraud = 'fraud';

    public function label(): string
    {
        return match ($this) {
            self::Pending => __('Pending'),
            self::PendingTransfer => __('Transfer in progress'),
            self::Active => __('Active'),
            self::Expired => __('Expired'),
            self::Cancelled => __('Cancelled'),
            self::TransferredAway => __('Transferred away'),
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
            self::Pending, self::PendingTransfer => 'info',
            self::Expired => 'warn',
            self::Fraud => 'crit',
            self::Cancelled, self::TransferredAway => 'muted',
        };
    }

    /**
     * Whether the domain is registered with us and should keep renewing.
     */
    public function isRenewable(): bool
    {
        return in_array($this, [self::Active, self::Expired], true);
    }
}
