<?php

use App\Automations\Runner;
use App\Models\AutomationRun;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Each automation run remembers which steps it started with. When staff change the steps while a
 * run waits, the run stops instead of going on at a step number that now means another step.
 * Runs already under way get the steps as they are now, so a later change stops them too.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('automation_runs')) {
            return;
        }

        if (! Schema::hasColumn('automation_runs', 'steps_hash')) {
            Schema::table('automation_runs', function (Blueprint $table) {
                $table->string('steps_hash', 64)->nullable();
            });
        }

        if (! Schema::hasTable('automations')) {
            return;
        }

        DB::table('automations')->orderBy('id')->select(['id', 'steps'])->each(function (object $automation): void {
            $steps = json_decode((string) $automation->steps, true);

            DB::table('automation_runs')
                ->where('automation_id', $automation->id)
                ->whereIn('status', [AutomationRun::QUEUED, AutomationRun::RUNNING, AutomationRun::WAITING])
                ->whereNull('steps_hash')
                ->update(['steps_hash' => Runner::hashSteps(is_array($steps) ? $steps : [])]);
        });
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
