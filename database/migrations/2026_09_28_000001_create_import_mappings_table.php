<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which record in another billing system (for example WHMCS client 42) became which Nuvabill record,
     * so running an import again updates records instead of adding them twice.
     */
    public function up(): void
    {
        Schema::create('import_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('source', 20);
            $table->string('entity', 40);
            $table->unsignedBigInteger('source_id');
            $table->unsignedBigInteger('local_id');

            $table->unique(['source', 'entity', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_mappings');
    }
};
