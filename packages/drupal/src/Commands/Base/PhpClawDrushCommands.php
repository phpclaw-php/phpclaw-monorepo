<?php

declare(strict_types=1);

namespace PhpClaw\Drupal\Commands\Base;

use Drush\Commands\DrushCommands;
use PhpClaw\Drupal\DrupalConsole;

/**
 * Base for every phpClaw Drush command, marking the process as a console run.
 */
abstract class PhpClawDrushCommands extends DrushCommands
{
    /**
     * Mark the process as a console run before any tool is constructed.
     *
     * @return void
     */
    public function __construct()
    {
        DrupalConsole::mark();

        parent::__construct();
    }
}
