<?php

namespace App\Enums;

/**
 * Where a network issue or planned maintenance stands. Issues go from "Looking into it" to
 * "Resolved"; maintenance goes from "Planned" to "Finished".
 */
enum IncidentStatus: string
{
    case Investigating = 'investigating';
    case Identified = 'identified';
    case Monitoring = 'monitoring';
    case Resolved = 'resolved';
    case Scheduled = 'scheduled';
    case InProgress = 'in_progress';
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::Investigating => __('Looking into it'),
            self::Identified => __('Cause found, fixing'),
            self::Monitoring => __('Fixed, watching'),
            self::Resolved => __('Resolved'),
            self::Scheduled => __('Planned'),
            self::InProgress => __('Under way'),
            self::Completed => __('Finished'),
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Investigating => 'crit',
            self::Identified, self::InProgress => 'warn',
            self::Monitoring, self::Scheduled => 'info',
            self::Resolved, self::Completed => 'good',
        };
    }

    public function isClosed(): bool
    {
        return $this === self::Resolved || $this === self::Completed;
    }

    /**
     * @return list<self>
     */
    public static function forKind(string $kind): array
    {
        return $kind === 'maintenance'
            ? [self::Scheduled, self::InProgress, self::Completed]
            : [self::Investigating, self::Identified, self::Monitoring, self::Resolved];
    }
}
