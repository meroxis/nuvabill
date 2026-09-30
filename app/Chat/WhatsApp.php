<?php

namespace App\Chat;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * WhatsApp through Meta's official Cloud API: the owner's number, connected by QR code (Meta's
 * signup page on the Nuvabill store) or by hand with the owner's own Meta app. Messages the business
 * starts use templates Meta approved; free text is allowed within 24 hours of the client's message.
 */
class WhatsApp
{
    public function isConnected(): bool
    {
        return $this->token() !== '' && (string) setting('chat.whatsapp_phone_id') !== '';
    }

    /**
     * The number the client area shows, digits only, for wa.me links.
     */
    public function number(): string
    {
        return (string) preg_replace('/\D/', '', (string) setting('chat.whatsapp_number'));
    }

    /**
     * Whether any message may be free text, without Meta's templates and 24-hour window. Add-ons that
     * send through another WhatsApp connection, such as a number linked by QR code, say yes.
     * Since Nuvabill 0.6.10.
     */
    public function sendsFreeTextAnytime(): bool
    {
        return false;
    }

    /**
     * The add-on that connects WhatsApp instead of Meta's platform: its name, the address of its page
     * and the connected number. The chat settings page then shows it instead of Meta's setup.
     * Since Nuvabill 0.6.10.
     *
     * @return array{name: string, url: string, number: string}|null
     */
    public function connectedThrough(): ?array
    {
        return null;
    }

    public function link(string $code): string
    {
        return 'https://wa.me/'.$this->number().'?text='.rawurlencode('LINK '.$code);
    }

    /**
     * The number that belongs to a WhatsApp account, with its display number and name.
     *
     * @return array{id: string, number: string, name: string}
     */
    public function phoneNumber(string $token, string $wabaId, ?string $phoneId = null): array
    {
        $numbers = $this->call('GET', $wabaId.'/phone_numbers', ['fields' => 'id,display_phone_number,verified_name'], $token)['data'] ?? [];
        $number = collect($numbers)->first(fn (array $row): bool => $phoneId === null || (string) $row['id'] === $phoneId);

        if (! is_array($number)) {
            throw new ChatError(__('This WhatsApp account has no phone number yet. Add one in WhatsApp Manager, then connect again.'));
        }

        return ['id' => (string) $number['id'], 'number' => (string) ($number['display_phone_number'] ?? ''), 'name' => (string) ($number['verified_name'] ?? '')];
    }

    /**
     * Send this account's webhooks straight to this site, instead of to the Meta app's own address.
     */
    public function routeWebhooks(string $token, string $wabaId, string $url, string $verifyToken): void
    {
        $this->call('POST', $wabaId.'/subscribed_apps', ['override_callback_uri' => $url, 'verify_token' => $verifyToken], $token);
    }

    /**
     * A number that also stays in the WhatsApp Business app must have its contacts and chat history
     * copied to the Cloud API within 24 hours of connecting, or Meta disconnects it again.
     */
    public function syncBusinessApp(string $token, string $phoneId): void
    {
        foreach (['smb_app_state_sync', 'history'] as $type) {
            $this->call('POST', $phoneId.'/smb_app_data', ['messaging_product' => 'whatsapp', 'sync_type' => $type], $token);
        }
    }

    /**
     * A brand-new number (not one moved from the WhatsApp Business app) is registered with a PIN.
     */
    public function register(string $token, string $phoneId, string $pin): void
    {
        $this->call('POST', $phoneId.'/register', ['messaging_product' => 'whatsapp', 'pin' => $pin], $token);
    }

    public function disconnect(): void
    {
        $waba = (string) setting('chat.whatsapp_waba');

        if ($this->token() !== '' && $waba !== '') {
            rescue(fn () => $this->call('DELETE', $waba.'/subscribed_apps'), report: false);
        }
    }

    public function sendText(string $to, string $text): void
    {
        $this->call('POST', setting('chat.whatsapp_phone_id').'/messages', [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'text',
            'text' => ['body' => mb_substr($text, 0, 4000), 'preview_url' => false],
        ]);
    }

    /**
     * @param  list<string>  $bodyParameters
     */
    public function sendTemplate(string $to, string $name, string $language, array $bodyParameters, ?string $urlSuffix): void
    {
        $components = [['type' => 'body', 'parameters' => array_map(fn (string $text): array => ['type' => 'text', 'text' => $text], $bodyParameters)]];

        if ($urlSuffix !== null) {
            $components[] = ['type' => 'button', 'sub_type' => 'url', 'index' => '0', 'parameters' => [['type' => 'text', 'text' => $urlSuffix]]];
        }

        $this->call('POST', setting('chat.whatsapp_phone_id').'/messages', [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'template',
            'template' => ['name' => $name, 'language' => ['code' => $language], 'components' => $components],
        ]);
    }

    /**
     * @param  array<string, mixed>  $template
     */
    public function createTemplate(array $template): void
    {
        try {
            $this->call('POST', setting('chat.whatsapp_waba').'/message_templates', $template);
        } catch (ChatError $error) {
            // Asking again for a template that exists is fine.
            if (! str_contains(mb_strtolower($error->getMessage()), 'already exists') && ! str_contains($error->getMessage(), '2388023')) {
                throw $error;
            }
        }
    }

    /**
     * @return list<array{name: string, language: string, status: string}>
     */
    public function templates(): array
    {
        $rows = $this->call('GET', setting('chat.whatsapp_waba').'/message_templates', ['fields' => 'name,language,status', 'limit' => 200])['data'] ?? [];

        return array_values(array_map(fn (array $row): array => [
            'name' => (string) ($row['name'] ?? ''),
            'language' => (string) ($row['language'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
        ], array_filter($rows, 'is_array')));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function call(string $method, string $path, array $data = [], ?string $token = null): array
    {
        $request = $this->client($token ?? $this->token());

        try {
            $response = match ($method) {
                'GET' => $request->get($path, $data),
                'DELETE' => $request->delete($path, $data),
                default => $request->post($path, $data),
            };
        } catch (ConnectionException) {
            throw new ChatError(__('Nuvabill could not reach WhatsApp. Please try again.'));
        }

        return $this->result($response);
    }

    /**
     * @return array<string, mixed>
     */
    private function result(Response $response): array
    {
        $data = $response->json() ?? [];

        if ($response->failed() || isset($data['error'])) {
            $error = is_array($data['error'] ?? null) ? $data['error'] : [];
            $message = trim(($error['error_user_msg'] ?? '') ?: ($error['message'] ?? 'HTTP '.$response->status()));
            $code = isset($error['error_subcode']) ? ' ('.$error['error_subcode'].')' : '';

            throw new ChatError($response->status() === 401 || ($error['code'] ?? null) === 190
                ? __('Meta did not accept the WhatsApp access token. Connect WhatsApp again.')
                : __('WhatsApp said: :error', ['error' => mb_substr($message.$code, 0, 240)]));
        }

        return is_array($data) ? $data : [];
    }

    private function client(string $token): PendingRequest
    {
        return Http::baseUrl('https://graph.facebook.com/'.config('nuvabill.whatsapp_connect.graph_version').'/')
            ->withToken($token)
            ->acceptJson()
            ->asJson()
            ->timeout(20);
    }

    private function token(): string
    {
        return trim((string) setting('chat.whatsapp_token'));
    }
}
