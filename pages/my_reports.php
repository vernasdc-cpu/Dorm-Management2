<?php
if (!in_array($user['role'] ?? '', ['resident'], true)) {
    http_response_code(403);
    echo '<div class="card"><h3>Forbidden</h3><p>Residents only.</p></div>';
    return;
}
$pdo = db();
$ctx = get_tenant_context($user);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_check();
    try {
        $category = trim((string)($_POST['category'] ?? ''));
        $subject = trim((string)($_POST['subject'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        if ($category === '' || $subject === '' || $description === '') throw new RuntimeException('All fields are required.');
        $ref = 'REP-' . date('Ymd') . '-' . str_pad((string)random_int(0,9999), 4, '0', STR_PAD_LEFT);
        $attachment = null;
        if (!empty($_FILES['attachment']) && (($_FILES['attachment']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE)) {
            $err = validate_upload($_FILES['attachment'], ['jpg','jpeg','png','webp'], 3);
            if ($err) throw new RuntimeException($err);
            $attachment = safe_store_upload($_FILES['attachment'], __DIR__ . '/../uploads/reports');
        }
        $ins = $pdo->prepare('INSERT INTO tenant_reports (report_ref, user_id, tenant_profile_id, resident_id, room_id, category, subject, description, attachment_path) VALUES (?,?,?,?,?,?,?,?,?)');
        $ins->execute([
            $ref, (int)$user['id'],
            $ctx['tenant_profile'] ? (int)$ctx['tenant_profile']['id'] : null,
            $ctx['resident'] ? (int)$ctx['resident']['residents_id'] : null,
            $ctx['resident']['room_id'] ?? ($ctx['tenant_profile']['room_id'] ?? null),
            $category, $subject, $description, $attachment
        ]);
        audit_log((int)$user['id'], 'Submitted report ' . $ref, 'reports', (string)$pdo->lastInsertId());
        flash('success', 'Report submitted.');
        redirect('index.php?page=my_reports');
    } catch (Throwable $t) {
        flash('error', $t->getMessage());
        redirect('index.php?page=my_reports');
    }
}

$reports = [];
try {
    $st = $pdo->prepare('SELECT * FROM tenant_reports WHERE user_id = ? ORDER BY id DESC');
    $st->execute([(int)$user['id']]);
    $reports = $st->fetchAll();
} catch (Throwable $t) {
    $reports = [];
}
?>
<div class="card" style="margin-bottom:14px">
  <h3>Submit Report/Maintenance Request</h3>
  <form method="post" enctype="multipart/form-data">
    <?=csrf_field()?>
    <div class="form-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
      <div class="field"><label>Category</label>
        <select name="category" required>
          <option>Plumbing or water</option><option>Electrical</option><option>Room maintenance</option><option>Cleaning</option><option>Security</option><option>Facilities</option><option>Other concerns</option>
        </select>
      </div>
      <div class="field"><label>Subject</label><input name="subject" required></div>
      <div class="field" style="grid-column:1/-1"><label>Description</label><textarea name="description" rows="4" required></textarea></div>
      <div class="field" style="grid-column:1/-1"><label>Attachment (jpg/png/webp, max 3MB)</label><input type="file" name="attachment" accept=".jpg,.jpeg,.png,.webp"></div>
    </div>
    <div style="margin-top:10px"><button class="btn">Submit</button></div>
  </form>
</div>

<div class="card">
  <h3>My Reports</h3>
  <div style="overflow-x:auto">
  <table class="tbl">
    <thead><tr><th>Ref</th><th>Date</th><th>Category</th><th>Subject</th><th>Status</th><th>Staff Response</th><th>Attachment</th></tr></thead>
    <tbody>
    <?php if (!$reports): ?><tr><td colspan="7">No reports yet.</td></tr><?php endif; ?>
    <?php foreach ($reports as $r): ?>
      <tr>
        <td><?=e($r['report_ref'])?></td>
        <td><?=e(substr($r['created_at'],0,10))?></td>
        <td><?=e($r['category'])?></td>
        <td><?=e($r['subject'])?></td>
        <td><?=e($r['status'])?></td>
        <td><?= !empty($r['staff_response']) ? nl2br(e($r['staff_response'])) : '—' ?></td>
        <td><?php if(!empty($r['attachment_path'])): ?><a class="btn ghost sm" href="uploads/reports/<?=e(basename($r['attachment_path']))?>" target="_blank">View</a><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
