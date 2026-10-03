<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Finds the source records that point to a Nuvabill record, so every import run can tell a client an
 * earlier version linked to an account it did not create, without reading the whole table each time.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('import_mappings') && ! Schema::hasIndex('import_mappings', ['entity', 'local_id'])) {
            Schema::table('import_mappings', function (Blueprint $table) {
                $table->index(['entity', 'local_id'], 'import_mappings_entity_local_id_index');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('import_mappings') && Schema::hasIndex('import_mappings', ['entity', 'local_id'])) {
            Schema::table('import_mappings', function (Blueprint $table) {
                $table->dropIndex('import_mappings_entity_local_id_index');
            });
        }
    }
};
