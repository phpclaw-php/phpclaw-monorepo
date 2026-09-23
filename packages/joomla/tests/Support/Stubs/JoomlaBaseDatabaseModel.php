<?php

declare(strict_types=1);

namespace Joomla\CMS\MVC\Model;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;

abstract class BaseDatabaseModel
{
    public function getDatabase(): DatabaseInterface
    {
        return Factory::getContainer()->get(DatabaseInterface::class);
    }
}
