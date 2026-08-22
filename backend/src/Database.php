<?php

declare(strict_types=1);

final class Database
{
    private PDO $connection;

public function __construct()
{
    $host = getenv('DB_HOST') ?: getenv('PGHOST') ?: '127.0.0.1';
    $port = getenv('DB_PORT') ?: getenv('PGPORT') ?: '5432';
    $database = getenv('DB_NAME') ?: getenv('PGDATABASE') ?: 'tsca_registry';
    $username = getenv('DB_USER') ?: getenv('PGUSER') ?: 'tsca_admin';
    $password = getenv('DB_PASSWORD') ?: getenv('PGPASSWORD') ?: '';

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