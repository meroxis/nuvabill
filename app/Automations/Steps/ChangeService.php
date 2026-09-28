<?php

namespace App\Automations\Steps;

use App\Automations\Context;
use App\Automations\StepFailed;
use App\Provisioning\Provisioner;

/**
 * Suspend or unsuspend the service on its server, the same way staff do from the service page.
 */
class ChangeService extends Step
{
    public function __construct(private readonly Provisioner $provisioner, private readonly bool $unsuspend = false) {}

    public function key(): string
    {
        return $this->unsuspend ? 'unsuspend_service' : 'suspend_service';
    }

    public function label(): string
    {
        return $this->unsuspend ? 'Unsuspend the service' : 'Suspend the service';
    }

    public function group(): string
    {
        return 'Services';
    }

    public function subjects(): array
    {
        return ['service'];
    }

    public function fields(): array
    {
        return $this->unsuspend ? [] : [self::field('reason', 'Reason', 'text', ['required' => true, 'max' => 190, 'default' => 'Suspended by an automation'])];
    }

    public function summary(array $config): string
    {
        return __($this->label());
    }

    public function preview(Context $context, array $config): string
    {
        return $this->unsuspend
            ? __('Would unsuspend :service', ['service' => $context->label()])
            : __('Would suspend :service', ['service' => $context->label()]);
    }

    public function run(Context $context, array $config): string
    {
        $service = $context->service() ?? throw new StepFailed(__('This step only works on services.'));
        $result = $this->unsuspend
            ? $this->provisioner->unsuspend($service)
            : $this->provisioner->suspend($service, (string) ($config['reason'] ?? 'Suspended by an automation'));

        if (! $result->success) {
            throw new StepFailed($result->message);
        }

        return $this->unsuspend
            ? __('Unsuspended :service', ['service' => $context->label()])
            : __('Suspended :service', ['service' => $context->label()]);
    }
}
