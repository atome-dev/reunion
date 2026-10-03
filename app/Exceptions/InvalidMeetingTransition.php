<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised when a meeting request is asked to move to a state it cannot reach from its current one.
 */
class InvalidMeetingTransition extends RuntimeException {}
