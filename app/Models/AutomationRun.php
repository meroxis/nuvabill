<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One run of an automation for one invoice, client, service or ticket: which step it is at, when
 * a wait ends, and what each step did.
 *
 * @property string $status
 * @property int $step
 * @property list<array{step: int|null, text: string, at: string}>|null $log
 */
class AutomationRun extends Model
{
    public const QUEUED = 'queued';

    public const RUNNING = 'running';

    public const WAITING = 'waiting';

    public const DONE = 'done';

    public const STOPPED = 'stopped';

    public const FAILED = 'failed';

    protected $fillable = ['automation_id', 'subject_type', 'subject_id', 'client_id', 'status', 'step', 'resume_at', 'log', 'error', 'dedupe_key', 'finished_at'];

    protected function casts(): array
    {
        return [
            'step' => 'integer',
            'resume_at' => 'datetime',
            'log' => 'array',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Automation, $this>
     */
    public function automation(): BelongsTo
    {
        return $this->belongsTo(Automation::class);
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function addLog(string $text, ?int $step = null): void
    {
        $this->log = [...($this->log ?? []), ['step' => $step, 'text' => $text, 'at' => now()->toIso8601String()]];
    }

    /**
     * The last thing that happened, for lists.
     */
    public function lastLog(): string
    {
        return (string) (collect($this->log ?? [])->last()['text'] ?? '');
    }

    public function tone(): string
    {
        return match ($this->status) {
            self::DONE => 'good',
            self::FAILED => 'crit',
            self::WAITING, self::QUEUED, self::RUNNING => 'info',
            default => '',
        };
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::QUEUED => __('Starting'),
            self::RUNNING => __('Running'),
            self::WAITING => __('Waiting'),
            self::DONE => __('Done'),
            self::STOPPED => __('Stopped'),
            self::FAILED => __('Failed'),
            default => $this->status,
        };
    }
}
