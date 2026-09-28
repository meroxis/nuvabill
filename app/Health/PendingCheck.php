<?php

namespace App\Health;

/**
 * Builds a check's result: name the check once, then say how it went.
 *
 *     $this->check('staff.two_factor', 'Staff with full access use two-factor sign-in', weight: 5)
 *         ->urgent(':names cannot sign in with a second step', ['names' => 'Raz'], fix: [...]);
 */
final class PendingCheck
{
    public function __construct(
        private readonly string $id,
        private readonly string $section,
        private readonly string $group,
        private readonly string $title,
        private readonly int $weight,
    ) {}

    /**
     * @param  array<string, string|int>  $params
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, mixed>|null  $link
     */
    public function passed(string $summary = '', array $params = [], array $items = [], ?array $link = null): CheckResult
    {
        return $this->result(Status::Passed, $summary, $params, '', $items, null, $link);
    }

    /**
     * Something to fix soon.
     *
     * @param  array<string, string|int>  $params
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, mixed>|null  $fix
     * @param  array<string, mixed>|null  $link
     */
    public function warning(string $summary, array $params = [], string $advice = '', array $items = [], ?array $fix = null, ?array $link = null): CheckResult
    {
        return $this->result(Status::Warning, $summary, $params, $advice, $items, $fix, $link);
    }

    /**
     * Something to fix today.
     *
     * @param  array<string, string|int>  $params
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, mixed>|null  $fix
     * @param  array<string, mixed>|null  $link
     */
    public function urgent(string $summary, array $params = [], string $advice = '', array $items = [], ?array $fix = null, ?array $link = null): CheckResult
    {
        return $this->result(Status::Urgent, $summary, $params, $advice, $items, $fix, $link);
    }

    /**
     * The check could not run here, for example on Windows or without a site address.
     *
     * @param  array<string, string|int>  $params
     */
    public function skipped(string $summary, array $params = []): CheckResult
    {
        return $this->result(Status::Skipped, $summary, $params, '', [], null, null);
    }

    /**
     * Urgent when $urgent is true, otherwise something to fix soon.
     *
     * @param  array<string, string|int>  $params
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, mixed>|null  $fix
     * @param  array<string, mixed>|null  $link
     */
    public function failed(bool $urgent, string $summary, array $params = [], string $advice = '', array $items = [], ?array $fix = null, ?array $link = null): CheckResult
    {
        return $this->result($urgent ? Status::Urgent : Status::Warning, $summary, $params, $advice, $items, $fix, $link);
    }

    /**
     * @param  array<string, string|int>  $params
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, mixed>|null  $fix
     * @param  array<string, mixed>|null  $link
     */
    private function result(Status $status, string $summary, array $params, string $advice, array $items, ?array $fix, ?array $link): CheckResult
    {
        return new CheckResult(
            id: $this->id,
            section: $this->section,
            group: $this->group,
            title: $this->title,
            status: $status,
            weight: $this->weight,
            summary: $summary,
            params: $params,
            advice: $advice,
            items: $items,
            fix: $fix,
            link: $link,
        );
    }
}
