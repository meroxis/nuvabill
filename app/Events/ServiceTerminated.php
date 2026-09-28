<?php

namespace App\Events;

use App\Models\Service;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A service was terminated.
 */
class ServiceTerminated
{
    use Dispatchable;

    public function __construct(public Service $service) {}
}
