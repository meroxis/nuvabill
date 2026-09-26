<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Google, GitHub and Facebook accounts clients sign in with. Clients who signed up with one have
     * no password of their own until they choose one (has_password).
     */
    public function up(): void
    {
        Schema::create('client_social_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 20);
            $table->string('provider_user_id', 191);
            $table->string('email')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_user_id']);
            $table->unique(['client_id', 'provider']);
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->boolean('has_password')->default(true)->after('password');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_social_accounts');

        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('has_password');
        });
    }
};
