<?php

declare(strict_types=1);

namespace PhpClaw\SqlGuard;

/**
 * SQL dialect selector controlling the guard's string/quote lexing rules.
 */
enum SqlDialect
{
    case Generic;
    case MySql;
    case PostgreSql;
    case Sqlite;
}
