<?php

use Database\Seeders\DefaultDataSeeder;
use Database\Seeders\EmailTemplateTranslations;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Credit notes for paid invoices (refunds, wallet credit or goodwill), and the date a client's
 * personal data was erased.
 */
return new class extends Migration
{
    private const TEMPLATE_KEYS = ['invoice.credit_note'];

    public function up(): void
    {
        if (! Schema::hasTable('credit_notes')) {
            Schema::create('credit_notes', function (Blueprint $table) {
                $table->id();
                $table->string('number', 40)->nullable()->unique();
                $table->foreignId('invoice_id')->constrained();
                $table->foreignId('client_id')->constrained();
                $table->foreignId('admin_id')->nullable()->constrained('admins')->nullOnDelete();
                $table->string('currency', 3);
                $table->bigInteger('subtotal');
                $table->bigInteger('tax')->default(0);
                $table->bigInteger('total');
                $table->string('tax_name')->nullable();
                $table->unsignedInteger('tax_rate')->nullable();
                $table->json('items');
                $table->string('method', 20);
                $table->string('reason', 500)->nullable();
                $table->timestamp('issued_at');
                $table->timestamps();

                $table->index(['client_id', 'issued_at']);
                $table->index('issued_at');
            });
        }

        if (! Schema::hasColumn('clients', 'erased_at')) {
            Schema::table('clients', function (Blueprint $table) {
                $table->timestamp('erased_at')->nullable();
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

        EmailTemplateTranslations::install(self::TEMPLATE_KEYS);
    }

    public function down(): void
    {
        DB::table('email_templates')->whereIn('key', self::TEMPLATE_KEYS)->delete();
        Schema::dropIfExists('credit_notes');

        if (Schema::hasColumn('clients', 'erased_at')) {
            Schema::table('clients', function (Blueprint $table) {
                $table->dropColumn('erased_at');
            });
        }
    }
};
