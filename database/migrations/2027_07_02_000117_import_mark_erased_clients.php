<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Clients erased before this version still had their import mappings, so another run of an import put
 * their name, phone, address and notes back and opened them again. Their mappings now become
 * 'client_erased' ones, as erasing does from now on, and the import skips those clients.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('import_mappings') || ! Schema::hasTable('clients') || ! Schema::hasColumn('clients', 'erased_at')) {
            return;
        }

        $entities = ['client', 'client_link', 'client_unproven'];
        $erased = DB::table('clients')->select('id')->whereNotNull('erased_at');

        $mappings = DB::table('import_mappings')
            ->whereIn('entity', $entities)
            ->whereIn('local_id', $erased)
            ->orderBy('id')
            ->get(['source', 'source_id', 'local_id'])
            ->unique(fn (object $mapping): string => $mapping->source.'|'.$mapping->source_id);

        foreach ($mappings as $mapping) {
            DB::table('import_mappings')->updateOrInsert(
                ['source' => $mapping->source, 'entity' => 'client_erased', 'source_id' => $mapping->source_id],
                ['local_id' => $mapping->local_id],
            );
        }

        DB::table('import_mappings')->whereIn('entity', $entities)->whereIn('local_id', $erased)->delete();
    }

    public function down(): void
    {
        // Which mapping each erased client had is not kept, and they must not be imported again anyway.
    }
};
