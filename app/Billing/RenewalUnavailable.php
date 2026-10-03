<?php

namespace App\Billing;

use RuntimeException;

/**
 * A domain cannot get a renewal invoice because no renewal price is known for it. It is never
 * renewed for free just because its price is missing; staff can still renew it by hand.
 */
class RenewalUnavailable extends RuntimeException {}
