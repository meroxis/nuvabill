<?php

namespace App\Support;

use App\Models\ActivityLog;
use App\Models\Client;
use Illuminate\Database\Eloquent\Model;

/**
 * Writes entries to the activity log that staff see in the admin area.
 */
class Activity
{
    public static function log(string $action, string $description, ?Model $subject = null, ?Client $client = null, ?Model $actor = null): ActivityLog
    {
        $actor ??= auth('admin')->user() ?? auth('web')->user();

        if ($client === null) {
            $client = match (true) {
                $subject instanceof Client => $subject,
                $subject !== null && isset($subject->client_id) => Client::find($subject->client_id),
                $actor instanceof Client => $actor,
                default => null,
            };
        }

        return ActivityLog::create([
            'actor_type' => $actor?->getMorphClass(),
            'actor_id' => $actor?->getKey(),
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'client_id' => $client?->getKey(),
            'action' => $action,
            'description' => $description,
            'ip_address' => app()->runningInConsole() ? null : request()->ip(),
        ]);
    }
}
