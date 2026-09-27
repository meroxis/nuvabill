<?php

use Database\Seeders\DefaultDataSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sites installed before v0.3 get the marketplace email templates. Staff roles that manage
 * settings may install from the marketplace, and roles that record payments may manage coupons.
 * Nothing that already exists is changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        foreach (DefaultDataSeeder::templates() as $key => [$name, $subject, $body]) {
            if (str_starts_with($key, 'marketplace.') && ! DB::table('email_templates')->where('key', $key)->exists()) {
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
            $added = [];

            if (in_array('settings.manage', $permissions, true) && ! in_array('marketplace.manage', $permissions, true)) {
                $added[] = 'marketplace.manage';
            }

            if (in_array('billing.manage', $permissions, true) && ! in_array('coupons.manage', $permissions, true)) {
                $added[] = 'coupons.manage';
            }

            if ($added !== []) {
                DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode(array_values([...$permissions, ...$added]))]);
            }
        }
    }

    public function down(): void
    {
        DB::table('email_templates')->where('key', 'like', 'marketplace.%')->delete();
    }
};
