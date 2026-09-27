<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The marketplace. Every copy of Nuvabill uses marketplace_installs to remember what it installed
 * from the marketplace. The other tables are only filled on the marketplace store itself
 * (my.nuvabill.com, NUVABILL_MARKETPLACE_STORE=true): developers, their items and versions,
 * reviews, license keys, earnings and payouts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_installs', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 64)->unique();
            $table->string('type', 20);
            $table->string('name');
            $table->string('version', 40);
            $table->text('license_key')->nullable();
            $table->string('license_status', 20)->nullable();
            $table->string('license_message')->nullable();
            $table->timestamp('license_checked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('developers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('name', 120);
            $table->string('slug', 64)->unique();
            $table->string('website')->nullable();
            $table->string('bio', 500)->nullable();
            $table->boolean('is_verified')->default(false);
            $table->boolean('is_official')->default(false);
            $table->unsignedTinyInteger('share_percent')->nullable();
            $table->string('payout_method', 30)->nullable();
            $table->text('payout_details')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();
        });

        Schema::create('marketplace_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('developer_id')->constrained()->cascadeOnDelete();
            $table->string('slug', 64)->unique();
            $table->string('type', 20);
            $table->string('name', 120);
            $table->string('summary', 200)->nullable();
            $table->text('description')->nullable();
            $table->string('category', 40)->nullable();
            $table->bigInteger('price')->default(0);
            $table->bigInteger('update_price')->default(0);
            $table->string('currency', 3)->default('USD');
            $table->string('status', 20)->default('draft');
            $table->boolean('is_featured')->default(false);
            $table->json('icon')->nullable();
            $table->json('screenshots')->nullable();
            $table->string('demo_url')->nullable();
            $table->string('docs_url')->nullable();
            $table->json('permissions')->nullable();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('latest_version_id')->nullable();
            $table->unsignedInteger('installs_count')->default(0);
            $table->unsignedInteger('sales_count')->default(0);
            $table->timestamps();
        });

        Schema::create('marketplace_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('marketplace_item_id')->constrained()->cascadeOnDelete();
            $table->string('version', 40);
            $table->text('changelog')->nullable();
            $table->string('file_path');
            $table->unsignedBigInteger('file_size')->default(0);
            $table->string('sha256', 64);
            $table->json('manifest')->nullable();
            $table->json('checks')->nullable();
            $table->json('checklist')->nullable();
            $table->string('status', 20)->default('pending');
            $table->foreignId('reviewer_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();

            $table->unique(['marketplace_item_id', 'version']);
        });

        Schema::create('marketplace_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('marketplace_version_id')->constrained()->cascadeOnDelete();
            $table->string('author_type', 20);
            $table->unsignedBigInteger('author_id')->nullable();
            $table->text('message');
            $table->timestamps();
        });

        Schema::create('licenses', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40)->unique();
            $table->foreignId('marketplace_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->nullable()->constrained()->nullOnDelete();
            $table->string('site')->nullable();
            $table->unsignedSmallInteger('site_changes')->default(0);
            $table->string('status', 20)->default('active');
            $table->date('updates_until')->nullable();
            $table->string('revoked_reason')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });

        Schema::create('license_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('license_id')->constrained()->cascadeOnDelete();
            $table->string('site');
            $table->string('ip', 45)->nullable();
            $table->string('version', 40)->nullable();
            $table->boolean('matched')->default(true);
            $table->timestamp('created_at')->nullable();

            $table->index(['license_id', 'created_at']);
        });

        Schema::create('payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('developer_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('amount');
            $table->string('currency', 3);
            $table->string('status', 20)->default('pending');
            $table->string('reference')->nullable();
            $table->string('notes', 500)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });

        Schema::create('developer_earnings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('developer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('marketplace_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('license_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->bigInteger('gross');
            $table->bigInteger('developer_share');
            $table->bigInteger('fee');
            $table->unsignedTinyInteger('share_percent');
            $table->string('currency', 3);
            $table->foreignId('payout_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('marketplace_downloads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('marketplace_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('marketplace_version_id')->constrained()->cascadeOnDelete();
            $table->foreignId('license_id')->nullable()->constrained()->nullOnDelete();
            $table->string('site')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_downloads');
        Schema::dropIfExists('developer_earnings');
        Schema::dropIfExists('payouts');
        Schema::dropIfExists('license_checks');
        Schema::dropIfExists('licenses');
        Schema::dropIfExists('marketplace_messages');
        Schema::dropIfExists('marketplace_versions');
        Schema::dropIfExists('marketplace_items');
        Schema::dropIfExists('developers');
        Schema::dropIfExists('marketplace_installs');
    }
};
