<?php

namespace App\Automations\Steps;

use App\Automations\Context;
use App\Automations\StepFailed;
use App\Models\Admin;

/**
 * Give the ticket to one staff member.
 */
class AssignTicket extends Step
{
    public function key(): string
    {
        return 'assign_ticket';
    }

    public function label(): string
    {
        return 'Assign the ticket';
    }

    public function group(): string
    {
        return 'Support';
    }

    public function subjects(): array
    {
        return ['ticket'];
    }

    public function fields(): array
    {
        return [self::field('admin', 'Staff member', 'select', [
            'required' => true,
            'options' => Admin::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->mapWithKeys(fn (string $name, int $id): array => [(string) $id => $name])->all(),
        ])];
    }

    public function summary(array $config): string
    {
        return __('Assign to :name', ['name' => Admin::query()->find((int) ($config['admin'] ?? 0))?->name ?? '—']);
    }

    public function preview(Context $context, array $config): string
    {
        return __('Would assign :ticket to :name', ['ticket' => $context->label(), 'name' => Admin::query()->find((int) ($config['admin'] ?? 0))?->name ?? '—']);
    }

    public function run(Context $context, array $config): string
    {
        $ticket = $context->ticket() ?? throw new StepFailed(__('This step only works on tickets.'));
        $admin = Admin::query()->where('is_active', true)->find((int) ($config['admin'] ?? 0)) ?? throw new StepFailed(__('That staff member no longer exists or is switched off.'));

        $ticket->update(['assigned_admin_id' => $admin->id]);

        return __('Assigned :ticket to :name', ['ticket' => $context->label(), 'name' => $admin->name]);
    }
}
