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
    
    echo "Connected to PostgreSQL.\n";
    
    $schema = file_get_contents(__DIR__ . '/../database/schema.sql');
    $pdo->exec($schema);
    echo "Schema applied successfully.\n";
    
    // Verify tables
    $tables = $pdo->query("SELECT tablename FROM pg_tables WHERE schemaname = 'public' ORDER BY tablename")->fetchAll();
    echo "\nTables:\n";
    foreach ($tables as $t) {
        echo "  - {$t['tablename']}\n";
    }
    
    // Verify admin user
    $admin = $pdo->query("SELECT username, role FROM tsca_users WHERE username = 'admin'")->fetch();
    if ($admin) {
        echo "\nAdmin user: {$admin['username']} (role: {$admin['role']})\n";
    }
    
} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
