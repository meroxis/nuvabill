<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Imported clients keep the password hash of their old billing system until their first sign-in,
 * when it is checked and replaced by a Nuvabill hash (App\Auth\LegacyPassword).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('clients', 'legacy_password')) {
            Schema::table('clients', function (Blueprint $table) {
                $table->text('legacy_password')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('clients', 'legacy_password')) {
            Schema::table('clients', function (Blueprint $table) {
                $table->dropColumn('legacy_password');
            });
        }
    }
};
