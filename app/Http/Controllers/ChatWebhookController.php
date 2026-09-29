<?php

namespace App\Http\Controllers;

use App\Chat\ChatInbox;
use App\Chat\Telegram;
use App\Chat\WhatsApp;
use App\Models\ChatLink;
use App\Support\Activity;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Messages from Telegram and WhatsApp. Telegram proves it is Telegram with the secret sent when the
 * webhook was set; WhatsApp posts to an address with a secret key only Meta knows, and with the
 * owner's own Meta app the body is also signed with its app secret.
 */
class ChatWebhookController extends Controller
{
    public function __construct(private ChatInbox $inbox, private Settings $settings) {}

    public function telegram(Request $request, Telegram $telegram): JsonResponse
    {
        $secret = (string) setting('chat.telegram_secret');

        if ($secret === '' || ! hash_equals($secret, (string) $request->header('X-Telegram-Bot-Api-Secret-Token'))) {
            abort(403);
        }

        $update = $request->json()->all();

        // The bot was added to or removed from a group: remember groups for staff alerts.
        if (isset($update['my_chat_member']['chat']['id'])) {
            $this->rememberGroup($update['my_chat_member']);

            return response()->json(['ok' => true]);
        }

        $message = $update['message'] ?? null;

        if (! is_array($message) || ($message['chat']['type'] ?? '') !== 'private') {
            return response()->json(['ok' => true]);
        }

        $chatId = (string) $message['chat']['id'];
        $reply = rescue(fn () => $this->inbox->handle(ChatLink::TELEGRAM, $chatId, (string) ($message['text'] ?? $message['caption'] ?? ''), $message['from']['first_name'] ?? null));

        if (is_string($reply)) {
            rescue(fn () => $telegram->send($chatId, $reply));
        }

        return response()->json(['ok' => true]);
    }

    public function whatsapp(Request $request, WhatsApp $whatsApp, string $key): Response|JsonResponse
    {
        $expected = (string) setting('chat.whatsapp_webhook_key');

        if ($expected === '' || ! hash_equals($expected, $key)) {
            abort(404);
        }

        // Meta checks the address once when it is set: echo the challenge back.
        if ($request->isMethod('GET')) {
            abort_unless($request->query('hub_mode') === 'subscribe' && hash_equals($expected, (string) $request->query('hub_verify_token')), 403);

            return response((string) $request->query('hub_challenge'), 200, ['Content-Type' => 'text/plain']);
        }

        $appSecret = (string) setting('chat.whatsapp_app_secret');

        if ($appSecret !== '' && ! hash_equals('sha256='.hash_hmac('sha256', $request->getContent(), $appSecret), (string) $request->header('X-Hub-Signature-256'))) {
            abort(403);
        }

        foreach ((array) $request->json('entry', []) as $entry) {
            foreach ((array) ($entry['changes'] ?? []) as $change) {
                $value = (array) ($change['value'] ?? []);

                match ($change['field'] ?? '') {
                    'messages' => $this->whatsappMessages($value, $whatsApp),
                    'message_template_status_update' => $this->templateStatus($value),
                    'account_update' => $this->accountUpdate($value),
                    default => null,
                };
            }
        }

        return response()->json(['ok' => true]);
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function whatsappMessages(array $value, WhatsApp $whatsApp): void
    {
        $names = collect((array) ($value['contacts'] ?? []))->mapWithKeys(fn (array $contact): array => [(string) ($contact['wa_id'] ?? '') => $contact['profile']['name'] ?? null]);

        foreach ((array) ($value['messages'] ?? []) as $message) {
            $from = (string) ($message['from'] ?? '');
            $text = (string) ($message['text']['body'] ?? $message['button']['text'] ?? $message['interactive']['button_reply']['title'] ?? $message['image']['caption'] ?? '');

            if ($from === '') {
                continue;
            }

            $reply = rescue(fn () => $this->inbox->handle(ChatLink::WHATSAPP, $from, $text, $names[$from] ?? null));

            if (is_string($reply)) {
                rescue(fn () => $whatsApp->sendText($from, $reply));
            }
        }
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function templateStatus(array $value): void
    {
        $name = (string) ($value['message_template_name'] ?? '');
        $language = (string) ($value['message_template_language'] ?? '');

        if ($name === '' || $language === '') {
            return;
        }

        $templates = (array) setting('chat.whatsapp_templates');
        $templates[$name] = [...(array) ($templates[$name] ?? []), $language => (string) ($value['event'] ?? 'PENDING')];
        $this->settings->set('chat.whatsapp_templates', $templates);
    }

    /**
     * The owner disconnected the number from Nuvabill, for example in the WhatsApp Business app.
     *
     * @param  array<string, mixed>  $value
     */
    private function accountUpdate(array $value): void
    {
        if (! isset($value['disconnection_info']) && ! in_array($value['event'] ?? '', ['PARTNER_REMOVED', 'ACCOUNT_DELETED'], true)) {
            return;
        }

        $this->settings->setMany(['chat.whatsapp_token' => '', 'chat.whatsapp_phone_id' => '', 'chat.whatsapp_waba' => '', 'chat.whatsapp_via' => '']);
        Activity::log('chat.whatsapp', 'WhatsApp was disconnected from Nuvabill in WhatsApp or by Meta');
    }

    /**
     * @param  array<string, mixed>  $membership
     */
    private function rememberGroup(array $membership): void
    {
        $chat = $membership['chat'];

        if (! in_array($chat['type'] ?? '', ['group', 'supergroup'], true)) {
            return;
        }

        $groups = (array) setting('chat.telegram_groups');
        $status = (string) ($membership['new_chat_member']['status'] ?? '');

        if (in_array($status, ['member', 'administrator'], true)) {
            $groups[(string) $chat['id']] = mb_substr((string) ($chat['title'] ?? $chat['id']), 0, 80);
        } else {
            unset($groups[(string) $chat['id']]);
        }

        $this->settings->set('chat.telegram_groups', $groups);
    }
}
