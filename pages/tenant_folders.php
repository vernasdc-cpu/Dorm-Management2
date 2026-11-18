<?php
$pdo = db();
$profileId = (int)($_GET['id'] ?? 0);
$error = null;
$entries = array_fill_keys(tenant_file_categories(), []);

try {
    if ($profileId <= 0) {
        $profiles = $pdo->query('SELECT id, full_name, unit_number, profile_status FROM tenant_profiles ORDER BY full_name, id')->fetchAll();
    } else {
        $st = $pdo->prepare('SELECT * FROM tenant_profiles WHERE id=?');
        $st->execute([$profileId]);
        $profile = $st->fetch();
        if (!$profile) {
            http_response_code(404);
            throw new RuntimeException('Tenant profile not found.');
        }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            csrf_check();
            $category = is_string($_POST['category'] ?? null) ? $_POST['category'] : '';
            if (!in_array($category, tenant_file_categories(), true)) throw new RuntimeException('Select a valid folder.');
            $file = $_FILES['document'] ?? null;
            if (!$file) throw new RuntimeException('Select a file to upload.');
            $validation = validate_upload($file, ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'webp'], 5);
            if ($validation) throw new RuntimeException('Upload failed: ' . $validation);
            $stored = tenant_file_store_upload($pdo, $profileId, $category, $file);
            try {
                $st = $pdo->prepare('INSERT INTO tenant_file_uploads (tenant_profile_id, category, file_name, file_path, uploaded_by) VALUES (?,?,?,?,?)');
                $st->execute([$profileId, $category, basename((string)$file['name']), $stored, (int)$user['id']]);
            } catch (Throwable $t) {
                tenant_file_remove($stored, '');
                throw $t;
            }
            audit_log((int)$user['id'], 'Uploaded tenant folder document', 'tenants', (string)$profileId);
            flash('success', 'Document saved in the tenant folder.');
            redirect('index.php?page=tenant_folders&id=' . $profileId);
        }
        $add = static function (string $category, string $label, string $url) use (&$entries): void {
            $entries[$category][] = ['label' => $label, 'url' => $url];
        };
        $st = $pdo->prepare('SELECT id, category, file_name FROM tenant_file_uploads WHERE tenant_profile_id=? ORDER BY id DESC');
        $st->execute([$profileId]);
        foreach ($st as $row) {
            if (isset($entries[$row['category']])) $add($row['category'], $row['file_name'], 'index.php?page=download&type=tenant_file&id=' . (int)$row['id']);
        }
        if (!empty($profile['photo_path'])) $add('Profile', '2x2 profile photo', 'index.php?page=download&type=tenant_photo&id=' . $profileId);
        $add('Profile', 'Tenant profile', 'index.php?page=tenants&q=' . rawurlencode((string)$profile['full_name']) . '#tenant-' . $profileId);
        $st = $pdo->prepare('SELECT id FROM tenant_acknowledgment_receipts WHERE tenant_profile_id=?');
        $st->execute([$profileId]);
        foreach ($st as $row) $add('Acknowledgment_Receipts', 'Tenant acknowledgment receipt', 'index.php?page=acknowledgment_receipt&id=' . (int)$row['id']);
        $st = $pdo->prepare("SELECT id FROM reservations WHERE tenant_profile_id=? AND status='Converted'");
        $st->execute([$profileId]);
        foreach ($st as $row) $add('Acknowledgment_Receipts', 'Reservation acknowledgment receipt', 'index.php?page=acknowledgment_receipt&type=reservation&id=' . (int)$row['id']);
        $st = $pdo->prepare("SELECT p.id FROM reservation_additional_payments p JOIN reservations r ON r.id=p.reservation_id WHERE r.tenant_profile_id=? AND r.status='Converted'");
        $st->execute([$profileId]);
        foreach ($st as $row) $add('Acknowledgment_Receipts', 'Reservation completion receipt', 'index.php?page=acknowledgment_receipt&type=reservation_payment&id=' . (int)$row['id']);
        $st = $pdo->prepare('SELECT b.id, b.invoice_number FROM bills b JOIN residents r ON r.residents_id=b.resident_id JOIN users u ON u.id=r.user_id WHERE u.tenant_profile_id=? ORDER BY b.id DESC');
        $st->execute([$profileId]);
        foreach ($st as $row) $add('Invoices', 'Invoice ' . ($row['invoice_number'] ?: '#' . $row['id']), 'index.php?page=invoice&id=' . (int)$row['id']);
        $st = $pdo->prepare('SELECT p.id, p.receipt_number FROM payments p JOIN residents r ON r.residents_id=p.resident_id JOIN users u ON u.id=r.user_id WHERE u.tenant_profile_id=? ORDER BY p.id DESC');
        $st->execute([$profileId]);
        foreach ($st as $row) $add('Payments', 'Payment receipt ' . ($row['receipt_number'] ?: '#' . $row['id']), 'index.php?page=receipt&id=' . (int)$row['id']);
        $st = $pdo->prepare('SELECT s.id FROM tenant_billing_payments s LEFT JOIN users u ON u.id=s.user_id WHERE COALESCE(s.tenant_profile_id,u.tenant_profile_id)=? AND s.screenshot_path IS NOT NULL ORDER BY s.id DESC');
        $st->execute([$profileId]);
        foreach ($st as $row) $add('Payments', 'Submitted payment screenshot #' . $row['id'], 'index.php?page=download&type=billing_proof&pid=' . (int)$row['id']);
        $st = $pdo->prepare('SELECT d.id FROM approved_billing_documents d JOIN tenant_billing_payments s ON s.id=d.submission_id LEFT JOIN users u ON u.id=s.user_id WHERE COALESCE(s.tenant_profile_id,u.tenant_profile_id)=? ORDER BY d.id DESC');
        $st->execute([$profileId]);
        foreach ($st as $row) {
            $add('Invoices', 'Approved invoice PDF #' . $row['id'], 'index.php?page=download&type=approved_invoice&id=' . (int)$row['id']);
            $add('Payments', 'Approved payment screenshot #' . $row['id'], 'index.php?page=download&type=approved_proof&id=' . (int)$row['id']);
        }
        $st = $pdo->prepare('SELECT c.id, c.contract_number FROM contracts c LEFT JOIN residents r ON r.residents_id=c.resident_id LEFT JOIN users u ON u.id=r.user_id WHERE COALESCE(c.tenant_profile_id,u.tenant_profile_id)=? ORDER BY c.id DESC');
        $st->execute([$profileId]);
        foreach ($st as $row) $add('Contracts', 'Contract ' . $row['contract_number'], 'index.php?page=contracts&q=' . rawurlencode((string)$row['contract_number']) . '#contract-' . (int)$row['id']);
        $st = $pdo->prepare('SELECT d.id, d.file_name FROM contract_documents d JOIN contracts c ON c.id=d.contract_id LEFT JOIN residents r ON r.residents_id=c.resident_id LEFT JOIN users u ON u.id=r.user_id WHERE COALESCE(c.tenant_profile_id,u.tenant_profile_id)=? ORDER BY d.id DESC');
        $st->execute([$profileId]);
        foreach ($st as $row) $add('Contracts', $row['file_name'], 'index.php?page=download&type=contract&id=' . (int)$row['id']);
        $st = $pdo->prepare('SELECT id, violation_ref, category FROM violations WHERE tenant_profile_id=? ORDER BY id DESC');
        $st->execute([$profileId]);
        foreach ($st as $row) $add('Violations', $row['violation_ref'] . ' - ' . $row['category'], 'index.php?page=violations#violation-' . (int)$row['id']);
        $st = $pdo->prepare('SELECT tr.id, tr.report_ref FROM tenant_reports tr LEFT JOIN users u ON u.id=tr.user_id WHERE COALESCE(tr.tenant_profile_id,u.tenant_profile_id)=? AND tr.attachment_path IS NOT NULL ORDER BY tr.id DESC');
        $st->execute([$profileId]);
        foreach ($st as $row) $add('Other_Documents', 'Report attachment ' . $row['report_ref'], 'index.php?page=download&type=report_attachment&id=' . (int)$row['id']);
        $st = $pdo->prepare('SELECT rr.id, rr.report_id FROM repair_requests rr JOIN residents r ON r.residents_id=rr.resident_id JOIN users u ON u.id=r.user_id WHERE u.tenant_profile_id=? AND rr.photo_path IS NOT NULL ORDER BY rr.id DESC');
        $st->execute([$profileId]);
        foreach ($st as $row) $add('Other_Documents', 'Repair photo ' . $row['report_id'], 'index.php?page=download&type=repair_photo&id=' . (int)$row['id']);
    }
} catch (Throwable $t) {
    error_log('Tenant folders: ' . $t->getMessage());
    $error = $t instanceof PDOException ? 'Could not load tenant folders. Check the database migrations and private storage.' : $t->getMessage();
}
?>
<?php if ($error): ?><div class="alert error"><?=e($error)?></div><?php endif; ?>
<?php if ($profileId <= 0 && !$error): ?>
<div class="card">
  <div class="row between"><h3>Profile folders</h3><a class="btn sm ghost" href="index.php?page=contracts">Back to Tenant Files</a></div>
  <div style="overflow-x:auto"><table class="tbl">
    <thead><tr><th>Tenant</th><th>Unit</th><th>Status</th><th>Folder</th></tr></thead><tbody>
    <?php if (!$profiles): ?><tr><td colspan="4">No tenant profiles.</td></tr><?php endif; ?>
    <?php foreach ($profiles as $row): ?>
      <tr><td><?=e($row['full_name'])?></td><td><?=e($row['unit_number'])?></td><td><?=e($row['profile_status'])?></td><td><a class="btn sm ghost" href="index.php?page=tenant_folders&amp;id=<?=(int)$row['id']?>">Open folder</a></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php elseif (isset($profile) && $profile): ?>
