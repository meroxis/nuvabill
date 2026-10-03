<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Client emails are saved in lowercase from now on, and sign-in looks them up in lowercase. Older
 * emails are lowercased here, so "Raz@Example.com" can still sign in on SQLite, which compares
 * letter case. An email whose lowercase form another client already has is left as it is: those
 * are two accounts for one mailbox, and staff decide which one stays.
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

                if (DB::table('clients')->where('email', $email)->where('id', '!=', $client->id)->exists()) {
                    continue;
                }

                DB::table('clients')->where('id', $client->id)->update(['email' => $email]);
            }
        });
    }

    public function down(): void
    {
        // The old letter case is not kept, and lowercase emails work everywhere.
    }
};
