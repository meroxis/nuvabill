<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An automatic payment whose result is not known yet (still processing at the bank, or no answer
 * from the gateway). It is checked before the invoice is charged again, so it is never paid twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('invoices', 'autopay_pending')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->json('autopay_pending')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('invoices', 'autopay_pending')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->dropColumn('autopay_pending');
            });
        }
    }
};
