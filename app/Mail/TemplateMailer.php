<?php

namespace App\Mail;

use App\Chat\ChatNotifier;
use App\Models\Client;
use App\Models\EmailTemplate;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\Ticket;
use App\Support\Locales;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use League\CommonMark\Util\RegexHelper;
use Throwable;

/**
 * Sends the editable email templates. Placeholders like {{ client.first_name }} are replaced with
 * plain values; templates are never compiled as code, so staff cannot run PHP through them, and
 * the values are never read as Markdown, so clients cannot add links or pictures through them.
 */
class TemplateMailer
{
    /**
     * The language to write in travels in the context under this key, so sendTo() keeps the
     * signature add-ons that extend this class (such as Crystal Mail) were built against.
     */
    public const LOCALE_KEY = '_locale';

    private const PLACEHOLDER = '/{{\s*([a-zA-Z0-9_.]+)\s*}}/';

    /**
     * Raw HTML in a template shows as text, and links that could run code are dropped.
     */
    private const MARKDOWN_OPTIONS = ['html_input' => 'escape', 'allow_unsafe_links' => false];

    /**
     * Set to true in the context when the same staff email goes to several people: only one of them
     * should also be posted to the team's Telegram group.
     */
    public const SKIP_CHAT_KEY = '_skip_chat';

    /**
     * @param  array<string, mixed>  $context
     */
    public function send(string $key, Client $client, array $context = []): bool
    {
        // Erased clients have no address or chat link left to write to.
        if ($client->isErased()) {
            return false;
        }

        $context = ['client' => self::clientContext($client)] + $context;
        $sent = $this->sendTo($key, $client->email, $client->name, $context + [self::LOCALE_KEY => Locales::forClient($client)]);

        // The same news on Telegram or WhatsApp, for clients who linked them.
        rescue(fn () => app(ChatNotifier::class)->clientEmailed($key, $client, $context));

        return $sent;
    }

    /**
     * Send a notification to the company's own address, for example "new order".
     *
     * @param  array<string, mixed>  $context
     */
    public function sendToStaff(string $key, array $context = []): bool
    {
        $email = (string) setting('company.email');

        return $email !== '' && $this->sendTo($key, $email, (string) setting('company.name'), $context + [self::LOCALE_KEY => Locales::default()]);
    }

