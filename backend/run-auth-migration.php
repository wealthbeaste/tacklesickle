<?php
declare(strict_types=1);
$dbh = new PDO("pgsql:host=127.0.0.1;port=5433;dbname=tsca_registry", "tsca_admin", "tsca_dev_password", [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$sql = file_get_contents(__DIR__ . '/../database/auth-migration.sql');
$dbh->exec($sql);
echo "Auth migration applied successfully.\n";

// Verify
$users = $dbh->query("SELECT id, username, role, status, last_login_at FROM tsca_users")->fetchAll(PDO::FETCH_ASSOC);
echo "\n=== Users after migration ===\n";
foreach ($users as $u) echo "  ID={$u['id']} user={$u['username']} role={$u['role']} status={$u['status']} last_login={$u['last_login_at']}\n";

$cols = $dbh->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'user_sessions' ORDER BY ordinal_position")->fetchAll(PDO::FETCH_COLUMN);
echo "\n=== user_sessions columns ===\n";
echo implode(', ', $cols) . "\n";

$sc = $dbh->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'screenings' ORDER BY ordinal_position")->fetchAll(PDO::FETCH_COLUMN);
echo "\n=== screenings columns (including review) ===\n";
echo implode(', ', $sc) . "\n";
