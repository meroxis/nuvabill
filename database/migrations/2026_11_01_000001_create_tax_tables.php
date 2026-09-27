<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tax rules by country and state, taxable products, tax-exempt clients with a tax ID, and the
     * tax each invoice was created with, so later changes to the rules never change old invoices.
     */
    public function up(): void
    {
        Schema::create('tax_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // Hundredths of a percent: 2000 is 20%.
            $table->unsignedInteger('rate');
            $table->char('country', 2)->nullable();
            $table->string('state')->nullable();
            $table->timestamps();

            $table->index(['country', 'state']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->boolean('taxable')->default(true);
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->boolean('tax_exempt')->default(false);
            $table->string('tax_id', 64)->nullable();
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->string('tax_name')->nullable();
            $table->unsignedInteger('tax_rate')->nullable();
            $table->boolean('tax_inclusive')->default(false);
        });

        Schema::table('invoice_items', function (Blueprint $table) {
            $table->boolean('taxed')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropColumn('taxed');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['tax_name', 'tax_rate', 'tax_inclusive']);
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['tax_exempt', 'tax_id']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('taxable');
        });

        Schema::dropIfExists('tax_rules');
    }
};
