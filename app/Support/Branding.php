<?php

namespace App\Support;

/**
 * Decides where the "Powered by Nuvabill" credit appears.
 *
 * The Community edition shows it on the client area, invoices and emails, as its license requires.
 * A valid White-label license from the marketplace store removes it ({@see WhiteLabel}).
 */
class Branding
{
    public const PRODUCT_NAME = 'Nuvabill';

    public const PRODUCT_URL = 'https://nuvabill.com';

    public static function showPoweredBy(): bool
    {
        // Before installation there are no settings yet: show the credit.
        return rescue(fn (): bool => ! app(WhiteLabel::class)->isActive(), true, report: false);
    }
}
