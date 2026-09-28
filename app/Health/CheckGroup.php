<?php

namespace App\Health;

/**
 * A group of related checks, shown as one card on the site health page, for example
 * "Staff and access". Add-ons can add their own groups (see SiteHealth::extend()).
 */
abstract class CheckGroup
{
    public const SECURITY = 'security';

    public const DATABASE = 'database';

    public const SEO = 'seo';

    /**
     * A short key used in addresses, for example "staff".
     */
    abstract public function key(): string;

    /**
     * Which tab the group belongs to: security, database or seo.
     */
    abstract public function section(): string;

    /**
     * The group's name in English; it is translated when shown.
     */
    abstract public function title(): string;

    /**
     * One sentence about what the group looks at, in English.
     */
    abstract public function description(): string;

    /**
     * An icon name from <x-icon>.
     */
    public function icon(): string
    {
        return 'shield';
    }

    /**
     * @return list<CheckResult>
     */
    abstract public function run(): array;

    protected function check(string $id, string $title, int $weight = 2): PendingCheck
    {
        return new PendingCheck($id, $this->section(), $this->key(), $title, $weight);
    }

    /**
     * A repair button for a result or one of its items.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    protected function fix(string $action, string $label, array $params = [], ?string $confirm = null, bool $danger = false): array
    {
        return array_filter([
            'action' => $action,
            'label' => $label,
            'params' => $params,
            'confirm' => $confirm,
            'danger' => $danger,
        ], fn (mixed $value): bool => $value !== null && $value !== [] && $value !== false);
    }

    /**
     * A link to the page where staff fix it themselves.
     *
     * @param  array<string, mixed>  $parameters
     * @return array{route: string, parameters: array<string, mixed>, label: string}
     */
    protected function link(string $route, string $label, array $parameters = []): array
    {
        return ['route' => $route, 'parameters' => $parameters, 'label' => $label];
    }
}
