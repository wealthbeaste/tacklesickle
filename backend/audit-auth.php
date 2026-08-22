<?php
declare(strict_types=1);
$dbh = new PDO("pgsql:host=127.0.0.1;port=5433;dbname=tsca_registry", "tsca_admin", "tsca_dev_password", [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$cols = $dbh->query("SELECT column_name, data_type, is_nullable, column_default FROM information_schema.columns WHERE table_name = 'tsca_users' ORDER BY ordinal_position")->fetchAll(PDO::FETCH_ASSOC);
echo "=== tsca_users columns ===\n";
foreach ($cols as $c) echo "  {$c['column_name']}: {$c['data_type']} (null={$c['is_nullable']}, default={$c['column_default']})\n";

$users = $dbh->query("SELECT id, username, full_name, role FROM tsca_users")->fetchAll(PDO::FETCH_ASSOC);
echo "\n=== Existing users ===\n";
foreach ($users as $u) echo "  ID={$u['id']} user={$u['username']} name={$u['full_name']} role={$u['role']}\n";

$tables = $dbh->query("SELECT tablename FROM pg_tables WHERE schemaname='public'")->fetchAll(PDO::FETCH_COLUMN);
echo "\n=== All tables ===\n";
echo implode(', ', $tables) . "\n";

// Check if columns exist on participants
$pc = $dbh->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'participants' ORDER BY ordinal_position")->fetchAll(PDO::FETCH_COLUMN);
echo "\n=== participants columns ===\n";
echo implode(', ', $pc) . "\n";

$sc = $dbh->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'screenings' ORDER BY ordinal_position")->fetchAll(PDO::FETCH_COLUMN);
echo "\n=== screenings columns ===\n";
echo implode(', ', $sc) . "\n";
