<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marketplace store: a refunded sale takes the developer's share back (a negative earning tied to
 * the credit note), key moves count per year from their own date, download counts look up sites
 * and addresses quickly, and listing changes on approved items wait for review.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('developer_earnings') && ! Schema::hasColumn('developer_earnings', 'credit_note_id')) {
            Schema::table('developer_earnings', function (Blueprint $table) {
                $table->foreignId('credit_note_id')->nullable()->constrained()->nullOnDelete();
                $table->unique(['credit_note_id', 'marketplace_item_id'], 'developer_earnings_credit_note_item_unique');
            });
        }

        if (Schema::hasTable('licenses') && ! Schema::hasColumn('licenses', 'site_changes_since')) {
            Schema::table('licenses', function (Blueprint $table) {
                $table->timestamp('site_changes_since')->nullable();
            });
        }

        if (Schema::hasTable('marketplace_downloads') && ! Schema::hasIndex('marketplace_downloads', 'marketplace_downloads_item_site_index')) {
            Schema::table('marketplace_downloads', function (Blueprint $table) {
                $table->index(['marketplace_item_id', 'site'], 'marketplace_downloads_item_site_index');
                $table->index(['marketplace_item_id', 'ip'], 'marketplace_downloads_item_ip_index');
            });
        }

        if (Schema::hasTable('marketplace_items') && ! Schema::hasColumn('marketplace_items', 'pending_listing')) {
            Schema::table('marketplace_items', function (Blueprint $table) {
                $table->json('pending_listing')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('marketplace_items', 'pending_listing')) {
            Schema::table('marketplace_items', function (Blueprint $table) {
                $table->dropColumn('pending_listing');
            });
        }

        if (Schema::hasIndex('marketplace_downloads', 'marketplace_downloads_item_site_index')) {
            Schema::table('marketplace_downloads', function (Blueprint $table) {
                $table->dropIndex('marketplace_downloads_item_site_index');
                $table->dropIndex('marketplace_downloads_item_ip_index');
            });
        }

        if (Schema::hasColumn('licenses', 'site_changes_since')) {
            Schema::table('licenses', function (Blueprint $table) {
                $table->dropColumn('site_changes_since');
            });
        }

        if (Schema::hasColumn('developer_earnings', 'credit_note_id')) {
            Schema::table('developer_earnings', function (Blueprint $table) {
                $table->dropForeign(['credit_note_id']);
                $table->dropUnique('developer_earnings_credit_note_item_unique');
                $table->dropColumn('credit_note_id');
            });
        }
    }
};
