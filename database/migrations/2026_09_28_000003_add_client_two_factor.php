<?php

use Database\Seeders\DefaultDataSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two-factor sign-in for clients: an authenticator app ("totp") or a code sent by email ("email").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('two_factor_method', 10)->nullable()->after('has_password');
            $table->text('two_factor_secret')->nullable()->after('two_factor_method');
            $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_secret');
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes');
        });

        [$name, $subject, $body] = DefaultDataSeeder::templates()['client.two_factor_code'];

        if (! DB::table('email_templates')->where('key', 'client.two_factor_code')->exists()) {
            DB::table('email_templates')->insert([
                'key' => 'client.two_factor_code',
                'name' => $name,
                'subject' => $subject,
                'body' => $body,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['two_factor_method', 'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at']);
        });

        DB::table('email_templates')->where('key', 'client.two_factor_code')->delete();
    }
};
