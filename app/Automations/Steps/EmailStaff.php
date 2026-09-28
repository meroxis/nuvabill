<?php

namespace App\Automations\Steps;

use App\Automations\Context;
use App\Automations\StepFailed;
use App\Mail\TemplateMailer;
use App\Models\Admin;
use Illuminate\Support\Str;

/**
 * Email the company address or one staff member, for example about a big order.
 */
class EmailStaff extends Step
{
    public function __construct(private readonly TemplateMailer $mailer) {}

    public function key(): string
    {
        return 'email_staff';
    }

    public function label(): string
    {
        return 'Email staff';
    }

    public function group(): string
    {
        return 'Messages';
    }

    public function fields(): array
    {
        $options = ['company' => __('The company address (:email)', ['email' => (string) setting('company.email')])];

        foreach (Admin::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']) as $admin) {
            $options['admin:'.$admin->id] = $admin->name;
        }

        return [
            self::field('to', 'Send to', 'select', ['required' => true, 'options' => $options, 'default' => 'company']),
            self::field('subject', 'Subject', 'text', ['required' => true, 'max' => 200]),
            self::field('body', 'Message', 'textarea', ['required' => true, 'max' => 5000, 'help' => 'Placeholders like {{ client.name }} and {{ order.total }} are filled in.']),
        ];
    }

    public function summary(array $config): string
    {
        return __('Email staff “:subject”', ['subject' => Str::limit(self::withoutPlaceholders((string) ($config['subject'] ?? '')), 60)]);
    }

    public function preview(Context $context, array $config): string
    {
        [$email, $name] = $this->recipient((string) ($config['to'] ?? 'company'));

        return __('Would email “:subject” to :name', ['subject' => TemplateMailer::render((string) ($config['subject'] ?? ''), $context->mailContext()), 'name' => $name ?: $email]);
    }

    public function run(Context $context, array $config): string
    {
        [$email, $name] = $this->recipient((string) ($config['to'] ?? 'company'));

        if ($email === '') {
            throw new StepFailed(__('There is no email address to send to.'));
        }

        if (! $this->mailer->sendText($email, $name, (string) $config['subject'], (string) $config['body'], $context->mailContext())) {
            throw new StepFailed(__('The email could not be sent. Check Settings → Sending email.'));
        }

        return __('Emailed “:subject” to :name', ['subject' => TemplateMailer::render((string) $config['subject'], $context->mailContext()), 'name' => $name ?: $email]);
    }

    /**
     * @return array{0: string, 1: string} Email address and name.
     */
    private function recipient(string $to): array
    {
        if (str_starts_with($to, 'admin:')) {
            $admin = Admin::query()->where('is_active', true)->find((int) substr($to, 6));

            return [(string) $admin?->email, (string) $admin?->name];
        }

        return [(string) setting('company.email'), (string) setting('company.name')];
    }
}
