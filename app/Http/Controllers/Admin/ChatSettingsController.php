<?php

namespace App\Http\Controllers\Admin;

use App\Chat\ChatError;
use App\Chat\ChatMessages;
use App\Chat\Telegram;
use App\Chat\WhatsApp;
use App\Chat\WhatsAppSetup;
use App\Http\Controllers\Controller;
use App\Models\ChatLink;
use App\Models\TicketDepartment;
use App\Support\Activity;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Settings → Chat apps: the Telegram bot, the WhatsApp number (by QR code or by hand), and which
 * client messages go to which app.
 */
class ChatSettingsController extends Controller
{
    public function edit(Request $request, Telegram $telegram, WhatsApp $whatsApp, Settings $settings): View
    {
        // Proves that the QR connect popup answering this page was opened by this page.
        $state = Str::random(32);
        $request->session()->put('chat.whatsapp_state', $state);

        // The secret part of this site's WhatsApp webhook address, shown for the manual setup.
        $key = (string) setting('chat.whatsapp_webhook_key');

        if ($key === '') {
            $key = Str::random(48);
            $settings->set('chat.whatsapp_webhook_key', $key);
        }

        return view('admin.settings.chat', [
            'telegram' => $telegram->isConnected(),
            'whatsApp' => $whatsApp->isConnected(),
            'groups' => (array) setting('chat.telegram_groups'),
            'templates' => (array) setting('chat.whatsapp_templates'),
            'templateLanguages' => array_map(fn (string $locale): string => ChatMessages::META_LANGUAGES[$locale], ChatMessages::templateLanguages()),
            'events' => ChatMessages::EVENTS,
            'matrix' => (array) setting('chat.events'),
            'departments' => TicketDepartment::query()->orderBy('sort_order')->pluck('name', 'id')->all(),
            'links' => ChatLink::query()->selectRaw('channel, count(*) as total')->groupBy('channel')->pluck('total', 'channel')->all(),
            'connectUrl' => config('nuvabill.whatsapp_connect.url').'?'.http_build_query(['origin' => url('/'), 'state' => $state]),
            'connectOrigin' => (string) parse_url((string) config('nuvabill.whatsapp_connect.url'), PHP_URL_SCHEME).'://'.parse_url((string) config('nuvabill.whatsapp_connect.url'), PHP_URL_HOST),
            'state' => $state,
            'webhookUrl' => route('webhooks.whatsapp', $key),
            'verifyToken' => $key,
        ]);
    }

