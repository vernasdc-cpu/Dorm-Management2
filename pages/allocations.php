<?php
// pages/allocations.php — Room occupancy and resident allocations.
// Housekeeping has read-only access; allocation changes are staff-only.
// Included AFTER layout_header. $user is available.
require_once __DIR__ . '/../includes/allocation_room_status.php';
$canManageAllocations = in_array($user['role'] ?? '', ['super_admin', 'admin_staff'], true);

if (!function_exists('refresh_room_status')) {
    function refresh_room_status(int $roomId): void {
        try {
            $pdo = db();
            $st = $pdo->prepare('SELECT status, max_occupants FROM rooms WHERE id=?');
            $st->execute([$roomId]);
            $r = $st->fetch();
            if (!$r) return;
            if (in_array($r['status'], ['Reserved', 'Under Maintenance', 'Unavailable'], true)) return;
            $c = $pdo->prepare("SELECT COUNT(*) FROM beds WHERE room_id=? AND status='occupied'");
            $c->execute([$roomId]);
            $occ = (int)$c->fetchColumn();
            $max = max(1, (int)$r['max_occupants']);
            $new = $occ <= 0 ? 'Available' : ($occ >= $max ? 'Fully Occupied' : 'Partially Occupied');
            if ($new !== $r['status']) {
                $u = $pdo->prepare('UPDATE rooms SET status=? WHERE id=?');
                $u->execute([$new, $roomId]);
            }
        } catch (Throwable $t) { error_log('refresh_room_status: ' . $t->getMessage()); }
    }
}

if (!function_exists('alloc_status_pill')) {
    function alloc_status_pill(string $s): string {
        $map = ['Available' => 'ok', 'Partially Occupied' => 'warn', 'Fully Occupied' => 'bad',
                'Reserved' => 'info', 'Under Maintenance' => 'warn', 'Unavailable' => 'bad',
                'active' => 'ok', 'reserved' => 'info', 'transferred' => 'warn', 'vacated' => '',
                'vacant' => 'ok', 'occupied' => 'bad', 'maintenance' => 'warn'];
        $class = str_starts_with($s, 'Reserved / ') ? 'info' : ($map[$s] ?? '');
        return '<span class="pill ' . $class . '">' . e(ucfirst($s)) . '</span>';
    }
}

function room_occ_row(PDO $pdo, int $roomId): array {
    $st = $pdo->prepare('SELECT max_occupants FROM rooms WHERE id=?');
    $st->execute([$roomId]);
    $max = max(1, (int)($st->fetchColumn() ?: 1));
    $c = $pdo->prepare("SELECT COUNT(*) FROM beds WHERE room_id=? AND status='occupied'");
    $c->execute([$roomId]);
    return [$max, (int)$c->fetchColumn()];
}

