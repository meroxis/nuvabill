<?php

namespace App\Chat;

use App\Support\Locales;
use Illuminate\Support\Arr;

/**
 * The short chat messages sent next to client emails, in the client's language. WhatsApp sends the
 * same text as a message template Meta approved, with :placeholders turned into {{1}}, {{2}}…
 */
final class ChatMessages
{
    /**
     * Email template key => what the chat message says and where its button goes.
     *
     * @var array<string, array{label: string, template: string, text: string, chat?: string, button: string, url: string}>
     */
    public const EVENTS = [
        'invoice.created' => [
            'label' => 'New invoice',
            'template' => 'nuvabill_invoice_created',
            'text' => 'Hello :name, invoice :number for :total is ready. It is due on :date.',
            'button' => 'Pay now',
            'url' => 'invoice.url',
        ],
        'invoice.reminder' => [
            'label' => 'Invoice overdue',
            'template' => 'nuvabill_invoice_overdue',
            'text' => 'Hello :name, invoice :number for :total was due on :date and is still unpaid.',
            'button' => 'Pay now',
            'url' => 'invoice.url',
        ],
        'invoice.payment_received' => [
            'label' => 'Payment received',
            'template' => 'nuvabill_payment_received',
            'text' => 'Hello :name, thank you! We received your payment for invoice :number.',
            'button' => 'See invoice',
            'url' => 'invoice.url',
        ],
        'service.welcome' => [
            'label' => 'Service is ready',
            'template' => 'nuvabill_service_ready',
            'text' => 'Hello :name, your :service is ready to use.',
            'button' => 'Open',
            'url' => 'service.url',
        ],
        'service.suspended' => [
            'label' => 'Service suspended',
            'template' => 'nuvabill_service_suspended',
            'text' => 'Hello :name, your :service is suspended. Please check your invoices to turn it back on.',
            'button' => 'See invoices',
            'url' => 'invoices_url',
        ],
        'ticket.reply' => [
            'label' => 'Reply to a ticket',
            'template' => 'nuvabill_ticket_reply',
            'text' => 'Hello :name, there is a new reply on your ticket #:number: :message You can answer here.',
            'chat' => "New reply on ticket #:number “:subject”:\n\n:message\n\nAnswer in this chat to reply.",
            'button' => 'See ticket',
            'url' => 'ticket.url',
        ],
        'domain.expiring' => [
            'label' => 'Domain expires soon',
            'template' => 'nuvabill_domain_expiring',
            'text' => 'Hello :name, your domain :domain expires on :date. Renew it to keep your website and email working.',
            'button' => 'Renew',
            'url' => 'domain.url',
        ],
    ];

    /**
     * Nuvabill language => Meta's template language code. Meta has no Kurdish (Sorani) templates.
     */
    public const META_LANGUAGES = [
        'en' => 'en', 'ar' => 'ar', 'az' => 'az', 'ca' => 'ca', 'cs' => 'cs', 'da' => 'da', 'de' => 'de', 'es' => 'es',
        'et' => 'et', 'fr' => 'fr', 'he' => 'he', 'hr' => 'hr', 'hu' => 'hu', 'it' => 'it', 'mk' => 'mk', 'nb' => 'nb',
        'nl' => 'nl', 'pt_BR' => 'pt_BR', 'pt_PT' => 'pt_PT', 'ro' => 'ro', 'ru' => 'ru', 'sv' => 'sv', 'tr' => 'tr',
        'uk' => 'uk', 'zh_CN' => 'zh_CN',
    ];

    private const EXAMPLES = [
        'name' => 'Raz',
        'number' => 'INV-1042',
        'total' => '$12.99',
        'date' => '12 Oct 2026',
        'service' => 'Business Hosting',
        'message' => 'Your website is back online.',
        'domain' => 'example.com',
        'subject' => 'Email not working',
    ];

