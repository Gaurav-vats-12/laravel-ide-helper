<?php

namespace Barryvdh\LaravelIdeHelper\Database;

use Barryvdh\LaravelIdeHelper\Database\Concerns\ConnectsToDatabase;
use Doctrine\DBAL\Driver\AbstractMySQLDriver;

/**
 * Migrated classes removed in Laravel 11 from Laravel 10.
 *
 * @see https://github.com/laravel/framework/blob/v10.43.0/src/Illuminate/Database/PDO/MySqlDriver.php
 */
class MySqlDriver extends AbstractMySQLDriver
{
    use ConnectsToDatabase;

    public function getName()
    {
        return 'pdo_mysql';
    }
}
