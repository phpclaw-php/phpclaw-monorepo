<?php

declare(strict_types=1);

namespace PhpClaw\Joomla\Component\Administrator\Database;

/**
 * Canonical phpClaw table names in Joomla `#__` prefix form, the single declaration site
 * every query, schema check and install routine builds its table name from.
 */
interface PhpClawTables
{
    public const CONVERSATIONS_TABLE = '#__phpclaw_conversations';

    public const MESSAGES_TABLE = '#__phpclaw_messages';

    public const MEMORY_TABLE = '#__phpclaw_memory';
}
