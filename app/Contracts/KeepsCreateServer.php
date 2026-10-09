<?php

namespace App\Contracts;

use App\Models\Service;

/**
 * A server module whose earlier create request for a service may have made an account it has not
 * found yet. While that is open, a new try must look on the same server, so the service is not
 * moved to another one when its server is turned off or full.
 */
interface KeepsCreateServer
{
    /**
     * Whether the next create of this waiting service must stay on its current server.
     */
    public function keepsServer(Service $service): bool;
}
