<?php

namespace App\Health;

/**
 * The outcome of one site health check.
 */
enum Status: string
{
    case Passed = 'passed';
    case Urgent = 'urgent';
    case Warning = 'warning';
    case Skipped = 'skipped';

    public function label(): string
    {
        return match ($this) {
            self::Passed => __('Passed'),
            self::Urgent => __('Urgent'),
            self::Warning => __('Should fix'),
            self::Skipped => __('Not checked'),
        };
    }

    /**
     * The pill tone the admin area uses for this outcome.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Passed => 'good',
            self::Urgent => 'crit',
            self::Warning => 'warn',
            self::Skipped => '',
        };
    }

    public function isProblem(): bool
    {
        return $this === self::Urgent || $this === self::Warning;
    }
}
