<?php

namespace App\Automations\Steps;

use App\Automations\Context;
use App\Automations\StepFailed;
use App\Enums\TicketPriority;

/**
 * Change how urgent the ticket is.
 */
class SetTicketPriority extends Step
{
    public function key(): string
    {
        return 'ticket_priority';
    }

    public function label(): string
    {
        return 'Change the ticket priority';
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
        return [self::field('priority', 'Priority', 'select', [
            'required' => true,
            'default' => TicketPriority::High->value,
            'options' => collect(TicketPriority::cases())->mapWithKeys(fn (TicketPriority $priority): array => [$priority->value => $priority->label()])->all(),
        ])];
    }

    public function summary(array $config): string
    {
        return __('Priority :priority', ['priority' => mb_strtolower($this->priority($config)->label())]);
    }

    public function preview(Context $context, array $config): string
    {
        return __('Would set the priority of :ticket to :priority', ['ticket' => $context->label(), 'priority' => mb_strtolower($this->priority($config)->label())]);
    }

    public function run(Context $context, array $config): string
    {
        $ticket = $context->ticket() ?? throw new StepFailed(__('This step only works on tickets.'));
        $ticket->update(['priority' => $this->priority($config)]);

        return __('Set the priority of :ticket to :priority', ['ticket' => $context->label(), 'priority' => mb_strtolower($this->priority($config)->label())]);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function priority(array $config): TicketPriority
    {
        return TicketPriority::tryFrom((string) ($config['priority'] ?? '')) ?? TicketPriority::High;
    }
}
