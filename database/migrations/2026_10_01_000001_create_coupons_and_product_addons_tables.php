<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Coupons (discount codes for orders and renewals) and product add-ons (extras such as daily
 * backups that clients add to a service and pay for with it).
 *
 * Safe to run again: on MySQL and MariaDB it used to stop at an index name longer than 64
 * characters, after some tables were already made.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('coupons')) {
            Schema::create('coupons', function (Blueprint $table) {
                $table->id();
                $table->string('code', 40)->unique();
                $table->string('type', 10);
                $table->bigInteger('value');
                $table->string('currency', 3)->nullable();
                $table->json('product_ids')->nullable();
                $table->json('billing_cycles')->nullable();
                $table->boolean('applies_to_domains')->default(false);
                $table->string('recurring', 10)->default('first');
                $table->unsignedSmallInteger('recurring_count')->nullable();
                $table->date('starts_at')->nullable();
                $table->date('ends_at')->nullable();
                $table->unsignedInteger('max_uses')->nullable();
                $table->unsignedInteger('max_uses_per_client')->nullable();
                $table->boolean('new_clients_only')->default(false);
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('uses')->default(0);
                $table->string('notes', 500)->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('coupon_redemptions')) {
            Schema::create('coupon_redemptions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('coupon_id')->constrained()->cascadeOnDelete();
                $table->foreignId('client_id')->constrained()->cascadeOnDelete();
                $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
                $table->bigInteger('amount');
                $table->string('currency', 3);
                $table->timestamps();

                $table->index(['coupon_id', 'client_id']);
            });
        }

        if (! Schema::hasColumn('services', 'coupon_id')) {
            Schema::table('services', function (Blueprint $table) {
                $table->foreignId('coupon_id')->nullable()->constrained()->nullOnDelete();
                $table->unsignedSmallInteger('coupon_payments_left')->nullable();
            });
        }

        if (! Schema::hasColumn('orders', 'coupon_id')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->foreignId('coupon_id')->nullable()->constrained()->nullOnDelete();
                $table->bigInteger('discount')->default(0);
            });
        }

        if (! Schema::hasTable('product_addons')) {
            Schema::create('product_addons', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->text('description')->nullable();
                $table->string('icon', 30)->nullable();
                $table->json('product_ids')->nullable();
                $table->boolean('is_visible')->default(true);
                $table->boolean('is_popular')->default(false);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('product_addon_prices')) {
            Schema::create('product_addon_prices', function (Blueprint $table) {
                $table->id();
                $table->foreignId('product_addon_id')->constrained()->cascadeOnDelete();
                $table->string('currency', 3);
                $table->string('billing_cycle', 20);
                $table->bigInteger('price');
                $table->bigInteger('setup_fee')->default(0);

                $table->unique(['product_addon_id', 'currency', 'billing_cycle'], 'product_addon_prices_cycle_unique');
            });
        } elseif (! Schema::hasIndex('product_addon_prices', ['product_addon_id', 'currency', 'billing_cycle'], 'unique')) {
            Schema::table('product_addon_prices', function (Blueprint $table) {
                $table->unique(['product_addon_id', 'currency', 'billing_cycle'], 'product_addon_prices_cycle_unique');
            });
        }

        if (! Schema::hasTable('service_addons')) {
            Schema::create('service_addons', function (Blueprint $table) {
                $table->id();
                $table->foreignId('service_id')->constrained()->cascadeOnDelete();
                $table->foreignId('product_addon_id')->nullable()->constrained()->nullOnDelete();
                $table->string('name');
                $table->bigInteger('recurring_amount')->default(0);
                $table->string('status', 20)->default('active');
                $table->timestamp('cancelled_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('service_addons');
        Schema::dropIfExists('product_addon_prices');
        Schema::dropIfExists('product_addons');

        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('coupon_id');
            $table->dropColumn('discount');
        });

        Schema::table('services', function (Blueprint $table) {
            $table->dropConstrainedForeignId('coupon_id');
            $table->dropColumn('coupon_payments_left');
        });

        Schema::dropIfExists('coupon_redemptions');
        Schema::dropIfExists('coupons');
    }
};
