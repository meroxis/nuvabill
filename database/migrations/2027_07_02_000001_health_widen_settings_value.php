<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Room for large settings, such as the list of files staff marked as their own in site health.
 * On MySQL and MariaDB a TEXT column stops at 64 KB, and saving more fails. SQLite has no such limit.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('settings', 'value') || ! in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        if (strtolower(Schema::getColumnType('settings', 'value')) === 'longtext') {
            return;
        }

        Schema::table('settings', function (Blueprint $table) {
            $table->longText('value')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Left as it is: making the column smaller again could cut off saved settings.
    }
};
