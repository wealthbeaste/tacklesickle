<?php

declare(strict_types=1);

final class Database
{
    private PDO $connection;

    public function __construct()
    {
        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $port = getenv('DB_PORT') ?: '5433';
        $database = getenv('DB_NAME') ?: 'tsca_registry';
        $username = getenv('DB_USER') ?: 'tsca_admin';
        $password = getenv('DB_PASSWORD') ?: '';

        $dsn = "pgsql:host={$host};port={$port};dbname={$database}";

        $this->connection = new PDO(
            $dsn,
            $username,
            $password,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
    }

    public function getConnection(): PDO
    {
        return $this->connection;
    }
}