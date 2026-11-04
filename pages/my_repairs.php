<?php
// My Repairs (resident). Inner content only ($user available).
if ($user['role'] !== 'resident') {
    http_response_code(403);
    echo '<div class="card"><h3>Forbidden</h3><p>Residents only.</p></div>';
    return;
}
$CATEGORIES = ['Broken bed','Broken cabinet','Damaged curtain','Plumbing problem','Electrical problem','Aircon problem','Water problem','Other'];
$PRIORITIES = ['Low','Medium','High','Emergency'];
$STATUSES = ['Reported','Pending','Assigned','In Progress','Completed','Cancelled'];

function myrep_priority_class(string $p): string {
    return match ($p) { 'Low' => 'info', 'Medium' => '', 'High' => 'warn', 'Emergency' => 'bad', default => '' };
}
function myrep_status_class(string $s): string {
    return match ($s) {
        'Reported' => 'info', 'Pending' => 'warn', 'Assigned' => 'info',
        'In Progress' => 'warn', 'Completed' => 'ok', 'Cancelled' => 'bad', default => '',
    };
}

try {
    $st = db()->prepare('SELECT residents_id, room_id FROM residents WHERE user_id=? LIMIT 1');
    $st->execute([(int)$user['id']]);
    $me = $st->fetch();
} catch (Throwable $t) { $me = false; }
if (!$me) {
    echo '<div class="card"><h3>No resident record</h3><p>Your account is not linked to a resident profile. Please contact the administrator.</p></div>';
    return;
}

// ---------- Submit new request ----------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_check();
    try {
        $category = trim($_POST['category'] ?? '');
        $priority = trim($_POST['priority'] ?? 'Medium');
        $description = trim($_POST['description'] ?? '');
        if (!in_array($category, $CATEGORIES, true)) throw new RuntimeException('Please select a valid category.');
        if (!in_array($priority, $PRIORITIES, true)) throw new RuntimeException('Please select a valid priority.');
        if ($description === '' || mb_strlen($description) > 2000) throw new RuntimeException('Description is required (max 2000 characters).');

        $photoName = null;
        if (!empty($_FILES['photo']) && (($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE)) {
            $err = validate_upload($_FILES['photo'], ['jpg', 'jpeg', 'png', 'webp'], 5);
            if ($err) throw new RuntimeException($err);
            $photoName = safe_store_upload($_FILES['photo'], __DIR__ . '/../uploads/repairs');
        }

        $next = (int)db()->query('SELECT COALESCE(MAX(id),0)+1 FROM repair_requests')->fetchColumn();
        $reportId = 'REP-' . str_pad((string)$next, 4, '0', STR_PAD_LEFT);

        $ins = db()->prepare('INSERT INTO repair_requests (report_id, resident_id, room_id, category, description, priority, photo_path, status) VALUES (?,?,?,?,?,?,?,?)');
        $ins->execute([$reportId, (int)$me['residents_id'], (int)$me['room_id'], $category, $description, $priority, $photoName, 'Reported']);
        $repairId = (int)db()->lastInsertId();
        db()->prepare('INSERT INTO repair_updates (repair_id, updated_by, old_status, new_status, notes) VALUES (?,?,?,?,?)')
            ->execute([$repairId, (int)$user['id'], null, 'Reported', 'Request submitted by resident.']);
        audit_log((int)$user['id'], 'Submitted repair request', 'repairs', $reportId);
        flash('success', 'Repair request submitted successfully.');
        redirect('index.php?page=my_repairs');
    } catch (Throwable $t) {
        flash('error', $t->getMessage());
        redirect('index.php?page=my_repairs');
    }
}

// ---------- My requests ----------
try {
    $st = db()->prepare('SELECT rr.*, rm.room_number FROM repair_requests rr JOIN rooms rm ON rm.id=rr.room_id WHERE rr.resident_id=? ORDER BY rr.date_reported DESC');
    $st->execute([(int)$me['residents_id']]);
    $rows = $st->fetchAll();
} catch (Throwable $t) { $rows = []; flash('error', 'Could not load your repair requests. Please try again later.'); }

$updatesByRepair = [];
if ($rows) {
    try {
        $ids = array_column($rows, 'id');
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $us = db()->prepare("SELECT ru.*, u.name AS updater FROM repair_updates ru LEFT JOIN users u ON u.id=ru.updated_by WHERE ru.repair_id IN ($ph) ORDER BY ru.created_at ASC");
        $us->execute($ids);
        foreach ($us->fetchAll() as $u) $updatesByRepair[$u['repair_id']][] = $u;
    } catch (Throwable $t) { /* optional */ }
}
?>

<div class="card" style="margin-bottom:14px">
  <div class="row between">
    <div><h3 style="margin:0">My Repair Requests</h3><p style="margin:4px 0 0;color:#7c6f8a;font-size:13px">Track maintenance requests for your room. Requests cannot be edited once Completed.</p></div>
    <button class="btn sm" onclick="document.getElementById('new-repair').classList.add('open')">+ Submit new request</button>
  </div>
