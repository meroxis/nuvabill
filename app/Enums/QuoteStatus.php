<?php

namespace App\Enums;

enum QuoteStatus: string
{
    case Draft = 'draft';
    case Sent = 'sent';
    case Accepted = 'accepted';
    case Declined = 'declined';

    /**
     * Shown for sent quotes after their "valid until" date. Never stored.
     */
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Draft => __('Draft'),
            self::Sent => __('Waiting for the client'),
            self::Accepted => __('Accepted'),
            self::Declined => __('Declined'),
            self::Expired => __('Expired'),
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Accepted => 'good',
            self::Sent => 'warn',
            self::Draft => 'info',
            self::Declined, self::Expired => 'muted',
        };
    }
}
