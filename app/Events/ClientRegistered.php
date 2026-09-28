<?php

namespace App\Events;

use App\Models\Client;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A client created an account themselves (sign-up page, checkout or social sign-in).
 */
class ClientRegistered
{
    use Dispatchable;

    public function __construct(public Client $client) {}
}
