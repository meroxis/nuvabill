<?php

namespace App\Chat;

use RuntimeException;

/**
 * A chat app could not be reached or refused a request. The message is safe to show to staff.
 */
class ChatError extends RuntimeException {}
