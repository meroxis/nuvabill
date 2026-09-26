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
        Schema::create('product_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_visible')->default(true);
            $table->timestamps();
        });

        Schema::create('servers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('module');
            $table->string('hostname');
            $table->string('ip_address', 45)->nullable();
            $table->unsignedInteger('port')->nullable();
            $table->boolean('use_ssl')->default(true);
            $table->string('username')->nullable();
            $table->text('password')->nullable();
            $table->text('api_token')->nullable();
            $table->json('nameservers')->nullable();
            $table->unsignedInteger('max_accounts')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_group_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('type')->default('hosting');
            $table->text('description')->nullable();
            $table->boolean('is_visible')->default(true);
            $table->boolean('requires_domain')->default(true);
            $table->string('server_module')->nullable();
            $table->foreignId('server_id')->nullable()->constrained()->nullOnDelete();
            $table->json('module_config')->nullable();
            $table->string('auto_setup')->default('payment');
            $table->unsignedInteger('stock')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('product_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->char('currency', 3);
            $table->string('billing_cycle');
            $table->bigInteger('price');
            $table->bigInteger('setup_fee')->default(0);
            $table->timestamps();

            $table->unique(['product_id', 'currency', 'billing_cycle']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_prices');
        Schema::dropIfExists('products');
        Schema::dropIfExists('servers');
        Schema::dropIfExists('product_groups');
    }
};
