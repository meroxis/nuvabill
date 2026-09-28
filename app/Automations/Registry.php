<?php

namespace App\Automations;

use App\Automations\Steps\AddCredit;
use App\Automations\Steps\AddFee;
use App\Automations\Steps\AssignTicket;
use App\Automations\Steps\CallWebhook;
use App\Automations\Steps\ChangeService;
use App\Automations\Steps\EmailStaff;
use App\Automations\Steps\OnlyIf;
use App\Automations\Steps\OpenTicket;
use App\Automations\Steps\SendEmail;
use App\Automations\Steps\SetTicketPriority;
use App\Automations\Steps\Step;
use App\Automations\Steps\TagClient;
use App\Automations\Steps\Wait;
use App\Billing\Wallet;
use App\Enums\DomainStatus;
use App\Enums\InvoiceStatus;
use App\Enums\QuoteStatus;
use App\Enums\ServiceStatus;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Automation;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Quote;
use App\Models\Service;
use App\Models\TicketDepartment;
use App\Provisioning\Provisioner;
use App\Support\Countries;
use App\Support\TicketDesk;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Everything automations can react to, check and do, and the entry point that starts them when
 * something happens. Add-ons add their own steps with addStep().
 */
class Registry
{
    /**
     * True while steps run, so what an automation does (suspending a service, opening a ticket)
     * does not start other automations in a loop.
     */
    public static bool $paused = false;

    /**
     * @var array<string, Step>
     */
    private array $extraSteps = [];

    /**
     * @var array<string, Trigger>|null
     */
    private ?array $triggers = null;

    /**
     * @var array<string, ConditionField>|null
     */
    private ?array $conditions = null;

    /**
     * Something happened: start every switched-on automation for it. Never throws, and waits for the
     * database transaction around the event to finish first.
     */
    public function fire(string $trigger, Model $subject, string $occurrence = ''): void
    {
        if (self::$paused) {
            return;
        }

        DB::afterCommit(fn () => rescue(function () use ($trigger, $subject, $occurrence): void {
            if (self::$paused) {
                return;
            }

            foreach (Automation::query()->where('is_active', true)->where('trigger', $trigger)->orderBy('id')->get() as $automation) {
                app(Runner::class)->start($automation, $subject, $occurrence);
            }
        }));
    }

    public function addStep(Step $step): void
    {
        $this->extraSteps[$step->key()] = $step;
    }

    /**
     * @return array<string, Trigger>
     */
    public function triggers(): array
    {
        return $this->triggers ??= $this->makeTriggers();
    }

    public function trigger(string $key): ?Trigger
    {
        return $this->triggers()[$key] ?? null;
    }

    /**
     * @return array<string, ConditionField>
     */
    public function conditionFields(): array
    {
        return $this->conditions ??= $this->makeConditions();
    }

    /**
     * @return array<string, Step>
     */
    public function steps(): array
    {
        $core = [
            app(SendEmail::class),
            app(EmailStaff::class),
            app(AddFee::class),
            new AddCredit(app(Wallet::class)),
            new TagClient,
            new TagClient(remove: true),
            new OpenTicket(app(TicketDesk::class)),
            new AssignTicket,
            new SetTicketPriority,
            new ChangeService(app(Provisioner::class)),
            new ChangeService(app(Provisioner::class), unsuspend: true),
            new CallWebhook,
            new Wait,
            new OnlyIf($this),
        ];
        $steps = [];

        foreach ([...$core, ...array_values($this->extraSteps)] as $step) {
            $steps[$step->key()] = $step;
        }

        return $steps;
    }

    public function step(string $key): ?Step
    {
        return $this->steps()[$key] ?? null;
    }

