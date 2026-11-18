<?php
// Housekeeping cleaning records for housekeeping staff and dormitory supervisors.
$pdo = db();
$role = (string)($user['role'] ?? '');
$isSupervisor = in_array($role, ['super_admin', 'admin_staff'], true);
$canAdd = $role === 'housekeeping';
$statuses = ['Clean', 'Undone', 'Unclean'];
$verificationStatuses = ['Verified', 'Awaiting check'];
$otherChecklistItems = [
    'kitchen' => 'Kitchen',
    'comfort_room' => 'Comfort Room',
    'floor' => 'Floor',
    'dispose_garbage' => 'Dispose Garbage',
    'common_area' => 'Common Area',
    'stairs' => 'Stairs',
    'hallway' => 'Hallway',
    'window' => 'Window',
    'wall_fan' => 'Wall Fan',
    'exhaust_fan' => 'Exhaust Fan',
];
$unitChecklistItems = ['window', 'wall_fan', 'exhaust_fan'];
$legacyChecklistItems = ['admin' => 'Admin', 'accounting' => 'Accounting', 'conference' => 'Conference'];
$checklistToday = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
$currentMonth = substr($checklistToday, 0, 7);
$month = trim((string)($_GET['month'] ?? $currentMonth));
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) $month = $currentMonth;

if (!function_exists('hk_status_class')) {
    function hk_status_class(string $status): string {
        return match ($status) {
            'Clean', 'Verified' => 'ok',
            'Unclean' => 'bad',
            default => 'warn',
        };
    }
}

$supervisors = [];
$units = [];
$availableUnits = [];
$rows = [];
$otherRecords = [];
$otherColumns = [];
$otherLoadError = false;
$stats = ['total' => 0, 'clean_count' => 0, 'undone_count' => 0, 'unclean_count' => 0];
$err = null;

