<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/tenant_files.php';

$apply = in_array('--apply', $argv, true);
if ($apply && function_exists('posix_geteuid') && posix_geteuid() === 0) {
    throw new RuntimeException('Run the file migration as the web-server user, not root.');
}
$pdo = db();
$sources = [
    ['table' => 'tenant_profiles', 'column' => 'photo_path', 'category' => 'Profile', 'directory' => 'tenants',
     'sql' => 'SELECT id, id AS profile_id, photo_path AS path FROM tenant_profiles WHERE photo_path IS NOT NULL'],
    ['table' => 'contract_documents', 'column' => 'file_path', 'category' => 'Contracts', 'directory' => 'contracts',
     'sql' => 'SELECT d.id, COALESCE(c.tenant_profile_id,u.tenant_profile_id) AS profile_id, d.file_path AS path FROM contract_documents d JOIN contracts c ON c.id=d.contract_id LEFT JOIN residents r ON r.residents_id=c.resident_id LEFT JOIN users u ON u.id=r.user_id'],
    ['table' => 'tenant_billing_payments', 'column' => 'screenshot_path', 'category' => 'Payments', 'directory' => 'billing',
     'sql' => 'SELECT s.id, COALESCE(s.tenant_profile_id,u.tenant_profile_id) AS profile_id, s.screenshot_path AS path FROM tenant_billing_payments s LEFT JOIN users u ON u.id=s.user_id WHERE s.screenshot_path IS NOT NULL'],
    ['table' => 'approved_billing_documents', 'column' => 'invoice_path', 'category' => 'Invoices', 'directory' => 'billing',
     'sql' => 'SELECT d.id, COALESCE(s.tenant_profile_id,u.tenant_profile_id) AS profile_id, d.invoice_path AS path FROM approved_billing_documents d JOIN tenant_billing_payments s ON s.id=d.submission_id LEFT JOIN users u ON u.id=s.user_id'],
    ['table' => 'approved_billing_documents', 'column' => 'proof_path', 'category' => 'Payments', 'directory' => 'billing',
     'sql' => 'SELECT d.id, COALESCE(s.tenant_profile_id,u.tenant_profile_id) AS profile_id, d.proof_path AS path FROM approved_billing_documents d JOIN tenant_billing_payments s ON s.id=d.submission_id LEFT JOIN users u ON u.id=s.user_id'],
    ['table' => 'tenant_reports', 'column' => 'attachment_path', 'category' => 'Other_Documents', 'directory' => 'reports',
     'sql' => 'SELECT tr.id, COALESCE(tr.tenant_profile_id,u.tenant_profile_id) AS profile_id, tr.attachment_path AS path FROM tenant_reports tr LEFT JOIN users u ON u.id=tr.user_id WHERE tr.attachment_path IS NOT NULL'],
    ['table' => 'repair_requests', 'column' => 'photo_path', 'category' => 'Other_Documents', 'directory' => 'repairs',
     'sql' => 'SELECT rr.id, u.tenant_profile_id AS profile_id, rr.photo_path AS path FROM repair_requests rr JOIN residents r ON r.residents_id=rr.resident_id LEFT JOIN users u ON u.id=r.user_id WHERE rr.photo_path IS NOT NULL'],
];
$candidates = 0;
$moved = 0;
$unlinked = 0;
$errors = 0;

if ($apply) {
    foreach ($pdo->query('SELECT id FROM tenant_profiles ORDER BY id') as $profile) {
        tenant_file_folder($pdo, (int)$profile['id']);
    }
}
foreach ($sources as $source) {
    foreach ($pdo->query($source['sql'])->fetchAll() as $row) {
        $old = (string)($row['path'] ?? '');
        if ($old === '' || str_starts_with($old, '@tenant/')) continue;
        if ((int)$row['profile_id'] <= 0) { $unlinked++; continue; }
        $candidates++;
        if (!$apply) continue;
        $stored = null;
        try {
            $legacyDir = __DIR__ . '/../uploads/' . $source['directory'];
            $original = tenant_file_resolve($old, $legacyDir);
            if (!is_file($original)) throw new RuntimeException('Original file is missing.');
            $extension = strtolower(pathinfo($old, PATHINFO_EXTENSION));
            $stored = tenant_file_new_path($pdo, (int)$row['profile_id'], $source['category'], $extension);
            $destination = tenant_file_resolve($stored, '');
            if (!copy($original, $destination)) throw new RuntimeException('Could not copy the original file.');
            $sourceHash = hash_file('sha256', $original);
            $destinationHash = hash_file('sha256', $destination);
            if ($sourceHash === false || $destinationHash === false || $sourceHash !== $destinationHash) {
                throw new RuntimeException('Could not copy and verify the original file.');
            }
            $pdo->beginTransaction();
            $update = $pdo->prepare('UPDATE ' . $source['table'] . ' SET ' . $source['column'] . '=? WHERE id=? AND ' . $source['column'] . '=?');
            $update->execute([$stored, (int)$row['id'], $old]);
            if ($update->rowCount() !== 1) throw new RuntimeException('The file record changed during migration.');
            $pdo->commit();
            $stored = null;
            $moved++;
            $references = 0;
            foreach ($sources as $other) {
                if ($other['directory'] !== $source['directory']) continue;
                $check = $pdo->prepare('SELECT COUNT(*) FROM ' . $other['table'] . ' WHERE ' . $other['column'] . '=?');
                $check->execute([$old]);
                $references += (int)$check->fetchColumn();
            }
            if ($references === 0 && is_file($original) && !unlink($original)) throw new RuntimeException('The verified original could not be removed.');
        } catch (Throwable $t) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($stored !== null) tenant_file_remove($stored, '');
            $errors++;
            fwrite(STDERR, 'Could not move ' . $source['table'] . ' record #' . (int)$row['id'] . ': ' . $t->getMessage() . "\n");
        }
    }
}
echo ($apply ? 'Moved' : 'Files eligible to move') . ': ' . ($apply ? $moved : $candidates) . "; unlinked files left in place: $unlinked; errors: $errors\n";
if (!$apply) echo "Dry run only. Back up the database, uploads and private storage, then rerun with --apply.\n";
if ($errors > 0) exit(1);
