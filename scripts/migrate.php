<?php
// Apply idempotent schema upgrades to an existing database.
require_once __DIR__ . '/../config/database.php';

$migrations = [
    __DIR__ . '/../database/08-tenant-billing-payments.sql',
    __DIR__ . '/../database/13-tenant-applications.sql',
    __DIR__ . '/../database/14-units.sql',
    __DIR__ . '/../database/15-housekeeping-units.sql',
    __DIR__ . '/../database/16-tenant-reports.sql',
    __DIR__ . '/../database/17-tenant-notification-retention.sql',
    __DIR__ . '/../database/18-login-rate-limits.sql',
    __DIR__ . '/../database/19-housekeeping-others-checklist.sql',
    __DIR__ . '/../database/20-housekeeping-daily-checklists.sql',
    __DIR__ . '/../database/21-reuse-deleted-user-emails.sql',
    __DIR__ . '/../database/22-tenant-acknowledgment-receipts.sql',
    __DIR__ . '/../database/23-acknowledgment-bank-reference.sql',
    __DIR__ . '/../database/24-reservations.sql',
    __DIR__ . '/../database/25-reservation-conversion.sql',
    __DIR__ . '/../database/26-bank-references.sql',
    __DIR__ . '/../database/27-reservation-full-receipts.sql',
    __DIR__ . '/../database/28-approved-billing-documents.sql',
    __DIR__ . '/../database/29-tenant-folders.sql',
    __DIR__ . '/../database/30-announcements.sql',
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
