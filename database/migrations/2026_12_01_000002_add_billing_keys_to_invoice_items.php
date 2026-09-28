<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One invoice per billing period: renewal lines carry a key such as "service:12:2026-10-01", and
 * the database refuses a second line with the same key. Cancelling an invoice frees its keys.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('invoice_items', 'billing_key')) {
            Schema::table('invoice_items', function (Blueprint $table) {
                $table->string('billing_key', 100)->nullable()->unique('invoice_items_billing_key_unique');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('invoice_items', 'billing_key')) {
            Schema::table('invoice_items', function (Blueprint $table) {
                $table->dropUnique('invoice_items_billing_key_unique');
                $table->dropColumn('billing_key');
            });
        }
    }
};
