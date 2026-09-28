<?php

namespace App\Automations;

use RuntimeException;

/**
 * A step could not do its work. The run stops and shows the message.
 */
class StepFailed extends RuntimeException {}
