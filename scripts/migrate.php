<?php
// Apply idempotent schema upgrades to an existing database.
require_once __DIR__ . '/../config/database.php';

$migrations = [
    __DIR__ . '/../database/13-tenant-applications.sql',
    __DIR__ . '/../database/14-units.sql',
    __DIR__ . '/../database/15-housekeeping-units.sql',
];

foreach ($migrations as $sqlFile) {
    $sql = file_get_contents($sqlFile);
    if ($sql === false) {
        throw new RuntimeException("Unable to read migration: $sqlFile");
    }

    $statements = array_filter(array_map('trim', explode(";\n", $sql)));
    foreach ($statements as $statement) {
        if ($statement !== '') {
            db()->exec($statement);
        }
    }
    echo 'OK: database/' . basename($sqlFile) . "\n";
}
