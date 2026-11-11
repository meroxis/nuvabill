<?php

namespace App\Automations\Steps;

use App\Automations\Context;
use App\Automations\Registry;

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
     * The staff permissions this step stands for, such as "billing.manage" for adding wallet
     * credit. Staff save, switch on and try an automation only when they have them all, so an
     * automation never does what the staff member may not do by hand. $subject is what the
     * trigger is about ("invoice", "client", "service" and so on), for steps that pass its
     * details on.
     *
     * A step that does not say what it needs asks for "settings.manage". Return an empty list
     * when the automations permission alone is enough.
     *
     * @return list<string>
     */
    public function permissions(string $subject): array
    {
        return ['settings.manage'];
    }

    /**
     * What staff need to see the subject of a run and its client, for steps that send these
     * details to a person or another system.
     *
     * @return list<string>
     */
    protected static function permissionsToSee(string $subject): array
    {
        return array_values(array_unique(['clients.view', Registry::subjectPermission($subject)]));
    }

    /**
     * The step's own permission, and what staff need to see the subject of a run. For steps that
     * leave something staff can read later, such as a ticket or a tag on the client: it tells them
     * what happened to the invoice, service or order the run was about.
     *
     * @return list<string>
     */
    protected static function permissionAndToSee(string $permission, string $subject): array
    {
        $toSee = Registry::subjectPermission($subject);

        // "clients.manage" already includes "clients.view".
        return $toSee === explode('.', $permission)[0].'.view' ? [$permission] : array_values(array_unique([$permission, $toSee]));
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
