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
    try {
        if ($action === 'create') {
            $ref = 'VIO-' . date('Ymd') . '-' . str_pad((string)random_int(0,9999), 4, '0', STR_PAD_LEFT);
            $tpid = (int)($_POST['tenant_profile_id'] ?? 0) ?: null;
            if (!$tpid) throw new RuntimeException('Please select a tenant profile.');
            $tenantSt = $pdo->prepare(
                "SELECT tp.room_id,
                        (SELECT u.id
                         FROM users u
                         WHERE u.role = 'resident' AND u.deleted_at IS NULL
                           AND (u.tenant_profile_id = tp.id
                                OR (tp.email IS NOT NULL AND tp.email <> '' AND LOWER(u.email) = LOWER(tp.email)))
                         ORDER BY (u.tenant_profile_id = tp.id) DESC, u.id DESC
                         LIMIT 1) AS user_id
                 FROM tenant_profiles tp
                 WHERE tp.id = ?"
            );
            $tenantSt->execute([$tpid]);
            $tenant = $tenantSt->fetch();
            if (!$tenant) throw new RuntimeException('Tenant profile not found.');
            $uid = (int)($tenant['user_id'] ?? 0) ?: null;
            $room = (int)($tenant['room_id'] ?? 0) ?: null;
            $date = trim((string)($_POST['violation_date'] ?? date('Y-m-d')));
            $cat = trim((string)($_POST['category'] ?? ''));
            $desc = trim((string)($_POST['description'] ?? ''));
            $penalty = max(0, (float)($_POST['penalty_amount'] ?? 0));
            $notes = trim((string)($_POST['tenant_visible_notes'] ?? ''));
            if ($cat === '' || $desc === '') throw new RuntimeException('Category and description required.');
            $ins = $pdo->prepare('INSERT INTO violations (violation_ref, tenant_profile_id, user_id, room_id, violation_date, category, description, penalty_amount, tenant_visible_notes, recorded_by) VALUES (?,?,?,?,?,?,?,?,?,?)');
            $ins->execute([$ref, $tpid, $uid, $room, $date, $cat, $desc, $penalty, $notes ?: null, (int)$user['id']]);
            audit_log((int)$user['id'], "Created violation $ref", 'violations', (string)$pdo->lastInsertId());
            flash('success', 'Violation recorded.');
            redirect('index.php?page=violations');
        } elseif ($action === 'update') {
            $id = (int)($_POST['id'] ?? 0);
            $status = trim((string)($_POST['status'] ?? 'Reviewed'));
            $penalty = max(0, (float)($_POST['penalty_amount'] ?? 0));
            $notes = trim((string)($_POST['tenant_visible_notes'] ?? ''));
            $pdo->prepare('UPDATE violations SET status=?, penalty_amount=?, tenant_visible_notes=? WHERE id=?')
                ->execute([$status, $penalty, $notes ?: null, $id]);
            audit_log((int)$user['id'], "Updated violation #$id", 'violations', (string)$id);
            flash('success', 'Violation updated.');
            redirect('index.php?page=violations');
        }
    } catch (Throwable $t) {
        flash('error', $t->getMessage());
        redirect('index.php?page=violations');
    }
}

$viol = [];
$tps = [];
try {
    $viol = $pdo->query("SELECT v.*, tp.full_name, u.name as uname FROM violations v LEFT JOIN tenant_profiles tp ON tp.id = v.tenant_profile_id LEFT JOIN users u ON u.id = v.user_id ORDER BY v.violation_date DESC, v.id DESC")->fetchAll();
} catch (Throwable $t) {
    error_log('violations.php list load: ' . $t->getMessage());
}

