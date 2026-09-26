<?php

namespace App\Enums;

enum TicketStatus: string
{
    case Open = 'open';
    case Answered = 'answered';
    case CustomerReply = 'customer_reply';
    case OnHold = 'on_hold';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Open => __('Open'),
            self::Answered => __('Answered'),
            self::CustomerReply => __('Customer reply'),
            self::OnHold => __('On hold'),
            self::Closed => __('Closed'),
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Open, self::CustomerReply => 'warn',
            self::Answered => 'good',
            self::OnHold => 'info',
            self::Closed => 'muted',
        };
    }

    /**
     * Whether staff still need to act on the ticket.
     */
    public function needsReply(): bool
    {
        return in_array($this, [self::Open, self::CustomerReply], true);
    }
}
