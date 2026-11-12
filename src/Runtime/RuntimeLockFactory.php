<?php

namespace App\Runtime;

use Doctrine\DBAL\Connection;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\DoctrineDbalPostgreSqlStore;

final class RuntimeLockFactory extends LockFactory
{
    public function __construct(Connection $connection)
    {
        parent::__construct(new DoctrineDbalPostgreSqlStore($connection));
    }
}
