<?php

namespace App\Billing;

use App\Models\Coupon;
use App\Models\Domain;
use App\Models\InvoiceItem;
use App\Models\Service;
use Carbon\CarbonImmutable;

/**
 * Builds invoice lines for services and domains so orders and renewals describe periods the same way.
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
     * A domain registration, transfer or renewal for the given years from the start date.
     *
     * @param  string  $type  One of the InvoiceItem::TYPE_DOMAIN_* constants.
     * @return array{type: string, description: string, amount: int, domain_id: int, period_start: CarbonImmutable, period_end: CarbonImmutable}
     */
    public static function domainPeriod(Domain $domain, string $type, int $years, int $amount, CarbonImmutable $start): array
    {
        $end = $start->addYearsNoOverflow($years)->subDay();

        $label = match ($type) {
            InvoiceItem::TYPE_DOMAIN_TRANSFER => __('Domain transfer'),
            InvoiceItem::TYPE_DOMAIN_RENEW => __('Domain renewal'),
            default => __('Domain registration'),
        };

        $period = trans_choice(':count year|:count years', $years, ['count' => $years]);

        return [
            'type' => $type,
            'description' => $label.' - '.$domain->name.' ('.$period.($type === InvoiceItem::TYPE_DOMAIN_RENEW ? ', '.$start->format('d M Y').' - '.$end->format('d M Y') : '').')',
            'amount' => $amount,
            'domain_id' => $domain->id,
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

    /**
     * A product add-on billed with its service for the same period as the service line.
     *
     * @return array{type: string, description: string, amount: int, service_id: int, period_start: CarbonImmutable, period_end: CarbonImmutable|null}
     */
    public static function addonPeriod(Service $service, string $name, CarbonImmutable $start, int $amount): array
    {
        $period = self::servicePeriod($service, $start, $amount);
        $end = $period['period_end'];

        return [
            'type' => InvoiceItem::TYPE_ADDON,
            'description' => $name.($service->domain ? ' - '.$service->domain : '').($end !== null ? ' ('.$start->format('d M Y').' - '.$end->format('d M Y').')' : ''),
            'amount' => $amount,
            'service_id' => $service->id,
            'period_start' => $start,
            'period_end' => $end,
        ];
    }

    /**
     * A coupon discount on a service or domain. The amount is made negative.
     *
     * @return array{type: string, description: string, amount: int, service_id: int|null, domain_id: int|null}
     */
    public static function discount(Coupon $coupon, int $amount, ?Service $service = null, ?Domain $domain = null): array
    {
        return [
            'type' => InvoiceItem::TYPE_DISCOUNT,
            'description' => __('Coupon :code (:discount)', ['code' => $coupon->code, 'discount' => $coupon->describe()]),
            'amount' => -abs($amount),
            'service_id' => $service?->id,
            'domain_id' => $domain?->id,
        ];
    }
}
