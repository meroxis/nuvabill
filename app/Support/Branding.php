<?php

namespace App\Support;

/**
 * Decides where the "Powered by Nuvabill" credit appears.
 *
 * The Community edition always shows it on the client area, invoices and emails, as its license
 * requires. White-label licenses that remove it arrive with the license server in v0.4.
 */
class Branding
{
    public const PRODUCT_NAME = 'Nuvabill';

    public const PRODUCT_URL = 'https://nuvabill.com';

    public static function showPoweredBy(): bool
    {
        return true;
    }
}
