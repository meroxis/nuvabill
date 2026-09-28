<?php

namespace App\Automations\Steps;

use App\Automations\Context;
use App\Automations\StepFailed;
use App\Billing\Wallet;
use App\Support\Money;

/**
 * Put money in the client's wallet, for example as a thank-you.
 */
class AddCredit extends Step
{
    public function __construct(private readonly Wallet $wallet) {}

    public function key(): string
    {
        return 'add_credit';
    }

    public function label(): string
    {
        return 'Add wallet credit';
    }

    public function group(): string
    {
        return 'Billing';
    }

    public function fields(): array
    {
        return [
            self::field('amount', 'Amount', 'money', ['required' => true, 'min' => 0.01, 'max' => 1000]),
            self::field('description', 'Shown in the wallet', 'text', ['required' => true, 'max' => 120, 'default' => 'Thank you']),
        ];
    }

    public function summary(array $config): string
    {
        return __('Add :amount wallet credit', ['amount' => money(Money::toMinor($config['amount'] ?? 0))]);
    }

    public function preview(Context $context, array $config): string
    {
        $client = $context->client();

        return __('Would add :amount to the wallet of :name', ['amount' => money(Money::toMinor($config['amount'] ?? 0), $client?->currency), 'name' => $client?->name ?? '—']);
    }

    public function run(Context $context, array $config): string
    {
        $client = $context->client() ?? throw new StepFailed(__('There is no client.'));
        $amount = Money::toMinor($config['amount'] ?? 0);

        if ($amount <= 0 || $amount > 100000) {
            throw new StepFailed(__('The amount must be between 0.01 and 1,000.'));
        }

        $this->wallet->change($client, $amount, (string) $config['description']);

        return __('Added :amount to the wallet of :name', ['amount' => money($amount, $client->currency), 'name' => $client->name]);
    }
}
