<?php

use Database\Seeders\DefaultDataSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Site health: the nightly check results, checks staff chose to ignore, fingerprints of installed
 * marketplace packages, the security alert email and the new "security.manage" staff right.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('health_runs')) {
            Schema::create('health_runs', function (Blueprint $table) {
                $table->id();
                $table->string('trigger', 20);
                $table->unsignedTinyInteger('security_score')->nullable();
                $table->unsignedTinyInteger('database_score')->nullable();
                $table->unsignedTinyInteger('seo_score')->nullable();
                $table->unsignedSmallInteger('urgent_count')->default(0);
                $table->unsignedSmallInteger('warning_count')->default(0);
                $table->unsignedSmallInteger('passed_count')->default(0);
                $table->unsignedInteger('duration_ms')->default(0);
                $table->longText('results');
                $table->timestamp('created_at')->useCurrent()->index();
            });
        }

        if (! Schema::hasTable('health_ignores')) {
            Schema::create('health_ignores', function (Blueprint $table) {
                $table->id();
                $table->string('check_id', 80)->unique();
                $table->string('reason');
                $table->foreignId('admin_id')->nullable()->constrained()->nullOnDelete();
                $table->timestamp('created_at')->useCurrent();
            });
        }

        if (Schema::hasTable('marketplace_installs') && ! Schema::hasColumn('marketplace_installs', 'file_hashes')) {
            Schema::table('marketplace_installs', function (Blueprint $table) {
                $table->longText('file_hashes')->nullable();
            });
        }

        $now = now();

        foreach (DefaultDataSeeder::templates() as $key => [$name, $subject, $body]) {
            if ($key === 'admin.security_alert' && ! DB::table('email_templates')->where('key', $key)->exists()) {
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

        // Whoever may change settings may also see and fix security issues.
        foreach (DB::table('roles')->get(['id', 'permissions']) as $role) {
            $permissions = json_decode((string) $role->permissions, true) ?: [];

            if (in_array('settings.manage', $permissions, true) && ! in_array('security.manage', $permissions, true)) {
                DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode([...$permissions, 'security.manage'])]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('health_ignores');
        Schema::dropIfExists('health_runs');

        if (Schema::hasColumn('marketplace_installs', 'file_hashes')) {
            Schema::table('marketplace_installs', function (Blueprint $table) {
                $table->dropColumn('file_hashes');
            });
        }

        DB::table('email_templates')->where('key', 'admin.security_alert')->delete();
    }
};
