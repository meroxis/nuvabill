<?php

namespace App\Automations\Steps;

use App\Automations\Context;
use Carbon\CarbonInterface;

/**
 * Pause the run for some hours or days. The run goes on by itself afterwards, and stops when the
 * reason it started is no longer true (for example the invoice was paid meanwhile).
 */
class Wait extends Step
{
    public function key(): string
    {
        return 'wait';
    }

    public function label(): string
    {
        return 'Wait';
    }

    public function group(): string
    {
        return 'Flow';
    }

    public function fields(): array
    {
        return [
            self::field('amount', 'How long', 'number', ['required' => true, 'min' => 1, 'max' => 365, 'default' => '7']),
            self::field('unit', 'Unit', 'select', ['required' => true, 'default' => 'days', 'options' => ['hours' => __('hours'), 'days' => __('days')]]),
        ];
    }

    public function summary(array $config): string
    {
        $amount = max(1, (int) ($config['amount'] ?? 1));

        return ($config['unit'] ?? 'days') === 'hours'
            ? trans_choice('Wait :count hour|Wait :count hours', $amount, ['count' => $amount])
            : trans_choice('Wait :count day|Wait :count days', $amount, ['count' => $amount]);
    }

    public function preview(Context $context, array $config): string
    {
        return __('Would wait until :date, then go on', ['date' => $this->until($config)->translatedFormat('d M Y H:i')]);
    }

    public function run(Context $context, array $config): string
    {
        return $this->summary($config);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function until(array $config, ?CarbonInterface $from = null): CarbonInterface
    {
        $amount = min(365, max(1, (int) ($config['amount'] ?? 1)));
        $from ??= now();

        return ($config['unit'] ?? 'days') === 'hours' ? $from->copy()->addHours($amount) : $from->copy()->addDays($amount);
    }
}
