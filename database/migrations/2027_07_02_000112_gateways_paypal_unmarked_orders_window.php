<?php

use App\Support\Settings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PayPal orders now carry this site's marker, and the webhook and the return page only count
 * payments with it. Orders made before this update name only the invoice. On a site that used
 * PayPal before, this saves when the update ran, and those payments still count for a while after
 * it, so a slow one such as an eCheck is not lost. A site that never set up PayPal has no such
 * orders, so nothing is saved and payments without the marker never count there.
 */
return new class extends Migration
{
    private const KEY = 'paypal.marked_orders_since';

    public function up(): void
    {
        if (! Schema::hasTable('settings') || ! Schema::hasTable('extensions') || DB::table('settings')->where('key', self::KEY)->exists()) {
            return;
        }

        $settings = rescue(fn (): mixed => json_decode(Crypt::decryptString((string) DB::table('extensions')->where('slug', 'paypal')->value('settings')), true), null, false);

        if (! is_array($settings) || ! is_string($settings['client_id'] ?? null) || trim($settings['client_id']) === '') {
            return;
        }

        DB::table('settings')->insert([
            'key' => self::KEY,
            'value' => json_encode(now()->toIso8601String()),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        rescue(fn () => app(Settings::class)->flush(), null, false);
    }

    public function down(): void
    {
        if (Schema::hasTable('settings')) {
            DB::table('settings')->where('key', self::KEY)->delete();
            rescue(fn () => app(Settings::class)->flush(), null, false);
        }
    }
};
