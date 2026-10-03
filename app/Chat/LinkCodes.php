<?php

namespace App\Chat;

use App\Enums\ClientStatus;
use App\Models\ChatLink;
use App\Models\Client;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Short one-time codes that link a chat to a client: the client area shows them in a QR code and a
 * link, and the chat app sends them back as "/start CODE" (Telegram) or "LINK CODE" (WhatsApp).
 */
final class LinkCodes
{
    private const MINUTES = 30;

    public static function for(Client $client): string
    {
        $key = 'chat-link-client:'.$client->id;
        $code = Cache::get($key);

        if (! is_string($code) || self::clientId(Cache::get('chat-link:'.$code)) !== $client->id) {
            $code = strtoupper(Str::random(8));
            Cache::put('chat-link:'.$code, $client->id, now()->addMinutes(self::MINUTES));
            Cache::put($key, $code, now()->addMinutes(self::MINUTES - 5));
        }

        return $code;
    }

    /**
     * Link the chat to the client whose code this is. Returns null for an unknown or old code, and
     * for a closed account.
     */
    public static function redeem(string $code, string $channel, string $externalId, ?string $name): ?ChatLink
    {
        $code = strtoupper(trim($code));
        $clientId = self::clientId(Cache::pull('chat-link:'.$code));

        if ($clientId === null || ($client = Client::query()->find($clientId)) === null || $client->status === ClientStatus::Closed) {
            return null;
        }

        Cache::forget('chat-link-client:'.$client->id);

        return ChatLink::query()->updateOrCreate(
            ['channel' => $channel, 'external_id' => $externalId],
            ['client_id' => $client->id, 'name' => $name !== null ? mb_substr($name, 0, 120) : null, 'last_inbound_at' => now()],
        );
    }

    /**
     * The client id kept for a code. Redis gives numbers back as strings ("42"), other cache
     * stores as integers, so both are read the same way.
     */
    private static function clientId(mixed $value): ?int
    {
        $id = is_int($value) || is_string($value) ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;

        return $id === false ? null : $id;
    }
}
