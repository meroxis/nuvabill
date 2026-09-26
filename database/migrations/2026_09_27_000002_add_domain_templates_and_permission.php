<?php

use Database\Seeders\DefaultDataSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sites installed before domains existed get the new email templates, and staff roles that
 * manage services may now manage domains too. Nothing that already exists is changed.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $now = now();

        foreach (DefaultDataSeeder::templates() as $key => [$name, $subject, $body]) {
            if (str_starts_with($key, 'domain.') && ! DB::table('email_templates')->where('key', $key)->exists()) {
                DB::table('email_templates')->insert([
                    'key' => $key,
                    'name' => $name,
                    'subject' => $subject,
                    'body' => $body,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        foreach (DB::table('roles')->get(['id', 'permissions']) as $role) {
            $permissions = json_decode((string) $role->permissions, true) ?: [];

            if (in_array('services.manage', $permissions, true) && ! in_array('domains.manage', $permissions, true)) {
                $permissions[] = 'domains.manage';
                DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode(array_values($permissions))]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('email_templates')->where('key', 'like', 'domain.%')->delete();
    }
};
