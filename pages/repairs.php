<?php
// Repairs management: super_admin, admin_staff, housekeeping. Inner content only ($user available).
if (!in_array($user['role'], ['super_admin', 'admin_staff', 'housekeeping'], true)) {
    http_response_code(403);
    echo '<div class="card"><h3>Forbidden</h3><p>Your role cannot access this page.</p></div>';
    return;
}
$isAdmin = in_array($user['role'], ['super_admin', 'admin_staff'], true);

$CATEGORIES = ['Broken bed','Broken cabinet','Damaged curtain','Plumbing problem','Electrical problem','Aircon problem','Water problem','Other'];
$PRIORITIES = ['Low','Medium','High','Emergency'];
$STATUSES = ['Reported','Pending','Assigned','In Progress','Completed','Cancelled'];

function repair_priority_class(string $p): string {
    return match ($p) { 'Low' => 'info', 'Medium' => '', 'High' => 'warn', 'Emergency' => 'bad', default => '' };
}
function repair_status_class(string $s): string {
    return match ($s) {
        'Reported' => 'info', 'Pending' => 'warn', 'Assigned' => 'info',
        'In Progress' => 'warn', 'Completed' => 'ok', 'Cancelled' => 'bad', default => '',
    };
}
function notif_priority_for(string $priority): string {
    return $priority === 'Emergency' ? 'Urgent' : ($priority === 'High' ? 'Important' : 'Normal');
}

// ---------- POST ----------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'update_status') {
            $id = (int)($_POST['id'] ?? 0);
            $newStatus = trim($_POST['status'] ?? '');
            $notes = trim($_POST['resolution_notes'] ?? '');
            if ($id <= 0 || !in_array($newStatus, $STATUSES, true)) throw new RuntimeException('Invalid status update.');
            $st = db()->prepare('SELECT * FROM repair_requests WHERE id=?');
            $st->execute([$id]);
            $rep = $st->fetch();
            if (!$rep) throw new RuntimeException('Repair request not found.');
            $old = $rep['status'];
            if ($newStatus === 'Completed') {
                $up = db()->prepare('UPDATE repair_requests SET status=?, resolution_notes=?, date_resolved=NOW() WHERE id=?');
                $up->execute([$newStatus, $notes !== '' ? $notes : $rep['resolution_notes'], $id]);
            } else {
                $up = db()->prepare('UPDATE repair_requests SET status=?, resolution_notes=?, date_resolved=NULL WHERE id=?');
                $up->execute([$newStatus, $notes !== '' ? $notes : $rep['resolution_notes'], $id]);
            }
            $lu = db()->prepare('INSERT INTO repair_updates (repair_id, updated_by, old_status, new_status, notes) VALUES (?,?,?,?,?)');
            $lu->execute([$id, (int)$user['id'], $old, $newStatus, $notes !== '' ? $notes : null]);
            // Notify resident
            $rs = db()->prepare('SELECT user_id FROM residents WHERE residents_id=?');
            $rs->execute([$rep['resident_id']]);
            $recipient = $rs->fetchColumn();
            if ($recipient) {
                $title = 'Repair ' . $rep['report_id'] . ' status changed to ' . $newStatus;
                $msg = 'Your repair request ' . $rep['report_id'] . ' (' . $rep['category'] . ') status changed from ' . $old . ' to ' . $newStatus . '.';
                if ($notes !== '') $msg .= ' Notes: ' . $notes;
                $nt = db()->prepare('INSERT INTO notifications (title, message, sender_id, recipient_id, priority) VALUES (?,?,?,?,?)');
                $nt->execute([$title, $msg, (int)$user['id'], (int)$recipient, notif_priority_for($rep['priority'])]);
            }
            audit_log((int)$user['id'], 'Updated repair status to ' . $newStatus, 'repairs', $rep['report_id']);
            flash('success', 'Repair ' . $rep['report_id'] . ' status changed to ' . $newStatus . '.');
            redirect('index.php?page=repairs');
        } elseif ($action === 'assign_staff') {
            $id = (int)($_POST['id'] ?? 0);
            $staffId = ($_POST['assigned_staff_id'] ?? '') === '' ? null : (int)$_POST['assigned_staff_id'];
            if ($id <= 0) throw new RuntimeException('Invalid repair request.');
            if ($staffId !== null) {
                $chk = db()->prepare("SELECT id, name FROM users WHERE id=? AND role='housekeeping' AND deleted_at IS NULL");
                $chk->execute([$staffId]);
                $staff = $chk->fetch();
                if (!$staff) throw new RuntimeException('Selected staff is not valid housekeeping personnel.');
            }
            $st = db()->prepare('SELECT * FROM repair_requests WHERE id=?');
            $st->execute([$id]);
            $rep = $st->fetch();
            if (!$rep) throw new RuntimeException('Repair request not found.');
            if (in_array($rep['status'], ['Reported', 'Pending'], true) && $staffId !== null) {
                $up = db()->prepare('UPDATE repair_requests SET assigned_staff_id=?, status=? WHERE id=?');
                $up->execute([$staffId, 'Assigned', $id]);
                $lu = db()->prepare('INSERT INTO repair_updates (repair_id, updated_by, old_status, new_status, notes) VALUES (?,?,?,?,?)');
                $lu->execute([$id, (int)$user['id'], $rep['status'], 'Assigned', 'Assigned to staff ID ' . $staffId]);
            } else {
                $up = db()->prepare('UPDATE repair_requests SET assigned_staff_id=? WHERE id=?');
                $up->execute([$staffId, $id]);
                $lu = db()->prepare('INSERT INTO repair_updates (repair_id, updated_by, old_status, new_status, notes) VALUES (?,?,?,?,?)');
                $lu->execute([$id, (int)$user['id'], $rep['status'], $rep['status'], $staffId ? 'Reassigned to staff ID ' . $staffId : 'Unassigned staff']);
            }
            audit_log((int)$user['id'], 'Assigned staff for repair', 'repairs', $rep['report_id']);
            flash('success', 'Staff assigned for ' . $rep['report_id'] . ' successfully.');
            redirect('index.php?page=repairs');
        } elseif ($action === 'delete') {
            if (!$isAdmin) { http_response_code(403); throw new RuntimeException('Only administrators can delete repair requests.'); }
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('Invalid repair request.');
            $st = db()->prepare('SELECT report_id, photo_path FROM repair_requests WHERE id=?');
            $st->execute([$id]);
            $rep = $st->fetch();
            if (!$rep) throw new RuntimeException('Repair request not found.');
            db()->prepare('DELETE FROM repair_requests WHERE id=?')->execute([$id]);
            if (!empty($rep['photo_path'])) {
                $f = __DIR__ . '/../uploads/repairs/' . basename($rep['photo_path']);
                if (is_file($f)) @unlink($f);
            }
            audit_log((int)$user['id'], 'Deleted repair request', 'repairs', $rep['report_id']);
            flash('success', 'Repair request deleted successfully.');
            redirect('index.php?page=repairs');
        }
    } catch (Throwable $t) {
        flash('error', $t->getMessage());
        redirect('index.php?page=repairs');
    }
}

