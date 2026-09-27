<?php

namespace App\Security\WebAuthn;

use RuntimeException;

/**
 * A passkey response that cannot be trusted. The message is for logs; people see a plain one.
 */
class WebAuthnException extends RuntimeException {}
