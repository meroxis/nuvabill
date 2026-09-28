<?php

namespace App\Automations;

use App\Billing\QuoteManager;
use App\Domains\DomainProvisioner;
use App\Mail\TemplateMailer;
use App\Models\Client;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Quote;
use App\Models\Service;
use App\Models\Ticket;
use Illuminate\Database\Eloquent\Model;

/**
 * What one automation run is about: an invoice, client, service, ticket, order, domain or quote,
 * with the client it belongs to and the placeholders for messages.
 */
final class Context
{
    public function __construct(public readonly string $trigger, public readonly Model $subject) {}

    public function subjectType(): string
    {
        return $this->subject->getMorphClass();
    }

    public function client(): ?Client
    {
        return $this->subject instanceof Client ? $this->subject : $this->subject->getRelationValue('client');
    }

    public function invoice(): ?Invoice
    {
        return $this->subject instanceof Invoice ? $this->subject : null;
    }

    public function service(): ?Service
    {
        return $this->subject instanceof Service ? $this->subject : null;
    }

    public function ticket(): ?Ticket
    {
        return $this->subject instanceof Ticket ? $this->subject : null;
    }

    public function order(): ?Order
    {
        return $this->subject instanceof Order ? $this->subject : null;
    }

    /**
     * The subject as people call it, for logs and tests: "Invoice INV-1042", "Ticket #482113".
     */
    public function label(): string
    {
        return self::describe($this->subject);
    }

    public static function describe(Model $subject): string
    {
        return match (true) {
            $subject instanceof Invoice => __('Invoice :number', ['number' => $subject->displayNumber()]),
            $subject instanceof Client => $subject->name,
            $subject instanceof Service => __('Service #:id (:name)', ['id' => $subject->id, 'name' => $subject->label()]),
            $subject instanceof Ticket => __('Ticket #:number', ['number' => $subject->number]),
            $subject instanceof Order => __('Order :number', ['number' => $subject->number]),
            $subject instanceof Domain => $subject->name,
            $subject instanceof Quote => __('Quote :number', ['number' => $subject->displayNumber()]),
            default => class_basename($subject).' #'.$subject->getKey(),
        };
    }

    /**
     * Placeholders for emails, the same names the email templates use: {{ client.first_name }},
     * {{ invoice.total }}, {{ service.domain }} and so on.
     *
     * @return array<string, mixed>
     */
    public function mailContext(): array
    {
        $subject = $this->subject;
        $client = $this->client();
        $context = $client !== null ? ['client' => TemplateMailer::clientContext($client)] : [];

        return match (true) {
            $subject instanceof Invoice => TemplateMailer::invoiceContext($subject) + $context,
            $subject instanceof Service => TemplateMailer::serviceContext($subject) + $context,
            $subject instanceof Ticket => TemplateMailer::ticketContext($subject) + $context,
            $subject instanceof Domain => DomainProvisioner::context($subject) + $context,
            $subject instanceof Quote => QuoteManager::context($subject) + $context,
            $subject instanceof Order => $context + ['order' => [
                'number' => $subject->number,
                'total' => money($subject->total, $subject->currency),
                'status' => $subject->status->label(),
            ]],
            default => $context,
        };
    }

    /**
     * What a web address step sends: the trigger and plain facts about the subject and client.
     *
     * @return array<string, mixed>
     */
    public function webhookData(): array
    {
        $client = $this->client();

        return [
            'trigger' => $this->trigger,
            'subject' => ['type' => $this->subjectType(), 'id' => $this->subject->getKey(), 'label' => $this->label()],
            'client' => $client === null ? null : [
                'id' => $client->id,
                'name' => $client->name,
                'email' => $client->email,
                'country' => $client->country,
                'tags' => $client->tagList(),
            ],
            'data' => $this->mailContext(),
            'sent_at' => now()->toIso8601String(),
        ];
    }
}
