<?php

namespace App\Chat;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * The owner's Telegram bot: set up with a token from @BotFather, answers through a webhook, and
 * sends messages with link buttons.
 */
class Telegram
{
    public function isConnected(): bool
    {
        return $this->token() !== '' && (string) setting('chat.telegram_bot') !== '';
    }

    /**
     * Check a new token and point the bot's webhook at this site.
     *
     * @return array{username: string, secret: string}
     */
    public function connect(string $token, string $webhookUrl): array
    {
        $me = $this->call('getMe', [], $token);
        $secret = Str::random(40);

        $this->call('setWebhook', [
            'url' => $webhookUrl,
            'secret_token' => $secret,
            'allowed_updates' => ['message', 'my_chat_member'],
            'drop_pending_updates' => true,
        ], $token);

        return ['username' => (string) ($me['username'] ?? ''), 'secret' => $secret];
    }

    public function disconnect(): void
    {
        if ($this->token() !== '') {
            rescue(fn () => $this->call('deleteWebhook', ['drop_pending_updates' => true]), report: false);
        }
    }

    /**
     * @param  list<array{label: string, url: string}>  $buttons
     */
    public function send(string $chatId, string $text, array $buttons = []): void
    {
        $payload = [
            'chat_id' => $chatId,
            'text' => mb_substr($text, 0, 4000),
            'link_preview_options' => ['is_disabled' => true],
        ];

        $buttons = array_values(array_filter($buttons, fn (array $button): bool => str_starts_with($button['url'], 'https://')));

        if ($buttons !== []) {
            $payload['reply_markup'] = ['inline_keyboard' => array_map(fn (array $row): array => array_map(
                fn (array $button): array => ['text' => $button['label'], 'url' => $button['url']],
                $row,
            ), array_chunk($buttons, 2))];
        }

        $this->call('sendMessage', $payload);
    }

    public function link(string $code): string
    {
        return 'https://t.me/'.setting('chat.telegram_bot').'?start='.$code;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function call(string $method, array $payload = [], ?string $token = null): array
    {
        $token ??= $this->token();

        try {
            $response = Http::timeout(15)->acceptJson()->post('https://api.telegram.org/bot'.$token.'/'.$method, $payload);
        } catch (ConnectionException) {
            throw new ChatError(__('Nuvabill could not reach Telegram. Please try again.'));
        }

        return $this->result($response);
    }

    /**
     * @return array<string, mixed>
     */
    private function result(Response $response): array
    {
        $data = $response->json() ?? [];

        if (! ($data['ok'] ?? false)) {
            $description = (string) ($data['description'] ?? 'HTTP '.$response->status());

            throw new ChatError($response->status() === 401 || $response->status() === 404
                ? __('Telegram did not accept the bot token. Copy it again from @BotFather.')
                : __('Telegram said: :error', ['error' => mb_substr($description, 0, 200)]));
        }

        return is_array($data['result'] ?? null) ? $data['result'] : [];
    }

    private function token(): string
    {
        return trim((string) setting('chat.telegram_token'));
    }
}
