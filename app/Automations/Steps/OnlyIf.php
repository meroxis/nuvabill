<?php

namespace App\Automations\Steps;

use App\Automations\Context;
use App\Automations\Registry;

/**
 * Go on only when a condition is true now, for example "the invoice is still unpaid" after a wait.
 * Otherwise the run stops here.
 */
class OnlyIf extends Step
{
    public function __construct(private readonly Registry $registry) {}

    public function key(): string
    {
        return 'only_if';
    }

    public function label(): string
    {
        return 'Only go on if';
    }

    public function group(): string
    {
        return 'Flow';
    }

    public function fields(): array
    {
        return [self::field('condition', 'Condition', 'condition', ['required' => true])];
    }

    public function summary(array $config): string
    {
        return __('Only go on if :condition', ['condition' => $this->describe($config)]);
    }

    public function preview(Context $context, array $config): string
    {
        return $this->passes($context, $config)
            ? __('Would go on: :condition', ['condition' => $this->describe($config)])
            : __('Would stop here: it is not true that :condition', ['condition' => $this->describe($config)]);
    }

    public function run(Context $context, array $config): string
    {
        return __('Checked: :condition', ['condition' => $this->describe($config)]);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function passes(Context $context, array $config): bool
    {
        $condition = (array) ($config['condition'] ?? []);

        return $this->registry->conditionPasses($context, $condition);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function describe(array $config): string
    {
        return mb_lcfirst($this->registry->describeCondition((array) ($config['condition'] ?? [])));
    }
}
