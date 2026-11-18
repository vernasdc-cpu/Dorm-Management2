<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/tenant_files.php';
if (APP_ENV !== 'testing' || getenv('TENANT_FOLDER_TEST') !== '1') {
    throw new RuntimeException('Run this test only against a disposable test database and private storage.');
}
$pdo = db();
$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};
$insertProfile = $pdo->prepare("INSERT INTO tenant_profiles (full_name, photo_path) VALUES (?,?)");
$insertProfile->execute(['Folder Test', 'folder-test-photo.jpg']);
$profileId = (int)$pdo->lastInsertId();
$insertProfile->execute(['Folder Test', 'folder-test-photo.jpg']);
$otherId = (int)$pdo->lastInsertId();
$folder = tenant_file_folder($pdo, $profileId);
$otherFolder = tenant_file_folder($pdo, $otherId);
$assert($folder !== $otherFolder, 'Profiles with the same name must have separate folders.');
$pdo->prepare('UPDATE tenant_profiles SET full_name=? WHERE id=?')->execute(['Renamed Test', $profileId]);
$assert(tenant_file_folder($pdo, $profileId) === $folder, 'Renaming a profile must not change its folder.');
foreach (tenant_file_categories() as $category) {
    $assert(is_dir(__DIR__ . '/../secret/tenant-files/' . $folder . '/' . $category), 'A category folder is missing.');
}
foreach (['@tenant/../Profile/a.jpg', '@tenant/test-1/Profile/../../a.jpg', '../outside.jpg'] as $invalid) {
    try {
        tenant_file_resolve($invalid, __DIR__ . '/../uploads/tenants');
        throw new LogicException('An unsafe path was accepted.');
    } catch (RuntimeException $expected) {}
}
$legacyDir = __DIR__ . '/../uploads/tenants';
if (!is_dir($legacyDir) && !mkdir($legacyDir, 0775, true) && !is_dir($legacyDir)) throw new RuntimeException('Could not create fixture directory.');
file_put_contents($legacyDir . '/folder-test-photo.jpg', 'test photo');
$suffix = bin2hex(random_bytes(5));
$roomId = (int)$pdo->query('SELECT id FROM rooms ORDER BY id LIMIT 1')->fetchColumn();
if (!$roomId) throw new RuntimeException('The isolated database needs a room fixture.');
$pdo->prepare("INSERT INTO users (name,email,password_hash,role,tenant_profile_id) VALUES (?,?,?,'resident',?)")
    ->execute(['Folder Test', $suffix . '@example.test', password_hash('test-password', PASSWORD_DEFAULT), $profileId]);
