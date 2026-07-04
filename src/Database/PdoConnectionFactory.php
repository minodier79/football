<?php
namespace App\Database;

use App\Config\DatabaseConfig;

class PdoConnectionFactory
{
    public static function fromConfig(DatabaseConfig $config): PdoConnection
    {
        return new PdoConnection(
            $config->dsn,
            $config->user,
            $config->pass
        );
    }
}