</div>

<div class="card">
  <div style="overflow-x:auto">
  <table class="tbl">
    <thead><tr><th>Report ID</th><th>Category</th><th>Priority</th><th>Status</th><th>Date Reported</th><th>Date Resolved</th><th>Actions</th></tr></thead>
    <tbody>
    <?php if (!$rows): ?><tr><td colspan="7">No repair requests yet. Click “Submit new request” to file one.</td></tr><?php endif; ?>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><strong><?= e($r['report_id']) ?></strong></td>
        <td><?= e($r['category']) ?></td>
        <td><span class="pill <?= myrep_priority_class($r['priority']) ?>"><?= e($r['priority']) ?></span></td>
        <td><span class="pill <?= myrep_status_class($r['status']) ?>"><?= e($r['status']) ?></span></td>
        <td><?= e(substr((string)$r['date_reported'], 0, 16)) ?></td>
        <td><?= e($r['date_resolved'] ? substr((string)$r['date_resolved'], 0, 16) : '—') ?></td>
        <td><button class="btn ghost sm" onclick="document.getElementById('v-<?= (int)$r['id'] ?>').classList.add('open')">View</button></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>

<?php foreach ($rows as $r): ?>
<div class="modal" id="v-<?= (int)$r['id'] ?>">
  <div class="box">
    <div class="row between"><h3 style="margin:0"><?= e($r['report_id']) ?> · <?= e($r['category']) ?></h3>
      <button class="btn ghost sm" onclick="document.getElementById('v-<?= (int)$r['id'] ?>').classList.remove('open')">Close</button></div>
    <p><span class="pill <?= myrep_priority_class($r['priority']) ?>"><?= e($r['priority']) ?></span>
       <span class="pill <?= myrep_status_class($r['status']) ?>"><?= e($r['status']) ?></span></p>
    <p><?= nl2br(e($r['description'])) ?></p>
    <?php if (!empty($r['photo_path'])): ?>
      <p><img src="uploads/repairs/<?= e(basename($r['photo_path'])) ?>" alt="Repair photo" style="max-width:100%;border-radius:10px;border:1px solid #efdff5"></p>
    <?php endif; ?>
    <?php if (!empty($r['resolution_notes'])): ?><p><strong>Resolution:</strong> <?= nl2br(e($r['resolution_notes'])) ?></p><?php endif; ?>
    <h4>Status timeline</h4>
    <?php $tl = $updatesByRepair[$r['id']] ?? []; ?>
    <?php if (!$tl): ?><p style="color:#7c6f8a;font-size:13px">No updates yet.</p>
    <?php else: ?><ul style="font-size:13px;padding-left:18px">
      <?php foreach ($tl as $u): ?>
        <li><strong><?= e($u['old_status'] ?? '—') ?> → <?= e($u['new_status'] ?? '—') ?></strong>
          by <?= e($u['updater'] ?? 'System') ?> · <?= e(substr((string)$u['created_at'], 0, 16)) ?>
          <?php if (!empty($u['notes'])): ?><br><?= nl2br(e($u['notes'])) ?><?php endif; ?></li>
      <?php endforeach; ?></ul>
    <?php endif; ?>
    <?php if ($r['status'] === 'Completed'): ?><p class="alert success">This request is Completed and can no longer be edited.</p><?php endif; ?>
  </div>
</div>
<?php endforeach; ?>

<div class="modal" id="new-repair">
  <div class="box">
    <div class="row between"><h3 style="margin:0">Submit repair request</h3>
      <button class="btn ghost sm" onclick="document.getElementById('new-repair').classList.remove('open')">Close</button></div>
    <form method="post" action="index.php?page=my_repairs" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <div class="form-grid">
        <div class="field"><label>Category</label>
          <select name="category" required><option value="">— Select —</option>
            <?php foreach ($CATEGORIES as $c): ?><option value="<?= e($c) ?>"><?= e($c) ?></option><?php endforeach; ?>
          </select></div>
        <div class="field"><label>Priority</label>
          <select name="priority" required>
            <?php foreach ($PRIORITIES as $p): ?><option value="<?= e($p) ?>" <?= $p==='Medium'?'selected':'' ?>><?= e($p) ?></option><?php endforeach; ?>
          </select></div>
      </div>
      <div class="field"><label>Description</label>
        <textarea name="description" rows="4" required maxlength="2000" placeholder="Describe the issue (location, details, urgency)"></textarea></div>
      <div class="field"><label>Photo (optional · JPG/PNG/WebP · max 5MB)</label>
        <input type="file" name="photo" accept=".jpg,.jpeg,.png,.webp"></div>
      <button class="btn" style="width:100%">Submit request</button>
    </form>
  </div>
</div>
