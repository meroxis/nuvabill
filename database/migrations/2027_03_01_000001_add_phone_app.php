<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The admin phone app: the phones and browsers each staff member gets push alerts on, and which
 * alerts they want.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('push_subscriptions')) {
            Schema::create('push_subscriptions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('admin_id')->constrained()->cascadeOnDelete();
                // Push addresses are long; the hash keeps them unique on every database.
                $table->text('endpoint');
                $table->char('endpoint_hash', 64)->unique();
                $table->string('public_key', 120);
                $table->string('auth_token', 40);
                $table->string('device', 120)->nullable();
                $table->timestamp('last_sent_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasColumn('admins', 'push_alerts')) {
            Schema::table('admins', function (Blueprint $table) {
                $table->json('push_alerts')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');

        if (Schema::hasColumn('admins', 'push_alerts')) {
            Schema::table('admins', function (Blueprint $table) {
                $table->dropColumn('push_alerts');
            });
        }
    }
};