    /**
     * Send a template in a language: the translation where there is one, otherwise the main text.
     * The language comes from $context[LOCALE_KEY] (send() and sendToStaff() set it); without it,
     * the site's default language.
     *
     * Add-ons extend this method, so its signature must not change.
     *
     * @param  array<string, mixed>  $context
     */
    public function sendTo(string $key, string $email, string $name, array $context = []): bool
    {
        $template = EmailTemplate::query()->with('translations')->where('key', $key)->where('is_active', true)->first();

        if ($template === null) {
            return false;
        }

        $locale = $context[self::LOCALE_KEY] ?? null;
        $locale = is_string($locale) && Locales::isSupported($locale) ? $locale : Locales::default();
        $skipChat = (bool) ($context[self::SKIP_CHAT_KEY] ?? false);
        unset($context[self::LOCALE_KEY], $context[self::SKIP_CHAT_KEY]);
        $context += $this->baseContext();
        [$subjectText, $bodyText] = $template->textFor($locale);

        $subject = self::render($subjectText, $context);
        $html = self::renderHtml($bodyText, $context);

        // Staff emails, such as a new ticket or order, also go to the team's Telegram group.
        if (! $skipChat && str_starts_with($key, 'admin.')) {
            rescue(fn () => app(ChatNotifier::class)->staffEmailed($subject, isset($context['admin_url']) ? (string) $context['admin_url'] : null));
        }

        try {
            // The frame around the message is in the same language as the message.
            Mail::to($email, $name)->locale($locale)->send(new TemplatedMessage($subject, $html));

            return true;
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }

    /**
     * Send a message written elsewhere (for example in an automation) with the same placeholders
     * and look as the email templates.
     *
     * @param  array<string, mixed>  $context
     */
    public function sendText(string $email, string $name, string $subject, string $body, array $context = []): bool
    {
        $context += $this->baseContext();
        $html = self::renderHtml($body, $context);

        try {
            Mail::to($email, $name)->locale(Locales::default())->send(new TemplatedMessage(self::render($subject, $context), $html));

            return true;
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }

    /**
     * Replace {{ dotted.keys }} with values from the context. Unknown keys become empty.
     *
     * @param  array<string, mixed>  $context
     */
    public static function render(string $text, array $context): string
    {
        return (string) preg_replace_callback(self::PLACEHOLDER, fn (array $match): string => self::plainValue(data_get($context, $match[1])), $text);
    }

    /**
     * The email body as HTML. The template is Markdown, but the values put into it stay plain
     * text: a client's name or ticket message cannot add links, pictures or headings to an email
     * that comes from the company. Only values wrapped in {@see MarkdownValue} are read as Markdown.
     *
     * @param  array<string, mixed>  $context
     */
    public static function renderHtml(string $markdown, array $context): string
    {
        // Each placeholder first becomes a word of letters and digits that Markdown leaves alone.
        // An address in angle brackets, <{{ invoice.url }}>, is linked below like a bare one.
        $markdown = (string) preg_replace('/<\s*({{\s*[a-zA-Z0-9_.]+\s*}})\s*>/', '$1', $markdown);
        $seed = bin2hex(random_bytes(6));
        $values = [];
        $markdown = (string) preg_replace_callback(self::PLACEHOLDER, function (array $match) use ($context, $seed, &$values): string {
            $values[] = [$match[1], data_get($context, $match[1])];

            return 'NBPH'.$seed.(count($values) - 1).'Z';
        }, $markdown);

        $html = Str::markdown($markdown, self::MARKDOWN_OPTIONS);
        $token = 'NBPH'.$seed.'(\d+)Z';

        // A value alone in its paragraph: Markdown values become their own blocks (such as a
        // list), and empty values leave no empty paragraph behind.
        $html = (string) preg_replace_callback('#<p>'.$token.'</p>#', function (array $match) use ($values): string {
            $value = $values[(int) $match[1]][1];

            return match (true) {
                $value instanceof MarkdownValue => rtrim(Str::markdown($value->markdown, self::MARKDOWN_OPTIONS)),
                self::plainValue($value) === '' => '',
                default => $match[0],
            };
        }, $html);

        // Then the values go back in, escaped for where they stand: in a tag's attribute or in text.
        $inLinkOrCode = 0;

        return (string) preg_replace_callback('#<[^>]*>|'.$token.'#', function (array $match) use ($values, $token, &$inLinkOrCode): string {
            if ($match[0][0] === '<') {
                if (preg_match('#^<(a|code)[\s>]#i', $match[0])) {
                    $inLinkOrCode++;
                } elseif (preg_match('#^</(a|code)\s*>#i', $match[0]) && $inLinkOrCode > 0) {
                    $inLinkOrCode--;
                }

                return self::fillAttributes($match[0], $token, $values);
            }

            [$key, $value] = $values[(int) $match[1]];

            if ($value instanceof MarkdownValue) {
                return rtrim(Str::inlineMarkdown($value->markdown, self::MARKDOWN_OPTIONS));
            }

            $text = self::plainValue($value);

            // Links Nuvabill made, such as {{ invoice.url }} on its own, stay clickable. The address
            // is the text, so nothing hides where it goes.
            if ($inLinkOrCode === 0 && preg_match('/url$/i', $key) && preg_match('#^https?://[^\s<>"]+$#i', $text)) {
                return '<a href="'.e($text).'">'.e($text).'</a>';
            }

            return nl2br(e($text));
        }, $html);
    }

    /**
     * @return array<string, mixed>
     */
    public static function clientContext(Client $client): array
    {
        return [
            'id' => $client->id,
            'first_name' => $client->first_name,
            'last_name' => $client->last_name,
            'name' => $client->name,
            'company_name' => $client->company_name,
            'email' => $client->email,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function invoiceContext(Invoice $invoice): array
    {
        return Locales::in(Locales::forClient($invoice->client), fn (): array => [
            'client' => self::clientContext($invoice->client),
            'invoice' => [
                'id' => $invoice->id,
                'number' => $invoice->displayNumber(),
                'subtotal' => money($invoice->subtotal, $invoice->currency),
                'tax' => money($invoice->tax, $invoice->currency),
                'total' => money($invoice->total, $invoice->currency),
                'balance' => money($invoice->balance(), $invoice->currency),
                'due_date' => $invoice->due_at->translatedFormat('d M Y'),
                'url' => route('client.invoices.show', $invoice),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function serviceContext(Service $service): array
    {
        return Locales::in(Locales::forClient($service->client), fn (): array => [
            'client' => self::clientContext($service->client),
            'service' => [
                'id' => $service->id,
                'product' => $service->product->name,
                'domain' => $service->domain,
                'username' => $service->username,
                'server' => $service->server?->hostname,
                'next_due_date' => $service->next_due_date?->translatedFormat('d M Y'),
                'amount' => money($service->recurring_amount, $service->currency),
                'url' => route('client.services.show', $service),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function ticketContext(Ticket $ticket): array
    {
        return Locales::in(Locales::forClient($ticket->client), fn (): array => [
            'client' => self::clientContext($ticket->client),
            'ticket' => [
                'number' => $ticket->number,
                'subject' => $ticket->subject,
                'department' => $ticket->department->name,
                'status' => $ticket->status->label(),
                'url' => route('client.tickets.show', $ticket),
            ],
        ]);
    }

    /**
     * Placeholders in a tag's attributes, such as the address in [Pay now]({{ invoice.url }}).
     * Addresses that could run code (javascript: and the like) are left out, as Markdown does.
     *
     * @param  list<array{0: string, 1: mixed}>  $values
     */
    private static function fillAttributes(string $tag, string $token, array $values): string
    {
        return (string) preg_replace_callback('#([a-zA-Z_:][-a-zA-Z0-9_:.]*)="([^"]*)"#', function (array $match) use ($token, $values): string {
            if (! preg_match('#'.$token.'#', $match[2])) {
                return $match[0];
            }

            $value = (string) preg_replace_callback(
                '#'.$token.'#',
                fn (array $placeholder): string => self::plainValue($values[(int) $placeholder[1]][1]),
                html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            );

            if (in_array(strtolower($match[1]), ['href', 'src'], true) && RegexHelper::isLinkPotentiallyUnsafe($value)) {
                $value = '';
            }

            return $match[1].'="'.e($value).'"';
        }, $tag);
    }

    private static function plainValue(mixed $value): string
    {
        return match (true) {
            $value instanceof MarkdownValue => $value->markdown,
            is_scalar($value) => (string) $value,
            default => '',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function baseContext(): array
    {
        return [
            'company' => [
                'name' => setting('company.name'),
                'email' => setting('company.email'),
                'url' => url('/'),
            ],
            'client_area_url' => route('client.dashboard'),
        ];
    }
}
