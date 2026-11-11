<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marketplace store: each download notes the network it came from and, when a connected site
 * made it, which site. Installs of free items are counted once per connected site or network.
 * Downloads from before this update keep both empty.
 *
 * Safe to run again, also after it stopped halfway: every column and index is only added when
 * it is missing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('marketplace_downloads')) {
            return;
        }

        if (! Schema::hasColumn('marketplace_downloads', 'network')) {
            Schema::table('marketplace_downloads', function (Blueprint $table) {
                $table->string('network', 50)->nullable();
            });
        }

        if (! Schema::hasColumn('marketplace_downloads', 'marketplace_site_id')) {
            Schema::table('marketplace_downloads', function (Blueprint $table) {
                $table->unsignedBigInteger('marketplace_site_id')->nullable();
            });
        }

        if (! Schema::hasIndex('marketplace_downloads', 'marketplace_downloads_item_network_index')) {
            Schema::table('marketplace_downloads', function (Blueprint $table) {
                $table->index(['marketplace_item_id', 'network'], 'marketplace_downloads_item_network_index');
            });
        }

        if (! Schema::hasIndex('marketplace_downloads', 'marketplace_downloads_item_connected_index')) {
            Schema::table('marketplace_downloads', function (Blueprint $table) {
                $table->index(['marketplace_item_id', 'marketplace_site_id'], 'marketplace_downloads_item_connected_index');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('marketplace_downloads')) {
            return;
        }

        foreach (['marketplace_downloads_item_network_index', 'marketplace_downloads_item_connected_index'] as $index) {
            if (Schema::hasIndex('marketplace_downloads', $index)) {
                Schema::table('marketplace_downloads', function (Blueprint $table) use ($index) {
                    $table->dropIndex($index);
                });
            }
        }

        foreach (['network', 'marketplace_site_id'] as $column) {
            if (Schema::hasColumn('marketplace_downloads', $column)) {
                Schema::table('marketplace_downloads', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
