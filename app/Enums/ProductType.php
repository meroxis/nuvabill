<?php

namespace App\Enums;

enum ProductType: string
{
    case Hosting = 'hosting';
    case Reseller = 'reseller';
    case Server = 'server';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Hosting => __('Shared hosting'),
            self::Reseller => __('Reseller hosting'),
            self::Server => __('VPS or server'),
            self::Other => __('Other service'),
        };
    }
}
