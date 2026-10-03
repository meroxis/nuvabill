<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Wayl payment links now remember whether they were made in Test mode, and test links stop
 * counting once Wayl is live. Open links made before this update do not say so: when Wayl is in
 * Test mode during the update, they are marked as test links.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payment_intents') || ! Schema::hasTable('extensions')) {
            return;
        }

        $settings = rescue(fn (): mixed => json_decode(Crypt::decryptString((string) DB::table('extensions')->where('slug', 'wayl')->value('settings')), true), null, false);

        if (! is_array($settings) || ($settings['mode'] ?? null) !== 'test') {
            return;
        }

        DB::table('payment_intents')->where('gateway', 'wayl')->where('status', 'pending')->orderBy('id')->get(['id', 'meta'])
            ->each(function (object $intent): void {
                $meta = json_decode((string) $intent->meta, true);
                $meta = is_array($meta) ? $meta : [];

                if (! isset($meta['env'])) {
                    DB::table('payment_intents')->where('id', $intent->id)->update(['meta' => json_encode($meta + ['env' => 'test'])]);
                }
            });
    }

    public function down(): void
    {
        // Nothing to undo: the mark only stops test links from counting once Wayl is live.
    }
};
