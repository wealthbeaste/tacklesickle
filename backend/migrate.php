<?php
declare(strict_types=1);

require_once __DIR__ . '/src/config.php';
require_once __DIR__ . '/src/Database.php';

Config::load(__DIR__ . '/.env');

$debug = filter_var(Config::get('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOLEAN);

function splitStatements(string $sql): array
{
    $statements = [];
    $current = '';
    $inDollarQuote = false;

    $len = strlen($sql);
    for ($i = 0; $i < $len; $i++) {
        $char = $sql[$i];

        if ($char === '$' && !$inDollarQuote) {
            $tag = '$' . $sql[$i + 1] ?? '';
            if (str_starts_with(substr($sql, $i), '$$')) {
                $inDollarQuote = true;
                $current .= '$$';
                $i++;
                continue;
            }
        } elseif ($char === '$' && $inDollarQuote) {
            if (str_starts_with(substr($sql, $i), '$$')) {
                $inDollarQuote = false;
                $current .= '$$';
                $i++;
                continue;
            }
        }

        if ($char === ';' && !$inDollarQuote) {
            $trimmed = trim($current);
            if ($trimmed !== '') {
                $statements[] = $trimmed;
            }
            $current = '';
            continue;
        }

        $current .= $char;
    }

    $trimmed = trim($current);
    if ($trimmed !== '') {
        $statements[] = $trimmed;
    }

    return $statements;
}

try {
    $db = (new Database())->getConnection();

    $db->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
        id SERIAL PRIMARY KEY,
        name VARCHAR(255) NOT NULL UNIQUE,
        applied_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
    )");

    $applied = [];
    $rows = $db->query("SELECT name FROM schema_migrations ORDER BY id")->fetchAll();
    foreach ($rows as $row) {
        $applied[] = $row['name'];
    }

    $migrations = [
        '001_schema' => __DIR__ . '/../database/schema.sql',
        '002_auth_migration' => __DIR__ . '/../database/auth-migration.sql',
    ];

    foreach ($migrations as $name => $file) {
        if (in_array($name, $applied, true)) {
            continue;
        }
        if (!is_file($file)) {
            if ($debug) echo "Migration file not found: {$file}\n";
            continue;
        }

        $sql = file_get_contents($file);
        if ($sql === false) continue;

        $statements = splitStatements($sql);
        $failed = false;

        $db->beginTransaction();
        try {
            foreach ($statements as $idx => $stmt) {
                try {
                    $db->exec($stmt);
                } catch (Throwable $e) {
                    if ($debug) echo "  Statement {$idx} warning: " . $e->getMessage() . "\n";
                }
            }
            $ins = $db->prepare("INSERT INTO schema_migrations (name) VALUES (:name)");
            $ins->execute([':name' => $name]);
            $db->commit();
            echo "Migration [{$name}] applied successfully.\n";
        } catch (Throwable $e) {
            $db->rollBack();
            echo "Migration [{$name}] failed: " . $e->getMessage() . "\n";
            $failed = true;
        }
    }

    echo "Migrations complete.\n";
} catch (Throwable $e) {
    echo "Migration runner error: " . $e->getMessage() . "\n";
}