    /**
     * @param  list<array<string, mixed>>  $conditions
     */
    public function conditionsPass(Context $context, array $conditions): bool
    {
        foreach ($conditions as $condition) {
            if (! $this->conditionPasses($context, (array) $condition)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $condition  {field, operator, value}
     */
    public function conditionPasses(Context $context, array $condition): bool
    {
        $field = $this->conditionFields()[(string) ($condition['field'] ?? '')] ?? null;

        return $field !== null
            && $field->appliesTo($context->subjectType())
            && $field->test($context, (string) ($condition['operator'] ?? ''), $condition['value'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $condition
     */
    public function describeCondition(array $condition): string
    {
        $field = $this->conditionFields()[(string) ($condition['field'] ?? '')] ?? null;

        return $field?->describe((string) ($condition['operator'] ?? ''), $condition['value'] ?? '') ?? '—';
    }

    /**
     * "Add a 5% fee · Email the client “Late fee added”".
     */
    public function summarise(Automation $automation): string
    {
        return collect($automation->steps)
            ->map(fn (array $step): string => $this->step((string) ($step['type'] ?? ''))?->summary((array) ($step['config'] ?? [])) ?? '—')
            ->implode(' · ');
    }

    public function describeTrigger(Automation $automation): string
    {
        return $this->trigger($automation->trigger)?->describe($automation->trigger_days) ?? $automation->trigger;
    }

    /**
     * Check a whole automation as the editor sends it, and return it cleaned.
     *
     * @param  array<string, mixed>  $input  {trigger, days, conditions: [...], steps: [...]}
     * @return array{trigger: string, trigger_days: int|null, conditions: list<array{field: string, operator: string, value: mixed}>, steps: list<array{type: string, config: array<string, mixed>}>}
     *
     * @throws ValidationException
     */
    public function clean(array $input): array
    {
        $errors = [];
        $trigger = $this->trigger((string) ($input['trigger'] ?? ''));

        if ($trigger === null) {
            throw ValidationException::withMessages(['definition' => __('Choose what starts the automation.')]);
        }

        $days = null;

        if ($trigger->isTimed()) {
            $days = filter_var($input['days'] ?? null, FILTER_VALIDATE_INT);

            if ($days === false || $days < 0 || $days > 365) {
                $errors[] = __('Enter a number of days from 0 to 365.');
                $days = 0;
            }
        }

        $conditions = [];

        foreach (array_slice(array_values((array) ($input['conditions'] ?? [])), 0, 10) as $index => $condition) {
            $clean = $this->cleanCondition((array) $condition, $trigger->subject);
            $clean === null
                ? $errors[] = __('Condition :number is not complete.', ['number' => $index + 1])
                : $conditions[] = $clean;
        }

        $steps = [];
        $input['steps'] = array_values((array) ($input['steps'] ?? []));

        if ($input['steps'] === []) {
            $errors[] = __('Add at least one step.');
        }

        foreach (array_slice($input['steps'], 0, 20) as $index => $step) {
            $type = $this->step((string) (((array) $step)['type'] ?? ''));

            if ($type === null || ! $type->appliesTo($trigger->subject)) {
                $errors[] = __('Step :number cannot be used with this trigger.', ['number' => $index + 1]);

                continue;
            }

            [$config, $stepErrors] = $this->cleanConfig($type, (array) (((array) $step)['config'] ?? []), $trigger->subject);

            foreach ($stepErrors as $error) {
                $errors[] = __('Step :number (:step): :error', ['number' => $index + 1, 'step' => __($type->label()), 'error' => $error]);
            }

            $steps[] = ['type' => $type->key(), 'config' => $config];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages(['definition' => $errors]);
        }

        return ['trigger' => $trigger->key, 'trigger_days' => $days, 'conditions' => $conditions, 'steps' => $steps];
    }

    /**
     * Triggers, conditions and steps for the editor, with every label in the language of the page.
     *
     * @return array<string, mixed>
     */
    public function editorDefinitions(): array
    {
        $triggers = [];

        foreach ($this->triggers() as $trigger) {
            $triggers[$trigger->key] = ['label' => __($trigger->label), 'group' => __($trigger->group), 'subject' => $trigger->subject, 'timed' => $trigger->isTimed(), 'unit' => $trigger->daysUnit ? __($trigger->daysUnit) : null];
        }

        $conditions = [];

        foreach ($this->conditionFields() as $field) {
            $conditions[$field->key] = [
                'label' => __($field->label),
                'type' => $field->type,
                'subjects' => $field->subjects,
                'operators' => array_map(fn (string $label): string => __($label), $field->operatorLabels()),
                'options' => $field->options(),
            ];
        }

        $steps = [];

        foreach ($this->steps() as $step) {
            $steps[$step->key()] = [
                'label' => __($step->label()),
                'group' => __($step->group()),
                'subjects' => $step->subjects(),
                'fields' => array_map(fn (array $field): array => ['label' => __((string) $field['label']), 'help' => isset($field['help']) ? __((string) $field['help']) : null] + $field, $step->fields()),
            ];
        }

        return ['triggers' => $triggers, 'conditions' => $conditions, 'steps' => $steps];
    }

    /**
     * @param  array<string, mixed>  $condition
     * @return array{field: string, operator: string, value: mixed}|null
     */
    private function cleanCondition(array $condition, string $subject): ?array
    {
        $field = $this->conditionFields()[(string) ($condition['field'] ?? '')] ?? null;
        $operator = (string) ($condition['operator'] ?? '');
        $value = $condition['value'] ?? null;

        if ($field === null || ! $field->appliesTo($subject) || ! isset($field->operators()[$operator]) || is_array($value)) {
            return null;
        }

        $value = trim((string) $value);
        $valid = match ($field->type) {
            'money' => is_numeric($value) && (float) $value >= 0 && (float) $value <= 10_000_000,
            'number' => filter_var($value, FILTER_VALIDATE_INT) !== false && (int) $value >= 0,
            'choice' => array_key_exists($value, $field->options()),
            default => $value !== '' && mb_strlen($value) <= 30,
        };

        return $valid ? ['field' => $field->key, 'operator' => $operator, 'value' => $value] : null;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    private function cleanConfig(Step $step, array $config, string $subject): array
    {
        $clean = [];
        $errors = [];

        foreach ($step->fields() as $field) {
            $name = (string) $field['name'];
            $value = $config[$name] ?? ($field['default'] ?? null);
            $label = __((string) $field['label']);

            if ($field['type'] === 'checkbox') {
                $clean[$name] = filter_var($value, FILTER_VALIDATE_BOOLEAN);

                continue;
            }

            if ($field['type'] === 'condition') {
                $condition = $this->cleanCondition((array) $value, $subject);
                $condition === null ? $errors[] = __('Complete the condition.') : $clean[$name] = $condition;

                continue;
            }

            $value = is_scalar($value) ? trim((string) $value) : '';

            if ($value === '') {
                if (! empty($field['required'])) {
                    $errors[] = __(':field is needed.', ['field' => $label]);
                }

                $clean[$name] = '';

                continue;
            }

            $max = (int) ($field['max'] ?? 0);
            $problem = match ($field['type']) {
                'number', 'money' => ! is_numeric($value)
                    || (isset($field['min']) && (float) $value < (float) $field['min'])
                    || (float) $value > (float) ($field['max'] ?? 1_000_000)
                    || (float) $value < 0,
                'select' => ! array_key_exists($value, (array) ($field['options'] ?? [])),
                'url' => ! filter_var($value, FILTER_VALIDATE_URL) || ! str_starts_with(strtolower($value), 'https://') || mb_strlen($value) > ($max ?: 500),
                default => $max > 0 && mb_strlen($value) > $max,
            };

            if ($problem) {
                $errors[] = __(':field is not valid.', ['field' => $label]);
            }

            $clean[$name] = $value;
        }

        return [$clean, $errors];
    }

    /**
     * @return array<string, Trigger>
     */
    private function makeTriggers(): array
    {
        $unpaid = fn (Model $invoice): bool => $invoice instanceof Invoice && $invoice->status === InvoiceStatus::Unpaid;
        $list = [
            new Trigger('client.registered', 'A client signs up', 'client', 'Clients'),
            new Trigger('order.placed', 'An order is placed', 'order', 'Orders'),
            new Trigger('invoice.paid', 'An invoice is paid', 'invoice', 'Invoices'),
            new Trigger('invoice.due_soon', 'An invoice is due soon', 'invoice', 'Invoices', 'days before the due date', ':count day before the due date|:count days before the due date',
                fn (CarbonImmutable $today, int $days) => Invoice::query()->with('client')->where('status', InvoiceStatus::Unpaid)
                    ->whereDate('due_at', '>=', $today)->whereDate('due_at', '<=', $today->addDays($days)), $unpaid),
            new Trigger('invoice.overdue', 'An invoice is overdue', 'invoice', 'Invoices', 'days after the due date', ':count day after the due date|:count days after the due date',
                fn (CarbonImmutable $today, int $days) => Invoice::query()->with('client')->where('status', InvoiceStatus::Unpaid)
                    ->whereDate('due_at', '<=', $today->subDays($days))->whereDate('due_at', '>=', $today->subDays($days + 7)), $unpaid),
            new Trigger('service.activated', 'A service is set up', 'service', 'Services'),
            new Trigger('service.suspended', 'A service is suspended', 'service', 'Services', stillTrue: fn (Model $service): bool => $service instanceof Service && $service->status === ServiceStatus::Suspended),
            new Trigger('service.terminated', 'A service is terminated', 'service', 'Services'),
            new Trigger('service.age', 'A service has been active for a while', 'service', 'Services', 'days after it was set up', ':count day after it was set up|:count days after it was set up',
                fn (CarbonImmutable $today, int $days) => Service::query()->with('client')->where('status', ServiceStatus::Active)
                    ->whereDate('registration_date', '<=', $today->subDays($days))->whereDate('registration_date', '>=', $today->subDays($days + 7)),
                fn (Model $service): bool => $service instanceof Service && $service->status === ServiceStatus::Active),
            new Trigger('domain.expiring', 'A domain expires soon', 'domain', 'Domains', 'days before it expires', ':count day before it expires|:count days before it expires',
                fn (CarbonImmutable $today, int $days) => Domain::query()->with('client')->where('status', DomainStatus::Active)
                    ->whereDate('expires_at', '>=', $today)->whereDate('expires_at', '<=', $today->addDays($days)),
                fn (Model $domain): bool => $domain instanceof Domain && $domain->status === DomainStatus::Active),
            new Trigger('ticket.opened', 'A client opens a ticket', 'ticket', 'Support'),
            new Trigger('ticket.client_reply', 'A client replies to a ticket', 'ticket', 'Support'),
            new Trigger('quote.unanswered', 'A quote is not answered', 'quote', 'Orders', 'days after it was sent', ':count day after it was sent|:count days after it was sent',
                fn (CarbonImmutable $today, int $days) => Quote::query()->with('client')->where('status', QuoteStatus::Sent)
                    ->where('sent_at', '<=', $today->subDays($days)->endOfDay())->where('sent_at', '>=', $today->subDays($days + 7)),
                fn (Model $quote): bool => $quote instanceof Quote && $quote->status === QuoteStatus::Sent),
        ];

        return collect($list)->keyBy(fn (Trigger $trigger): string => $trigger->key)->all();
    }

    /**
     * @return array<string, ConditionField>
     */
    private function makeConditions(): array
    {
        $enum = fn (array $cases): array => collect($cases)->mapWithKeys(fn ($case): array => [$case->value => $case->label()])->all();
        $list = [
            new ConditionField('client.tag', 'The client', 'tag', [], fn (Context $c): mixed => null),
            new ConditionField('client.country', 'The client’s country', 'choice', [], fn (Context $c): string => (string) $c->client()?->country, fn (): array => Countries::all()),
            new ConditionField('client.paid_invoices', 'The client’s paid invoices', 'number', [],
                fn (Context $c): int => (int) $c->client()?->invoices()->where('status', InvoiceStatus::Paid)->count()),
            new ConditionField('client.active_services', 'The client’s active services', 'number', [],
                fn (Context $c): int => (int) $c->client()?->services()->where('status', ServiceStatus::Active)->count()),
            new ConditionField('client.open_tickets', 'The client’s open tickets', 'number', [],
                fn (Context $c): int => (int) $c->client()?->tickets()->where('status', '!=', TicketStatus::Closed)->count()),
            new ConditionField('invoice.total', 'The invoice total', 'money', ['invoice'], fn (Context $c): int => (int) $c->invoice()?->total),
            new ConditionField('invoice.status', 'The invoice', 'choice', ['invoice'], fn (Context $c): string => (string) $c->invoice()?->status->value, fn (): array => $enum(InvoiceStatus::cases())),
            new ConditionField('order.total', 'The order total', 'money', ['order'], fn (Context $c): int => (int) $c->order()?->total),
            new ConditionField('service.product', 'The product', 'choice', ['service'], fn (Context $c): string => (string) $c->service()?->product_id,
                fn (): array => Product::query()->orderBy('name')->pluck('name', 'id')->mapWithKeys(fn (string $name, int $id): array => [(string) $id => $name])->all()),
            new ConditionField('service.status', 'The service', 'choice', ['service'], fn (Context $c): string => (string) $c->service()?->status->value, fn (): array => $enum(ServiceStatus::cases())),
            new ConditionField('ticket.department', 'The ticket department', 'choice', ['ticket'], fn (Context $c): string => (string) $c->ticket()?->ticket_department_id,
                fn (): array => TicketDepartment::query()->orderBy('name')->pluck('name', 'id')->mapWithKeys(fn (string $name, int $id): array => [(string) $id => $name])->all()),
            new ConditionField('ticket.priority', 'The ticket priority', 'choice', ['ticket'], fn (Context $c): string => (string) $c->ticket()?->priority->value, fn (): array => $enum(TicketPriority::cases())),
            new ConditionField('ticket.status', 'The ticket', 'choice', ['ticket'], fn (Context $c): string => (string) $c->ticket()?->status->value, fn (): array => $enum(TicketStatus::cases())),
            new ConditionField('quote.status', 'The quote', 'choice', ['quote'], fn (Context $c): string => $c->subject instanceof Quote ? $c->subject->status->value : '', fn (): array => $enum(QuoteStatus::cases())),
            new ConditionField('domain.status', 'The domain', 'choice', ['domain'], fn (Context $c): string => $c->subject instanceof Domain ? $c->subject->status->value : '', fn (): array => $enum(DomainStatus::cases())),
        ];

        return collect($list)->keyBy(fn (ConditionField $field): string => $field->key)->all();
    }
}
