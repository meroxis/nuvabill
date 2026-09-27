<?php

namespace App\Mail;

use App\Models\Client;
use App\Models\EmailTemplate;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\Ticket;
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
     * @param  array<string, mixed>  $context
     */
    public function send(string $key, Client $client, array $context = []): bool
    {
        return $this->sendTo($key, $client->email, $client->name, ['client' => self::clientContext($client)] + $context);
    }

    /**
     * Send a notification to the company's own address, for example "new order".
     *
     * @param  array<string, mixed>  $context
     */
    public function sendToStaff(string $key, array $context = []): bool
    {
        $email = (string) setting('company.email');

        return $email !== '' && $this->sendTo($key, $email, (string) setting('company.name'), $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function sendTo(string $key, string $email, string $name, array $context = []): bool
    {
        $template = EmailTemplate::query()->where('key', $key)->where('is_active', true)->first();

        if ($template === null) {
            return false;
        }

        $context += $this->baseContext();

        $subject = self::render($template->subject, $context);
        $html = Str::markdown(self::render($template->body, $context), [
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
        ]);

        try {
            Mail::to($email, $name)->send(new TemplatedMessage($subject, $html));

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
        return [
            'client' => self::clientContext($invoice->client),
            'invoice' => [
                'id' => $invoice->id,
                'number' => $invoice->displayNumber(),
                'subtotal' => money($invoice->subtotal, $invoice->currency),
                'tax' => money($invoice->tax, $invoice->currency),
                'total' => money($invoice->total, $invoice->currency),
                'balance' => money($invoice->balance(), $invoice->currency),
                'due_date' => $invoice->due_at->format('d M Y'),
                'url' => route('client.invoices.show', $invoice),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function serviceContext(Service $service): array
    {
        return [
            'client' => self::clientContext($service->client),
            'service' => [
                'id' => $service->id,
                'product' => $service->product->name,
                'domain' => $service->domain,
                'username' => $service->username,
                'server' => $service->server?->hostname,
                'next_due_date' => $service->next_due_date?->format('d M Y'),
                'amount' => money($service->recurring_amount, $service->currency),
                'url' => route('client.services.show', $service),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function ticketContext(Ticket $ticket): array
    {
        return [
            'client' => self::clientContext($ticket->client),
            'ticket' => [
                'number' => $ticket->number,
                'subject' => $ticket->subject,
                'department' => $ticket->department->name,
                'status' => $ticket->status->label(),
                'url' => route('client.tickets.show', $ticket),
            ],
        ];
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
