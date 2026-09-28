<?php

namespace App\Automations\Steps;

use App\Automations\Context;
use App\Automations\StepFailed;
use App\Mail\TemplateMailer;
use Illuminate\Support\Str;

/**
 * Email the client a message written in the automation, with placeholders such as
 * {{ client.first_name }} or {{ invoice.total }}.
 */
class SendEmail extends Step
{
    public function __construct(private readonly TemplateMailer $mailer) {}

    public function key(): string
    {
        return 'send_email';
    }

    public function label(): string
    {
        return 'Email the client';
    }

    public function group(): string
    {
        return 'Messages';
    }

    public function fields(): array
    {
        return [
            self::field('subject', 'Subject', 'text', ['required' => true, 'max' => 200]),
            self::field('body', 'Message', 'textarea', ['required' => true, 'max' => 5000, 'help' => 'Placeholders like {{ client.first_name }}, {{ invoice.number }}, {{ invoice.total }} and {{ invoice.url }} are filled in. Markdown works, for example **bold**.']),
        ];
    }

    public function summary(array $config): string
    {
        return __('Email the client “:subject”', ['subject' => Str::limit(self::withoutPlaceholders((string) ($config['subject'] ?? '')), 60)]);
    }

    public function preview(Context $context, array $config): string
    {
        $subject = TemplateMailer::render((string) ($config['subject'] ?? ''), $context->mailContext());

        return __('Would email “:subject” to :name', ['subject' => $subject, 'name' => $context->client()?->name ?? '—']);
    }

    public function run(Context $context, array $config): string
    {
        $client = $context->client() ?? throw new StepFailed(__('There is no client to email.'));

        if (! $this->mailer->sendText($client->email, $client->name, (string) $config['subject'], (string) $config['body'], $context->mailContext())) {
            throw new StepFailed(__('The email could not be sent. Check Settings → Sending email.'));
        }

        return __('Emailed “:subject” to :name', ['subject' => TemplateMailer::render((string) $config['subject'], $context->mailContext()), 'name' => $client->name]);
    }
}
