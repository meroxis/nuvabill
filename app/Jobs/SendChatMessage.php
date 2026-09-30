<?php

namespace App\Jobs;

use App\Chat\ChatError;
use App\Chat\ChatMessages;
use App\Chat\Telegram;
use App\Chat\WhatsApp;
use App\Models\ChatLink;
use App\Support\Activity;
use App\Support\Locales;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sends one chat message in the background: a client notification to one linked chat, or a staff
 * alert to the team's Telegram group. A failure is logged, never retried, and never stops an email.
 */
class SendChatMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(public ?int $linkId, public string $event, public array $context) {}

    public function handle(Telegram $telegram, WhatsApp $whatsApp): void
    {
        try {
            if ($this->event === 'staff') {
                $telegram->send((string) $this->context['chat'], (string) $this->context['subject'], $this->context['url'] ? [['label' => __('Open'), 'url' => (string) $this->context['url']]] : []);

                return;
            }

            $link = ChatLink::query()->with('client')->find($this->linkId);

            if ($link === null) {
                return;
            }

            $this->sendToClient($link, $telegram, $whatsApp);
        } catch (ChatError $error) {
            Activity::log('chat.failed', mb_substr(ucfirst($this->event).' chat message not sent: '.$error->getMessage(), 0, 250));
        }
    }

    private function sendToClient(ChatLink $link, Telegram $telegram, WhatsApp $whatsApp): void
    {
        $locale = Locales::isSupported($link->client->language) ? (string) $link->client->language : Locales::default();
        $message = ChatMessages::build($this->event, $this->context, $locale);

        if ($message === null) {
            return;
        }

        if ($link->channel === ChatLink::TELEGRAM) {
            $telegram->send($link->external_id, $message['text'], array_filter([$message['button']]));

            return;
        }

        // WhatsApp: free text inside the 24-hour window (or always, through an add-on that allows it),
        // otherwise a template Meta approved.
        if ($link->canReceiveFreeText() || $whatsApp->sendsFreeTextAnytime()) {
            $whatsApp->sendText($link->external_id, $message['text'].($message['button'] ? "\n\n".$message['button']['url'] : ''));

            return;
        }

        $language = $this->templateLanguage($message['template'], $locale);

        if ($language === null) {
            throw new ChatError('Meta has not approved the '.$message['template'].' template yet.');
        }

        $whatsApp->sendTemplate(
            $link->external_id,
            $message['template'],
            ChatMessages::META_LANGUAGES[$language],
            ChatMessages::templateValues($this->event, $message['values'], $language),
            $message['button'] ? ChatMessages::urlSuffix($message['button']['url']) : null,
        );
    }

    /**
     * The client's language when Meta approved the template in it, else the store's, else English.
     */
    private function templateLanguage(string $template, string $locale): ?string
    {
        $approved = (array) (((array) setting('chat.whatsapp_templates'))[$template] ?? []);

        foreach ([$locale, Locales::default(), 'en'] as $candidate) {
            $code = ChatMessages::META_LANGUAGES[$candidate] ?? null;

            if ($code !== null && ($approved[$code] ?? null) === 'APPROVED') {
                return $candidate;
            }
        }

        return null;
    }
}
