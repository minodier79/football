<?php 
namespace App\Config;

class DatabaseConfig
{
    public function __construct(
        public readonly string $dsn,
        public readonly string $user,
        public readonly string $pass
    ) {}
}