<div class="card">
  <div class="row between"><h3><?=e($profile['full_name'])?> - Profile folder</h3><a class="btn sm ghost" href="index.php?page=tenant_folders">All folders</a></div>
  <p>Records link to their original pages. Uploaded files are stored in the tenant's private folder.</p>
  <form method="post" enctype="multipart/form-data">
    <?=csrf_field()?>
    <div class="row">
      <select name="category" required><?php foreach (tenant_file_categories() as $category): ?><option value="<?=e($category)?>"><?=e(str_replace('_', ' ', $category))?></option><?php endforeach; ?></select>
      <input type="file" name="document" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.webp" required>
      <button class="btn sm">Upload file</button>
    </div>
    <small>PDF, Word or image; maximum 5 MB.</small>
  </form>
</div>
<?php if (!$error): foreach ($entries as $category => $items): ?>
<details class="card">
  <summary><strong><?=e(str_replace('_', ' ', $category))?></strong> (<?=count($items)?>)</summary>
  <?php if (!$items): ?><p>No documents or records in this folder.</p><?php else: ?>
  <ul><?php foreach ($items as $item): ?><li><a href="<?=e($item['url'])?>" target="_blank" rel="noopener"><?=e($item['label'])?></a></li><?php endforeach; ?></ul>
  <?php endif; ?>
</details>
<?php endforeach; endif; ?>
<?php endif; ?>
