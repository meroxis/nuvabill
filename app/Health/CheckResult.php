<?php

namespace App\Health;

/**
 * One check's result, as stored with a run and shown on the site health pages.
 *
 * Titles, summaries and advice are English source texts with :placeholders. They are translated
 * when shown, so a run saved at night reads in the language of whoever opens it.
 *
 * An item is one row of detail, for example a staff member or a file:
 * {label, value?, status?, mono?, fix?}. A fix is a button that runs a safe repair:
 * {action, label, params?, confirm?, danger?}.
 */
final readonly class CheckResult
{
    /**
     * @param  array<string, string|int>  $params
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, mixed>|null  $fix
     * @param  array{route: string, parameters?: array<string, mixed>, label: string}|null  $link
     */
    public function __construct(
        public string $id,
        public string $section,
        public string $group,
        public string $title,
        public Status $status,
        public int $weight = 2,
        public string $summary = '',
        public array $params = [],
        public string $advice = '',
        public array $items = [],
        public ?array $fix = null,
        public ?array $link = null,
        public bool $ignored = false,
        public ?string $ignoreReason = null,
    ) {}

    public function isProblem(): bool
    {
        return ! $this->ignored && $this->status->isProblem();
    }

    public function withIgnore(string $reason): self
    {
        return new self(...[...get_object_vars($this), 'ignored' => true, 'ignoreReason' => $reason]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'id' => $this->id,
            'section' => $this->section,
            'group' => $this->group,
            'title' => $this->title,
            'status' => $this->status->value,
            'weight' => $this->weight,
            'summary' => $this->summary,
            'params' => $this->params,
            'advice' => $this->advice,
            'items' => $this->items,
            'fix' => $this->fix,
            'link' => $this->link,
            'ignored' => $this->ignored,
            'ignore_reason' => $this->ignoreReason,
        ], fn (mixed $value): bool => $value !== null && $value !== '' && $value !== [] && $value !== false);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) $data['id'],
            section: (string) $data['section'],
            group: (string) $data['group'],
            title: (string) $data['title'],
            status: Status::tryFrom((string) ($data['status'] ?? '')) ?? Status::Skipped,
            weight: (int) ($data['weight'] ?? 2),
            summary: (string) ($data['summary'] ?? ''),
            params: (array) ($data['params'] ?? []),
            advice: (string) ($data['advice'] ?? ''),
            items: array_values((array) ($data['items'] ?? [])),
            fix: isset($data['fix']) ? (array) $data['fix'] : null,
            link: isset($data['link']) ? (array) $data['link'] : null,
            ignored: (bool) ($data['ignored'] ?? false),
            ignoreReason: isset($data['ignore_reason']) ? (string) $data['ignore_reason'] : null,
        );
    }

    /**
     * The title in the current language.
     */
    public function displayTitle(): string
    {
        return __($this->title, $this->params);
    }

    public function displaySummary(): string
    {
        return $this->summary === '' ? '' : __($this->summary, $this->params);
    }

    public function displayAdvice(): string
    {
        return $this->advice === '' ? '' : __($this->advice, $this->params);
    }
}
