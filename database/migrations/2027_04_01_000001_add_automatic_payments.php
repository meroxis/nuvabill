<?php

use Database\Seeders\DefaultDataSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Automatic payments: cards and PayPal accounts clients save for renewals, the switch clients
 * turn them off with, what happened when Nuvabill last tried to charge an invoice, and the
 * emails about it.
 */
return new class extends Migration
{
    private const TEMPLATE_KEYS = ['invoice.autopay_upcoming', 'invoice.autopay_failed', 'payment.method_expiring'];

    public function up(): void
    {
        if (! Schema::hasTable('payment_methods')) {
            Schema::create('payment_methods', function (Blueprint $table) {
                $table->id();
                $table->foreignId('client_id')->constrained()->cascadeOnDelete();
                $table->string('gateway', 50);
                // "card" or "paypal". The card itself stays with the gateway; only its reference is kept.
                $table->string('type', 20);
                $table->string('reference', 191);
                $table->string('customer_reference', 191)->nullable();
                $table->string('brand', 30)->nullable();
                $table->string('last4', 4)->nullable();
                $table->unsignedTinyInteger('expires_month')->nullable();
                $table->unsignedSmallInteger('expires_year')->nullable();
                $table->string('email')->nullable();
                $table->boolean('is_default')->default(false);
                $table->timestamp('last_used_at')->nullable();
                $table->timestamp('expiry_notice_at')->nullable();
                $table->timestamps();
                $table->unique(['gateway', 'reference']);
            });
        }

        if (! Schema::hasColumn('clients', 'auto_pay')) {
            Schema::table('clients', function (Blueprint $table) {
                $table->boolean('auto_pay')->default(true);
            });
        }

        if (! Schema::hasColumn('invoices', 'autopay_attempts')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->unsignedTinyInteger('autopay_attempts')->default(0);
                $table->timestamp('autopay_retry_at')->nullable();
                $table->timestamp('autopay_notice_at')->nullable();
                $table->string('autopay_error')->nullable();
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
        Schema::dropIfExists('payment_methods');

        if (Schema::hasColumn('invoices', 'autopay_attempts')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->dropColumn(['autopay_attempts', 'autopay_retry_at', 'autopay_notice_at', 'autopay_error']);
            });
        }

        if (Schema::hasColumn('clients', 'auto_pay')) {
            Schema::table('clients', function (Blueprint $table) {
                $table->dropColumn('auto_pay');
            });
        }
    }
};
