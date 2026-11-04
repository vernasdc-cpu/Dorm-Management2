<?php
if (!in_array($user['role'] ?? '', ['super_admin', 'admin_staff'], true)) {
    http_response_code(403);
    echo '<div class="card"><h3>Forbidden</h3><p>Your role cannot access this page.</p></div>';
    return;
}
$pdo = db();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    try {
        if ($id <= 0) throw new RuntimeException('Invalid report.');
        if ($action === 'update') {
            $status = trim((string)($_POST['status'] ?? 'Submitted'));
            $resp = trim((string)($_POST['staff_response'] ?? ''));
            $assign = (int)($_POST['assigned_staff_id'] ?? 0);
            $allowed = ['Submitted','In Progress','Resolved','Rejected'];
            if (!in_array($status, $allowed, true)) $status = 'Submitted';
            $pdo->prepare('UPDATE tenant_reports SET status=?, staff_response=?, assigned_staff_id=? WHERE id=?')
                ->execute([$status, $resp ?: null, $assign ?: null, $id]);
            if ($status === 'Resolved') {
                $pdo->prepare('UPDATE tenant_reports SET resolution_date = CURDATE() WHERE id = ? AND resolution_date IS NULL')->execute([$id]);
            }
            audit_log((int)$user['id'], "Updated report #$id to $status", 'reports', (string)$id);
            flash('success', 'Report updated.');
            redirect('index.php?page=admin_reports');
        }
    } catch (Throwable $t) {
        flash('error', $t->getMessage());
        redirect('index.php?page=admin_reports');
    }
}

$reps = [];
$staff = [];
try {
    $reps = $pdo->query("SELECT tr.*, u.name as reporter FROM tenant_reports tr JOIN users u ON u.id = tr.user_id ORDER BY FIELD(tr.status,'Submitted','In Progress','Resolved','Rejected'), tr.id DESC")->fetchAll();
    $staff = $pdo->query("SELECT id,name FROM users WHERE role IN ('super_admin','admin_staff','housekeeping') AND deleted_at IS NULL ORDER BY name")->fetchAll();
} catch (Throwable $t) {
    $reps = []; $staff = [];
}
?>
<div class="card">
  <h3>Tenant Reports & Maintenance</h3>
  <div style="overflow-x:auto">
  <table class="tbl">
    <thead><tr><th>Ref</th><th>Reporter</th><th>Category</th><th>Subject</th><th>Description</th><th>Status</th><th>Attachment</th><th>Response</th><th>Action</th></tr></thead>
    <tbody>
    <?php if (!$reps): ?><tr><td colspan="9">No reports.</td></tr><?php endif; ?>
    <?php foreach ($reps as $r): ?>
      <tr>
        <td><?=e($r['report_ref'])?></td>
        <td><?=e($r['reporter'])?></td>
        <td><?=e($r['category'])?></td>
        <td><?=e($r['subject'])?></td>
        <td><?=nl2br(e(mb_substr($r['description'],0,80)))?><?=mb_strlen($r['description'])>80?'...':''?></td>
        <td><?=e($r['status'])?></td>
        <td><?php if(!empty($r['attachment_path'])): ?><a class="btn ghost sm" href="uploads/reports/<?=e(basename($r['attachment_path']))?>" target="_blank">View</a><?php endif; ?></td>
        <td><?= $r['staff_response'] ? nl2br(e($r['staff_response'])) : '—' ?></td>
        <td>
          <form method="post">
            <?=csrf_field()?><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="<?=(int)$r['id']?>">
            <select name="status">
              <?php foreach (['Submitted','In Progress','Resolved','Rejected'] as $st): ?><option value="<?=e($st)?>" <?=$r['status']===$st?'selected':''?>><?=e($st)?></option><?php endforeach; ?>
            </select>
            <select name="assigned_staff_id"><option value="">Assign</option><?php foreach ($staff as $s): ?><option value="<?=(int)$s['id']?>" <?=$r['assigned_staff_id']==$s['id']?'selected':''?>><?=e($s['name'])?></option><?php endforeach; ?></select>
            <textarea name="staff_response" placeholder="Response" style="width:200px"><?=e($r['staff_response']??'')?></textarea>
            <button class="btn sm">Update</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
