<?php

namespace App\Chat;

use App\Jobs\SendChatMessage;
use App\Models\ChatLink;
use App\Models\Client;
use App\Support\Demo;
use Illuminate\Support\Facades\DB;

/**
 * Sends a short chat message next to a client email, to the chat apps the client linked, when the
 * owner turned that message on for that app in Settings → Chat apps. Sending happens in the
 * background, so an email never waits for Telegram or Meta.
 */
class ChatNotifier
{
    public function __construct(private Telegram $telegram, private WhatsApp $whatsApp) {}

    /**
     * @param  array<string, mixed>  $context  the email context
     */
    public function clientEmailed(string $event, Client $client, array $context): void
    {
        if (! isset(ChatMessages::EVENTS[$event]) || Demo::isEnabled()) {
            return;
        }

        $events = (array) setting('chat.events');
        $channels = array_filter([
            ChatLink::TELEGRAM => ($events[$event][ChatLink::TELEGRAM] ?? false) && $this->telegram->isConnected(),
            ChatLink::WHATSAPP => ($events[$event][ChatLink::WHATSAPP] ?? false) && $this->whatsApp->isConnected(),
        ]);

        if ($channels === []) {
            return;
        }

        $links = ChatLink::query()->where('client_id', $client->id)->whereIn('channel', array_keys($channels))->pluck('id');
        $context['invoices_url'] = route('client.invoices.index');

        foreach ($links as $linkId) {
            DB::afterCommit(fn () => SendChatMessage::dispatch($linkId, $event, $context));
        }
    }

    /**
     * Tell the team's Telegram group about a staff email, such as a new ticket or order.
     */
    public function staffEmailed(string $subject, ?string $url): void
    {
        $chat = (string) setting('chat.telegram_staff_chat');

        if ($chat === '' || Demo::isEnabled() || ! $this->telegram->isConnected()) {
            return;
        }

        DB::afterCommit(fn () => SendChatMessage::dispatch(null, 'staff', ['subject' => $subject, 'url' => $url, 'chat' => $chat]));
    }
}
