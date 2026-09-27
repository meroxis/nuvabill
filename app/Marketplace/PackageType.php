<?php

namespace App\Marketplace;

use App\Extensions\ExtensionManifest;

/**
 * What a marketplace package is, which manifest file it carries, and where it is installed.
 */
enum PackageType: string
{
    case Theme = 'theme';
    case OrderForm = 'orderform';
    case Gateway = 'gateway';
    case Server = 'server';
    case Registrar = 'registrar';
    case Addon = 'addon';

    /**
     * A license for Nuvabill itself, such as the White-label license. It has no package to install
     * and only counts while its yearly subscription is paid.
     */
    case License = 'license';

    public function label(): string
    {
        return match ($this) {
            self::Theme => __('Client theme'),
            self::OrderForm => __('Order form'),
            self::Gateway => __('Payment gateway'),
            self::Server => __('Server module'),
            self::Registrar => __('Domain registrar'),
            self::Addon => __('Extension'),
            self::License => __('Nuvabill license'),
        };
    }

    /**
     * The manifest file at the top of the package.
     */
    public function manifestFile(): string
    {
        return match ($this) {
            self::Theme => 'theme.json',
            self::OrderForm => 'orderform.json',
            default => 'extension.json',
        };
    }

    /**
     * The folder the package is installed in.
     */
    public function directory(string $slug): string
    {
        return match ($this) {
            self::Theme => config('nuvabill.themes_path').DIRECTORY_SEPARATOR.$slug,
            self::OrderForm => config('nuvabill.orderforms_path').DIRECTORY_SEPARATOR.$slug,
            default => config('nuvabill.extensions_path').DIRECTORY_SEPARATOR.$this->value.'s'.DIRECTORY_SEPARATOR.$slug,
        };
    }

    public function isExtension(): bool
    {
        return in_array($this->value, ExtensionManifest::TYPES, true);
    }

    /**
     * Where this kind of package shows in the admin marketplace tabs.
     */
    public function tab(): string
    {
        return match ($this) {
            self::Theme => 'themes',
            self::OrderForm => 'orderforms',
            default => 'extensions',
        };
    }
}
