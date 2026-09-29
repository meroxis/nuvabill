<?php

namespace App\Ai;

use RuntimeException;

/**
 * AI help could not answer. The message is already translated and safe to show to staff.
 */
class AiUnavailable extends RuntimeException {}
