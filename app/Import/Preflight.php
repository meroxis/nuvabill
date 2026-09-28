<?php

namespace App\Import;

/**
 * The result of a dry run: what each step would do and the problems found, before anything changes.
 *
 * Texts are English source texts with :placeholders, translated when shown.
 */
final class Preflight
{
    public const ERROR = 'error';

    public const WARNING = 'warning';

    public const INFO = 'info';

    /**
     * @var array<string, array{label: string, total: int, new: int, existing: int, skipped: int}>
     */
    private array $steps = [];

    /**
     * @var list<array{level: string, text: string, params: array<string, string|int>, examples: list<string>}>
     */
    private array $problems = [];

    public function __construct(
        public readonly string $system,
        public readonly string $version,
    ) {}

    /**
     * @param  int  $new  Rows not imported before that would be added.
     * @param  int  $existing  Rows imported before (updated) or linked to a matching Nuvabill record.
     * @param  int  $skipped  Rows that would be left out.
     */
    public function step(string $key, string $label, int $total, int $new, int $existing, int $skipped = 0): self
    {
        $this->steps[$key] = ['label' => $label, 'total' => $total, 'new' => max(0, $new), 'existing' => max(0, $existing), 'skipped' => max(0, $skipped)];

        return $this;
    }

    /**
     * Only added when $count is above zero, so sources can report every kind of problem unconditionally.
     *
     * @param  array<string, string|int>  $params
     * @param  list<string>  $examples
     */
    public function problem(string $level, int $count, string $text, array $params = [], array $examples = []): self
    {
        if ($count > 0) {
            $this->problems[] = ['level' => $level, 'text' => $text, 'params' => ['count' => $count] + $params, 'examples' => array_slice(array_values(array_map('strval', $examples)), 0, 5)];
        }

        return $this;
    }

    public function hasErrors(): bool
    {
        return collect($this->problems)->contains('level', self::ERROR);
    }

    /**
     * @return array{system: string, version: string, steps: array<string, array{label: string, total: int, new: int, existing: int, skipped: int}>, problems: list<array{level: string, text: string, params: array<string, string|int>, examples: list<string>}>, checked_at: string}
     */
    public function toArray(): array
    {
        $order = [self::ERROR => 0, self::WARNING => 1, self::INFO => 2];
        $problems = $this->problems;
        usort($problems, fn (array $a, array $b): int => $order[$a['level']] <=> $order[$b['level']]);

        return [
            'system' => $this->system,
            'version' => $this->version,
            'steps' => $this->steps,
            'problems' => $problems,
            'checked_at' => now()->toIso8601String(),
        ];
    }
}
