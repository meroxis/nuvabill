<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Links a record from another billing system to the Nuvabill record it was imported as.
 */
class ImportMapping extends Model
{
    public $timestamps = false;

    protected $fillable = ['source', 'entity', 'source_id', 'local_id'];

    protected function casts(): array
    {
        return [
            'source_id' => 'integer',
            'local_id' => 'integer',
        ];
    }
}