try {
    $tps = $pdo->query(
        "SELECT tp.id, tp.full_name, tp.email, tp.room_id, tp.unit_number, r.room_number,
                u.email AS user_email
         FROM tenant_profiles tp
         LEFT JOIN rooms r ON r.id = tp.room_id
         LEFT JOIN users u ON u.id = (
             SELECT matched.id
             FROM users matched
             WHERE matched.role = 'resident' AND matched.deleted_at IS NULL
               AND (matched.tenant_profile_id = tp.id
                    OR (tp.email IS NOT NULL AND tp.email <> '' AND LOWER(matched.email) = LOWER(tp.email)))
             ORDER BY (matched.tenant_profile_id = tp.id) DESC, matched.id DESC
             LIMIT 1
         )
         ORDER BY tp.full_name"
    )->fetchAll();
} catch (Throwable $t) {
    error_log('violations.php tenant list load: ' . $t->getMessage());
}
?>
<div class="card" style="margin-bottom:14px">
  <h3>Record Violation</h3>
  <form method="post">
    <?=csrf_field()?><input type="hidden" name="action" value="create">
    <div class="form-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
      <div class="field"><label>Tenant Profile</label><select name="tenant_profile_id" id="violationTenant" required><option value="">Select tenant</option><?php foreach ($tps as $t): ?><option value="<?= (int)$t['id'] ?>" data-email="<?=e($t['email'] ?: ($t['user_email'] ?? ''))?>" data-room="<?=e($t['room_number'] ?: ($t['unit_number'] ?? ''))?>"><?=e($t['full_name'])?></option><?php endforeach; ?></select></div>
      <div class="field"><label>Resident User Email</label><input id="violationEmail" type="email" readonly></div>
      <div class="field"><label>Date</label><input type="date" name="violation_date" value="<?=date('Y-m-d')?>" required></div>
      <div class="field"><label>Room</label><input id="violationRoom" readonly></div>
      <div class="field"><label>Category</label><input name="category" required></div>
      <div class="field"><label>Penalty Amount</label><input type="number" step="0.01" min="0" name="penalty_amount" value="0"></div>
      <div class="field" style="grid-column:1/-1"><label>Description</label><textarea name="description" rows="3" required></textarea></div>
      <div class="field" style="grid-column:1/-1"><label>Notes visible to tenant</label><textarea name="tenant_visible_notes" rows="2"></textarea></div>
    </div>
    <div style="margin-top:10px"><button class="btn">Record</button></div>
  </form>
</div>

<script>
document.getElementById('violationTenant').addEventListener('change', function () {
  var selected = this.options[this.selectedIndex];
  document.getElementById('violationEmail').value = selected.getAttribute('data-email') || '';
  document.getElementById('violationRoom').value = selected.getAttribute('data-room') || '';
});
</script>

<div class="card">
  <h3>Violations</h3>
  <div style="overflow-x:auto">
  <table class="tbl">
    <thead><tr><th>Ref</th><th>Tenant</th><th>Date</th><th>Category</th><th>Description</th><th>Penalty</th><th>Status</th><th>Tenant Notes</th><th>Action</th></tr></thead>
    <tbody>
    <?php if (!$viol): ?><tr><td colspan="9">No violations.</td></tr><?php endif; ?>
    <?php foreach ($viol as $v): ?>
      <tr>
        <td><?=e($v['violation_ref'])?></td>
        <td><?=e($v['full_name'] ?: $v['uname'])?></td>
        <td><?=e($v['violation_date'])?></td>
        <td><?=e($v['category'])?></td>
        <td><?=nl2br(e($v['description']))?></td>
        <td><?=money($v['penalty_amount'])?></td>
        <td><?=e($v['status'])?></td>
        <td><?= $v['tenant_visible_notes'] ? nl2br(e($v['tenant_visible_notes'])) : '—' ?></td>
        <td>
          <form method="post">
            <?=csrf_field()?><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="<?=(int)$v['id']?>">
            <select name="status">
              <?php foreach (['Reported','Reviewed','Resolved','Dismissed'] as $st): ?><option value="<?=e($st)?>" <?=$v['status']===$st?'selected':''?>><?=e($st)?></option><?php endforeach; ?>
            </select>
            <input type="number" step="0.01" min="0" name="penalty_amount" value="<?=e((string)$v['penalty_amount'])?>" style="width:90px">
            <input name="tenant_visible_notes" value="<?=e($v['tenant_visible_notes']??'')?>" placeholder="Tenant notes" style="width:150px">
            <button class="btn sm">Update</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
