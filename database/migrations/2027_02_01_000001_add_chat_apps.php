<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chat apps: which clients linked Telegram or WhatsApp, and which ticket messages came from a chat.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('chat_links')) {
            Schema::create('chat_links', function (Blueprint $table) {
                $table->id();
                $table->foreignId('client_id')->constrained()->cascadeOnDelete();
                $table->string('channel', 20);
                // The Telegram chat ID or the WhatsApp phone number.
                $table->string('external_id', 64);
                $table->string('name', 120)->nullable();
                // WhatsApp only allows free text within 24 hours of the client's last message.
                $table->timestamp('last_inbound_at')->nullable();
                $table->timestamps();
                $table->unique(['channel', 'external_id']);
            });
        }

        if (! Schema::hasColumn('ticket_replies', 'channel')) {
            Schema::table('ticket_replies', function (Blueprint $table) {
                $table->string('channel', 20)->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_links');

        if (Schema::hasColumn('ticket_replies', 'channel')) {
            Schema::table('ticket_replies', fn (Blueprint $table) => $table->dropColumn('channel'));
        }
    }
};
