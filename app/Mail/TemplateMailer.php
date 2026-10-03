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
use Throwable;

/**
 * Sends the editable email templates. Placeholders like {{ client.first_name }} are replaced with
 * plain values; templates are never compiled as code, so staff cannot run PHP through them.
 */
class TemplateMailer
{
    /**
     * The language to write in travels in the context under this key, so sendTo() keeps the
     * signature add-ons that extend this class (such as Crystal Mail) were built against.
     */
    public const LOCALE_KEY = '_locale';

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
        $html = Str::markdown(self::render($bodyText, $context), [
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
        ]);

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
        $html = Str::markdown(self::render($body, $context), [
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
        ]);

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
        return (string) preg_replace_callback('/{{\s*([a-zA-Z0-9_.]+)\s*}}/', function (array $match) use ($context): string {
            $value = data_get($context, $match[1]);

            return is_scalar($value) ? (string) $value : '';
        }, $text);
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
