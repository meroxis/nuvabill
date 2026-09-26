<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('tld_prices', function (Blueprint $table) {
            $table->id();
            $table->string('tld', 63);
            $table->char('currency', 3);
            $table->string('registrar')->nullable();
            $table->bigInteger('register_price');
            $table->bigInteger('transfer_price');
            $table->bigInteger('renew_price');
            $table->unsignedTinyInteger('min_years')->default(1);
            $table->unsignedTinyInteger('max_years')->default(10);
            $table->boolean('epp_required')->default(true);
            $table->boolean('is_featured')->default(false);
            $table->boolean('is_enabled')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['tld', 'currency']);
        });

        Schema::create('domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name')->index();
            $table->string('tld', 63);
            $table->string('registrar')->nullable();
            $table->string('order_type')->default('register');
            $table->string('status')->default('pending')->index();
            $table->unsignedTinyInteger('years')->default(1);
            $table->char('currency', 3);
            $table->bigInteger('first_payment_amount')->default(0);
            $table->bigInteger('recurring_amount')->default(0);
            $table->date('registered_at')->nullable();
            $table->date('expires_at')->nullable()->index();
            $table->date('next_due_date')->nullable()->index();
            $table->boolean('auto_renew')->default(true);
            $table->json('nameservers')->nullable();
            $table->text('epp_code')->nullable();
            $table->json('registrar_data')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('expiry_notice_sent_at')->nullable();
            $table->timestamps();
        });

        Schema::table('invoice_items', function (Blueprint $table) {
            $table->foreignId('domain_id')->nullable()->after('service_id')->constrained()->nullOnDelete();
            $table->index(['domain_id', 'period_start']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->boolean('needs_review')->default(false)->after('status');
            $table->json('fraud_reasons')->nullable()->after('needs_review');
        });

        Schema::create('payment_intents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->string('gateway');
            $table->string('reference');
            $table->bigInteger('amount');
            $table->char('currency', 3);
            $table->string('status')->default('pending');
            $table->json('meta')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['gateway', 'reference']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_intents');

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['needs_review', 'fraud_reasons']);
        });

        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropIndex(['domain_id', 'period_start']);
            $table->dropConstrainedForeignId('domain_id');
        });

        Schema::dropIfExists('domains');
        Schema::dropIfExists('tld_prices');
    }
};
