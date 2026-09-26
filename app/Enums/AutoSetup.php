<?php

namespace App\Enums;

enum AutoSetup: string
{
    case OnOrder = 'order';
    case OnPayment = 'payment';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::OnOrder => __('As soon as the order is placed'),
            self::OnPayment => __('When the first payment is received'),
            self::Manual => __('Manually by staff'),
        };
    }
}
