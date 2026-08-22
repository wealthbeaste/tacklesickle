<?php

declare(strict_types=1);

final class Database
{
    private PDO $connection;

public function __construct()
{
    $host = getenv('PGHOST') ?: getenv('DB_HOST') ?: '127.0.0.1';
    $port = getenv('PGPORT') ?: getenv('DB_PORT') ?: '5432';
    $database = getenv('PGDATABASE') ?: getenv('DB_NAME') ?: 'tsca_registry';
    $username = getenv('PGUSER') ?: getenv('DB_USER') ?: 'tsca_admin';
    $password = getenv('PGPASSWORD') ?: getenv('DB_PASSWORD') ?: '';

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