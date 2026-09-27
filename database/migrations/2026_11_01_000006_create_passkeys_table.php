<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Passkeys (WebAuthn) for staff and clients. Only the public key is stored; the private key
     * never leaves the person's device.
     */
    public function up(): void
    {
        Schema::create('passkeys', function (Blueprint $table) {
            $table->id();
            $table->morphs('owner');
            $table->string('name', 100);
            $table->text('credential_id');
            $table->char('credential_hash', 64)->unique();
            $table->text('public_key');
            $table->integer('algorithm');
            $table->unsignedBigInteger('sign_count')->default(0);
            $table->json('transports')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('passkeys');
    }
};
