<?php

namespace App\Events;

use App\Models\Service;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A service was set up for the first time and is now active.
 */
class ServiceActivated
{
    use Dispatchable;

    public function __construct(public Service $service) {}
}
