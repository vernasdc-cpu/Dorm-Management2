<?php
// pages/rooms.php — Room management (cards + filters + CRUD + status).
// Roles: super_admin, admin_staff (full), housekeeping (read-only).
// Included inside index.php AFTER layout_header. $user is available.

$isReadOnly = (($user['role'] ?? '') === 'housekeeping');

function room_type_name_by_id(PDO $pdo, int $typeId): string {
    $st = $pdo->prepare('SELECT name FROM room_types WHERE id=?');
    $st->execute([$typeId]);
    return (string)$st->fetchColumn();
}

function room_beds_ordered(PDO $pdo, int $roomId, bool $lock = false): array {
    $sql = 'SELECT * FROM beds WHERE room_id=? ORDER BY CAST(SUBSTRING(bed_number,2) AS UNSIGNED), id';
    if ($lock) $sql .= ' FOR UPDATE';
    $st = $pdo->prepare($sql);
    $st->execute([$roomId]);
    return $st->fetchAll();
}

// Tables that keep historical records and must not be silently deleted with a room.
// Beds cascade and tenant/housekeeping links are SET NULL, so they are not blockers.
function room_delete_blockers(PDO $pdo, int $roomId): array {
    $checks = [
        'bills' => 'bill',
        'contracts' => 'contract',
        'room_allocations' => 'allocation record',
        'repair_requests' => 'repair request',
        'tenant_profiles' => 'tenant profile',
    ];
    $blockers = [];
    foreach ($checks as $table => $label) {
        $st = $pdo->prepare("SELECT COUNT(*) FROM $table WHERE room_id=?");
        $st->execute([$roomId]);
        $count = (int)$st->fetchColumn();
        if ($count > 0) $blockers[] = $count . ' ' . $label . ($count === 1 ? '' : 's');
    }
    return $blockers;
}

// Turn posted bed-number fields into exactly $max unique labels (defaults B1..Bn).
function room_normalize_bed_labels($raw, int $max): array {
    if (!is_array($raw)) $raw = ($raw === null || $raw === '') ? [] : [$raw];
    $posted = [];
    foreach ($raw as $v) {
        $v = trim((string)$v);
        if ($v !== '') $posted[] = $v;
    }
    $labels = [];
    $seen = [];
    for ($i = 0; $i < $max; $i++) {
        $label = $posted[$i] ?? '';
        if ($label === '') {
            $n = $i + 1;
            do {
                $candidate = 'B' . $n;
                $n++;
            } while (isset($seen[strtolower($candidate)]));
            $label = $candidate;
        }
        if (strlen($label) > 10) throw new RuntimeException('Bed numbers must be 10 characters or fewer.');
        if (!preg_match('/^[A-Za-z0-9 _-]{1,10}$/', $label)) {
            throw new RuntimeException('Bed numbers may only contain letters, numbers, spaces, hyphens or underscores.');
        }
        $key = strtolower($label);
        if (isset($seen[$key])) throw new RuntimeException('Bed numbers must be unique within the room.');
        $seen[$key] = true;
        $labels[] = $label;
    }
    return $labels;
}

