<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Marketplace store: each earning names the service it was paid for, a refunded sale takes the
 * developer's share back (a negative earning tied to the credit note and to the earning it takes
 * from), key moves count per year from their own date, download counts look up sites and
 * addresses quickly, and listing changes on approved items wait for review.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('developer_earnings') && ! Schema::hasColumn('developer_earnings', 'credit_note_id')) {
            Schema::table('developer_earnings', function (Blueprint $table) {
                $table->foreignId('credit_note_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('service_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('reverses_id')->nullable()->constrained('developer_earnings')->cascadeOnDelete();
                $table->unique(['credit_note_id', 'reverses_id'], 'developer_earnings_credit_note_reverses_unique');
            });

            $this->fillEarningServices();
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
            // MySQL and MariaDB drop the index of the item foreign key once these indexes cover it, so it
            // comes back first; without it the indexes cannot be dropped.
            if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)
                && ! Schema::hasIndex('marketplace_downloads', 'marketplace_downloads_marketplace_item_id_foreign')) {
                Schema::table('marketplace_downloads', function (Blueprint $table) {
                    $table->index('marketplace_item_id', 'marketplace_downloads_marketplace_item_id_foreign');
                });
            }

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
                $table->dropForeign(['reverses_id']);
                $table->dropForeign(['service_id']);
                $table->dropForeign(['credit_note_id']);
                $table->dropUnique('developer_earnings_credit_note_reverses_unique');
                $table->dropColumn(['reverses_id', 'service_id', 'credit_note_id']);
            });
        }
    }

    /**
     * Earnings made before this update name their service through their license. An earning
     * without one (the key was made after the payment) takes the only key for its item that was
     * bought on its invoice.
     */
    private function fillEarningServices(): void
    {
        if (! Schema::hasTable('licenses') || ! Schema::hasTable('invoice_items')) {
            return;
        }

        DB::table('developer_earnings')->whereNull('service_id')->orderBy('id')->chunkById(200, function ($earnings): void {
            foreach ($earnings as $earning) {
                if ($earning->license_id === null && $earning->invoice_id === null) {
                    continue;
                }

                $licenses = $earning->license_id !== null
                    ? DB::table('licenses')->where('id', $earning->license_id)->get(['id', 'service_id'])
                    : DB::table('licenses')
                        ->where('marketplace_item_id', $earning->marketplace_item_id)
                        ->whereIn('service_id', DB::table('invoice_items')->where('invoice_id', $earning->invoice_id)->whereNotNull('service_id')->select('service_id'))
                        ->limit(2)
                        ->get(['id', 'service_id']);

                if ($licenses->count() === 1 && $licenses->first()->service_id !== null) {
                    DB::table('developer_earnings')->where('id', $earning->id)->update([
                        'service_id' => $licenses->first()->service_id,
                        'license_id' => $licenses->first()->id,
                    ]);
                }
            }
        });
    }
};