$userId = (int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO residents (user_id,room_id) VALUES (?,?)')->execute([$userId, $roomId]);
$residentId = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO contracts (contract_number,resident_id,room_id,start_date,end_date) VALUES (?,?,?,'2026-01-01','2027-01-01')")
    ->execute(['FOLDER-' . $suffix, $residentId, $roomId]);
$contractId = (int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO contract_documents (contract_id,file_name,file_path) VALUES (?,?,?)')
    ->execute([$contractId, 'contract.pdf', $suffix . '-contract.pdf']);
$contractDocId = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO tenant_billing_payments (tenant_profile_id,resident_id,user_id,full_name,date_of_payment,screenshot_path) VALUES (?,?,?,'Folder Test','2026-01-01',?)")
    ->execute([$profileId, $residentId, $userId, $suffix . '-proof.jpg']);
$submissionId = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO tenant_billing_payments (full_name,date_of_payment,screenshot_path) VALUES ('Unlinked','2026-01-01',?)")
    ->execute([$suffix . '-proof.jpg']);
$pdo->prepare('INSERT INTO approved_billing_documents (submission_id,user_id,invoice_path,proof_path,created_at) VALUES (?,?,?,?,NOW())')
    ->execute([$submissionId, $userId, $suffix . '-invoice.pdf', $suffix . '-approved.jpg']);
$approvedId = (int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO tenant_reports (report_ref,user_id,tenant_profile_id,category,subject,description,attachment_path) VALUES (?,?,?,?,?,?,?)')
    ->execute(['REPORT-' . $suffix, $userId, $profileId, 'Other', 'Test', 'Test', $suffix . '-report.jpg']);
$reportId = (int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO repair_requests (report_id,resident_id,room_id,category,description,photo_path) VALUES (?,?,?,?,?,?)')
    ->execute(['REPAIR-' . $suffix, $residentId, $roomId, 'Other', 'Test', $suffix . '-repair.jpg']);
$repairId = (int)$pdo->lastInsertId();
$fixtures = [
    ['contracts', $suffix . '-contract.pdf', 'contract_documents', 'file_path', $contractDocId, 'Contracts'],
    ['billing', $suffix . '-proof.jpg', 'tenant_billing_payments', 'screenshot_path', $submissionId, 'Payments'],
    ['billing', $suffix . '-invoice.pdf', 'approved_billing_documents', 'invoice_path', $approvedId, 'Invoices'],
    ['billing', $suffix . '-approved.jpg', 'approved_billing_documents', 'proof_path', $approvedId, 'Payments'],
    ['reports', $suffix . '-report.jpg', 'tenant_reports', 'attachment_path', $reportId, 'Other_Documents'],
    ['repairs', $suffix . '-repair.jpg', 'repair_requests', 'photo_path', $repairId, 'Other_Documents'],
];
foreach ($fixtures as [$directory, $name]) {
    $dir = __DIR__ . '/../uploads/' . $directory;
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) throw new RuntimeException('Could not create fixture directory.');
    if (file_put_contents($dir . '/' . $name, 'fixture-' . $name) === false) throw new RuntimeException('Could not create fixture file.');
}
$command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../scripts/move-tenant-files.php');
exec($command, $dryOutput, $dryCode);
$assert($dryCode === 0 && is_file($legacyDir . '/folder-test-photo.jpg'), 'Dry run must not move files.');
exec($command . ' --apply', $output, $code);
$assert($code === 0, 'Tenant file migration failed: ' . implode("\n", $output));
$st = $pdo->prepare('SELECT photo_path FROM tenant_profiles WHERE id=?');
$st->execute([$profileId]);
$path = (string)$st->fetchColumn();
$assert(str_starts_with($path, '@tenant/' . $folder . '/Profile/'), 'Photo was not moved into the profile folder.');
$assert(file_get_contents(tenant_file_resolve($path, $legacyDir)) === 'test photo', 'Moved photo contents changed.');
$st->execute([$otherId]);
$otherPath = (string)$st->fetchColumn();
$assert($path !== $otherPath && file_get_contents(tenant_file_resolve($otherPath, $legacyDir)) === 'test photo', 'A shared legacy file was not preserved for both profiles.');
clearstatcache(true, $legacyDir . '/folder-test-photo.jpg');
$assert(!is_file($legacyDir . '/folder-test-photo.jpg'), 'Original photo was not removed.');
foreach ($fixtures as [$directory, $name, $table, $column, $id, $category]) {
    $st = $pdo->prepare("SELECT $column FROM $table WHERE id=?");
    $st->execute([$id]);
    $movedPath = (string)$st->fetchColumn();
    $assert(str_starts_with($movedPath, '@tenant/' . $folder . '/' . $category . '/'), 'File was moved to the wrong category.');
    $assert(file_get_contents(tenant_file_resolve($movedPath, '')) === 'fixture-' . $name, 'Moved file contents changed.');
    $oldPath = __DIR__ . '/../uploads/' . $directory . '/' . $name;
    clearstatcache(true, $oldPath);
    $assert(is_file($oldPath) === ($name === $suffix . '-proof.jpg'), 'Shared or migrated original was not handled correctly.');
}
exec($command . ' --apply', $repeatOutput, $repeatCode);
$assert($repeatCode === 0 && is_file(tenant_file_resolve($path, $legacyDir)), 'Migration must be safe to repeat.');
$missingName = $suffix . '-missing.jpg';
$pdo->prepare('UPDATE tenant_profiles SET photo_path=? WHERE id=?')->execute([$missingName, $otherId]);
exec($command . ' --apply 2>&1', $missingOutput, $missingCode);
$st = $pdo->prepare('SELECT photo_path FROM tenant_profiles WHERE id=?');
$st->execute([$otherId]);
$assert($missingCode !== 0 && $st->fetchColumn() === $missingName, 'Missing originals must be reported without changing the record.');
file_put_contents($legacyDir . '/' . $missingName, 'recovered photo');
exec($command . ' --apply', $recoveryOutput, $recoveryCode);
$assert($recoveryCode === 0, 'Migration did not recover after the missing file was restored.');
echo "Tenant folder migration tests passed.\n";
