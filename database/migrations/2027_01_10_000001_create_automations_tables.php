<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Automations: "when this happens, only if…, do these steps", and a log of every run. Also client tags
 * and ticket assignment, which automations use, and the new "automations.manage" staff right.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('automations')) {
            Schema::create('automations', function (Blueprint $table) {
                $table->id();
                $table->string('name', 120);
                $table->string('trigger', 60)->index();
                $table->unsignedInteger('trigger_days')->nullable();
                $table->json('conditions')->nullable();
                $table->json('steps');
                $table->boolean('is_active')->default(false)->index();
                $table->string('template', 60)->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('automation_runs')) {
            Schema::create('automation_runs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('automation_id')->constrained()->cascadeOnDelete();
                $table->string('subject_type', 30);
                $table->unsignedBigInteger('subject_id');
                $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
                $table->string('status', 20)->default('queued')->index();
                $table->unsignedSmallInteger('step')->default(0);
                $table->timestamp('resume_at')->nullable()->index();
                $table->json('log')->nullable();
                $table->string('error', 500)->nullable();
                // One run per automation, subject and occasion, even when two checks meet.
                $table->string('dedupe_key', 191)->unique();
                $table->timestamp('finished_at')->nullable();
                $table->timestamps();
                $table->index(['subject_type', 'subject_id']);
            });
        }

        if (! Schema::hasColumn('clients', 'tags')) {
            Schema::table('clients', function (Blueprint $table) {
                $table->json('tags')->nullable();
            });
        }

        if (! Schema::hasColumn('tickets', 'assigned_admin_id')) {
            Schema::table('tickets', function (Blueprint $table) {
                $table->foreignId('assigned_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            });
        }

        // Staff who may change settings may also manage automations.
        foreach (DB::table('roles')->get(['id', 'permissions']) as $role) {
            $permissions = json_decode((string) $role->permissions, true) ?: [];

            if (in_array('settings.manage', $permissions, true) && ! in_array('automations.manage', $permissions, true)) {
                DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode([...$permissions, 'automations.manage'])]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('tickets', 'assigned_admin_id')) {
            Schema::table('tickets', function (Blueprint $table) {
                $table->dropConstrainedForeignId('assigned_admin_id');
            });
        }

        if (Schema::hasColumn('clients', 'tags')) {
            Schema::table('clients', function (Blueprint $table) {
                $table->dropColumn('tags');
            });
        }

        Schema::dropIfExists('automation_runs');
        Schema::dropIfExists('automations');
    }
};