if (!function_exists('refresh_room_status')) {
    function refresh_room_status(int $roomId): void {
        try {
            $pdo = db();
            $st = $pdo->prepare('SELECT status, max_occupants FROM rooms WHERE id=?');
            $st->execute([$roomId]);
            $r = $st->fetch();
            if (!$r) return;
            // Keep manually-set statuses; only auto-derive the occupancy ones.
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

if (!function_exists('room_status_pill')) {
    function room_status_pill(string $s): string {
        $map = [
            'Available' => 'ok', 'Partially Occupied' => 'warn', 'Fully Occupied' => 'bad',
            'Reserved' => 'info', 'Under Maintenance' => 'warn', 'Unavailable' => 'bad',
        ];
        $cls = $map[$s] ?? '';
        return '<span class="pill ' . $cls . '">' . e($s) . '</span>';
    }
}

$ROOM_STATUSES = ['Available', 'Partially Occupied', 'Fully Occupied', 'Reserved', 'Under Maintenance', 'Unavailable'];

// ---------------- POST mutations (admin roles only) ----------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if ($isReadOnly) {
        flash('error', 'Your role is read-only and cannot modify rooms.');
        redirect('index.php?page=rooms');
    }
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'create_unit') {
        try {
            $pdo = db();
            $unitNumber = trim((string)($_POST['unit_number'] ?? ''));
            $floorId = (int)($_POST['floor_id'] ?? 0);
            if ($unitNumber === '' || strlen($unitNumber) > 20 || $floorId <= 0) {
                throw new RuntimeException('Enter a unit number and choose a floor.');
            }
            $floorCheck = $pdo->prepare('SELECT id FROM floors WHERE id=?');
            $floorCheck->execute([$floorId]);
            if (!$floorCheck->fetchColumn()) throw new RuntimeException('Please choose a valid floor.');

            $duplicate = $pdo->prepare('SELECT id FROM units WHERE unit_number=? AND floor_id=?');
            $duplicate->execute([$unitNumber, $floorId]);
            if ($duplicate->fetchColumn()) throw new RuntimeException('This unit already exists on the selected floor.');

            $st = $pdo->prepare('INSERT INTO units (unit_number, floor_id) VALUES (?,?)');
            $st->execute([$unitNumber, $floorId]);
            $unitId = (int)$pdo->lastInsertId();
            audit_log((int)$user['id'], 'Created unit ' . $unitNumber, 'rooms', (string)$unitId);
            flash('success', 'Unit ' . $unitNumber . ' created successfully.');
        } catch (RuntimeException $e) {
            flash('error', $e->getMessage());
        } catch (Throwable $e) {
            error_log('rooms.php create_unit: ' . $e->getMessage());
            flash('error', 'Could not create the unit. Please try again.');
        }
        redirect('index.php?page=rooms');
    }

    if ($action === 'create_room') {
        try {
            $pdo = db();
            $roomNo  = trim((string)($_POST['room_number'] ?? ''));
            $unitId  = (int)($_POST['unit_id'] ?? 0);
            $typeId  = (int)($_POST['room_type_id'] ?? 0);
            $aircon  = $_POST['aircon'] ?? 'Non-Aircon';
            $max     = (int)($_POST['max_occupants'] ?? 0);
            $rate    = (float)($_POST['monthly_rate'] ?? 0);
            $status  = $_POST['status'] ?? 'Available';
            $desc    = trim((string)($_POST['description'] ?? ''));
            if ($roomNo === '' || $unitId <= 0 || $typeId <= 0) throw new RuntimeException('Please complete all required fields.');
            $unitSt = $pdo->prepare('SELECT id, floor_id, unit_number FROM units WHERE id=?');
            $unitSt->execute([$unitId]);
            $unit = $unitSt->fetch();
            if (!$unit) throw new RuntimeException('Please choose a valid unit.');
            $floorId = (int)$unit['floor_id'];
            if (!in_array($aircon, ['Aircon', 'Non-Aircon'], true)) $aircon = 'Non-Aircon';
            if (!in_array($status, $ROOM_STATUSES, true)) $status = 'Available';
            $typeName = room_type_name_by_id($pdo, $typeId);
            if ($typeName === '') throw new RuntimeException('Please choose a valid room type.');
            if ($typeName === 'Solo') $max = 1;
            if ($max < 1 || $max > 12) throw new RuntimeException('Capacity must be between 1 and 12.');
            if ($rate < 0) throw new RuntimeException('Monthly rate cannot be negative.');
            $labels = room_normalize_bed_labels($_POST['bed_numbers'] ?? [], $max);
            $dup = $pdo->prepare('SELECT id FROM rooms WHERE room_number=?');
            $dup->execute([$roomNo]);
            if ($dup->fetch()) throw new RuntimeException('This room number is already in the room list. Please enter a different room number.');
            $pdo->beginTransaction();
            try {
                $ins = $pdo->prepare('INSERT INTO rooms (room_number, unit_id, floor_id, room_type_id, aircon, max_occupants, monthly_rate, status, description) VALUES (?,?,?,?,?,?,?,?,?)');
                $ins->execute([$roomNo, $unitId, $floorId, $typeId, $aircon, $max, $rate, $status, $desc ?: null]);
                $roomId = (int)$pdo->lastInsertId();
                $bed = $pdo->prepare('INSERT INTO beds (room_id, bed_number, status) VALUES (?,?,\'vacant\')');
                foreach ($labels as $label) $bed->execute([$roomId, $label]);
                $pdo->commit();
            } catch (Throwable $t) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $t;
            }
            refresh_room_status($roomId);
            audit_log((int)$user['id'], 'Created room ' . $roomNo, 'rooms', (string)$roomId);
            flash('success', 'Room ' . $roomNo . ' created successfully.');
        } catch (RuntimeException $e) {
            flash('error', $e->getMessage());
        } catch (Throwable $e) {
            error_log('rooms.php create_room: ' . $e->getMessage());
            flash('error', 'Could not create the room. Please try again.');
        }
        redirect('index.php?page=rooms');
    }

    if ($action === 'update_room') {
        try {
            $pdo = db();
            $roomId  = (int)($_POST['room_id'] ?? 0);
            $roomNo  = trim((string)($_POST['room_number'] ?? ''));
            $unitId  = (int)($_POST['unit_id'] ?? 0);
            $typeId  = (int)($_POST['room_type_id'] ?? 0);
            $aircon  = $_POST['aircon'] ?? 'Non-Aircon';
            $max     = (int)($_POST['max_occupants'] ?? 0);
            $rate    = (float)($_POST['monthly_rate'] ?? 0);
            $status  = $_POST['status'] ?? 'Available';
            $desc    = trim((string)($_POST['description'] ?? ''));
            $cur = $pdo->prepare('SELECT * FROM rooms WHERE id=?');
            $cur->execute([$roomId]);
            $room = $cur->fetch();
            if (!$room) throw new RuntimeException('Room not found.');
            if ($roomNo === '' || $unitId <= 0 || $typeId <= 0) throw new RuntimeException('Please complete all required fields.');
            $unitSt = $pdo->prepare('SELECT id, floor_id FROM units WHERE id=?');
            $unitSt->execute([$unitId]);
            $unit = $unitSt->fetch();
            if (!$unit) throw new RuntimeException('Please choose a valid unit.');
            $floorId = (int)$unit['floor_id'];
            if (!in_array($aircon, ['Aircon', 'Non-Aircon'], true)) $aircon = 'Non-Aircon';
            if (!in_array($status, $ROOM_STATUSES, true)) $status = 'Available';
            $typeName = room_type_name_by_id($pdo, $typeId);
            if ($typeName === '') throw new RuntimeException('Please choose a valid room type.');
            if ($typeName === 'Solo') $max = 1;
            if ($max < 1 || $max > 12) throw new RuntimeException('Capacity must be between 1 and 12.');
            if ($rate < 0) throw new RuntimeException('Monthly rate cannot be negative.');
            $labels = room_normalize_bed_labels($_POST['bed_numbers'] ?? [], $max);
            $occ = $pdo->prepare("SELECT COUNT(*) FROM beds WHERE room_id=? AND status='occupied'");
            $occ->execute([$roomId]);
            $occupied = (int)$occ->fetchColumn();
            if ($max < $occupied) {
                throw new RuntimeException('Room capacity cannot go below the ' . $occupied . ' occupied bed(s).');
            }
            $dup = $pdo->prepare('SELECT id FROM rooms WHERE room_number=? AND id<>?');
            $dup->execute([$roomNo, $roomId]);
            if ($dup->fetch()) throw new RuntimeException('This room number is already in the room list. Please enter a different room number.');
            $pdo->beginTransaction();
            try {
                $upd = $pdo->prepare('UPDATE rooms SET room_number=?, unit_id=?, floor_id=?, room_type_id=?, aircon=?, max_occupants=?, monthly_rate=?, status=?, description=? WHERE id=?');
                $upd->execute([$roomNo, $unitId, $floorId, $typeId, $aircon, $max, $rate, $status, $desc ?: null, $roomId]);

                $beds = room_beds_ordered($pdo, $roomId, true);
                if (count($beds) > $max) {
                    // Drop surplus vacant beds (highest numbers first), never occupied/reserved ones.
                    $excess = count($beds) - $max;
                    $ids = $pdo->prepare("SELECT id FROM beds WHERE room_id=? AND status='vacant' ORDER BY CAST(SUBSTRING(bed_number,2) AS UNSIGNED) DESC LIMIT " . (int)$excess);
                    $ids->execute([$roomId]);
                    foreach ($ids->fetchAll(PDO::FETCH_COLUMN) as $bid) {
                        $pdo->prepare('DELETE FROM beds WHERE id=?')->execute([$bid]);
                    }
                    $beds = room_beds_ordered($pdo, $roomId, true);
                }

                // Occupied/reserved beds keep their label so allocation history stays valid.
                // Vacant beds can be renamed; final labels must still be unique.
                $kept = array_slice($beds, 0, $max);
                $finalLabels = [];
                foreach ($kept as $i => $bed) {
                    $finalLabels[$i] = $bed['status'] === 'vacant' ? $labels[$i] : $bed['bed_number'];
                }
                for ($i = count($beds); $i < $max; $i++) $finalLabels[$i] = $labels[$i];
                $seenFinal = [];
                foreach ($finalLabels as $label) {
                    $key = strtolower($label);
                    if (isset($seenFinal[$key])) throw new RuntimeException('Bed numbers must be unique within the room.');
                    $seenFinal[$key] = true;
                }
                // Beds beyond the new capacity may remain when they are reserved/occupied.
                foreach (array_slice($beds, $max) as $extraBed) {
                    $key = strtolower($extraBed['bed_number']);
                    if (isset($seenFinal[$key])) throw new RuntimeException('Bed numbers must be unique within the room.');
                    $seenFinal[$key] = true;
                }

                $pending = [];
                foreach ($kept as $i => $bed) {
                    if ($bed['status'] === 'vacant' && $bed['bed_number'] !== $labels[$i]) {
                        $pending[] = ['id' => (int)$bed['id'], 'label' => $labels[$i]];
                    }
                }
                if ($pending) {
                    // Temporary labels avoid unique-key collisions while swapping names.
                    foreach ($pending as $p) {
                        $pdo->prepare('UPDATE beds SET bed_number=? WHERE id=?')->execute(['~' . substr((string)$p['id'], -9), $p['id']]);
                    }
                    foreach ($pending as $p) {
                        $pdo->prepare('UPDATE beds SET bed_number=? WHERE id=?')->execute([$p['label'], $p['id']]);
                    }
                }

                if (count($beds) < $max) {
                    $bed = $pdo->prepare('INSERT INTO beds (room_id, bed_number, status) VALUES (?,?,\'vacant\')');
                    for ($i = count($beds); $i < $max; $i++) $bed->execute([$roomId, $labels[$i]]);
                }
                $pdo->commit();
            } catch (Throwable $t) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $t;
            }
            refresh_room_status($roomId);
            audit_log((int)$user['id'], 'Updated room ' . $roomNo, 'rooms', (string)$roomId);
            flash('success', 'Room ' . $roomNo . ' updated successfully.');
        } catch (RuntimeException $e) {
            flash('error', $e->getMessage());
        } catch (Throwable $e) {
            error_log('rooms.php update_room: ' . $e->getMessage());
            flash('error', 'Could not update the room. Please try again.');
        }
        redirect('index.php?page=rooms');
    }

    if ($action === 'delete_room') {
        try {
            $pdo = db();
            $roomId = (int)($_POST['room_id'] ?? 0);
            $cur = $pdo->prepare('SELECT room_number FROM rooms WHERE id=?');
            $cur->execute([$roomId]);
            $room = $cur->fetch();
            if (!$room) throw new RuntimeException('Room not found.');
            $a = $pdo->prepare("SELECT COUNT(*) FROM room_allocations WHERE room_id=? AND status='active'");
            $a->execute([$roomId]);
            $o = $pdo->prepare("SELECT COUNT(*) FROM beds WHERE room_id=? AND status='occupied'");
            $o->execute([$roomId]);
            if ((int)$a->fetchColumn() > 0 || (int)$o->fetchColumn() > 0) {
                throw new RuntimeException('Cannot delete room with active occupants.');
            }
            $blockers = room_delete_blockers($pdo, $roomId);
            if ($blockers) {
                throw new RuntimeException(
                    'Cannot delete Room ' . $room['room_number'] . ' because it is linked to '
                    . implode(', ', $blockers)
                    . '. Set it to Unavailable instead to preserve the history, or remove those records first.'
                );
            }
            $pdo->prepare('DELETE FROM rooms WHERE id=?')->execute([$roomId]);
            audit_log((int)$user['id'], 'Deleted room ' . $room['room_number'], 'rooms', (string)$roomId);
            flash('success', 'Room ' . $room['room_number'] . ' deleted successfully.');
        } catch (RuntimeException $e) {
            flash('error', $e->getMessage());
        } catch (PDOException $e) {
            error_log('rooms.php delete_room: ' . $e->getMessage());
            flash('error', 'This room cannot be deleted because related records still reference it. Set it to Unavailable instead.');
        } catch (Throwable $e) {
            error_log('rooms.php delete_room: ' . $e->getMessage());
            flash('error', 'Could not delete the room. Please try again.');
        }
        redirect('index.php?page=rooms');
    }

    if ($action === 'set_status') {
        try {
            $pdo = db();
            $roomId = (int)($_POST['room_id'] ?? 0);
            $status = $_POST['status'] ?? '';
            $allowed = ['Available', 'Reserved', 'Under Maintenance', 'Unavailable'];
            if (!in_array($status, $allowed, true)) throw new RuntimeException('Invalid status.');
            $cur = $pdo->prepare('SELECT room_number FROM rooms WHERE id=?');
            $cur->execute([$roomId]);
            $room = $cur->fetch();
            if (!$room) throw new RuntimeException('Room not found.');
            $pdo->prepare('UPDATE rooms SET status=? WHERE id=?')->execute([$status, $roomId]);
            refresh_room_status($roomId); // no-op for manual statuses, recomputes for Available
            audit_log((int)$user['id'], 'Set room ' . $room['room_number'] . ' status to ' . $status, 'rooms', (string)$roomId);
            flash('success', 'Room ' . $room['room_number'] . ' is now ' . $status . '.');
        } catch (RuntimeException $e) {
            flash('error', $e->getMessage());
        } catch (Throwable $e) {
            error_log('rooms.php set_status: ' . $e->getMessage());
            flash('error', 'Could not update room status. Please try again.');
        }
        redirect('index.php?page=rooms');
    }
}