    /**
     * The message for one event, or null when the event has no chat message.
     *
     * @param  array<string, mixed>  $context  the email context
     * @return array{text: string, button: array{label: string, url: string}|null, template: string, values: array<string, string>}|null
     */
    public static function build(string $event, array $context, string $locale): ?array
    {
        $definition = self::EVENTS[$event] ?? null;

        if ($definition === null) {
            return null;
        }

        $values = self::values($context);
        $url = (string) Arr::get($context, $definition['url'], '');

        return [
            'text' => __($definition['chat'] ?? $definition['text'], $values, $locale),
            'button' => $url !== '' ? ['label' => __($definition['button'], [], $locale), 'url' => $url] : null,
            'template' => $definition['template'],
            'values' => $values,
        ];
    }

    /**
     * The template Nuvabill asks Meta to approve for one event and language.
     *
     * @return array<string, mixed>
     */
    public static function template(string $event, string $locale): array
    {
        $definition = self::EVENTS[$event];
        [$body, $order] = self::numbered(__($definition['text'], [], $locale));

        return [
            'name' => $definition['template'],
            'language' => self::META_LANGUAGES[$locale],
            'category' => 'UTILITY',
            'components' => [
                ['type' => 'BODY', 'text' => $body, 'example' => ['body_text' => [array_map(fn (string $name): string => self::EXAMPLES[$name] ?? 'Raz', $order)]]],
                ['type' => 'BUTTONS', 'buttons' => [[
                    'type' => 'URL',
                    'text' => mb_substr(__($definition['button'], [], $locale), 0, 25),
                    'url' => self::siteRoot().'{{1}}',
                    'example' => ['client/invoices/1042'],
                ]]],
            ],
        ];
    }

    /**
     * The body values in the order this language's template uses them.
     *
     * @param  array<string, string>  $values
     * @return list<string>
     */
    public static function templateValues(string $event, array $values, string $locale): array
    {
        [, $order] = self::numbered(__(self::EVENTS[$event]['text'], [], $locale));

        // Meta refuses values with line breaks, tabs or long runs of spaces.
        return array_map(fn (string $name): string => mb_substr(trim((string) preg_replace('/\s+/u', ' ', $values[$name] ?? '')), 0, 300) ?: '-', $order);
    }

    /**
     * The part of a link after the site's address, for the template's link button.
     */
    public static function urlSuffix(string $url): ?string
    {
        $root = self::siteRoot();

        return str_starts_with($url, $root) ? substr($url, strlen($root)) : null;
    }

    public static function siteRoot(): string
    {
        return rtrim(url('/'), '/').'/';
    }

    /**
     * The languages templates are made in: English and the store's main language.
     *
     * @return list<string>
     */
    public static function templateLanguages(): array
    {
        return array_values(array_unique(array_filter(['en', Locales::default()], fn (string $locale): bool => isset(self::META_LANGUAGES[$locale]))));
    }

    /**
     * ":name, invoice :number" becomes "{{1}}, invoice {{2}}", with the names in that order.
     *
     * @return array{0: string, 1: list<string>}
     */
    private static function numbered(string $text): array
    {
        $order = [];
        $body = (string) preg_replace_callback('/:([a-z_]+)/', function (array $match) use (&$order): string {
            if (! array_key_exists($match[1], self::EXAMPLES)) {
                return $match[0];
            }

            $order[] = $match[1];

            return '{{'.count($order).'}}';
        }, $text);

        return [$body, $order];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, string>
     */
    private static function values(array $context): array
    {
        $service = trim((string) Arr::get($context, 'service.product', ''));
        $domain = trim((string) Arr::get($context, 'service.domain', ''));

        return [
            'name' => (string) Arr::get($context, 'client.first_name', ''),
            'number' => (string) (Arr::get($context, 'invoice.number') ?? Arr::get($context, 'ticket.number', '')),
            'total' => (string) Arr::get($context, 'invoice.total', ''),
            'date' => (string) (Arr::get($context, 'invoice.due_date') ?? Arr::get($context, 'domain.expires_at', '')),
            'service' => $domain !== '' ? $service.' ('.$domain.')' : $service,
            'message' => mb_substr(trim((string) Arr::get($context, 'reply.message', '')), 0, 1500),
            'domain' => (string) Arr::get($context, 'domain.name', ''),
            'subject' => (string) Arr::get($context, 'ticket.subject', ''),
        ];
    }
}