// ---------- Filters ----------
$fSearch  = trim($_GET['search'] ?? '');
$fStatus  = trim($_GET['status'] ?? '');
$fPriority = trim($_GET['priority'] ?? '');
$fRoom    = trim($_GET['room'] ?? '');
$fStaff   = trim($_GET['staff'] ?? '');

try {
    $stats = [
        'open' => (int)db()->query("SELECT COUNT(*) FROM repair_requests WHERE status IN ('Reported','Pending','Assigned')")->fetchColumn(),
        'progress' => (int)db()->query("SELECT COUNT(*) FROM repair_requests WHERE status='In Progress'")->fetchColumn(),
        'completed' => (int)db()->query("SELECT COUNT(*) FROM repair_requests WHERE status='Completed'")->fetchColumn(),
        'emergency' => (int)db()->query("SELECT COUNT(*) FROM repair_requests WHERE priority='Emergency' AND status NOT IN ('Completed','Cancelled')")->fetchColumn(),
    ];
} catch (Throwable $t) { $stats = ['open'=>0,'progress'=>0,'completed'=>0,'emergency'=>0]; }

try {
    $rooms = db()->query('SELECT id, room_number FROM rooms ORDER BY room_number')->fetchAll();
    $staffList = db()->query("SELECT id, name FROM users WHERE role='housekeeping' AND deleted_at IS NULL ORDER BY name")->fetchAll();
} catch (Throwable $t) { $rooms = []; $staffList = []; }

