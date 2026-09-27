<?php

use Database\Seeders\DefaultDataSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sites installed before v0.4 get the quote and affiliate email templates, and staff roles that
 * manage clients may manage affiliates. Nothing that already exists is changed.
 */
return new class extends Migration
{
    private const TEMPLATE_KEYS = ['quote.sent', 'admin.quote_accepted', 'affiliate.commission'];

    public function up(): void
    {
        $now = now();

        foreach (DefaultDataSeeder::templates() as $key => [$name, $subject, $body]) {
            if (in_array($key, self::TEMPLATE_KEYS, true) && ! DB::table('email_templates')->where('key', $key)->exists()) {
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

            if (in_array('clients.manage', $permissions, true) && ! in_array('affiliates.manage', $permissions, true)) {
                DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode([...$permissions, 'affiliates.manage'])]);
            }
        }
    }

    public function down(): void
    {
        DB::table('email_templates')->whereIn('key', self::TEMPLATE_KEYS)->delete();
    }
};
