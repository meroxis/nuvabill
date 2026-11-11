<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Affiliate commissions: each new commission keeps the money it was worked out on (the part of
 * the invoice paid through a payment method, with tax), so the commissions for one referred
 * client never stand for more money than that client brought in. Commissions made before this
 * update keep an empty value and are released as before.
 *
 * Safe to run again: the column is only added when it is missing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('affiliate_commissions') && ! Schema::hasColumn('affiliate_commissions', 'base')) {
            Schema::table('affiliate_commissions', function (Blueprint $table) {
                $table->bigInteger('base')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('affiliate_commissions') && Schema::hasColumn('affiliate_commissions', 'base')) {
            Schema::table('affiliate_commissions', function (Blueprint $table) {
                $table->dropColumn('base');
            });
        }
    }
};
