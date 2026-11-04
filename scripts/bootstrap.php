<?php
// First-run bootstrap: seeds demo data only when the database has no users yet.
// Used automatically by the Docker entrypoint; safe to run any number of times.
require_once __DIR__ . '/../config/database.php';

$hasUsers = false;
try {
    $hasUsers = (int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0;
} catch (Throwable $t) {
    // Database/table not ready yet — let the installer create the schema.
}

if ($hasUsers) {
    require __DIR__ . '/migrate.php';
    echo "Users already exist, skipping bootstrap.\n";
    exit(0);
}

require __DIR__ . '/install.php';