// ---------- Listing ----------
$where = []; $params = [];
if ($fSearch !== '') { $where[] = '(rr.report_id LIKE ? OR rr.description LIKE ?)'; $params[] = "%$fSearch%"; $params[] = "%$fSearch%"; }
if (in_array($fStatus, $STATUSES, true)) { $where[] = 'rr.status=?'; $params[] = $fStatus; }
if (in_array($fPriority, $PRIORITIES, true)) { $where[] = 'rr.priority=?'; $params[] = $fPriority; }
if ($fRoom !== '' && ctype_digit($fRoom)) { $where[] = 'rr.room_id=?'; $params[] = (int)$fRoom; }
if ($fStaff !== '') {
    if ($fStaff === 'unassigned') $where[] = 'rr.assigned_staff_id IS NULL';
    elseif (ctype_digit($fStaff)) { $where[] = 'rr.assigned_staff_id=?'; $params[] = (int)$fStaff; }
}
$sql = 'SELECT rr.*, rm.room_number, u.name AS resident_name, s.name AS staff_name
        FROM repair_requests rr
        JOIN residents r ON r.residents_id = rr.resident_id
        JOIN users u ON u.id = r.user_id
        JOIN rooms rm ON rm.id = rr.room_id
        LEFT JOIN users s ON s.id = rr.assigned_staff_id'
     . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
     . ' ORDER BY rr.date_reported DESC LIMIT 500';
try {
    $st = db()->prepare($sql); $st->execute($params);
    $rows = $st->fetchAll();
} catch (Throwable $t) { $rows = []; flash('error', 'Could not load repair requests. Please try again later.'); }

// Updates timeline (bulk fetch for displayed rows)
$updatesByRepair = [];
if ($rows) {
    try {
        $ids = array_column($rows, 'id');
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $us = db()->prepare("SELECT ru.*, u.name AS updater FROM repair_updates ru LEFT JOIN users u ON u.id=ru.updated_by WHERE ru.repair_id IN ($ph) ORDER BY ru.created_at ASC");
        $us->execute($ids);
        foreach ($us->fetchAll() as $u) $updatesByRepair[$u['repair_id']][] = $u;
    } catch (Throwable $t) { /* timeline optional */ }
}
$qs = fn(array $over = []) => 'index.php?page=repairs&' . http_build_query(array_filter(array_merge(
    ['search'=>$fSearch,'status'=>$fStatus,'priority'=>$fPriority,'room'=>$fRoom,'staff'=>$fStaff], $over), fn($v) => $v !== ''));
?>

<div class="grid g4" style="margin-bottom:14px">
  <div class="card stat"><b><?= (int)$stats['open'] ?></b><span>Open Requests</span></div>
  <div class="card stat"><b><?= (int)$stats['progress'] ?></b><span>In Progress</span></div>
  <div class="card stat"><b><?= (int)$stats['completed'] ?></b><span>Completed</span></div>
  <div class="card stat"><b><?= (int)$stats['emergency'] ?></b><span>Emergency (unresolved)</span></div>
</div>

<div class="card">
  <form class="toolbar" method="get" action="index.php">
    <input type="hidden" name="page" value="repairs">
    <input name="search" placeholder="Search report ID / description" value="<?= e($fSearch) ?>" style="min-width:220px">
    <select name="status"><option value="">All statuses</option>
      <?php foreach ($STATUSES as $s): ?><option value="<?= e($s) ?>" <?= $fStatus===$s?'selected':'' ?>><?= e($s) ?></option><?php endforeach; ?>
    </select>
    <select name="priority"><option value="">All priorities</option>
      <?php foreach ($PRIORITIES as $p): ?><option value="<?= e($p) ?>" <?= $fPriority===$p?'selected':'' ?>><?= e($p) ?></option><?php endforeach; ?>
    </select>
    <select name="room"><option value="">All rooms</option>
      <?php foreach ($rooms as $r): ?><option value="<?= (int)$r['id'] ?>" <?= $fRoom===(string)$r['id']?'selected':'' ?>><?= e($r['room_number']) ?></option><?php endforeach; ?>
    </select>
    <select name="staff"><option value="">All staff</option><option value="unassigned" <?= $fStaff==='unassigned'?'selected':'' ?>>Unassigned</option>
      <?php foreach ($staffList as $s): ?><option value="<?= (int)$s['id'] ?>" <?= $fStaff===(string)$s['id']?'selected':'' ?>><?= e($s['name']) ?></option><?php endforeach; ?>
    </select>
    <button class="btn sm" type="submit">Filter</button>
    <a class="btn ghost sm" href="index.php?page=repairs">Reset</a>
  </form>

  <div style="overflow-x:auto">
  <table class="tbl">
    <thead><tr><th>Report ID</th><th>Resident</th><th>Room</th><th>Category</th><th>Priority</th><th>Status</th><th>Date Reported</th><th>Assigned Staff</th><th class="no-print">Actions</th></tr></thead>
    <tbody>
    <?php if (!$rows): ?><tr><td colspan="9">No repair requests found.</td></tr><?php endif; ?>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><strong><?= e($r['report_id']) ?></strong></td>
        <td><?= e($r['resident_name']) ?></td>
        <td><?= e($r['room_number']) ?></td>
        <td><?= e($r['category']) ?></td>
        <td><span class="pill <?= repair_priority_class($r['priority']) ?>"><?= e($r['priority']) ?></span></td>
        <td><span class="pill <?= repair_status_class($r['status']) ?>"><?= e($r['status']) ?></span></td>
        <td><?= e(substr((string)$r['date_reported'], 0, 16)) ?></td>
        <td><?= e($r['staff_name'] ?? '—') ?></td>
        <td class="no-print">
          <button class="btn ghost sm" onclick="document.getElementById('view-<?= (int)$r['id'] ?>').classList.add('open')">View</button>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>

