<?php

namespace App\Chat;

use App\Http\Controllers\WhatsAppConnectController;
use App\Support\Activity;
use App\Support\Demo;
use App\Support\Settings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Connects the owner's WhatsApp number: saves the access token and number, routes the account's
 * webhooks to this site, copies a WhatsApp Business app number's contacts and history (Meta's rule),
 * and asks Meta to approve Nuvabill's message templates.
 */
class WhatsAppSetup
{
    public function __construct(private WhatsApp $whatsApp, private Settings $settings) {}

    /**
     * @param  string  $via  "qr" for Meta's signup page, "manual" for the owner's own Meta app
     * @param  bool  $businessApp  the number stays in the WhatsApp Business app (QR coexistence)
     * @return array{number: string, name: string, pin: string|null}
     */
    public function connect(string $token, string $wabaId, ?string $phoneId, string $via, bool $businessApp = false, string $appSecret = ''): array
    {
        $phone = $this->whatsApp->phoneNumber($token, $wabaId, $phoneId);
        $key = (string) setting('chat.whatsapp_webhook_key') ?: Str::random(48);

        // Saved first: Meta checks the webhook address right away, and the check needs the key.
        $this->settings->setMany([
            'chat.whatsapp_token' => $token,
            'chat.whatsapp_waba' => $wabaId,
            'chat.whatsapp_phone_id' => $phone['id'],
            'chat.whatsapp_number' => $phone['number'],
            'chat.whatsapp_name' => $phone['name'],
            'chat.whatsapp_via' => $via,
            'chat.whatsapp_webhook_key' => $key,
            'chat.whatsapp_app_secret' => $via === 'manual' ? $appSecret : '',
            'chat.whatsapp_templates' => [],
        ]);

        try {
            $this->whatsApp->routeWebhooks($token, $wabaId, route('webhooks.whatsapp', $key), $key);

            if ($businessApp) {
                $this->whatsApp->syncBusinessApp($token, $phone['id']);
            }
        } catch (ChatError $error) {
            $this->forget();

            throw $error;
        }

        // A brand-new number from Meta's signup page still has to be registered, with a PIN.
        $pin = null;

        if ($via === 'qr' && ! $businessApp) {
            $pin = (string) random_int(100000, 999999);
            rescue(fn () => $this->whatsApp->register($token, $phone['id'], $pin), report: false);
        }

        rescue(fn () => $this->refreshTemplates(), report: false);
        Activity::log('chat.whatsapp', "WhatsApp connected: {$phone['number']}");

        return $phone + ['pin' => $pin];
    }

    /**
     * Ask Meta to approve every missing template, then read back what Meta decided.
     */
    public function refreshTemplates(): void
    {
        if (! str_starts_with(ChatMessages::siteRoot(), 'https://')) {
            throw new ChatError(__('WhatsApp templates need your site address to start with https://. Check APP_URL in the .env file.'));
        }

        $known = $this->statuses();

        foreach (array_keys(ChatMessages::EVENTS) as $event) {
            foreach (ChatMessages::templateLanguages() as $locale) {
                $template = ChatMessages::template($event, $locale);

                if (! isset($known[$template['name']][$template['language']])) {
                    $this->whatsApp->createTemplate($template);
                }
            }
        }

        $this->settings->set('chat.whatsapp_templates', $this->statuses());
    }

    public function disconnect(): void
    {
        $this->whatsApp->disconnect();
        $this->forget();
        Activity::log('chat.whatsapp', 'WhatsApp disconnected');
    }

    /**
     * Whether the Nuvabill store's QR connect page runs Meta's signup yet. It only does once Meta
     * has approved the store's Meta app, so until then the owner is told so before opening it.
     */
    public function canConnectByQr(): bool
    {
        $page = (string) config('nuvabill.whatsapp_connect.url');

        // The demo shows the button; connecting is locked there, and it never calls other servers.
        if (Demo::isEnabled()) {
            return true;
        }

        if (parse_url($page, PHP_URL_HOST) === parse_url(url('/'), PHP_URL_HOST)) {
            return WhatsAppConnectController::isReady();
        }

        return Cache::remember('chat.whatsapp_qr_ready', now()->addMinutes(10), function () use ($page): bool {
            try {
                return Http::timeout(5)->acceptJson()->get($page.'/status')->json('ready') === true;
            } catch (ConnectionException) {
                return false;
            }
        });
    }

    /**
     * Template name => language => status (APPROVED, PENDING, REJECTED), for Nuvabill's templates.
     *
     * @return array<string, array<string, string>>
     */
    private function statuses(): array
    {
        $names = array_column(ChatMessages::EVENTS, 'template');
        $statuses = [];

        foreach ($this->whatsApp->templates() as $template) {
            if (in_array($template['name'], $names, true)) {
                $statuses[$template['name']][$template['language']] = $template['status'];
            }
        }

        return $statuses;
    }

    private function forget(): void
    {
        $this->settings->setMany([
            'chat.whatsapp_token' => '',
            'chat.whatsapp_waba' => '',
            'chat.whatsapp_phone_id' => '',
            'chat.whatsapp_number' => '',
            'chat.whatsapp_name' => '',
            'chat.whatsapp_via' => '',
            'chat.whatsapp_app_secret' => '',
            'chat.whatsapp_templates' => [],
        ]);
    }
}
