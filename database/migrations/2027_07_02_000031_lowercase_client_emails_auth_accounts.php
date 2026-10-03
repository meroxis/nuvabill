<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Client emails are saved in lowercase from now on, and sign-in looks them up in lowercase. Older
 * emails are lowercased here, so "Raz@Example.com" can still sign in on SQLite, which compares
 * letter case. An email whose lowercase form another client already has is left as it is: those
 * are two accounts for one mailbox, and staff decide which one stays. Each such client gets an
 * entry in the activity log (site health lists them too), and still signs in with its own spelling.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('clients') || ! Schema::hasColumn('clients', 'email')) {
            return;
        }

        DB::table('clients')->select(['id', 'email'])->chunkById(500, function ($clients): void {
            foreach ($clients as $client) {
                $email = Str::lower(trim((string) $client->email));

                if ($email === $client->email || $email === '') {
                    continue;
                }

                $otherId = DB::table('clients')->where('email', $email)->where('id', '!=', $client->id)->value('id');

                if ($otherId !== null) {
                    $this->tellStaff((int) $client->id, (int) $otherId);

                    continue;
                }

                DB::table('clients')->where('id', $client->id)->update(['email' => $email]);
            }
        });
    }

    /**
     * Written straight to the table, without the models, so later changes to them cannot break this
     * update. A problem here never stops the update: the email is simply left as it is.
     */
    private function tellStaff(int $clientId, int $otherId): void
    {
        Log::warning("Clients #{$clientId} and #{$otherId} use the same email in different letter case. Merge or rename one of them.");

        if (! Schema::hasTable('activity_logs')) {
            return;
        }

        rescue(fn () => DB::table('activity_logs')->insert([
            'subject_type' => 'client',
            'subject_id' => $clientId,
            'client_id' => $clientId,
            'action' => 'client.email_duplicate',
            'description' => "Client #{$otherId} uses this email in lowercase. Merge or rename one of them.",
            'created_at' => now(),
        ]), report: false);
    }

    public function down(): void
    {
        // The old letter case is not kept, and lowercase emails work everywhere.
    }
};
