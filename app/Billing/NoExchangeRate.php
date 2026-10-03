<?php

namespace App\Billing;

use RuntimeException;

/**
 * Money paid on an invoice cannot go back into the client's wallet, because the wallet is in
 * another currency and no exchange rate is set. Nothing was changed; staff add a rate first.
 */
class NoExchangeRate extends RuntimeException {}
