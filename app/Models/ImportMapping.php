<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Links a record from another billing system to the Nuvabill record it was imported as.
 */
class ImportMapping extends Model
{
    /**
     * The mappings that tie a source client to a Nuvabill client.
     */
    public const CLIENT_ENTITIES = ['client', 'client_link', 'client_unproven'];

    public $timestamps = false;

    protected $fillable = ['source', 'entity', 'source_id', 'local_id'];

    protected function casts(): array
    {
        return [
            'source_id' => 'integer',
            'local_id' => 'integer',
        ];
    }

    /**
     * An erased client is never imported again. Its client mappings become 'client_erased' ones, which every
     * import checks first: a later run neither puts its personal data back nor adds records to it. Removing
     * the mappings instead would bring the client back as a new account.
     */
    public static function markClientErased(int $clientId): void
    {
        $sources = static::query()
            ->whereIn('entity', self::CLIENT_ENTITIES)
            ->where('local_id', $clientId)
            ->get(['source', 'source_id'])
            ->unique(fn (self $mapping): string => $mapping->source.'|'.$mapping->source_id);

        foreach ($sources as $mapping) {
            static::query()->updateOrCreate(
                ['source' => $mapping->source, 'entity' => 'client_erased', 'source_id' => $mapping->source_id],
                ['local_id' => $clientId],
            );
        }

        static::query()->whereIn('entity', self::CLIENT_ENTITIES)->where('local_id', $clientId)->delete();
    }
}