try {
    $supervisors = $pdo->query("SELECT id, name, role FROM users
        WHERE role IN ('admin_staff','super_admin') AND status='active' AND deleted_at IS NULL
        ORDER BY CASE role WHEN 'super_admin' THEN 0 ELSE 1 END, name")->fetchAll();
    $units = $pdo->query("SELECT un.id, un.unit_number, f.name AS floor_name
        FROM units un JOIN floors f ON f.id=un.floor_id ORDER BY un.unit_number, f.name")->fetchAll();
    if ($canAdd) {
        $recorded = $pdo->prepare('SELECT DISTINCT unit_id FROM housekeeping_records WHERE staff_id=? AND cleaning_date=? AND unit_id IS NOT NULL');
        $recorded->execute([(int)$user['id'], $checklistToday]);
        $recordedUnitIds = array_fill_keys(array_map('intval', $recorded->fetchAll(PDO::FETCH_COLUMN)), true);
        $availableUnits = array_values(array_filter($units, static fn(array $unit): bool => !isset($recordedUnitIds[(int)$unit['id']])));
    }
} catch (Throwable $t) {
    error_log('housekeeping.php options load: ' . $t->getMessage());
    $err = 'Could not load housekeeping form options.';
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    try {
        if ($action === 'change_password') {
            if (!$canAdd) throw new RuntimeException('Only housekeeping staff can change their password here.');

            $currentPassword = (string)($_POST['current_password'] ?? '');
            $newPassword = (string)($_POST['new_password'] ?? '');
            $confirmPassword = (string)($_POST['confirm_password'] ?? '');
            $st = $pdo->prepare('SELECT password_hash FROM users WHERE id=? AND deleted_at IS NULL');
            $st->execute([(int)$user['id']]);
            $passwordHash = $st->fetchColumn();
            if (!$passwordHash || !password_verify($currentPassword, (string)$passwordHash)) {
                throw new RuntimeException('Current password is incorrect.');
            }
            if (strlen($newPassword) < 8) throw new RuntimeException('New password must be at least 8 characters.');
            if ($newPassword !== $confirmPassword) throw new RuntimeException('New passwords do not match.');

            $st = $pdo->prepare('UPDATE users SET password_hash=? WHERE id=?');
            $st->execute([password_hash($newPassword, PASSWORD_DEFAULT), (int)$user['id']]);
            audit_log((int)$user['id'], 'Changed own password', 'housekeeping', (string)$user['id']);
            flash('success', 'Password changed.');
            redirect('index.php?page=housekeeping&month=' . rawurlencode($month));
        }

        if ($action === 'create_others' || $action === 'update_others') {
            if (!$canAdd) throw new RuntimeException('Only housekeeping staff can manage this checklist.');

            $date = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
            $selected = $_POST['checklist_items'] ?? [];
            if (!is_array($selected)) throw new RuntimeException('Please select valid checklist items.');
            foreach ($selected as $item) {
                if (!is_string($item) || !array_key_exists($item, $otherChecklistItems)) {
                    throw new RuntimeException('Please select valid checklist items.');
                }
            }
            $selected = array_values(array_unique($selected));
            if (!$selected) throw new RuntimeException('Select at least one checklist item.');
            $itemUnits = [];
            foreach ($unitChecklistItems as $item) {
                if (in_array($item, $selected, true)) {
                    $value = $_POST['unit_' . $item] ?? '';
                    if (!is_string($value)) throw new RuntimeException('Enter a valid unit for ' . $otherChecklistItems[$item] . '.');
                    $value = trim($value);
                    if ($value === '' || mb_strlen($value) > 255) {
                        throw new RuntimeException('Enter the unit cleaned for ' . $otherChecklistItems[$item] . ' (maximum 255 characters).');
                    }
                    $itemUnits[$item] = $value;
                }
            }

            $pdo->beginTransaction();
            try {
                if ($action === 'create_others') {
                    try {
                        $guard = $pdo->prepare('INSERT INTO housekeeping_other_daily_entries (staff_id, checklist_date) VALUES (?,?)');
                        $guard->execute([(int)$user['id'], $date]);
                    } catch (PDOException $t) {
                        if ((int)($t->errorInfo[1] ?? 0) === 1062) {
                            throw new RuntimeException('You have already submitted an Others checklist today.');
                        }
                        throw $t;
                    }
                    $st = $pdo->prepare('INSERT INTO housekeeping_other_records (staff_id, checklist_date) VALUES (?,?)');
                    $st->execute([(int)$user['id'], $date]);
                    $recordId = (int)$pdo->lastInsertId();
                } else {
                    $st = $pdo->prepare('SELECT id, checklist_date FROM housekeeping_other_records WHERE id=? AND staff_id=? FOR UPDATE');
                    $st->execute([$id, (int)$user['id']]);
                    $existing = $st->fetch();
                    if (!$existing) throw new RuntimeException('You can only edit your own Others checklist.');
                    $recordId = (int)$existing['id'];
                    $date = (string)$existing['checklist_date'];
                    $keys = array_keys($otherChecklistItems);
                    $placeholders = implode(',', array_fill(0, count($keys), '?'));
                    $delete = $pdo->prepare("DELETE FROM housekeeping_other_items WHERE record_id=? AND item_key IN ($placeholders)");
                    $delete->execute(array_merge([$recordId], $keys));
                }
                $itemSt = $pdo->prepare('INSERT INTO housekeeping_other_items (record_id, item_key, unit_details) VALUES (?,?,?)');
                foreach ($selected as $item) $itemSt->execute([$recordId, $item, $itemUnits[$item] ?? null]);
                $pdo->commit();
            } catch (Throwable $t) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $t;
            }

            audit_log((int)$user['id'], ($action === 'create_others' ? 'Submitted' : 'Updated') . ' Others housekeeping checklist for ' . $date, 'housekeeping', (string)$recordId);
            flash('success', $action === 'create_others' ? 'Others checklist saved.' : 'Others checklist updated.');
            redirect('index.php?page=housekeeping&month=' . rawurlencode(substr($date, 0, 7)));
        }

        if ($action === 'supervisor_update') {
            if (!$isSupervisor) throw new RuntimeException('Only Admin Staff and Super Admin can update verification.');
            $st = $pdo->prepare('SELECT id, cleaning_date, unit_number FROM housekeeping_records WHERE id=?');
            $st->execute([$id]);
            $record = $st->fetch();
            if (!$record) throw new RuntimeException('Cleaning record not found.');

            $verification = (string)($_POST['verification_status'] ?? '');
            if (!in_array($verification, $verificationStatuses, true)) {
                throw new RuntimeException('Please choose a valid verification status.');
            }
            $remarks = trim((string)($_POST['remarks'] ?? ''));
            if (mb_strlen($remarks) > 255) throw new RuntimeException('Remark / note is too long (max 255 characters).');
            $verifiedBy = $verification === 'Verified' ? (int)$user['id'] : null;
            $verifiedAt = $verification === 'Verified' ? date('Y-m-d H:i:s') : null;

            $st = $pdo->prepare('UPDATE housekeeping_records
                SET verification_status=?, verified_by=?, verified_at=?, remarks=? WHERE id=?');
            $st->execute([$verification, $verifiedBy, $verifiedAt, $remarks !== '' ? $remarks : null, $id]);
            audit_log((int)$user['id'], 'Updated housekeeping verification/remark for record #' . $id, 'housekeeping', (string)$id);
            flash('success', 'Verification and remark updated.');
            redirect('index.php?page=housekeeping&month=' . rawurlencode(substr((string)$record['cleaning_date'], 0, 7)));
        }

        if ($action === 'create' || $action === 'update') {
            if (!$canAdd) throw new RuntimeException('You cannot add cleaning records.');

            $pdo->beginTransaction();
            $existing = null;
            if ($action === 'update') {
                $st = $pdo->prepare('SELECT * FROM housekeeping_records WHERE id=? FOR UPDATE');
                $st->execute([$id]);
                $existing = $st->fetch();
                if (!$existing) throw new RuntimeException('Cleaning record not found.');
                if (!$isSupervisor && ($role !== 'housekeeping' || (int)$existing['created_by'] !== (int)$user['id'])) {
                    throw new RuntimeException('You can only edit cleaning records that you added.');
                }
            }

            $staffId = (int)$user['id'];
            if ($action === 'update' && !$isSupervisor) $staffId = (int)$existing['staff_id'];
            $staffCheck = $pdo->prepare("SELECT id FROM users WHERE id=? AND role='housekeeping' AND status='active' AND deleted_at IS NULL");
            $staffCheck->execute([$staffId]);
            if (!$staffCheck->fetchColumn()) throw new RuntimeException('Please choose a valid housekeeper.');

            $supervisorId = (int)($_POST['supervisor_id'] ?? 0);
            if ($supervisorId <= 0) throw new RuntimeException('Please choose a supervisor.');
            $supervisorCheck = $pdo->prepare("SELECT id FROM users WHERE id=? AND role IN ('admin_staff','super_admin') AND status='active' AND deleted_at IS NULL");
            $supervisorCheck->execute([$supervisorId]);
            if (!$supervisorCheck->fetchColumn()) throw new RuntimeException('Please choose a valid supervisor.');

            $date = $action === 'create'
                ? (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d')
                : substr((string)$existing['cleaning_date'], 0, 10);

            $unitIds = $action === 'create' ? ($_POST['unit_ids'] ?? []) : [$_POST['unit_id'] ?? ''];
            if (!is_array($unitIds) || !$unitIds) throw new RuntimeException('Please select at least one unit.');
            foreach ($unitIds as $unitId) {
                if (!is_string($unitId) || !ctype_digit($unitId) || (int)$unitId <= 0) {
                    throw new RuntimeException('Please choose valid units.');
                }
            }
            $unitIds = array_values(array_unique(array_map('intval', $unitIds)));
            $unitCheck = $pdo->prepare('SELECT id, unit_number FROM units WHERE id=?');
            $selectedUnits = [];
            foreach ($unitIds as $unitId) {
                $unitCheck->execute([$unitId]);
                $unit = $unitCheck->fetch();
                if (!$unit) throw new RuntimeException('Please choose a valid unit.');
                $selectedUnits[] = $unit;
            }

            $status = (string)($_POST['status'] ?? '');
            if (!in_array($status, $statuses, true)) throw new RuntimeException('Please choose a valid cleaning status.');

            $staffLock = $pdo->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');
            $staffLock->execute([$staffId]);
            if (!$staffLock->fetchColumn()) throw new RuntimeException('Housekeeper not found.');
            $duplicateCheck = $pdo->prepare('SELECT id FROM housekeeping_records WHERE staff_id=? AND cleaning_date=? AND unit_id=? AND id<>? LIMIT 1 FOR UPDATE');
            foreach ($selectedUnits as $unit) {
                if ($action === 'update' && (int)$existing['unit_id'] === (int)$unit['id']) continue;
                $duplicateCheck->execute([$staffId, $date, (int)$unit['id'], $action === 'update' ? $id : 0]);
                if ($duplicateCheck->fetchColumn()) {
                    throw new RuntimeException('You already have a cleaning record for unit ' . $unit['unit_number'] . ' on ' . $date . '. Edit the existing record instead.');
                }
            }

            if ($action === 'create') {
                $st = $pdo->prepare('INSERT INTO housekeeping_records
                    (staff_id, cleaning_date, unit_id, room_id, unit_number, status, remarks, supervisor_id, verification_status, verified_by, verified_at, created_by)
                    VALUES (?,?,?,NULL,?,?,?,?,?,?,?,?)');
                foreach ($selectedUnits as $unit) {
                    $st->execute([$staffId, $date, (int)$unit['id'], $unit['unit_number'], $status,
                        null, $supervisorId, 'Awaiting check', null, null, (int)$user['id']]);
                    $recordId = (int)$pdo->lastInsertId();
                    audit_log((int)$user['id'], 'Added housekeeping record for unit ' . $unit['unit_number'] . ' (' . $status . ')', 'housekeeping', (string)$recordId);
                }
                $count = count($selectedUnits);
                $successMessage = $count === 1 ? 'Cleaning record added for unit ' . $selectedUnits[0]['unit_number'] . '.' : $count . ' cleaning records added.';
            } else {
                $unit = $selectedUnits[0];
                $st = $pdo->prepare('UPDATE housekeeping_records
                    SET staff_id=?, unit_id=?, room_id=NULL, unit_number=?, status=?, supervisor_id=?
                    WHERE id=?');
                $st->execute([$staffId, (int)$unit['id'], $unit['unit_number'], $status,
                    $supervisorId, $id]);
                audit_log((int)$user['id'], 'Updated housekeeping record #' . $id . ' for unit ' . $unit['unit_number'], 'housekeeping', (string)$id);
                $successMessage = 'Cleaning record updated.';
            }
            $pdo->commit();
            flash('success', $successMessage);
            redirect('index.php?page=housekeeping&month=' . rawurlencode(substr($date, 0, 7)));
        }

        if ($action === 'delete') {
            $st = $pdo->prepare('SELECT id, staff_id, unit_number FROM housekeeping_records WHERE id=?');
            $st->execute([$id]);
            $record = $st->fetch();
            if (!$record) throw new RuntimeException('Cleaning record not found.');
            if ($role !== 'housekeeping' || (int)$record['staff_id'] !== (int)$user['id'] || (int)$record['created_by'] !== (int)$user['id']) {
                throw new RuntimeException('You can only delete cleaning records that you added.');
            }
            $pdo->prepare('DELETE FROM housekeeping_records WHERE id=?')->execute([$id]);
            audit_log((int)$user['id'], 'Deleted housekeeping record #' . $id . ' for unit ' . $record['unit_number'], 'housekeeping', (string)$id);
            flash('success', 'Cleaning record deleted.');
            redirect('index.php?page=housekeeping&month=' . rawurlencode($month));
        }

        throw new RuntimeException('Invalid cleaning-record action.');
    } catch (Throwable $t) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('error', $t->getMessage() ?: 'Could not save the cleaning record.');
        redirect('index.php?page=housekeeping&month=' . rawurlencode($month));
    }
}

try {
    $st = $pdo->prepare("SELECT r.id, r.checklist_date, r.staff_id, staff.name AS staff_name, i.item_key, i.unit_details
        FROM housekeeping_other_records r
        JOIN users staff ON staff.id=r.staff_id
        LEFT JOIN housekeeping_other_items i ON i.record_id=r.id
        WHERE r.checklist_date >= ? AND r.checklist_date < DATE_ADD(?, INTERVAL 1 MONTH)
        ORDER BY r.checklist_date DESC, r.id DESC");
    $monthStart = $month . '-01';
    $st->execute([$monthStart, $monthStart]);
    $otherItemSet = [];
    foreach ($st->fetchAll() as $itemRow) {
        $recordId = (int)$itemRow['id'];
        if (!isset($otherRecords[$recordId])) {
            $otherRecords[$recordId] = [
                'id' => $recordId,
                'checklist_date' => $itemRow['checklist_date'],
                'staff_id' => (int)$itemRow['staff_id'],
                'staff_name' => $itemRow['staff_name'],
                'items' => [],
            ];
        }
        if ($itemRow['item_key'] !== null && array_key_exists($itemRow['item_key'], $otherChecklistItems + $legacyChecklistItems)) {
            $otherRecords[$recordId]['items'][$itemRow['item_key']] = $itemRow['unit_details'] ?? '';
            $otherItemSet[$itemRow['item_key']] = true;
        }
    }
    $baseItems = array_diff_key($otherChecklistItems, array_flip($unitChecklistItems));
    $otherColumns = $baseItems + array_intersect_key($legacyChecklistItems, $otherItemSet)
        + array_intersect_key($otherChecklistItems, array_flip($unitChecklistItems), $otherItemSet);
} catch (Throwable $t) {
    error_log('housekeeping.php Others checklist load: ' . $t->getMessage());
    $otherLoadError = true;
    $err = $err ?? 'Could not load Others checklist records. Please run the database migration.';
}

try {
    $st = $pdo->prepare("SELECT h.*, staff.name AS staff_name, supervisor.name AS supervisor_name,
            verifier.name AS verified_by_name, COALESCE(un.unit_number, h.unit_number, r.room_number) AS display_unit
        FROM housekeeping_records h
        JOIN users staff ON staff.id=h.staff_id
        LEFT JOIN users supervisor ON supervisor.id=h.supervisor_id
        LEFT JOIN users verifier ON verifier.id=h.verified_by
        LEFT JOIN units un ON un.id=h.unit_id
        LEFT JOIN rooms r ON r.id=h.room_id
        WHERE h.cleaning_date >= ? AND h.cleaning_date < DATE_ADD(?, INTERVAL 1 MONTH)
        ORDER BY h.cleaning_date DESC, h.id DESC
        LIMIT 1000");
    $monthStart = $month . '-01';
    $st->execute([$monthStart, $monthStart]);
    $rows = $st->fetchAll();

    $statsSt = $pdo->prepare("SELECT COUNT(*) AS total,
        COALESCE(SUM(status='Clean'),0) AS clean_count,
        COALESCE(SUM(status='Undone'),0) AS undone_count,
        COALESCE(SUM(status='Unclean'),0) AS unclean_count
        FROM housekeeping_records WHERE cleaning_date >= ? AND cleaning_date < DATE_ADD(?, INTERVAL 1 MONTH)");
    $statsSt->execute([$monthStart, $monthStart]);
    $stats = $statsSt->fetch() ?: $stats;
} catch (Throwable $t) {
    error_log('housekeeping.php records load: ' . $t->getMessage());
    $err = $err ?? 'Could not load housekeeping records. Please run the database migration.';
}

if (($_GET['export'] ?? '') === 'others_csv') {
    while (ob_get_level() > 0) ob_end_clean();
    if ($otherLoadError) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        exit('Could not export Others checklist records. Please try again later.');
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="housekeeping-others-' . $month . '.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, array_merge(['Name', 'Date'], array_values($otherColumns)));
    foreach ($otherRecords as $record) {
        $staffName = (string)$record['staff_name'];
        if (preg_match('/^[\s]*[=+\-@]/u', $staffName)) $staffName = "'" . $staffName;
        $line = [$staffName, $record['checklist_date']];
        foreach ($otherColumns as $itemKey => $label) {
            $value = array_key_exists($itemKey, $record['items']) ? ($record['items'][$itemKey] !== '' ? $record['items'][$itemKey] : 'Yes') : '';
            if (preg_match('/^[\s]*[=+\-@]/u', $value)) $value = "'" . $value;
            $line[] = $value;
        }
        fputcsv($output, $line);
    }
    fclose($output);
    exit;
}

if (($_GET['export'] ?? '') === 'csv') {
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="housekeeping-' . $month . '.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Name', 'Date', 'Unit', 'Status', 'Remark / Note', 'Supervisor Inspection', 'Verification', 'Verified By', 'Verified At']);
    foreach ($rows as $record) {
        fputcsv($output, [
            $record['staff_name'], $record['cleaning_date'], $record['display_unit'], $record['status'],
            $record['remarks'], $record['supervisor_name'], $record['verification_status'],
            $record['verified_by_name'], $record['verified_at'],
        ]);
    }
    fclose($output);
    exit;
}

$success = flash('success');
$errorMessage = flash('error');
?>
<?php if ($err): ?><div class="alert error"><?=e($err)?></div><?php endif; ?>
<?php if ($errorMessage): ?><div class="alert error"><?=e($errorMessage)?></div><?php endif; ?>
<?php if ($success): ?><div class="alert success"><?=e($success)?></div><?php endif; ?>

<?php if ($canAdd): ?>
<div class="card"><h3>Change my password</h3>
  <form method="post">
    <?=csrf_field()?><input type="hidden" name="action" value="change_password">
    <div class="form-grid">
      <div class="field"><label>Current password</label><input name="current_password" type="password" required autocomplete="current-password"></div>
      <div class="field"><label>New password (min 8)</label><input name="new_password" type="password" required minlength="8" autocomplete="new-password"></div>
      <div class="field"><label>Confirm new password</label><input name="confirm_password" type="password" required autocomplete="new-password"></div>
    </div>
    <button class="btn sm">Change password</button>
  </form>
</div>
<?php endif; ?>

<div class="grid g4" style="margin-bottom:14px">
  <div class="card stat"><b><?=e((string)$stats['total'])?></b><span>Records this month</span></div>
  <div class="card stat"><b><?=e((string)$stats['clean_count'])?></b><span>Clean</span></div>
  <div class="card stat"><b><?=e((string)$stats['undone_count'])?></b><span>Undone</span></div>
  <div class="card stat"><b><?=e((string)$stats['unclean_count'])?></b><span>Unclean</span></div>
</div>

<div class="toolbar no-print">
  <form method="get" action="index.php" class="row" style="flex:1;flex-wrap:wrap">
    <input type="hidden" name="page" value="housekeeping">
    <label for="hk-month">Month</label>
    <input id="hk-month" type="month" name="month" value="<?=e($month)?>">
    <button class="btn sm ghost" type="submit">Show month</button>
  </form>
  <div class="row">
    <a class="btn sm ghost" href="index.php?page=housekeeping&amp;month=<?=e($month)?>&amp;export=csv">Export CSV</a>
    <button class="btn sm ghost" type="button" onclick="window.print()">Print</button>
    <?php if ($canAdd): ?><button class="btn sm" type="button" onclick="openModal('hk-create')">+ Add Cleaning Record</button><?php endif; ?>
    <?php if ($canAdd): ?><button class="btn sm" type="button" onclick="openModal('hk-others')">Others</button><?php endif; ?>
  </div>
</div>

<div class="card" style="padding:0;overflow:auto">
  <div class="row between" style="padding:14px 14px 0">
    <h3 style="margin:0">Others Checklist</h3>
    <?php if ($otherRecords): ?><a class="btn sm ghost no-print" href="index.php?page=housekeeping&amp;month=<?=e($month)?>&amp;export=others_csv">Export Others CSV</a><?php endif; ?>
  </div>
  <table class="tbl">
    <thead><tr><th>Name</th><th>Date</th><?php foreach ($otherColumns as $label): ?><th><?=e($label)?></th><?php endforeach; ?><?php if ($canAdd): ?><th class="no-print">Actions</th><?php endif; ?></tr></thead>
    <tbody>
      <?php if (!$otherRecords): ?><tr><td colspan="<?=count($otherColumns) + 2 + ($canAdd ? 1 : 0)?>">No Others checklist records for <?=e(date('F Y', strtotime($month . '-01')))?>.</td></tr><?php endif; ?>
      <?php foreach ($otherRecords as $record): ?>
        <tr>
          <td><?=e($record['staff_name'])?></td>
          <td><?=e(substr((string)$record['checklist_date'], 0, 10))?></td>
          <?php foreach ($otherColumns as $itemKey => $label): ?><td><?=array_key_exists($itemKey, $record['items']) ? e($record['items'][$itemKey] !== '' ? $record['items'][$itemKey] : '✓') : ''?></td><?php endforeach; ?>
          <?php if ($canAdd): ?><td class="no-print"><?php if ($record['staff_id'] === (int)$user['id']): ?><button class="btn sm ghost" type="button" onclick="openModal('hk-others-edit-<?=(int)$record['id']?>')">Edit</button><?php else: ?>—<?php endif; ?></td><?php endif; ?>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="card" style="padding:0;overflow:auto">
<table class="tbl">
  <thead><tr>
    <th>Name</th><th>Date</th><th>Unit</th><th>Status</th><th>Remark / Note</th>
    <th>Supervisor Inspection</th><th>Verification</th><th class="no-print">Actions</th>
  </tr></thead>
  <tbody>
  <?php if (!$rows): ?><tr><td colspan="8">No cleaning records for <?=e(date('F Y', strtotime($month . '-01')))?>.</td></tr><?php endif; ?>
  <?php foreach ($rows as $record):
      $canHousekeeperManage = $role === 'housekeeping'
          && (int)$record['staff_id'] === (int)$user['id']
          && (int)$record['created_by'] === (int)$user['id'];
  ?>
    <tr>
      <td><strong><?=e($record['staff_name'])?></strong></td>
      <td><?=e(substr((string)$record['cleaning_date'], 0, 10))?></td>
      <td><?=e($record['display_unit'])?></td>
      <td><span class="pill <?=hk_status_class($record['status'])?>"><?=e($record['status'])?></span></td>
      <td style="max-width:320px;white-space:normal"><?=e($record['remarks'] ?: '—')?></td>
      <td><?=e($record['supervisor_name'] ?: '—')?></td>
      <td><span class="pill <?=hk_status_class($record['verification_status'])?>"><?=e($record['verification_status'])?></span>
        <?php if ($record['verified_by_name']): ?><br><small><?=e($record['verified_by_name'])?> · <?=e($record['verified_at'])?></small><?php endif; ?>
      </td>
      <td class="no-print" style="white-space:nowrap">
        <?php if ($canHousekeeperManage): ?>
          <button class="btn sm ghost" type="button" onclick="openModal('hk-edit-<?=(int)$record['id']?>')">Edit</button>
          <form method="post" style="display:inline" onsubmit="return confirmDel('Delete this cleaning record?')">
            <?=csrf_field()?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=(int)$record['id']?>">
            <button class="btn sm danger">Delete</button>
          </form>
        <?php elseif ($isSupervisor): ?>
          <button class="btn sm ghost" type="button" onclick="openModal('hk-verify-<?=(int)$record['id']?>')">Edit verification / note</button>
        <?php else: ?>—<?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<?php if ($canAdd): ?>
<?php
$otherForms = [['modal' => 'hk-others', 'record' => null]];
foreach ($otherRecords as $record) {
    if ($record['staff_id'] === (int)$user['id']) {
        $otherForms[] = ['modal' => 'hk-others-edit-' . $record['id'], 'record' => $record];
    }
}
foreach ($otherForms as $form):
    $editing = $form['record'] !== null;
    $formRecord = $form['record'];
    $modalId = $form['modal'];
?>
<div class="modal" id="<?=e($modalId)?>" onclick="if(event.target===this)closeModal('<?=e($modalId)?>')"><div class="box">
  <h3><?=$editing ? 'Edit Others Checklist' : 'Others Checklist'?></h3>
  <form method="post" action="index.php?page=housekeeping&amp;month=<?=e($month)?>">
    <?=csrf_field()?><input type="hidden" name="action" value="<?=$editing ? 'update_others' : 'create_others'?>">
    <?php if ($editing): ?><input type="hidden" name="id" value="<?=(int)$formRecord['id']?>"><?php endif; ?>
    <div class="field"><label>Name</label><input value="<?=e($user['name'])?>" readonly></div>
    <div class="field"><label>Date (Philippine time)</label><input type="date" value="<?=e($editing ? $formRecord['checklist_date'] : $checklistToday)?>" readonly></div>
    <p>Only one Others checklist can be submitted per staff member each day.</p>
    <div class="form-grid">
      <?php foreach ($otherChecklistItems as $itemKey => $label): ?>
        <?php $unitInputId = $modalId . '-unit-' . $itemKey; ?>
        <div class="field">
          <label style="display:flex;align-items:center;gap:8px">
            <input type="checkbox" style="width:auto" name="checklist_items[]" value="<?=e($itemKey)?>" <?=($editing ? array_key_exists($itemKey, $formRecord['items']) : in_array($itemKey, ['kitchen', 'floor', 'dispose_garbage', 'hallway'], true)) ? 'checked' : ''?><?php if (in_array($itemKey, $unitChecklistItems, true)): ?> data-unit-checkbox="<?=e($unitInputId)?>"<?php endif; ?>>
            <?=e($label)?>
          </label>
          <?php if (in_array($itemKey, $unitChecklistItems, true)): ?>
            <label for="<?=e($unitInputId)?>" hidden>Unit cleaned</label>
            <input class="hk-unit-input" id="<?=e($unitInputId)?>" name="unit_<?=e($itemKey)?>" value="<?=e($editing ? ($formRecord['items'][$itemKey] ?? '') : '')?>" maxlength="255" placeholder="Enter unit" disabled hidden>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="row"><button class="btn sm">Save Checklist</button><button type="button" class="btn sm ghost" onclick="closeModal('<?=e($modalId)?>')">Cancel</button></div>
  </form>
</div></div>
<?php endforeach; ?>
<script>
document.querySelectorAll('[data-unit-checkbox]').forEach(function (checkbox) {
  var unit = document.getElementById(checkbox.dataset.unitCheckbox);
  function updateUnit() {
    unit.disabled = !checkbox.checked;
    unit.required = checkbox.checked;
    unit.parentElement.querySelector('label[for]').hidden = !checkbox.checked;
    unit.hidden = !checkbox.checked;
  }
  checkbox.addEventListener('change', updateUnit);
  updateUnit();
});
</script>

<div class="modal" id="hk-create" onclick="if(event.target===this)closeModal('hk-create')"><div class="box">
  <h3>Add Cleaning Record</h3>
  <form method="post">
    <?=csrf_field()?><input type="hidden" name="action" value="create">
    <div class="form-grid">
      <div class="field"><label>Name</label><input value="<?=e($user['name'])?>" readonly></div>
      <div class="field"><label>Date (Philippine time)</label><input type="date" value="<?=e($checklistToday)?>" readonly></div>
      <div class="field"><label id="hk-units-label">Units</label>
        <details class="hk-unit-dropdown">
          <summary>Select units</summary>
          <div class="hk-unit-list">
            <?php foreach ($availableUnits as $unit): ?>
              <label><input type="checkbox" name="unit_ids[]" value="<?=(int)$unit['id']?>"> Unit <?=e($unit['unit_number'])?> · <?=e($unit['floor_name'])?></label>
            <?php endforeach; ?>
            <?php if (!$availableUnits): ?><span>No units available for you today.</span><?php endif; ?>
          </div>
        </details>
        <small>Select one or more units. Units you already recorded today are hidden; edit an existing record to update it.</small>
      </div>
      <div class="field"><label>Status</label><select name="status" required>
        <?php foreach ($statuses as $status): ?><option value="<?=e($status)?>" <?=$status === 'Undone' ? 'selected' : ''?>><?=e($status)?></option><?php endforeach; ?>
      </select></div>
      <div class="field"><label>Supervisor Inspection</label><select name="supervisor_id" required><option value="">Select supervisor</option>
        <?php foreach ($supervisors as $supervisor): ?><option value="<?=(int)$supervisor['id']?>" <?=(int)$supervisor['id'] === (int)$user['id'] ? 'selected' : ''?>><?=e($supervisor['name'])?> (<?=e($supervisor['role'] === 'super_admin' ? 'Super Admin' : 'Admin Staff')?>)</option><?php endforeach; ?>
      </select></div>
    </div>
    <div class="row"><button class="btn sm">Save</button><button type="button" class="btn sm ghost" onclick="closeModal('hk-create')">Cancel</button></div>
  </form>
</div></div>
<script>
document.querySelectorAll('#hk-create .hk-unit-dropdown').forEach(function (dropdown) {
  var summary = dropdown.querySelector('summary');
  var boxes = dropdown.querySelectorAll('input[name="unit_ids[]"]');
  function updateSummary() {
    var count = Array.from(boxes).filter(function (box) { return box.checked; }).length;
    summary.textContent = count ? count + (count === 1 ? ' unit selected' : ' units selected') : 'Select units';
  }
  boxes.forEach(function (box) { box.addEventListener('change', updateSummary); });
  updateSummary();
});
</script>
<?php endif; ?>

<?php foreach ($rows as $record):
    $canHousekeeperManage = $role === 'housekeeping'
        && (int)$record['staff_id'] === (int)$user['id']
        && (int)$record['created_by'] === (int)$user['id'];
    if (!$canHousekeeperManage) continue;
?>
<div class="modal" id="hk-edit-<?=(int)$record['id']?>" onclick="if(event.target===this)closeModal('hk-edit-<?=(int)$record['id']?>')"><div class="box">
  <h3>Edit Cleaning Record</h3>
  <form method="post">
    <?=csrf_field()?><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="<?=(int)$record['id']?>">
    <div class="form-grid">
      <div class="field"><label>Name</label><input value="<?=e($record['staff_name'])?>" readonly></div>
      <div class="field"><label>Date</label><input type="date" value="<?=e(substr((string)$record['cleaning_date'], 0, 10))?>" readonly></div>
      <div class="field"><label>Unit</label><select name="unit_id" required>
        <?php foreach ($units as $unit): ?><option value="<?=(int)$unit['id']?>" <?=(int)$record['unit_id'] === (int)$unit['id'] ? 'selected' : ''?>>Unit <?=e($unit['unit_number'])?> · <?=e($unit['floor_name'])?></option><?php endforeach; ?>
      </select></div>
      <div class="field"><label>Status</label><select name="status" required>
        <?php foreach ($statuses as $status): ?><option value="<?=e($status)?>" <?=$record['status'] === $status ? 'selected' : ''?>><?=e($status)?></option><?php endforeach; ?>
      </select></div>
      <div class="field"><label>Supervisor Inspection</label><select name="supervisor_id" required>
        <?php foreach ($supervisors as $supervisor): ?><option value="<?=(int)$supervisor['id']?>" <?=(int)$record['supervisor_id'] === (int)$supervisor['id'] ? 'selected' : ''?>><?=e($supervisor['name'])?> (<?=e($supervisor['role'] === 'super_admin' ? 'Super Admin' : 'Admin Staff')?>)</option><?php endforeach; ?>
      </select></div>
    </div>
    <div class="row"><button class="btn sm">Save</button><button type="button" class="btn sm ghost" onclick="closeModal('hk-edit-<?=(int)$record['id']?>')">Cancel</button></div>
  </form>
</div></div>
<?php endforeach; ?>

<?php if ($isSupervisor): foreach ($rows as $record): ?>
<div class="modal" id="hk-verify-<?=(int)$record['id']?>" onclick="if(event.target===this)closeModal('hk-verify-<?=(int)$record['id']?>')"><div class="box">
  <h3>Update Verification / Note</h3>
  <p><?=e($record['staff_name'])?> · <?=e($record['display_unit'])?> · <?=e(substr((string)$record['cleaning_date'], 0, 10))?></p>
  <form method="post">
    <?=csrf_field()?><input type="hidden" name="action" value="supervisor_update"><input type="hidden" name="id" value="<?=(int)$record['id']?>">
    <div class="field"><label>Verification</label><select name="verification_status" required>
      <?php foreach ($verificationStatuses as $verification): ?><option value="<?=e($verification)?>" <?=$record['verification_status'] === $verification ? 'selected' : ''?>><?=e($verification)?></option><?php endforeach; ?>
    </select></div>
    <div class="field"><label>Remark / Note</label><textarea name="remarks" rows="3" maxlength="255"><?=e($record['remarks'] ?? '')?></textarea></div>
    <div class="row"><button class="btn sm">Save</button><button type="button" class="btn sm ghost" onclick="closeModal('hk-verify-<?=(int)$record['id']?>')">Cancel</button></div>
  </form>
</div></div>
<?php endforeach; endif; ?>

<style>
.hk-unit-dropdown { border:1px solid #6b7280; border-radius:6px; background:#fff; }
.hk-unit-dropdown summary { cursor:pointer; padding:9px 10px; }
.hk-unit-list { max-height:180px; overflow-y:auto; padding:4px 10px 10px; }
.hk-unit-list label { display:flex; align-items:center; gap:8px; padding:4px 0; }
.field .hk-unit-list input[type="checkbox"] { width:auto; margin:0; }
.field input.hk-unit-input { border:2px solid #374151; color:#111827; }
.field input.hk-unit-input::placeholder { color:#4b5563; opacity:1; }
@media print {
  .no-print, .modal, .sidebar, .topbar, .footer { display:none !important; }
  .content, .card { max-width:none !important; box-shadow:none !important; }
}
</style>
