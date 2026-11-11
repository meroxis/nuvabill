<?php

namespace App\Chat;

use App\Enums\ClientStatus;
use App\Mail\TemplateMailer;
use App\Models\ChatLink;
use App\Models\Client;
use App\Support\Activity;
use App\Support\Locales;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Short one-time codes that link a chat to a client: the client area shows them in a QR code and a
 * link, and the chat app sends them back as "/start CODE" (Telegram) or "LINK CODE" (WhatsApp).
 *
 * A code belongs to the client and to the password they had when it was made: after a new
 * password, a code shown before no longer links anything.
 */
final class LinkCodes
{
    private const MINUTES = 30;

    public static function for(Client $client): string
    {
        $key = 'chat-link-client:'.$client->id;
        $code = Cache::get($key);

        if (! is_string($code) || ! self::isFor(Cache::get('chat-link:'.$code), $client)) {
            $code = strtoupper(Str::random(8));
            Cache::put('chat-link:'.$code, self::entry($client), now()->addMinutes(self::MINUTES));
            Cache::put($key, $code, now()->addMinutes(self::MINUTES - 5));
        }

        return $code;
    }

    /**
     * Link the chat to the client whose code this is. Returns null for an unknown or old code, for
     * a closed account, and for a code made before the client's password changed. The client gets
     * an email about every newly linked chat.
     */
    public static function redeem(string $code, string $channel, string $externalId, ?string $name): ?ChatLink
    {
        $code = strtoupper(trim($code));
        $entry = Cache::pull('chat-link:'.$code);
        $clientId = self::clientId($entry);

        if ($clientId === null || ($client = Client::query()->find($clientId)) === null || $client->status === ClientStatus::Closed || ! self::isFor($entry, $client)) {
            return null;
        }

        Cache::forget('chat-link-client:'.$client->id);

        $link = ChatLink::query()->updateOrCreate(
            ['channel' => $channel, 'external_id' => $externalId],
            ['client_id' => $client->id, 'name' => $name !== null ? mb_substr($name, 0, 120) : null, 'last_inbound_at' => now()],
        );

        // A new password saved while this chat was being linked ends this link like every other one.
        if (! self::isFor($entry, $client->refresh())) {
            $link->delete();

            return null;
        }

        // The same chat sending a new code of the same account gives nobody new access.
        if ($link->wasRecentlyCreated || $link->wasChanged('client_id')) {
            self::tellOwner($client, $link);
        }

        return $link;
    }

    /**
     * A linked chat gets invoices and ticket replies and writes to support as the client, so the
     * account owner hears about every new one by email, and staff see it in the activity log.
     */
    private static function tellOwner(Client $client, ChatLink $link): void
    {
        $app = $link->channelLabel();
        // What the owner knows the chat by: its name, and on WhatsApp its phone number.
        $chat = trim((string) $link->name.($link->channel === ChatLink::WHATSAPP ? ' +'.$link->external_id : ''));

        Activity::log('client.chat_linked', "{$client->name} connected a {$app} chat".($chat !== '' ? " ({$chat})" : ''), $client, $client, $client);

        // Names come from the client and from the chat app, so they go in as placeholders: the
        // mailer writes those as plain text, never as links or formatting.
        $locale = Locales::forClient($client);
        $lines = array_filter([
            __('Hi :name,', ['name' => '{{ client_name }}'], $locale),
            __('A :app chat was connected to your :company account. It gets your invoices, reminders and ticket replies, and can write to our support team in your name.', ['app' => $app, 'company' => '{{ company.name }}'], $locale),
            $chat !== '' ? __('Name of the chat: :name', ['name' => '{{ chat_name }}'], $locale) : null,
            __('If this was not you, sign in, disconnect the chat on your Account page and change your password. Someone else may be using your account.', [], $locale),
            '{{ account_url }}',
        ]);

        rescue(fn () => app(TemplateMailer::class)->sendText(
            $client->email,
            $client->name,
            __('A chat app was connected to your account', [], $locale),
            implode("\n\n", $lines),
            ['client_name' => $client->first_name, 'chat_name' => $chat, 'account_url' => route('client.account.edit')],
        ));
    }

    /**
     * What the cache keeps for a code: the client and a mark of their current password.
     */
    private static function entry(Client $client): string
    {
        return $client->id.'|'.substr(hash('sha256', 'chat-link|'.$client->getAuthPassword()), 0, 16);
    }

    /**
     * Whether a kept code is this client's and was made with the password they have now.
     */
    private static function isFor(mixed $entry, Client $client): bool
    {
        return is_string($entry) && hash_equals(self::entry($client), $entry);
    }

    private static function clientId(mixed $entry): ?int
    {
        $id = is_string($entry) ? filter_var(Str::before($entry, '|'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;

        return $id === false ? null : $id;
    }
}
