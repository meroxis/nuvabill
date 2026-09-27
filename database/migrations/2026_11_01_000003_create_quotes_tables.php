<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Quotes staff send to clients. Accepting one creates an invoice with the same lines and tax.
     */
    public function up(): void
    {
        Schema::create('quotes', function (Blueprint $table) {
            $table->id();
            $table->string('number')->nullable()->unique();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('subject');
            $table->string('status')->default('draft')->index();
            $table->char('currency', 3);
            $table->bigInteger('subtotal')->default(0);
            $table->string('tax_name')->nullable();
            $table->unsignedInteger('tax_rate')->nullable();
            $table->boolean('tax_inclusive')->default(false);
            $table->bigInteger('tax')->default(0);
            $table->bigInteger('total')->default(0);
            $table->date('valid_until');
            $table->text('notes')->nullable();
            $table->text('admin_notes')->nullable();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->timestamps();
        });

        Schema::create('quote_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quote_id')->constrained()->cascadeOnDelete();
            $table->string('description');
            $table->bigInteger('amount');
            $table->boolean('taxed')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_items');
        Schema::dropIfExists('quotes');
    }
};
