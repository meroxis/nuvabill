<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * "When this happens, only if…, do these steps." The trigger and steps are keys of
 * App\Automations\Registry; conditions and step settings are stored as JSON.
 *
 * @property string $name
 * @property string $trigger
 * @property int|null $trigger_days
 * @property list<array{field: string, operator: string, value: mixed}>|null $conditions
 * @property list<array{type: string, config: array<string, mixed>}> $steps
 * @property bool $is_active
 * @property string|null $template
 */
class Automation extends Model
{
    protected $fillable = ['name', 'trigger', 'trigger_days', 'conditions', 'steps', 'is_active', 'template'];

    protected function casts(): array
    {
        return [
            'trigger_days' => 'integer',
            'conditions' => 'array',
            'steps' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<AutomationRun, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(AutomationRun::class);
    }
}
