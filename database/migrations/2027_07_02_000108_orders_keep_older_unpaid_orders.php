<?php

use App\Support\Settings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Unpaid new orders are now cancelled after 7 days by default. On a site that updates, the pending
 * orders placed before the update are left alone: their clients may still pay by bank transfer or
 * another payment staff confirm by hand. Only orders placed from now on are cancelled that way.
 * A new site has no orders yet, so nothing is saved and every order counts.
 */
return new class extends Migration
{
    private const KEY = 'orders.cancel_unpaid_from';

    public function up(): void
    {
        if (! Schema::hasTable('settings') || ! Schema::hasTable('orders') || ! DB::table('orders')->exists()) {
            return;
        }

        if (DB::table('settings')->where('key', self::KEY)->exists()) {
            return;
        }

        // Stored the way Settings::set() stores a value: JSON encoded.
        DB::table('settings')->insert([
            'key' => self::KEY,
            'value' => json_encode(now()->toIso8601String()),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        rescue(fn () => app(Settings::class)->flush(), report: false);
    }

    public function down(): void
    {
        if (Schema::hasTable('settings')) {
            DB::table('settings')->where('key', self::KEY)->delete();
            rescue(fn () => app(Settings::class)->flush(), report: false);
        }
    }
};