// ---------------- POST actions ----------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!$canManageAllocations) {
        http_response_code(403);
        echo '<div class="card"><h3>Forbidden</h3><p>Room Allocation is read-only for your role.</p></div>';
        return;
    }
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'transfer') {
        try {
            $pdo = db();
            $allocId = (int)($_POST['allocation_id'] ?? 0);
            $newRoomId = (int)($_POST['new_room_id'] ?? 0);
            $newBedId = (int)($_POST['new_bed_id'] ?? 0);
            if ($allocId <= 0 || $newRoomId <= 0 || $newBedId <= 0) throw new RuntimeException('Please select the allocation, new room and new bed.');
            $cur = $pdo->prepare("SELECT a.*, r.room_number AS old_room FROM room_allocations a JOIN rooms r ON r.id=a.room_id WHERE a.id=? AND a.status='active'");
            $cur->execute([$allocId]);
            $a = $cur->fetch();
            if (!$a) throw new RuntimeException('Active allocation not found.');
            [$max, $occ] = room_occ_row($pdo, $newRoomId);
            if ($occ >= $max) throw new RuntimeException('Room is already fully occupied.');
            $bed = $pdo->prepare('SELECT * FROM beds WHERE id=? AND room_id=?');
            $bed->execute([$newBedId, $newRoomId]);
            $nb = $bed->fetch();
            if (!$nb) throw new RuntimeException('Selected bed does not belong to the new room.');
            if ($nb['status'] !== 'vacant') throw new RuntimeException('Selected bed is no longer vacant.');
            $rm = $pdo->prepare('SELECT room_number FROM rooms WHERE id=?');
            $rm->execute([$newRoomId]);
            $newNo = (string)$rm->fetchColumn();
            $oldRoomId = (int)$a['room_id'];
            $pdo->beginTransaction();
            try {
                $pdo->prepare('UPDATE room_allocations SET status=\'transferred\', vacated_at=NOW() WHERE id=?')->execute([$allocId]);
                $pdo->prepare('UPDATE beds SET status=\'vacant\', resident_id=NULL WHERE room_id=? AND bed_number=?')->execute([$oldRoomId, $a['bed_no']]);
                $pdo->prepare('UPDATE beds SET status=\'occupied\', resident_id=? WHERE id=?')->execute([$a['resident_id'], $newBedId]);
                $pdo->prepare('UPDATE residents SET room_id=?, bed_no=? WHERE residents_id=?')->execute([$newRoomId, $nb['bed_number'], $a['resident_id']]);
                $pdo->prepare('INSERT INTO room_allocations (resident_id, room_id, bed_no, status, allocated_by, remarks) VALUES (?,?,?,\'active\',?,?)')
                    ->execute([$a['resident_id'], $newRoomId, $nb['bed_number'], (int)$user['id'], 'Transferred from room ' . $a['old_room']]);
                $pdo->commit();
            } catch (Throwable $t) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $t;
            }
            refresh_room_status($oldRoomId);
            refresh_room_status($newRoomId);
            audit_log((int)$user['id'], 'Transferred resident #' . $a['resident_id'] . ' from room ' . $a['old_room'] . ' to room ' . $newNo, 'allocations', (string)$allocId);
            flash('success', 'Resident transferred to room ' . $newNo . ' (' . $nb['bed_number'] . ').');
        } catch (RuntimeException $e) {
            flash('error', $e->getMessage());
        } catch (Throwable $e) {
            error_log('allocations.php transfer: ' . $e->getMessage());
            flash('error', 'Could not transfer the resident. Please try again.');
        }
        redirect('index.php?page=allocations');
    }

    if ($action === 'vacate') {
        try {
            $pdo = db();
            $allocId = (int)($_POST['allocation_id'] ?? 0);
            $cur = $pdo->prepare("SELECT a.*, r.room_number FROM room_allocations a JOIN rooms r ON r.id=a.room_id WHERE a.id=? AND a.status IN ('active','reserved')");
            $cur->execute([$allocId]);
            $a = $cur->fetch();
            if (!$a) throw new RuntimeException('Allocation not found or already closed.');
            $pdo->beginTransaction();
            try {
                $pdo->prepare("UPDATE room_allocations SET status='vacated', vacated_at=NOW() WHERE id=?")->execute([$allocId]);
                $pdo->prepare('UPDATE beds SET status=\'vacant\', resident_id=NULL WHERE room_id=? AND bed_number=?')->execute([$a['room_id'], $a['bed_no']]);
                $pdo->prepare('UPDATE residents SET room_id=NULL, bed_no=NULL WHERE residents_id=?')->execute([$a['resident_id']]);
                $pdo->commit();
            } catch (Throwable $t) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $t;
            }
            refresh_room_status((int)$a['room_id']);
            audit_log((int)$user['id'], 'Vacated resident #' . $a['resident_id'] . ' from room ' . $a['room_number'], 'allocations', (string)$allocId);
            flash('success', 'Bed vacated in room ' . $a['room_number'] . '.');
        } catch (RuntimeException $e) {
            flash('error', $e->getMessage());
        } catch (Throwable $e) {
            error_log('allocations.php vacate: ' . $e->getMessage());
            flash('error', 'Could not vacate the bed. Please try again.');
        }
        redirect('index.php?page=allocations');
    }

    if ($action === 'clear_history') {
        try {
            if (($user['role'] ?? '') !== 'super_admin') {
                throw new RuntimeException('Only the Super Admin can clear room allocation history.');
            }
            $pdo = db();
            $cleared = (int)$pdo->exec("DELETE FROM room_allocations WHERE status IN ('vacated','transferred')");
            audit_log((int)$user['id'], 'Cleared room allocation history (' . $cleared . ' record(s))', 'allocations');
            flash('success', 'Room allocation history cleared (' . $cleared . ' record(s)).');
        } catch (RuntimeException $e) {
            flash('error', $e->getMessage());
        } catch (Throwable $e) {
            error_log('allocations.php clear_history: ' . $e->getMessage());
            flash('error', 'Could not clear allocation history. Please try again.');
        }
        redirect('index.php?page=allocations');
    }

}

