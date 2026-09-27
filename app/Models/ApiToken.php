<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A key for the REST API. It acts as its staff member, limited to reading unless it can write.
 * The key itself is shown once when it is made; only its SHA-256 hash is kept.
 */
class ApiToken extends Model
{
    public const PREFIX = 'nb_';

    protected $fillable = ['admin_id', 'name', 'token_hash', 'hint', 'can_write', 'last_used_at', 'last_used_ip'];

    protected function casts(): array
    {
        return [
            'can_write' => 'boolean',
            'last_used_at' => 'datetime',
        ];
    }

    /**
     * Make a new key. Returns the model and the key to show once.
     *
     * @return array{0: self, 1: string}
     */
    public static function issue(Admin $admin, string $name, bool $canWrite): array
    {
        $plain = self::PREFIX.Str::random(40);

        $token = self::create([
            'admin_id' => $admin->id,
            'name' => $name,
            'token_hash' => hash('sha256', $plain),
            'hint' => substr($plain, -4),
            'can_write' => $canWrite,
        ]);

        return [$token, $plain];
    }

    public static function findByPlainText(string $plain): ?self
    {
        return str_starts_with($plain, self::PREFIX) ? self::query()->where('token_hash', hash('sha256', $plain))->first() : null;
    }

    /**
     * @return BelongsTo<Admin, $this>
     */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }
}
