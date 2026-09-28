<?php

namespace App\Models;

use App\Health\CheckResult;
use App\Health\Status;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * One site health check of the whole site: every result, and a score per tab.
 *
 * @property int $id
 * @property string $trigger
 * @property int|null $security_score
 * @property int|null $database_score
 * @property int|null $seo_score
 * @property int $urgent_count
 * @property int $warning_count
 * @property int $passed_count
 * @property int $duration_ms
 * @property list<array<string, mixed>> $results
 * @property Carbon $created_at
 */
class HealthRun extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['trigger', 'security_score', 'database_score', 'seo_score', 'urgent_count', 'warning_count', 'passed_count', 'duration_ms', 'results'];

    protected function casts(): array
    {
        return [
            'results' => 'array',
            'security_score' => 'integer',
            'database_score' => 'integer',
            'seo_score' => 'integer',
            'urgent_count' => 'integer',
            'warning_count' => 'integer',
            'passed_count' => 'integer',
            'duration_ms' => 'integer',
        ];
    }

    public static function latestRun(): ?self
    {
        return self::query()->latest('id')->first();
    }

    /**
     * @return Collection<int, CheckResult>
     */
    public function checks(?string $section = null): Collection
    {
        return collect($this->results)
            ->map(fn (array $data): CheckResult => CheckResult::fromArray($data))
            ->when($section !== null, fn (Collection $checks) => $checks->where('section', $section))
            ->values();
    }

    public function check(string $id): ?CheckResult
    {
        return $this->checks()->firstWhere('id', $id);
    }

    public function score(string $section): ?int
    {
        return match ($section) {
            'security' => $this->security_score,
            'database' => $this->database_score,
            'seo' => $this->seo_score,
            default => null,
        };
    }

    /**
     * How many open problems a tab has, by status.
     */
    public function problemCount(string $section, Status $status): int
    {
        return $this->checks($section)->filter(fn (CheckResult $check): bool => ! $check->ignored && $check->status === $status)->count();
    }
}
