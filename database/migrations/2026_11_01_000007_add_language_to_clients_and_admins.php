<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The language each client and staff member picked. Empty means the site default.
     */
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('language', 8)->nullable();
        });

        Schema::table('admins', function (Blueprint $table) {
            $table->string('language', 8)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('language');
        });

        Schema::table('admins', function (Blueprint $table) {
            $table->dropColumn('language');
        });
    }
};
