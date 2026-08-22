<?php

declare(strict_types=1);

$host = '127.0.0.1';
$port = '5433';
$dbname = 'tsca_registry';
$user = 'tsca_admin';
$password = 'tsca_dev_password';

try {
    $pdo = new PDO(
        "pgsql:host={$host};port={$port};dbname={$dbname}",
        $user,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    echo "SUCCESS: PHP connected to PostgreSQL.\n";

    $result = $pdo->query('SELECT version()');
    $row = $result->fetch();

    echo "Database: {$dbname}\n";
    echo "PostgreSQL: {$row['version']}\n";

} catch (PDOException $e) {
    echo "DATABASE CONNECTION FAILED\n";
    echo $e->getMessage() . "\n";
    exit(1);
}