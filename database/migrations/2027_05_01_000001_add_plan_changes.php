<?php

use Database\Seeders\DefaultDataSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Plan changes: clients (and staff) move a service to a bigger or smaller product, with a fair
 * price for the rest of the billing period.
 */
return new class extends Migration
{
    private const TEMPLATE_KEYS = ['service.plan_changed'];

    public function up(): void
    {
        if (! Schema::hasColumn('products', 'upgrade_product_ids')) {
            Schema::table('products', function (Blueprint $table) {
                $table->json('upgrade_product_ids')->nullable();
            });
        }

        if (! Schema::hasTable('plan_changes')) {
            Schema::create('plan_changes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('service_id')->constrained()->cascadeOnDelete();
                $table->foreignId('client_id')->constrained()->cascadeOnDelete();
                $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('admin_id')->nullable()->constrained('admins')->nullOnDelete();
                $table->unsignedBigInteger('from_product_id');
                $table->unsignedBigInteger('to_product_id');
                $table->string('billing_cycle', 20);
                $table->string('currency', 3);
                $table->bigInteger('old_amount');
                $table->bigInteger('new_amount');
                $table->bigInteger('difference');
                $table->string('mode', 20);
                $table->string('status', 20)->default('pending');
                $table->date('apply_on')->nullable();
                $table->timestamp('applied_at')->nullable();
                $table->timestamps();

                $table->index(['service_id', 'status']);
                $table->index(['status', 'apply_on']);
            });
        }

        $now = now();

        foreach (DefaultDataSeeder::templates() as $key => [$name, $subject, $body]) {
            if (in_array($key, self::TEMPLATE_KEYS, true) && ! DB::table('email_templates')->where('key', $key)->exists()) {
                DB::table('email_templates')->insert([
                    'key' => $key,
                    'name' => $name,
                    'subject' => $subject,
                    'body' => $body,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('email_templates')->whereIn('key', self::TEMPLATE_KEYS)->delete();
        Schema::dropIfExists('plan_changes');

        if (Schema::hasColumn('products', 'upgrade_product_ids')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn('upgrade_product_ids');
            });
        }
    }
};
