<?php

namespace App\Ai;

use Anthropic\Client;
use LogicException;

/**
 * Makes the Anthropic client for the owner's key. Tests swap this class for one with a fake
 * HTTP transport, so no test ever reaches Anthropic.
 */
class ClientFactory
{
    public function make(string $key): Client
    {
        if (app()->runningUnitTests()) {
            throw new LogicException('Tests must bind a fake App\Ai\ClientFactory.');
        }

        // The key, token and address are all given, so nothing is read from the server's environment.
        return new Client(
            apiKey: $key,
            authToken: '',
            baseUrl: 'https://api.anthropic.com',
            requestOptions: ['timeout' => 60.0, 'maxRetries' => 1],
        );
    }
}
