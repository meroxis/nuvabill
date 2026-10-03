<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each automation run remembers which steps it started with. When staff change the steps while a
 * run waits, the run stops instead of going on at a step number that now means another step.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('automation_runs') && ! Schema::hasColumn('automation_runs', 'steps_hash')) {
            Schema::table('automation_runs', function (Blueprint $table) {
                $table->string('steps_hash', 64)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('automation_runs', 'steps_hash')) {
            Schema::table('automation_runs', function (Blueprint $table) {
                $table->dropColumn('steps_hash');
            });
        }
    }
};
