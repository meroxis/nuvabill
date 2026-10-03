<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Wayl payment links now remember whether they were made in Test or Live mode, and test links stop
 * counting once Wayl is live. Open links made before this update do not say so. A link made after
 * Wayl's settings last changed was made in the mode Wayl is in now. Any other open link is marked
 * "unknown": when it reports paid, staff check it in Wayl instead of the invoice being marked paid.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payment_intents') || ! Schema::hasTable('extensions')) {
            return;
        }

        $extension = DB::table('extensions')->where('slug', 'wayl')->first(['settings', 'updated_at']);
        $settings = $extension === null ? null : rescue(fn (): mixed => json_decode(Crypt::decryptString((string) $extension->settings), true), null, false);
        $mode = is_array($settings) ? (($settings['mode'] ?? null) === 'test' ? 'test' : 'live') : null;
        $changedAt = $extension?->updated_at === null ? null : rescue(fn (): Carbon => Carbon::parse($extension->updated_at), null, false);

        DB::table('payment_intents')->where('gateway', 'wayl')->where('status', 'pending')->orderBy('id')->get(['id', 'meta', 'created_at'])
            ->each(function (object $intent) use ($mode, $changedAt): void {
                $meta = json_decode((string) $intent->meta, true);
                $meta = is_array($meta) ? $meta : [];

                if (isset($meta['env'])) {
                    return;
                }

                // Timestamps keep whole seconds: a link made in the same second as the change is unknown.
                $madeAt = $intent->created_at === null ? null : rescue(fn (): Carbon => Carbon::parse($intent->created_at), null, false);
                $env = $mode !== null && $changedAt !== null && $madeAt?->greaterThan($changedAt) ? $mode : 'unknown';

                DB::table('payment_intents')->where('id', $intent->id)->update(['meta' => json_encode($meta + ['env' => $env])]);
            });
    }

    public function down(): void
    {
        // Nothing to undo: the mark only decides which old links still count once Wayl is live.
    }
};
