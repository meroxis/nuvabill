<?php

namespace App\Automations\Steps;

use App\Automations\Context;

/**
 * One thing an automation does, such as "send an email" or "add a late fee". Add-ons can add
 * their own (see App\Automations\Registry::addStep()).
 *
 * Every step can say what it would do (preview) without changing anything, which the "Try it"
 * box on the automation page uses.
 */
abstract class Step
{
    /**
     * A short key stored with the automation, for example "send_email".
     */
    abstract public function key(): string;

    /**
     * The name in the step list, in English; it is translated when shown.
     */
    abstract public function label(): string;

    /**
     * What the step does with these settings, for the automation list.
     *
     * @param  array<string, mixed>  $config
     */
    abstract public function summary(array $config): string;

    /**
     * What the step would do now, without doing it.
     *
     * @param  array<string, mixed>  $config
     */
    abstract public function preview(Context $context, array $config): string;

    /**
     * Do it, and say what was done. Throw StepFailed when it could not be done.
     *
     * @param  array<string, mixed>  $config
     */
    abstract public function run(Context $context, array $config): string;

    /**
     * Messages, Billing, Clients, Support, Services, Flow or Other, in English.
     */
    public function group(): string
    {
        return 'Other';
    }

    /**
     * The settings staff fill in: name, label (English), type (text, textarea, number, money,
     * select, checkbox, url or condition), and optionally options, default, help, required, min and max.
     *
     * @return list<array<string, mixed>>
     */
    public function fields(): array
    {
        return [];
    }

    /**
     * What the step can work on, for example ["invoice"]. Empty means anything with a client.
     *
     * @return list<string>
     */
    public function subjects(): array
    {
        return [];
    }

    public function appliesTo(string $subject): bool
    {
        return $this->subjects() === [] || in_array($subject, $this->subjects(), true);
    }

    /**
     * "Late fee added to invoice …" instead of "{{ invoice.number }}", for lists.
     */
    protected static function withoutPlaceholders(string $text): string
    {
        return trim((string) preg_replace('/{{\s*[a-zA-Z0-9_.]+\s*}}/', '…', $text));
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    protected static function field(string $name, string $label, string $type, array $extra = []): array
    {
        return ['name' => $name, 'label' => $label, 'type' => $type] + $extra;
    }
}
