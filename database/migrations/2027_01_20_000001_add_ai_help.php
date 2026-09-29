<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AI help: what each AI request cost, translations and summaries on tickets, and the new "ai.use"
 * staff right for everyone who answers tickets or edits products.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_usages')) {
            Schema::create('ai_usages', function (Blueprint $table) {
                $table->id();
                $table->foreignId('admin_id')->nullable()->constrained()->nullOnDelete();
                $table->string('feature', 40);
                $table->string('model', 60);
                $table->unsignedInteger('input_tokens')->default(0);
                $table->unsignedInteger('output_tokens')->default(0);
                // Millionths of a US dollar, so small requests still add up exactly.
                $table->unsignedBigInteger('cost_micros')->default(0);
                $table->nullableMorphs('subject');
                $table->timestamp('created_at')->nullable()->index();
            });
        }

        Schema::table('ticket_replies', function (Blueprint $table) {
            if (! Schema::hasColumn('ticket_replies', 'language')) {
                $table->string('language', 12)->nullable();
            }

            if (! Schema::hasColumn('ticket_replies', 'translation')) {
                $table->text('translation')->nullable();
            }

            if (! Schema::hasColumn('ticket_replies', 'original_message')) {
                $table->text('original_message')->nullable();
            }
        });

        if (! Schema::hasColumn('tickets', 'ai_summary')) {
            Schema::table('tickets', function (Blueprint $table) {
                $table->json('ai_summary')->nullable();
            });
        }

        foreach (DB::table('roles')->get(['id', 'permissions']) as $role) {
            $permissions = json_decode((string) $role->permissions, true) ?: [];
            $usesAi = array_intersect(['support.manage', 'products.manage', 'settings.manage'], $permissions) !== [];

            if ($usesAi && ! in_array('ai.use', $permissions, true)) {
                DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode([...$permissions, 'ai.use'])]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usages');

        foreach (['language', 'translation', 'original_message'] as $column) {
            if (Schema::hasColumn('ticket_replies', $column)) {
                Schema::table('ticket_replies', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }

        if (Schema::hasColumn('tickets', 'ai_summary')) {
            Schema::table('tickets', fn (Blueprint $table) => $table->dropColumn('ai_summary'));
        }
    }
};
