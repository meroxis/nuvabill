<?php

namespace App\Models;

use App\Enums\IncidentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A note on a network issue or maintenance, for example "The fix is in place, we are watching".
 */
class NetworkIncidentUpdate extends Model
{
    protected $fillable = ['network_incident_id', 'admin_id', 'status', 'message'];

    protected function casts(): array
    {
        return [
            'status' => IncidentStatus::class,
        ];
    }

    /**
     * @return BelongsTo<NetworkIncident, $this>
     */
    public function incident(): BelongsTo
    {
        return $this->belongsTo(NetworkIncident::class, 'network_incident_id');
    }

    /**
     * @return BelongsTo<Admin, $this>
     */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }
}
