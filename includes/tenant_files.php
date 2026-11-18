<?php

function tenant_file_categories(): array {
    return ['Profile', 'Acknowledgment_Receipts', 'Invoices', 'Payments', 'Contracts', 'Valid_IDs', 'Violations', 'Other_Documents'];
}

function tenant_file_folder(PDO $pdo, int $profileId): string {
    if ($profileId <= 0) throw new RuntimeException('Invalid tenant profile.');
    $st = $pdo->prepare('SELECT full_name FROM tenant_profiles WHERE id=?');
    $st->execute([$profileId]);
    $name = $st->fetchColumn();
    if ($name === false) throw new RuntimeException('Tenant profile not found.');
    $slug = trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower((string)$name)), '-');
    $folder = substr($slug !== '' ? $slug : 'tenant', 0, 80) . '-' . $profileId;
    $pdo->prepare('INSERT INTO tenant_file_folders (tenant_profile_id, folder_name) VALUES (?,?) ON DUPLICATE KEY UPDATE tenant_profile_id=tenant_profile_id')
        ->execute([$profileId, $folder]);
    $st = $pdo->prepare('SELECT folder_name FROM tenant_file_folders WHERE tenant_profile_id=?');
    $st->execute([$profileId]);
    $folder = (string)$st->fetchColumn();
    if (!preg_match('/\A[a-z0-9-]+-[1-9][0-9]*\z/', $folder)) throw new RuntimeException('Invalid tenant folder configuration.');
    foreach (tenant_file_categories() as $category) {
        $directory = __DIR__ . '/../secret/tenant-files/' . $folder . '/' . $category;
        if (!is_dir($directory) && !@mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException('Could not create the tenant file folder. Check private storage permissions.');
        }
    }
    return $folder;
}

function tenant_file_new_path(PDO $pdo, int $profileId, string $category, string $extension): string {
    if (!in_array($category, tenant_file_categories(), true) || !preg_match('/\A[a-z0-9]{1,10}\z/', $extension)) {
        throw new RuntimeException('Invalid tenant file category or extension.');
    }
    return '@tenant/' . tenant_file_folder($pdo, $profileId) . '/' . $category . '/' . bin2hex(random_bytes(16)) . '.' . $extension;
}

function tenant_file_resolve(string $stored, string $legacyDirectory): string {
    if (str_starts_with($stored, '@tenant/')) {
        $categories = implode('|', array_map('preg_quote', tenant_file_categories()));
        if (!preg_match('~\A@tenant/([a-z0-9-]+-[1-9][0-9]*)/(' . $categories . ')/([a-f0-9]{32}\.[a-z0-9]{1,10})\z~', $stored, $parts)) {
            throw new RuntimeException('Invalid tenant file path.');
        }
        return __DIR__ . '/../secret/tenant-files/' . $parts[1] . '/' . $parts[2] . '/' . $parts[3];
    }
    if ($stored === '' || basename($stored) !== $stored || $stored === '.' || $stored === '..') throw new RuntimeException('Invalid legacy file path.');
    return rtrim($legacyDirectory, '/\\') . DIRECTORY_SEPARATOR . $stored;
}

function tenant_file_store_upload(PDO $pdo, int $profileId, string $category, array $file): string {
    $extension = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
    $stored = tenant_file_new_path($pdo, $profileId, $category, $extension);
    if (!move_uploaded_file($file['tmp_name'], tenant_file_resolve($stored, ''))) throw new RuntimeException('Could not save the tenant file.');
    return $stored;
}

function tenant_file_remove(string $stored, string $legacyDirectory): void {
    $path = tenant_file_resolve($stored, $legacyDirectory);
    if (is_file($path) && !unlink($path)) error_log('Could not remove a tenant file.');
}