// ---------------- Data ----------------
$q = trim((string)($_GET['q'] ?? ''));
$rooms = []; $active = []; $history = []; $vacantBeds = []; $tenantProfiles = []; $loadError = null;
try {
    $pdo = db();
    $where = ''; $params = [];
    if ($q !== '') { $where = 'WHERE r.room_number LIKE ?'; $params[] = '%' . $q . '%'; }
    $st = $pdo->prepare("SELECT r.*, f.name AS floor_name, t.name AS type_name,
              (SELECT COUNT(*) FROM beds b WHERE b.room_id=r.id AND b.status='occupied') AS occupied,
              (SELECT COUNT(*) FROM beds b WHERE b.room_id=r.id AND b.status='reserved') AS reserved
            FROM rooms r JOIN floors f ON f.id=r.floor_id JOIN room_types t ON t.id=r.room_type_id
            $where ORDER BY r.room_number");
    $st->execute($params);
    $rooms = $st->fetchAll();

    if ($canManageAllocations) {
        $vacantBeds = $pdo->query("SELECT b.id, b.room_id, b.bed_number, r.room_number
            FROM beds b JOIN rooms r ON r.id=b.room_id WHERE b.status='vacant'
            ORDER BY r.room_number, CAST(SUBSTRING(b.bed_number,2) AS UNSIGNED)")->fetchAll();
    }
    $tenantProfiles = $pdo->query("SELECT tp.id, tp.full_name, tp.email, tp.unit_number, tp.room_id, tp.bed_id,
              tp.contract_start, tp.contract_end, tp.profile_status,
              r.room_number, b.bed_number
        FROM tenant_profiles tp
        LEFT JOIN rooms r ON r.id=tp.room_id
        LEFT JOIN beds b ON b.id=tp.bed_id
        ORDER BY tp.full_name")->fetchAll();
    $active = $pdo->query("SELECT a.*, u.name AS resident_name, r.room_number, f.name AS floor_name
        FROM room_allocations a
        JOIN residents res ON res.residents_id=a.resident_id
        JOIN users u ON u.id=res.user_id
        JOIN rooms r ON r.id=a.room_id
        JOIN floors f ON f.id=r.floor_id
        WHERE a.status IN ('active','reserved') ORDER BY a.allocated_at DESC")->fetchAll();

    $history = $pdo->query("SELECT a.*, u.name AS resident_name, r.room_number
        FROM room_allocations a
        JOIN residents res ON res.residents_id=a.resident_id
        JOIN users u ON u.id=res.user_id
        JOIN rooms r ON r.id=a.room_id
        WHERE a.status IN ('transferred','vacated')
        ORDER BY COALESCE(a.vacated_at, a.allocated_at) DESC LIMIT 60")->fetchAll();
} catch (Throwable $e) {
    error_log('allocations.php load: ' . $e->getMessage());
    $loadError = 'Could not load allocation data right now. Please try again later.';
}
?>

<script>
function allocFilterBeds(rId, bId) {
  var r = document.getElementById(rId), b = document.getElementById(bId);
  if (!r || !b) return;
  var v = r.value, first = null;
  for (var i = 0; i < b.options.length; i++) {
    var o = b.options[i];
    var show = !v || o.getAttribute('data-room') === v;
    o.hidden = !show;
    if (show && !first) first = o;
  }
  if (first) b.value = first.value;
}
</script>

<div class="card">
  <div class="row between" style="flex-wrap:wrap;gap:10px">
    <div><h3 style="margin:0">Rooms &amp; Occupancy</h3>
    <small style="color:#7c6f8a">View occupied and reserved beds per room.<?php if ($canManageAllocations): ?> Create reservations in Reservations and assign tenants in Tenant Profiles.<?php else: ?> This page is read-only.<?php endif; ?></small></div>
    <form class="toolbar" method="get" action="index.php" style="margin:0">
      <input type="hidden" name="page" value="allocations">
      <input name="q" placeholder="Search room" value="<?=e($q)?>">
      <button class="btn sm">Search</button>
    </form>
  </div>
</div>

<?php if ($loadError): ?>
  <div class="alert error" style="margin-top:12px"><?=e($loadError)?></div>
<?php else: ?>

<div class="card" style="margin-top:12px;overflow:auto">
<table class="tbl"><thead><tr>
  <th>Room</th><th>Floor</th><th>Type</th><th>Aircon</th><th>Occupancy</th><th>Rate</th><th>Status</th>
</tr></thead><tbody>
<?php foreach ($rooms as $r):
  $occ = (int)$r['occupied']; $max = max(1, (int)$r['max_occupants']);
  $pct = (int)round($occ / $max * 100);
  $reserved = (int)$r['reserved'];
  $status = allocation_room_status((string)$r['status'], $occ, $reserved, $max);
?>
<tr>
  <td><strong><?=e($r['room_number'])?></strong></td>
  <td><?=e($r['floor_name'])?></td>
  <td><?=e($r['type_name'])?></td>
  <td><?=e($r['aircon'])?></td>
  <td style="min-width:140px"><?=$occ?> / <?=$max?>
    <div style="height:8px;background:#f3e8ff;border-radius:6px;overflow:hidden;margin-top:4px">
      <div style="height:100%;width:<?=$pct?>%;background:linear-gradient(135deg,#FF00FF,#8F00FF)"></div>
    </div>
  </td>
  <td><?=money($r['monthly_rate'])?></td>
  <td>
    <?=alloc_status_pill($status === 'Reserved' && $reserved > 0 ? 'Reserved / ' . $reserved : $status)?>
    <?php if ($reserved > 0 && $status !== 'Reserved'): ?><span class="pill info">Reserved / <?=$reserved?></span><?php endif; ?>
    <?php if ($r['status'] === 'Reserved' && $status !== 'Reserved'): ?><span class="pill info">Room reserved</span><?php endif; ?>
  </td>
</tr>
<?php endforeach; ?>
</tbody></table>
</div>

<div class="card" style="margin-top:14px">
  <h3>Tenant Profiles in Rooms (<?=count($tenantProfiles)?>)</h3>
  <div style="overflow:auto"><table class="tbl"><thead><tr>
    <th>Tenant</th><th>Room</th><th>Bed</th><th>Contract</th><th>Payment</th>
  </tr></thead><tbody>
  <?php if (!$tenantProfiles): ?><tr><td colspan="5">No tenant profiles yet. Add one from Tenant Profiles.</td></tr><?php endif; ?>
  <?php foreach ($tenantProfiles as $tp): ?>
  <tr>
    <td><?=e($tp['full_name'])?></td>
    <td><?=e($tp['room_number'] ?? ($tp['unit_number'] ?: '—'))?></td>
    <td><?=e($tp['bed_number'] ?? '—')?></td>
    <td><?= $tp['contract_start'] && $tp['contract_end'] ? e($tp['contract_start']) . ' → ' . e($tp['contract_end']) : '—' ?></td>
  </tr>
  <?php endforeach; ?>
  </tbody></table></div>
</div>

<div class="card" style="margin-top:14px">
  <h3>Resident Allocations (<?=count($active)?>)</h3>
  <div style="overflow:auto"><table class="tbl"><thead><tr>
    <th>Resident</th><th>Room</th><th>Bed</th><th>Status</th><th>Since</th><?php if ($canManageAllocations): ?><th>Actions</th><?php endif; ?>
  </tr></thead><tbody>
  <?php if (!$active): ?><tr><td colspan="<?=$canManageAllocations ? 6 : 5?>">No active resident allocations.</td></tr><?php endif; ?>
  <?php foreach ($active as $a): ?>
  <tr>
    <td><?=e($a['resident_name'])?></td>
    <td><?=e($a['room_number'])?> <small style="color:#7c6f8a">(<?=e($a['floor_name'])?>)</small></td>
    <td><?=e($a['bed_no'] ?? '—')?></td>
    <td><?=alloc_status_pill($a['status'])?></td>
    <td><?=e(substr((string)$a['allocated_at'], 0, 10))?></td>
    <?php if ($canManageAllocations): ?><td><div class="row" style="flex-wrap:wrap">
      <button class="btn ghost sm" onclick="openModal('tr-<?=$a['id']?>');allocFilterBeds('trr-<?=$a['id']?>','trb-<?=$a['id']?>')">Transfer</button>
      <form method="post" style="display:inline" onsubmit="return confirmDel('Vacate this bed?')">
        <?=csrf_field()?><input type="hidden" name="action" value="vacate">
        <input type="hidden" name="allocation_id" value="<?=$a['id']?>">
        <button class="btn danger sm">Vacate</button>
      </form>
    </div></td><?php endif; ?>
  </tr>
  <?php endforeach; ?>
  </tbody></table></div>
</div>

<?php if ($canManageAllocations): foreach ($active as $a): ?>
<div class="modal" id="tr-<?=$a['id']?>" onclick="if(event.target===this)closeModal('tr-<?=$a['id']?>')">
  <div class="box">
    <div class="row between"><h3>Transfer <?=e($a['resident_name'])?></h3>
      <button class="btn ghost sm" onclick="closeModal('tr-<?=$a['id']?>')">Close</button></div>
    <p style="font-size:13px;color:#7c6f8a">From room <?=e($a['room_number'])?> (<?=e($a['bed_no'] ?? '')?>)</p>
    <form method="post">
      <?=csrf_field()?>
      <input type="hidden" name="action" value="transfer">
      <input type="hidden" name="allocation_id" value="<?=$a['id']?>">
      <div class="field"><label>New room</label><select id="trr-<?=$a['id']?>" name="new_room_id" required onchange="allocFilterBeds('trr-<?=$a['id']?>','trb-<?=$a['id']?>')">
        <?php foreach ($rooms as $r): ?>
          <option value="<?=$r['id']?>" <?=((int)$r['id']===(int)$a['room_id'])?'disabled':''?>>Room <?=e($r['room_number'])?> (<?=e($r['status'])?>)</option>
        <?php endforeach; ?>
      </select></div>
      <div class="field"><label>New bed (vacant)</label><select id="trb-<?=$a['id']?>" name="new_bed_id" required>
        <?php foreach ($vacantBeds as $vb): ?>
          <option value="<?=$vb['id']?>" data-room="<?=$vb['room_id']?>">Room <?=e($vb['room_number'])?> – <?=e($vb['bed_number'])?></option>
        <?php endforeach; ?>
      </select></div>
      <button class="btn sm">Transfer</button>
    </form>
  </div>
</div>
<?php endforeach; endif; ?>

<div class="card" style="margin-top:14px">
  <div class="row between" style="flex-wrap:wrap;gap:10px">
    <h3 style="margin:0">Room History (recent vacated / transferred)</h3>
    <?php if ($history && ($user['role'] ?? '') === 'super_admin'): ?>
    <form method="post" onsubmit="return confirmDel('Clear all vacated and transferred allocation history? This cannot be undone.')">
      <?=csrf_field()?>
      <input type="hidden" name="action" value="clear_history">
      <button class="btn danger sm">Clear History</button>
    </form>
    <?php endif; ?>
  </div>
  <div style="overflow:auto"><table class="tbl"><thead><tr>
    <th>Resident</th><th>Room</th><th>Bed</th><th>Status</th><th>Allocated</th><th>Closed</th><th>Remarks</th>
  </tr></thead><tbody>
  <?php if (!$history): ?><tr><td colspan="7">No history yet.</td></tr><?php endif; ?>
  <?php foreach ($history as $h): ?>
  <tr>
    <td><?=e($h['resident_name'])?></td>
    <td><?=e($h['room_number'])?></td>
    <td><?=e($h['bed_no'] ?? '—')?></td>
    <td><?=alloc_status_pill($h['status'])?></td>
    <td><?=e(substr((string)$h['allocated_at'], 0, 10))?></td>
    <td><?=e($h['vacated_at'] ? substr((string)$h['vacated_at'], 0, 10) : '—')?></td>
    <td><?=e($h['remarks'] ?? '—')?></td>
  </tr>
  <?php endforeach; ?>
  </tbody></table></div>
</div>

<?php endif; ?>
