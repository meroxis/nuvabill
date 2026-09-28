<?php

namespace App\Contracts;

use App\Models\Service;

/**
 * A server module whose control panel signs clients in with a form instead of a one-time link, for
 * example CyberPanel. The client area shows a page that sends the form in the client's own browser.
 */
interface HasLoginForm
{
    /**
     * The form that signs the client in: where it posts to, and its fields. Null when it is not
     * available right now.
     *
     * @return array{url: string, fields: array<string, string>}|null
     */
    public function loginForm(Service $service): ?array;
}
