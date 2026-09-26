<?php

namespace App\Billing;

use App\Models\InvoiceItem;
use App\Models\Service;
use Carbon\CarbonImmutable;

/**
 * Builds invoice lines for services so orders and renewals describe periods the same way.
 */
class LineItems
{
    /**
     * @return array{type: string, description: string, amount: int, service_id: int, period_start: CarbonImmutable, period_end: CarbonImmutable|null}
     */
    public static function servicePeriod(Service $service, CarbonImmutable $start, int $amount): array
    {
        $cycle = $service->billing_cycle;
        $end = $cycle->isRecurring() ? $cycle->advance($start)->subDay() : null;

        $description = $service->product->name.($service->domain ? ' - '.$service->domain : '');

        if ($end !== null) {
            $description .= ' ('.$start->format('d M Y').' - '.$end->format('d M Y').')';
        }

        return [
            'type' => InvoiceItem::TYPE_SERVICE,
            'description' => $description,
            'amount' => $amount,
            'service_id' => $service->id,
            'period_start' => $start,
            'period_end' => $end,
        ];
    }

    /**
     * @return array{type: string, description: string, amount: int, service_id: int}
     */
    public static function setupFee(Service $service, int $amount): array
    {
        return [
            'type' => InvoiceItem::TYPE_SETUP,
            'description' => __('Setup fee').' - '.$service->product->name,
            'amount' => $amount,
            'service_id' => $service->id,
        ];
    }
}