// ---------------- Filters + data ----------------
$fSearch = trim((string)($_GET['search'] ?? ''));
$fFloor  = $_GET['floor_id'] ?? '';
$fType   = $_GET['room_type_id'] ?? '';
$fAircon = $_GET['aircon'] ?? '';
$fStatus = $_GET['status'] ?? '';

$floors = []; $types = []; $units = []; $rooms = []; $bedsByRoom = []; $tenantsByRoom = []; $tenantByBed = []; $loadError = null;
try {
    $pdo = db();
    $floors = $pdo->query('SELECT * FROM floors ORDER BY name')->fetchAll();
    $types  = $pdo->query('SELECT * FROM room_types ORDER BY name')->fetchAll();
    $units = $pdo->query("SELECT un.id, un.unit_number, un.floor_id, f.name AS floor_name, COUNT(r.id) AS room_count
        FROM units un JOIN floors f ON f.id=un.floor_id
        LEFT JOIN rooms r ON r.unit_id=un.id
        GROUP BY un.id, un.unit_number, un.floor_id, f.name
        ORDER BY un.unit_number, f.name")->fetchAll();

    $where = []; $params = [];
    if ($fSearch !== '') { $where[] = 'r.room_number LIKE ?'; $params[] = '%' . $fSearch . '%'; }
    if ($fFloor !== '' && ctype_digit((string)$fFloor)) { $where[] = 'r.floor_id=?'; $params[] = (int)$fFloor; }
    if ($fType !== '' && ctype_digit((string)$fType)) { $where[] = 'r.room_type_id=?'; $params[] = (int)$fType; }
    if (in_array($fAircon, ['Aircon', 'Non-Aircon'], true)) { $where[] = 'r.aircon=?'; $params[] = $fAircon; }
    if (in_array($fStatus, $ROOM_STATUSES, true)) { $where[] = 'r.status=?'; $params[] = $fStatus; }

    $sql = "SELECT r.*, f.name AS floor_name, t.name AS type_name,
              un.unit_number AS linked_unit_number,
              (SELECT COUNT(*) FROM beds b WHERE b.room_id=r.id AND b.status='occupied') AS occupied,
              (
                EXISTS(SELECT 1 FROM bills b WHERE b.room_id=r.id)
                OR EXISTS(SELECT 1 FROM contracts c WHERE c.room_id=r.id)
                OR EXISTS(SELECT 1 FROM room_allocations ra WHERE ra.room_id=r.id)
                OR EXISTS(SELECT 1 FROM repair_requests rr WHERE rr.room_id=r.id)
                OR EXISTS(SELECT 1 FROM tenant_profiles tp WHERE tp.room_id=r.id)
              ) AS has_history
            FROM rooms r
            JOIN floors f ON f.id=r.floor_id
            JOIN room_types t ON t.id=r.room_type_id
            LEFT JOIN units un ON un.id=r.unit_id"
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '') .
            ' ORDER BY r.room_number';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rooms = $st->fetchAll();
    usort($rooms, static function (array $a, array $b): int {
        $unitA = $a['linked_unit_number'] ?: explode('-', (string)$a['room_number'], 2)[0];
        $unitB = $b['linked_unit_number'] ?: explode('-', (string)$b['room_number'], 2)[0];
        return strnatcasecmp($unitA, $unitB) ?: strnatcasecmp((string)$a['room_number'], (string)$b['room_number']);
    });
    $roomsByUnit = [];
    $unitLabels = [];
    foreach ($rooms as $room) {
        $unit = $room['linked_unit_number'] ?: explode('-', (string)$room['room_number'], 2)[0];
        $groupKey = !empty($room['unit_id']) ? 'unit:' . (int)$room['unit_id'] : 'legacy:' . strtolower($unit);
        $roomsByUnit[$groupKey][] = (string)$room['room_number'];
        $unitLabels[$groupKey] = (string)$unit;
    }

    if ($rooms) {
        $ids = array_column($rooms, 'id');
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $bs = $pdo->prepare("SELECT b.*, u.name AS occupant_name
                             FROM beds b
                             LEFT JOIN residents res ON res.residents_id=b.resident_id
                             LEFT JOIN users u ON u.id=res.user_id
                             WHERE b.room_id IN ($ph)
                             ORDER BY b.room_id, CAST(SUBSTRING(b.bed_number,2) AS UNSIGNED)");
        $bs->execute($ids);
        foreach ($bs->fetchAll() as $b) $bedsByRoom[$b['room_id']][] = $b;

        // Tenant profiles assigned to these rooms (added from the Tenant Profiles page).
        try {
            $tps = $pdo->prepare("SELECT id, room_id, bed_id, full_name, tenant_type, phone, photo_path
                                  FROM tenant_profiles WHERE room_id IN ($ph) ORDER BY full_name");
            $tps->execute($ids);
            foreach ($tps->fetchAll() as $tRow) {
                $tenantsByRoom[$tRow['room_id']][] = $tRow;
                if (!empty($tRow['bed_id'])) $tenantByBed[(int)$tRow['bed_id']] = $tRow;
            }
        } catch (Throwable $t) { $tenantsByRoom = []; $tenantByBed = []; }
    }
} catch (Throwable $e) {
    error_log('rooms.php load: ' . $e->getMessage());
    $loadError = 'Could not load rooms right now. Please try again later.';
}
?>

<div class="card">
  <div class="row between" style="flex-wrap:wrap;gap:10px">
    <div>
      <h3 style="margin:0">Rooms (<?=count($rooms)?>)</h3>
      <small style="color:#7c6f8a">Browse vacant rooms by floor, type, aircon and room number.</small>
    </div>
    <?php if (!$isReadOnly): ?>
      <div class="row" style="gap:8px;flex-wrap:wrap">
        <button class="btn sm ghost" onclick="openModal('createUnit')">+ Add Unit</button>
        <button class="btn sm" onclick="openModal('createRoom')">+ Create Room</button>
      </div>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <h3>Units (<?=count($units)?>)</h3>
  <?php if (!$units): ?>
    <p>No units have been added yet. Add a unit before creating rooms.</p>
  <?php else: ?>
    <div style="overflow:auto">
      <table class="tbl"><thead><tr><th>Unit number</th><th>Floor</th><th>Rooms</th></tr></thead><tbody>
      <?php foreach ($units as $unit): ?>
        <tr><td><strong><?=e($unit['unit_number'])?></strong></td><td><?=e($unit['floor_name'])?></td><td><?=(int)$unit['room_count']?></td></tr>
      <?php endforeach; ?>
      </tbody></table>
    </div>
  <?php endif; ?>
</div>

<form class="toolbar" method="get" action="index.php" style="margin-top:12px">
  <input type="hidden" name="page" value="rooms">
  <input name="search" placeholder="Search room number" value="<?=e($fSearch)?>" style="min-width:160px">
  <select name="floor_id">
    <option value="">All floors</option>
    <?php foreach ($floors as $f): ?>
      <option value="<?=$f['id']?>" <?=((string)$fFloor===(string)$f['id'])?'selected':''?>><?=e($f['name'])?></option>
    <?php endforeach; ?>
  </select>
  <select name="room_type_id">
    <option value="">Solo / Shared</option>
    <?php foreach ($types as $t): ?>
      <option value="<?=$t['id']?>" <?=((string)$fType===(string)$t['id'])?'selected':''?>><?=e($t['name'])?></option>
    <?php endforeach; ?>
  </select>
  <select name="aircon">
    <option value="">Aircon / Non-Aircon</option>
    <option value="Aircon" <?=$fAircon==='Aircon'?'selected':''?>>Aircon</option>
    <option value="Non-Aircon" <?=$fAircon==='Non-Aircon'?'selected':''?>>Non-Aircon</option>
  </select>
  <select name="status">
    <option value="">All statuses</option>
    <?php foreach ($ROOM_STATUSES as $s): ?>
      <option value="<?=e($s)?>" <?=$fStatus===$s?'selected':''?>><?=e($s)?></option>
    <?php endforeach; ?>
  </select>
  <button class="btn sm">Filter</button>
  <a class="btn ghost sm" href="index.php?page=rooms">Reset</a>
</form>

<?php if ($loadError): ?>
  <div class="alert error"><?=e($loadError)?></div>
<?php elseif (!$rooms): ?>
  <div class="card"><p>No rooms match your filters. Try clearing the search.</p></div>
<?php else: ?>
<div class="room-grid">
  <?php $lastUnit = null; ?>
  <?php foreach ($rooms as $r):
    $unitLabel = $r['linked_unit_number'] ?: explode('-', (string)$r['room_number'], 2)[0];
    $groupKey = !empty($r['unit_id']) ? 'unit:' . (int)$r['unit_id'] : 'legacy:' . strtolower($unitLabel);
    if ($groupKey !== $lastUnit):
      $lastUnit = $groupKey;
  ?>
  <div style="grid-column:1 / -1;margin-top:8px">
    <h3 style="margin:0">Unit <?=e($unitLabels[$groupKey])?></h3>
    <small style="color:#7c6f8a">Rooms: <?=e(implode(', ', $roomsByUnit[$groupKey]))?></small>
  </div>
  <?php endif;
    $occ = (int)$r['occupied']; $max = max(1, (int)$r['max_occupants']);
    $avail = max(0, $max - $occ);
    $pct = (int)round($occ / $max * 100);
    $blocked = in_array($r['status'], ['Reserved', 'Under Maintenance', 'Unavailable'], true);
    $hasVacancy = $avail > 0 && !$blocked;
    $hasHistory = !empty($r['has_history']);
  ?>
  <div class="room-card">
    <div class="row between">
      <strong style="font-size:17px">Room <?=e($r['room_number'])?></strong>
      <?=room_status_pill($r['status'])?>
    </div>
    <div style="font-size:13px;color:#7c6f8a;margin:4px 0">
      <?=e($r['floor_name'])?> · <?=e($r['type_name'])?> ·
      <span class="pill <?= $r['aircon']==='Aircon' ? 'info' : '' ?>"><?=e($r['aircon'])?></span>
    </div>
    <div style="font-size:13px">Available beds: <strong><?=$avail?> / <?=$max?></strong></div>
    <div class="bar"><i style="width:<?=$pct?>%"></i></div>
    <div style="font-size:13px;color:#7c6f8a"><?=$occ?> of <?=$max?> beds occupied</div>
    <div style="font-size:13px;color:#7c6f8a">Registered tenant profiles: <strong><?=count($tenantsByRoom[$r['id']] ?? [])?></strong></div>
    <div style="margin:6px 0;font-weight:600"><?=money($r['monthly_rate'])?> <small style="font-weight:400;color:#7c6f8a">/ month</small></div>
    <small style="color:#7c6f8a">
      <?php if ($hasVacancy): ?>To request this room, please contact administration.
      <?php elseif ($blocked): ?>This room is currently not bookable (<?=e($r['status'])?>).
      <?php else: ?>This room is currently fully occupied.<?php endif; ?>
    </small>
    <div class="row" style="margin-top:10px;flex-wrap:wrap">
      <button class="btn ghost sm" onclick="openModal('details-<?=$r['id']?>')">View Details</button>
      <?php if (!$isReadOnly): ?>
        <button class="btn ghost sm" onclick="openModal('edit-<?=$r['id']?>')">Edit</button>
        <?php if ($r['status'] !== 'Under Maintenance'): ?>
          <form method="post" style="display:inline" onsubmit="return confirmDel('Set room <?=e($r['room_number'])?> Under Maintenance?')">
            <?=csrf_field()?>
            <input type="hidden" name="action" value="set_status">
            <input type="hidden" name="room_id" value="<?=$r['id']?>">
            <input type="hidden" name="status" value="Under Maintenance">
            <button class="btn ghost sm">Maintenance</button>
          </form>
        <?php endif; ?>
        <?php if ($blocked || $r['status'] !== 'Available'): ?>
          <form method="post" style="display:inline">
            <?=csrf_field()?>
            <input type="hidden" name="action" value="set_status">
            <input type="hidden" name="room_id" value="<?=$r['id']?>">
            <input type="hidden" name="status" value="Available">
            <button class="btn ghost sm">Set Available</button>
          </form>
        <?php endif; ?>
        <?php if ($r['status'] !== 'Unavailable'): ?>
          <form method="post" style="display:inline" onsubmit="return confirmDel('Mark room <?=e($r['room_number'])?> Unavailable? Existing bills and records will be preserved.')">
            <?=csrf_field()?>
            <input type="hidden" name="action" value="set_status">
            <input type="hidden" name="room_id" value="<?=$r['id']?>">
            <input type="hidden" name="status" value="Unavailable">
            <button class="btn ghost sm">Set Unavailable</button>
          </form>
        <?php endif; ?>
        <?php if (!$hasHistory && $occ === 0): ?>
          <form method="post" style="display:inline" onsubmit="return confirmDel('Delete room <?=e($r['room_number'])?>?')">
            <?=csrf_field()?>
            <input type="hidden" name="action" value="delete_room">
            <input type="hidden" name="room_id" value="<?=$r['id']?>">
            <button class="btn danger sm">Delete</button>
          </form>
        <?php else: ?>
          <span class="pill info" title="<?= $hasHistory ? 'This room has bills, contracts, tenant profiles, allocations or repairs and cannot be deleted.' : 'This room has active occupants and cannot be deleted.' ?>">Archive only</span>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- Details modal -->
  <div class="modal" id="details-<?=$r['id']?>" onclick="if(event.target===this)closeModal('details-<?=$r['id']?>')">
    <div class="box">
      <div class="row between"><h3>Room <?=e($r['room_number'])?> — Details</h3>
        <button class="btn ghost sm" onclick="closeModal('details-<?=$r['id']?>')">Close</button></div>
      <p style="font-size:13px;color:#7c6f8a"><?=e($r['floor_name'])?> · <?=e($r['type_name'])?> · <?=e($r['aircon'])?> · <?=money($r['monthly_rate'])?>/mo · <?=room_status_pill($r['status'])?></p>
      <?php if (!empty($r['description'])): ?><p><?=e($r['description'])?></p><?php endif; ?>
      <table class="tbl"><thead><tr><th>Bed</th><th>Status</th><th>Occupant</th></tr></thead><tbody>
        <?php foreach (($bedsByRoom[$r['id']] ?? []) as $b):
          $bc = $b['status']==='occupied' ? 'bad' : ($b['status']==='vacant' ? 'ok' : 'warn');
          $tenant = $tenantByBed[(int)$b['id']] ?? null;
          $occupant = $b['occupant_name'] ?? ($tenant['full_name'] ?? null);
          $bedLabel = $b['status'] === 'vacant' ? 'Available' : ucfirst((string)$b['status']); ?>
          <tr><td><?=e($b['bed_number'])?></td>
            <td><span class="pill <?=$bc?>"><?=e($bedLabel)?></span></td>
            <td><?=e($occupant ?? '—')?></td></tr>
        <?php endforeach; ?>
      </tbody></table>

      <h4 style="margin:16px 0 6px">Tenant Profiles in this Room (<?=count($tenantsByRoom[$r['id']] ?? [])?>)</h4>
      <div style="overflow:auto">
      <table class="tbl"><thead><tr><th>Photo</th><th>Complete Name</th><th>Role</th><th>Phone / Cp</th></tr></thead><tbody>
        <?php if (empty($tenantsByRoom[$r['id']])): ?>
          <tr><td colspan="4">No tenant profiles assigned to this room.</td></tr>
        <?php else: foreach ($tenantsByRoom[$r['id']] as $tp): ?>
          <tr>
            <td>
              <?php if (!empty($tp['photo_path'])): ?>
                <img src="index.php?page=download&amp;type=tenant_photo&amp;id=<?=(int)$tp['id']?>" alt="2x2 photo" style="width:44px;height:44px;object-fit:cover;border-radius:6px;border:2px solid var(--line);display:block">
              <?php else: ?>—<?php endif; ?>
            </td>
            <td><strong><?=e($tp['full_name'])?></strong></td>
            <td><span class="pill info"><?=e($tp['tenant_type'])?></span></td>
            <td><?=e($tp['phone'] ?? '—')?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody></table>
      </div>
    </div>
  </div>

  <?php if (!$isReadOnly): ?>
  <!-- Edit modal -->
  <div class="modal" id="edit-<?=$r['id']?>" onclick="if(event.target===this)closeModal('edit-<?=$r['id']?>')">
    <div class="box">
      <div class="row between"><h3>Edit Room <?=e($r['room_number'])?></h3>
        <button class="btn ghost sm" onclick="closeModal('edit-<?=$r['id']?>')">Close</button></div>
      <form method="post">
        <?=csrf_field()?>
        <input type="hidden" name="action" value="update_room">
        <input type="hidden" name="room_id" value="<?=$r['id']?>">
        <div class="form-grid">
          <div class="field"><label>Room number</label><input name="room_number" required maxlength="20" value="<?=e($r['room_number'])?>"></div>
          <div class="field"><label>Unit</label><select name="unit_id" required>
            <?php foreach ($units as $unit): ?><option value="<?=(int)$unit['id']?>" <?=(int)$r['unit_id']===(int)$unit['id']?'selected':''?>>Unit <?=e($unit['unit_number'])?> · <?=e($unit['floor_name'])?></option><?php endforeach; ?>
          </select></div>
          <div class="field"><label>Type</label><select name="room_type_id" required data-room-type="edit-<?=$r['id']?>" onchange="roomTypeChanged('edit-<?=$r['id']?>')">
            <?php foreach ($types as $t): ?><option value="<?=$t['id']?>" data-type-name="<?=e($t['name'])?>" <?=(int)$r['room_type_id']===(int)$t['id']?'selected':''?>><?=e($t['name'])?></option><?php endforeach; ?>
          </select></div>
          <div class="field"><label>Aircon</label><select name="aircon">
            <option value="Aircon" <?=$r['aircon']==='Aircon'?'selected':''?>>Aircon</option>
            <option value="Non-Aircon" <?=$r['aircon']==='Non-Aircon'?'selected':''?>>Non-Aircon</option>
          </select></div>
          <div class="field" id="maxWrap-edit-<?=$r['id']?>"><label>Max occupants</label><input id="maxOcc-edit-<?=$r['id']?>" type="number" name="max_occupants" min="1" max="12" required value="<?=(int)$r['max_occupants']?>" oninput="renderRoomBeds('edit-<?=$r['id']?>')"></div>
          <div class="field"><label>Monthly rate (₱)</label><input type="number" step="0.01" min="0" name="monthly_rate" required value="<?=e($r['monthly_rate'])?>"></div>
          <div class="field"><label>Status</label><select name="status">
            <?php foreach ($ROOM_STATUSES as $s): ?><option value="<?=e($s)?>" <?=$r['status']===$s?'selected':''?>><?=e($s)?></option><?php endforeach; ?>
          </select></div>
          <div class="field"><label>Description</label><input name="description" maxlength="255" value="<?=e($r['description'] ?? '')?>"></div>
        </div>
        <div id="bedWrap-edit-<?=$r['id']?>" style="display:none;margin-top:10px">
          <label style="font-weight:600">Bed numbers</label>
          <div class="form-grid" id="bedFields-edit-<?=$r['id']?>" data-initial="<?=e(json_encode(array_column($bedsByRoom[$r['id']] ?? [], 'bed_number')))?>"></div>
          <small style="color:#7c6f8a">One field per bed. Vacant bed labels can be renamed; occupied beds keep their number.</small>
        </div>
        <small style="color:#7c6f8a">Currently <?=$occ?> bed(s) occupied. Capacity cannot go below that.</small>
        <div class="row" style="margin-top:10px"><button class="btn sm">Save changes</button></div>
      </form>
    </div>
  </div>
  <?php endif; ?>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if (!$isReadOnly): ?>
<div class="modal" id="createUnit" onclick="if(event.target===this)closeModal('createUnit')">
  <div class="box">
    <div class="row between"><h3>Add Unit</h3>
      <button class="btn ghost sm" onclick="closeModal('createUnit')">Close</button></div>
    <form method="post">
      <?=csrf_field()?><input type="hidden" name="action" value="create_unit">
      <div class="form-grid">
        <div class="field"><label>Unit number</label><input name="unit_number" required maxlength="20" placeholder="301"></div>
        <div class="field"><label>Floor</label><select name="floor_id" required>
          <option value="">— Select —</option>
          <?php foreach ($floors as $f): ?><option value="<?=(int)$f['id']?>"><?=e($f['name'])?></option><?php endforeach; ?>
        </select></div>
      </div>
      <div class="row" style="margin-top:10px"><button class="btn sm">Add Unit</button></div>
    </form>
  </div>
</div>

<!-- Create modal -->
<div class="modal" id="createRoom" onclick="if(event.target===this)closeModal('createRoom')">
  <div class="box">
    <div class="row between"><h3>Create Room</h3>
      <button class="btn ghost sm" onclick="closeModal('createRoom')">Close</button></div>
    <form method="post">
      <?=csrf_field()?>
      <input type="hidden" name="action" value="create_room">
      <div class="form-grid">
        <div class="field"><label>Room number</label><input name="room_number" required maxlength="20"></div>
        <div class="field"><label>Unit</label><select name="unit_id" required>
          <option value="">— Select —</option>
          <?php foreach ($units as $unit): ?><option value="<?=(int)$unit['id']?>">Unit <?=e($unit['unit_number'])?> · <?=e($unit['floor_name'])?></option><?php endforeach; ?>
        </select></div>
        <div class="field"><label>Type</label><select name="room_type_id" required data-room-type="create" onchange="roomTypeChanged('create')">
          <option value="">— Select —</option>
          <?php foreach ($types as $t): ?><option value="<?=$t['id']?>" data-type-name="<?=e($t['name'])?>"><?=e($t['name'])?></option><?php endforeach; ?>
        </select></div>
        <div class="field"><label>Aircon</label><select name="aircon">
          <option value="Non-Aircon">Non-Aircon</option><option value="Aircon">Aircon</option>
        </select></div>
        <div class="field" id="maxWrap-create" style="display:none"><label>Max occupants</label><input id="maxOcc-create" type="number" name="max_occupants" min="1" max="12" value="4" required oninput="renderRoomBeds('create')"></div>
        <div class="field"><label>Monthly rate (₱)</label><input type="number" step="0.01" min="0" name="monthly_rate" value="0" required></div>
        <div class="field"><label>Status</label><select name="status">
          <?php foreach ($ROOM_STATUSES as $s): ?><option value="<?=e($s)?>"><?=e($s)?></option><?php endforeach; ?>
        </select></div>
        <div class="field"><label>Description</label><input name="description" maxlength="255"></div>
      </div>
      <?php if (!$units): ?><small style="color:#991b1b">Add a unit before creating a room.</small><?php endif; ?>
      <div id="bedWrap-create" style="display:none;margin-top:10px">
        <label style="font-weight:600">Bed numbers</label>
        <div class="form-grid" id="bedFields-create" data-initial="[]"></div>
        <small style="color:#7c6f8a">Shared rooms show one bed-number field per occupant. Solo rooms always have one bed.</small>
      </div>
      <small style="color:#7c6f8a">Beds B1..Bn are created from the fields above.</small>
      <div class="row" style="margin-top:10px"><button class="btn sm">Create room</button></div>
    </form>
  </div>
</div>
<?php endif; ?>

<script>
function roomTypeName(select) {
  if (!select || select.selectedIndex < 0) return '';
  var opt = select.options[select.selectedIndex];
  return opt ? (opt.getAttribute('data-type-name') || '') : '';
}
function roomBedsEscape(value) {
  return String(value == null ? '' : value)
    .replace(/&/g, '&amp;').replace(/"/g, '&quot;')
    .replace(/</g, '&lt;').replace(/>/g, '&gt;');
}
function renderRoomBeds(prefix) {
  var select = document.querySelector('[data-room-type="' + prefix + '"]');
  var max = document.getElementById('maxOcc-' + prefix);
  var box = document.getElementById('bedFields-' + prefix);
  if (!select || !max || !box) return;
  if (roomTypeName(select) !== 'Shared') { box.innerHTML = ''; return; }

  var n = parseInt(max.value, 10);
  if (isNaN(n) || n < 1) n = 0;
  if (n > 12) n = 12;

  var current = {};
  box.querySelectorAll('input[name="bed_numbers[]"]').forEach(function (input) {
    current[input.getAttribute('data-bed-index')] = input.value;
  });

  var initial = {};
  if (box.getAttribute('data-initial-applied') !== '1') {
    try {
      JSON.parse(box.getAttribute('data-initial') || '[]').forEach(function (value, index) {
        initial[index + 1] = value;
      });
    } catch (e) {}
    box.setAttribute('data-initial-applied', '1');
  }

  var html = '';
  for (var i = 1; i <= n; i++) {
    var value = current[i] !== undefined ? current[i] : (initial[i] || ('B' + i));
    html += '<div class="field"><label>Bed ' + i + '</label>'
         + '<input name="bed_numbers[]" data-bed-index="' + i + '" maxlength="10" value="'
         + roomBedsEscape(value) + '" required></div>';
  }
  box.innerHTML = html;
}
function roomTypeChanged(prefix) {
  var select = document.querySelector('[data-room-type="' + prefix + '"]');
  var maxWrap = document.getElementById('maxWrap-' + prefix);
  var max = document.getElementById('maxOcc-' + prefix);
  var bedWrap = document.getElementById('bedWrap-' + prefix);
  if (!select || !maxWrap || !max || !bedWrap) return;

  var name = roomTypeName(select);
  var shared = name === 'Shared';
  if (name === 'Solo') max.value = 1;
  if (shared && (!max.value || isNaN(parseInt(max.value, 10)) || parseInt(max.value, 10) < 2)) {
    max.value = 2;
  }
  max.min = shared ? 2 : 1;
  maxWrap.style.display = shared ? '' : 'none';
  bedWrap.style.display = shared ? '' : 'none';
  renderRoomBeds(prefix);
}
document.querySelectorAll('[data-room-type]').forEach(function (select) {
  roomTypeChanged(select.getAttribute('data-room-type'));
});
</script>
