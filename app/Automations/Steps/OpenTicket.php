<?php

namespace App\Automations\Steps;

use App\Automations\Context;
use App\Automations\StepFailed;
use App\Enums\TicketPriority;
use App\Mail\TemplateMailer;
use App\Models\TicketDepartment;
use App\Support\TicketDesk;
use Illuminate\Support\Str;

/**
 * Open a ticket about the client, for example "call this client about the overdue invoice".
 * The first message comes from the company, not from the client.
 */
class OpenTicket extends Step
{
    public function __construct(private readonly TicketDesk $desk) {}

    public function key(): string
    {
        return 'open_ticket';
    }

    public function label(): string
    {
        return 'Open a ticket';
    }

    public function group(): string
    {
        return 'Support';
    }

    public function fields(): array
    {
        return [
            self::field('department', 'Department', 'select', ['required' => true, 'options' => TicketDepartment::query()->orderBy('name')->pluck('name', 'id')->mapWithKeys(fn (string $name, int $id): array => [(string) $id => $name])->all()]),
            self::field('subject', 'Subject', 'text', ['required' => true, 'max' => 190]),
            self::field('message', 'Message', 'textarea', ['required' => true, 'max' => 5000, 'help' => 'Placeholders like {{ client.name }} and {{ invoice.number }} are filled in.']),
            self::field('priority', 'Priority', 'select', ['required' => true, 'default' => TicketPriority::Medium->value, 'options' => collect(TicketPriority::cases())->mapWithKeys(fn (TicketPriority $priority): array => [$priority->value => $priority->label()])->all()]),
            self::field('notify', 'Email the client about it', 'checkbox', ['default' => false]),
        ];
    }

    public function summary(array $config): string
    {
        return __('Open a ticket “:subject”', ['subject' => Str::limit(self::withoutPlaceholders((string) ($config['subject'] ?? '')), 60)]);
    }

    public function preview(Context $context, array $config): string
    {
        $department = TicketDepartment::query()->find((int) ($config['department'] ?? 0));

        return __('Would open a ticket “:subject” in :department for :name', [
            'subject' => TemplateMailer::render((string) ($config['subject'] ?? ''), $context->mailContext()),
            'department' => $department?->name ?? '—',
            'name' => $context->client()?->name ?? '—',
        ]);
    }

    public function run(Context $context, array $config): string
    {
        $client = $context->client() ?? throw new StepFailed(__('There is no client.'));
        $department = TicketDepartment::query()->find((int) ($config['department'] ?? 0)) ?? throw new StepFailed(__('The support department no longer exists.'));
        $mail = $context->mailContext();

        $ticket = $this->desk->openFromCompany(
            $client,
            $department,
            TemplateMailer::render((string) $config['subject'], $mail),
            TemplateMailer::render((string) $config['message'], $mail),
            TicketPriority::tryFrom((string) ($config['priority'] ?? '')) ?? TicketPriority::Medium,
            $context->service(),
            (bool) ($config['notify'] ?? false),
        );

        return __('Opened ticket #:number', ['number' => $ticket->number]);
    }
}
