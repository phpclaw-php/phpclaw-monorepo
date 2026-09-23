<?php

declare(strict_types=1);

namespace PhpClaw\WordPress\Exceptions;

use PhpClaw\Exceptions\PhpClawException;

/**
 * Thrown when a user attempts to access a conversation that belongs to another user.
 */
final class ConversationAccessDeniedException extends PhpClawException {}
