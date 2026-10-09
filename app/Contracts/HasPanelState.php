<?php

namespace App\Contracts;

use App\Models\Service;

/**
 * A client panel module that can read just the server's state, for the panel's checks every few
 * seconds after a power action. Without it, each check builds the whole panel.
 */
interface HasPanelState
{
    /**
     * The live state: running, stopped, suspended or unknown. Throws when the server does not answer.
     */
    public function clientPanelState(Service $service): string;
}