    public function telegram(Request $request, Telegram $telegram, Settings $settings): RedirectResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:120', 'regex:/^\d{5,}:[A-Za-z0-9_-]{30,}$/']], [
            'token.regex' => __('This does not look like a bot token. It looks like 123456789:AAF… and comes from @BotFather.'),
        ]);

        try {
            $bot = $telegram->connect(trim($data['token']), route('webhooks.telegram'));
        } catch (ChatError $error) {
            return back()->withInput()->with('error', $error->getMessage());
        }

        $settings->setMany(['chat.telegram_token' => trim($data['token']), 'chat.telegram_bot' => $bot['username'], 'chat.telegram_secret' => $bot['secret']]);
        Activity::log('chat.telegram', "Telegram bot connected: @{$bot['username']}");

        return back()->with('status', __('Telegram is connected as @:bot.', ['bot' => $bot['username']]));
    }

    public function telegramStaff(Request $request, Telegram $telegram, Settings $settings): RedirectResponse
    {
        $data = $request->validate(['staff_chat' => ['nullable', Rule::in(array_map('strval', array_keys((array) setting('chat.telegram_groups'))))]]);
        $settings->set('chat.telegram_staff_chat', (string) ($data['staff_chat'] ?? ''));

        if (filled($data['staff_chat'] ?? null)) {
            try {
                $telegram->send((string) $data['staff_chat'], __('Nuvabill will post new tickets, client replies and orders in this group.'));
            } catch (ChatError $error) {
                return back()->with('error', $error->getMessage());
            }
        }

        return back()->with('status', __('Settings saved.'));
    }

    public function telegramDisconnect(Telegram $telegram, Settings $settings): RedirectResponse
    {
        $telegram->disconnect();
        $settings->setMany(['chat.telegram_token' => '', 'chat.telegram_bot' => '', 'chat.telegram_secret' => '', 'chat.telegram_staff_chat' => '']);
        Activity::log('chat.telegram', 'Telegram bot disconnected');

        return back()->with('status', __('Telegram is disconnected.'));
    }

    /**
     * The QR popup on the Nuvabill store finished: it handed this page the owner's token.
     */
    public function whatsappConnect(Request $request, WhatsAppSetup $setup): JsonResponse
    {
        $data = $request->validate([
            'state' => ['required', 'string'],
            'token' => ['required', 'string', 'max:1000'],
            'waba_id' => ['required', 'string', 'regex:/^\d{5,25}$/'],
            'phone_number_id' => ['nullable', 'string', 'regex:/^\d{5,25}$/'],
            'business_app' => ['boolean'],
        ]);

        if (! hash_equals((string) $request->session()->pull('chat.whatsapp_state'), $data['state'])) {
            return response()->json(['message' => __('This connection was started in another window. Open Settings → Chat apps and try again.')], 422);
        }

        try {
            $phone = $setup->connect($data['token'], $data['waba_id'], $data['phone_number_id'] ?? null, 'qr', (bool) ($data['business_app'] ?? false));
        } catch (ChatError $error) {
            return response()->json(['message' => $error->getMessage()], 422);
        }

        $message = __('WhatsApp is connected: :number.', ['number' => $phone['number']]);

        if ($phone['pin'] !== null) {
            $message .= ' '.__('Its two-step PIN is :pin. Keep it somewhere safe.', ['pin' => $phone['pin']]);
        }

        $request->session()->flash('status', $message);

        return response()->json(['ok' => true]);
    }

    public function whatsappManual(Request $request, WhatsAppSetup $setup): RedirectResponse
    {
        $data = $request->validate([
            'phone_number_id' => ['required', 'string', 'regex:/^\d{5,25}$/'],
            'waba_id' => ['required', 'string', 'regex:/^\d{5,25}$/'],
            'token' => ['required', 'string', 'max:1000'],
            'app_secret' => ['required', 'string', 'regex:/^[a-f0-9]{32}$/'],
        ], [
            'app_secret.regex' => __('The app secret is 32 letters and digits, from your Meta app → App settings → Basic.'),
        ]);

        try {
            $phone = $setup->connect(trim($data['token']), $data['waba_id'], $data['phone_number_id'], 'manual', false, $data['app_secret']);
        } catch (ChatError $error) {
            return back()->withInput($request->except('token', 'app_secret'))->with('error', $error->getMessage());
        }

        return back()->with('status', __('WhatsApp is connected: :number.', ['number' => $phone['number']]));
    }

    public function whatsappTemplates(WhatsAppSetup $setup): RedirectResponse
    {
        try {
            $setup->refreshTemplates();
        } catch (ChatError $error) {
            return back()->with('error', $error->getMessage());
        }

        return back()->with('status', __('Templates checked with Meta.'));
    }

    public function whatsappDisconnect(WhatsAppSetup $setup): RedirectResponse
    {
        $setup->disconnect();

        return back()->with('status', __('WhatsApp is disconnected.'));
    }

    public function messages(Request $request, Settings $settings): RedirectResponse
    {
        $data = $request->validate([
            'events' => ['array'],
            'events.*' => ['array'],
            'whatsapp_tickets' => ['boolean'],
            'department' => ['nullable', Rule::exists('ticket_departments', 'id')],
        ]);

        $matrix = [];

        foreach (array_keys(ChatMessages::EVENTS) as $event) {
            foreach ([ChatLink::TELEGRAM, ChatLink::WHATSAPP] as $channel) {
                $matrix[$event][$channel] = (bool) ($data['events'][$event][$channel] ?? false);
            }
        }

        $settings->setMany([
            'chat.events' => $matrix,
            'chat.whatsapp_tickets' => (bool) ($data['whatsapp_tickets'] ?? false),
            'chat.department' => isset($data['department']) ? (int) $data['department'] : null,
        ]);

        return back()->with('status', __('Settings saved.'));
    }
}