<?php foreach ($rows as $r): ?>
<div class="modal" id="view-<?= (int)$r['id'] ?>">
  <div class="box">
    <div class="row between"><h3 style="margin:0"><?= e($r['report_id']) ?> · <?= e($r['category']) ?></h3>
      <button class="btn ghost sm" onclick="document.getElementById('view-<?= (int)$r['id'] ?>').classList.remove('open')">Close</button></div>
    <p style="color:#7c6f8a;font-size:13px"><?= e($r['resident_name']) ?> · Room <?= e($r['room_number']) ?> · Reported <?= e(substr((string)$r['date_reported'], 0, 16)) ?></p>
    <p><span class="pill <?= repair_priority_class($r['priority']) ?>"><?= e($r['priority']) ?></span>
       <span class="pill <?= repair_status_class($r['status']) ?>"><?= e($r['status']) ?></span></p>
    <p><?= nl2br(e($r['description'])) ?></p>
    <?php if (!empty($r['photo_path'])): ?>
      <p><a href="uploads/repairs/<?= e(basename($r['photo_path'])) ?>" target="_blank" rel="noopener">
        <img src="uploads/repairs/<?= e(basename($r['photo_path'])) ?>" alt="Repair photo" style="max-width:100%;border-radius:10px;border:1px solid #efdff5"></a></p>
    <?php endif; ?>
    <?php if (!empty($r['resolution_notes'])): ?><p><strong>Resolution:</strong> <?= nl2br(e($r['resolution_notes'])) ?></p><?php endif; ?>
    <?php if (!empty($r['date_resolved'])): ?><p style="font-size:13px;color:#7c6f8a">Resolved: <?= e(substr((string)$r['date_resolved'], 0, 16)) ?></p><?php endif; ?>

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

    <h4>Assign staff</h4>
    <form method="post" action="<?= e($qs()) ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="assign_staff">
      <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
      <div class="row">
        <select name="assigned_staff_id" style="flex:1;padding:9px 11px;border:1px solid #efdff5;border-radius:10px">
          <option value="">— Unassigned —</option>
          <?php foreach ($staffList as $s): ?><option value="<?= (int)$s['id'] ?>" <?= (int)$r['assigned_staff_id']===(int)$s['id']?'selected':'' ?>><?= e($s['name']) ?></option><?php endforeach; ?>
        </select>
        <button class="btn sm">Assign</button>
      </div>
    </form>

    <h4>Update status</h4>
    <form method="post" action="<?= e($qs()) ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="update_status">
      <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
      <div class="form-grid">
        <div class="field"><label>Status</label>
          <select name="status"><?php foreach ($STATUSES as $s): ?><option value="<?= e($s) ?>" <?= $r['status']===$s?'selected':'' ?>><?= e($s) ?></option><?php endforeach; ?></select>
        </div>
        <div class="field"><label>Resolution notes</label>
          <textarea name="resolution_notes" rows="2" placeholder="Required when Completed"><?= e($r['resolution_notes'] ?? '') ?></textarea>
        </div>
      </div>
      <button class="btn sm">Save status</button>
      <?php if ($isAdmin): ?>
      <button class="btn danger sm" name="__unused" type="button" onclick="if(confirm('Delete <?= e($r['report_id']) ?>?')){document.getElementById('del-<?= (int)$r['id'] ?>').submit();}">Delete</button>
      <?php endif; ?>
    </form>
    <?php if ($isAdmin): ?>
    <form id="del-<?= (int)$r['id'] ?>" method="post" action="<?= e($qs()) ?>" style="display:none">
      <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
    </form>
    <?php endif; ?>
  </div>
</div>
<?php endforeach; ?>
