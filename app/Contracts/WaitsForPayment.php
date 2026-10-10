<?php

namespace App\Contracts;

use App\Models\Service;

/**
 * A server module that may refuse to set up a service until its order invoice is paid, such as a
 * VPS with paid extras. Such a setup runs again once that invoice is paid. A setup that failed for
 * any other reason never runs again by itself: a create without a clear answer may have made an
 * account, and a second create would make a second one.
 */
interface WaitsForPayment
{
    /**
     * Whether the last setup of this waiting service was refused only because its order invoice
     * was not paid yet, before anything was sent to the server.
     */
    public function waitsForPayment(Service $service): bool;
}
