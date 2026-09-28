<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An old store address that forwards visitors and search engines to the new one, made when a
 * product or group gets a new web address.
 *
 * @property string $from_path
 * @property string $to_path
 * @property int $hits
 */
class SeoRedirect extends Model
{
    protected $fillable = ['from_path', 'to_path', 'hits'];

    protected function casts(): array
    {
        return ['hits' => 'integer'];
    }

    /**
     * Forward $from to $to. Addresses that forwarded to $from now go straight to $to, and $to
     * stops forwarding because it is in use again.
     */
    public static function remember(string $from, string $to): void
    {
        $from = self::normalise($from);
        $to = self::normalise($to);

        if ($from === $to || $from === '') {
            return;
        }

        self::query()->where('from_path', $to)->delete();
        self::query()->where('to_path', $from)->update(['to_path' => $to]);
        self::query()->updateOrCreate(['from_path' => $from], ['to_path' => $to]);
    }

    /**
     * Where an address that no longer exists should go, if it moved.
     */
    public static function target(string $path): ?self
    {
        return self::query()->where('from_path', self::normalise($path))->first();
    }

    public static function normalise(string $path): string
    {
        return mb_substr(trim($path, '/'), 0, 191);
    }
}
