<?php
// pages/tenants.php — Tenant profile registry (Tenant/Resident & Tenant/Commercial).
// Profiles carry a 2x2 picture, floor/unit assignment and contract details.
// Saving a profile with a unit automatically links it to that room (Rooms → View Details).
require_once __DIR__ . '/../includes/tenant_profile_helpers.php';
$pdo = db();
$err = null;

$TENANT_TYPES = ['Tenant/Resident', 'Tenant/Commercial'];
$PROFILE_STATUSES = ['Active', 'Inactive'];
$FLOORS = ['G', '2nd', '3rd', '4th', '5th', '6th'];

function tenant_profile_status_class(string $s): string {
    return $s === 'Active' ? 'ok' : 'bad';
}
function tenant_photo_url(array $row): string {
    return 'index.php?page=download&type=tenant_photo&id=' . (int)$row['id'];
}
$clean = static function (array $src): array {
    return [
        'tenant_type' => (string)($src['tenant_type'] ?? ''),
        'full_name' => trim((string)($src['full_name'] ?? '')),
        'email' => trim((string)($src['email'] ?? '')),
        'phone' => trim((string)($src['phone'] ?? '')),
        'bank_reference' => is_string($src['bank_reference'] ?? '') ? trim($src['bank_reference'] ?? '') : '',
        'contract_start' => trim((string)($src['contract_start'] ?? '')),
        'contract_end' => trim((string)($src['contract_end'] ?? '')),
        'floor_level' => trim((string)($src['floor_level'] ?? '')),
        'unit_number' => trim((string)($src['unit_number'] ?? '')),
        'profile_status' => (string)($src['profile_status'] ?? ''),
    ];
};
$validate = static function (array $d) use ($TENANT_TYPES, $PROFILE_STATUSES, $FLOORS): array {
    if ($d['full_name'] === '') throw new RuntimeException('Complete name is required.');
    if ($d['email'] !== '' && !filter_var($d['email'], FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Invalid email address.');
    if (!in_array($d['tenant_type'], $TENANT_TYPES, true)) throw new RuntimeException('Please choose a valid role.');
    if (!in_array($d['profile_status'], $PROFILE_STATUSES, true)) throw new RuntimeException('Please choose a valid tenant status.');
    if (!in_array($d['floor_level'], $FLOORS, true)) throw new RuntimeException('Please choose a floor.');
    if ($d['unit_number'] === '') throw new RuntimeException('Please choose a unit number.');
    $derivedFloor = tenant_floor_from_unit($d['unit_number']);
    if ($derivedFloor === '') throw new RuntimeException('That unit number does not match a supported floor.');
    if ($derivedFloor !== $d['floor_level']) throw new RuntimeException('The selected unit does not belong to the chosen floor.');
    tenant_validate_contract_dates($d['contract_start'], $d['contract_end']);
    return $d;
};

// Unit numbers are actual rooms; the leading digit decides the floor (2xx → 2nd, etc.).
$unitRooms = [];
$unitFloors = [];
try {
    foreach ($pdo->query('SELECT id, room_number, monthly_rate FROM rooms ORDER BY room_number')->fetchAll() as $rm) {
        $label = tenant_floor_from_unit($rm['room_number']);
        if ($label === '') continue;
        $rm['floor_label'] = $label;
        $unitRooms[] = $rm;
        $unitFloors[$rm['room_number']] = $label;
    }
} catch (Throwable $t) {
    error_log('Could not load rooms for tenant profiles: ' . $t->getMessage());
    $err = 'Could not load rooms for tenant profiles.';
}

$partialReservations = [];
try {
    $partialReservations = $pdo->query("SELECT r.id, r.full_name, r.email, r.phone, r.bank_reference, r.unit_number, r.amount, r.received_at
        FROM reservations r JOIN rooms rm ON rm.id=r.room_id JOIN beds b ON b.id=r.bed_id AND b.room_id=r.room_id
        WHERE r.status='Reserved' AND r.payment_classification='Partial' AND b.status='reserved' AND b.resident_id IS NULL
        ORDER BY r.full_name, r.id")->fetchAll();
} catch (Throwable $t) {
    error_log('Could not load partial reservations for tenant profiles: ' . $t->getMessage());
    $err = $err ?? 'Could not load partial reservations. Please check that database migrations have been run.';
}

// ---------- POST handlers ----------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'create') {
            if (!empty($_POST['reservation_id'])) throw new RuntimeException('Select the reservation again and complete its payment before saving.');
            $d = $validate($clean($_POST));
            if (isset($_POST['bank_reference']) && !is_string($_POST['bank_reference'])) throw new RuntimeException('Invalid bank reference number.');
            if ($d['bank_reference'] === '') throw new RuntimeException('Bank reference number is required.');
            if (mb_strlen($d['bank_reference']) > 100) throw new RuntimeException('Bank reference number must be at most 100 characters.');
            if ($d['email'] === '') throw new RuntimeException('An email address is required to create the tenant account and save its receipt.');
            if (mb_strlen($d['full_name']) > 120 || mb_strlen($d['email']) > 160) throw new RuntimeException('The name or email is too long.');
            if (($_POST['payment_confirmed'] ?? '') !== '1') throw new RuntimeException('Confirm that the advance and deposit have been received before creating the receipt.');
            $creationToken = (string)($_POST['creation_token'] ?? '');
            if (!preg_match('/\A[a-f0-9]{64}\z/', $creationToken)) throw new RuntimeException('Invalid receipt submission. Please try again.');
            $findReceipt = $pdo->prepare('SELECT id FROM tenant_acknowledgment_receipts WHERE creation_token=?');
            $findReceipt->execute([$creationToken]);
            if ($existingReceiptId = $findReceipt->fetchColumn()) {
                flash('success', 'This tenant profile and receipt were already saved.');
                redirect('index.php?page=acknowledgment_receipt&id=' . (int)$existingReceiptId);
            }
            tenant_validate_new_profile_details($d['phone'], $d['contract_start'], $d['contract_end'], $_FILES['photo'] ?? null);
            $photo = null;
            $newPhoto = false;
            $id = 0; $bedNo = '—'; $contractSynced = false;
            try {
                $pdo->beginTransaction();
                $chk = $pdo->prepare('SELECT id, monthly_rate FROM rooms WHERE room_number=? FOR UPDATE');
                $chk->execute([$d['unit_number']]);
                $room = $chk->fetch();
                if (!$room) throw new RuntimeException('Unit number was not found in Rooms.');
                $roomId = (int)$room['id'];
                $rate = (string)$room['monthly_rate'];
                if ((float)$rate <= 0) throw new RuntimeException('Set a positive monthly rate for this room before issuing a receipt.');
                if ((string)($_POST['expected_rate'] ?? '') !== $rate) throw new RuntimeException('The room rate changed. Please review the updated amount and try again.');
                $st = $pdo->prepare('INSERT INTO tenant_profiles (tenant_type, full_name, email, phone, contract_start, contract_end, unit_number, floor_level, room_id, photo_path, profile_status, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
                $st->execute([
                    $d['tenant_type'], $d['full_name'], $d['email'] ?: null, $d['phone'] ?: null,
                    $d['contract_start'] ?: null, $d['contract_end'] ?: null,
                    $d['unit_number'], $d['floor_level'], $roomId, $photo,
                    $d['profile_status'], (int)$user['id'],
                ]);
                $id = (int)$pdo->lastInsertId();
                $photo = tenant_store_photo($_FILES['photo'] ?? null, null, $id);
                $newPhoto = $photo !== null;
                $pdo->prepare('UPDATE tenant_profiles SET photo_path=? WHERE id=?')->execute([$photo, $id]);
                $receipt = $pdo->prepare('INSERT INTO tenant_acknowledgment_receipts (tenant_profile_id, creation_token, tenant_name, address, unit_number, monthly_rate, total_amount, bank_reference, received_by_name, received_by, received_at) VALUES (?, ?, ?, ?, ?, ?, ? * 2, ?, ?, ?, NOW())');
                $receipt->execute([$id, $creationToken, $d['full_name'], '7461 Heart Bldg., Brgy. San Antonio, Makati City', $d['unit_number'], $rate, $rate, $d['bank_reference'], $user['name'], (int)$user['id']]);
                $receiptId = (int)$pdo->lastInsertId();
                $bedId = assign_tenant_bed($pdo, $roomId);
                $pdo->prepare('UPDATE tenant_profiles SET bed_id=? WHERE id=?')->execute([$bedId, $id]);
                $bedSt = $pdo->prepare('SELECT bed_number FROM beds WHERE id=?');
                $bedSt->execute([$bedId]);
                $bedNo = (string)$bedSt->fetchColumn();
                $contractSynced = sync_tenant_contract($pdo, $id, $roomId, $d['contract_start'], $d['contract_end'], (int)$user['id']);
                $account = tenant_provision_account($pdo, [
                    'id' => $id,
                    'full_name' => $d['full_name'],
                    'email' => $d['email'],
                    'phone' => $d['phone'],
                    'profile_status' => $d['profile_status'],
                    'room_id' => $roomId,
                ]);
                $pdo->commit();
            } catch (Throwable $t) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                if ($newPhoto) tenant_remove_photo($photo);
                if ($t instanceof PDOException && (int)($t->errorInfo[1] ?? 0) === 1062) {
                    $findReceipt->execute([$creationToken]);
                    if ($existingReceiptId = $findReceipt->fetchColumn()) {
                        flash('success', 'This tenant profile and receipt were already saved.');
                        redirect('index.php?page=acknowledgment_receipt&id=' . (int)$existingReceiptId);
                    }
                }
                throw $t;
            }
            refresh_room_status($roomId);
            audit_log((int)$user['id'], 'Created tenant profile ' . $d['full_name'] . ' (unit ' . $d['unit_number'] . ', bed ' . $bedNo . ')', 'tenants', (string)$id);
            $msg = 'Tenant profile created and added to Room ' . $d['unit_number'] . ' (bed ' . $bedNo . ').';
            $msg .= $contractSynced ? ' Contract added to Contract Monitoring.' : ' Add both contract dates to create its contract.';
            $msg .= $account['created'] ? ' Tenant portal account created.' : ' Existing tenant portal account linked.';
            $msg .= ' The acknowledgment receipt is saved in the tenant account under My Profile.';
            $mailError = null;
            if (tenant_send_account_email([
                'full_name' => $d['full_name'],
                'email' => $account['email'],
                'profile_status' => $d['profile_status'],
                'account_status' => $account['status'],
            ], $account['password'], $mailError)) {
                $msg .= ' Welcome email sent.';
            } else {
                flash('error', 'Tenant profile and account were created, but the welcome email could not be sent: ' . ($mailError ?: 'Unknown mail error.'));
            }
            flash('success', $msg);
            redirect('index.php?page=acknowledgment_receipt&id=' . $receiptId);
        }

        if ($action === 'deactivate') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('Invalid tenant profile.');
            $st = $pdo->prepare("UPDATE tenant_profiles SET profile_status='Inactive' WHERE id=? AND profile_status='Active'");
            $st->execute([$id]);
            if ($st->rowCount() !== 1) throw new RuntimeException('The tenant was not found or is already inactive.');
            audit_log((int)$user['id'], 'Marked tenant profile inactive', 'tenants', (string)$id);
            flash('success', 'Tenant profile marked inactive. The tenant account, bed assignment, and contract were not changed.');
            $filterArgs = ['page' => 'tenants'];
            foreach (['q', 'type', 'status', 'floor'] as $filter) {
                if (isset($_GET[$filter]) && is_string($_GET[$filter])) $filterArgs[$filter] = $_GET[$filter];
            }
            redirect('index.php?' . http_build_query($filterArgs));
        }

        if ($action === 'update') {
            $id = (int)($_POST['id'] ?? 0);
            $cur = $pdo->prepare('SELECT * FROM tenant_profiles WHERE id=?');
            $cur->execute([$id]);
            $existing = $cur->fetch();
            if (!$existing) throw new RuntimeException('Tenant profile not found.');
            $d = $validate($clean($_POST));
            $chk = $pdo->prepare('SELECT id FROM rooms WHERE room_number=?');
            $chk->execute([$d['unit_number']]);
            $roomId = (int)$chk->fetchColumn();
            if (!$roomId) throw new RuntimeException('Unit number was not found in Rooms.');
 
            $oldRoomId = (int)($existing['room_id'] ?? 0);
            $oldBedId = (int)($existing['bed_id'] ?? 0);
            $keepBedId = ($oldRoomId === $roomId && $oldBedId > 0) ? $oldBedId : null;
            $photo = tenant_store_photo($_FILES['photo'] ?? null, $existing['photo_path'], $id);
            $newPhoto = $photo !== $existing['photo_path'];
            $bedNo = '—'; $contractSynced = false;

            try {
                $pdo->beginTransaction();
                $newBedId = assign_tenant_bed($pdo, $roomId, $keepBedId);
                $st = $pdo->prepare('UPDATE tenant_profiles SET tenant_type=?, full_name=?, email=?, phone=?, contract_start=?, contract_end=?, unit_number=?, floor_level=?, room_id=?, bed_id=?, photo_path=?, profile_status=? WHERE id=?');
                $st->execute([
                    $d['tenant_type'], $d['full_name'], $d['email'] ?: null, $d['phone'] ?: null,
                    $d['contract_start'] ?: null, $d['contract_end'] ?: null,
                    $d['unit_number'], $d['floor_level'], $roomId, $newBedId, $photo,
                    $d['profile_status'], $id,
                ]);
                if ($oldBedId > 0 && $oldBedId !== $newBedId) release_tenant_bed($pdo, $oldBedId);
                $bedSt = $pdo->prepare('SELECT bed_number FROM beds WHERE id=?');
                $bedSt->execute([$newBedId]);
                $bedNo = (string)$bedSt->fetchColumn();
                $contractSynced = sync_tenant_contract($pdo, $id, $roomId, $d['contract_start'], $d['contract_end'], (int)$user['id']);
                $pdo->commit();
            } catch (Throwable $t) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                if ($newPhoto) tenant_remove_photo($photo);
                throw $t;
            }

            if ($newPhoto) tenant_remove_photo($existing['photo_path']);
            refresh_room_status($oldRoomId);
            if ($roomId !== $oldRoomId) refresh_room_status($roomId);
            audit_log((int)$user['id'], 'Updated tenant profile ' . $d['full_name'] . ' (unit ' . $d['unit_number'] . ', bed ' . $bedNo . ')', 'tenants', (string)$id);
            $msg = 'Tenant profile updated (Room ' . $d['unit_number'] . ', bed ' . $bedNo . ').';
            $msg .= $contractSynced ? ' Contract synchronized.' : ' Add both contract dates to create its contract.';
            flash('success', $msg);
            redirect('index.php?page=tenants');
        }

        if ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            try {
                $pdo->beginTransaction();
                $chk = $pdo->prepare('SELECT full_name, photo_path, room_id, bed_id, profile_status FROM tenant_profiles WHERE id=? FOR UPDATE');
                $chk->execute([$id]);
                $row = $chk->fetch();
                if (!$row) throw new RuntimeException('Tenant profile not found.');
                if ($row['profile_status'] !== 'Inactive') {
                    throw new RuntimeException('Tenant is active. Only inactive tenants can be deleted.');
                }
                $oldRoomId = (int)($row['room_id'] ?? 0);
                $oldBedId = (int)($row['bed_id'] ?? 0);
                $linkedUsers = $pdo->prepare("UPDATE users SET deleted_at=NOW(), status='inactive' WHERE tenant_profile_id=? AND deleted_at IS NULL");
                $linkedUsers->execute([$id]);
                $deletedUserCount = $linkedUsers->rowCount();
                // The linked contract is removed with the tenant (cascade also
                // covers this, but keep it explicit for schemas without the FK).
                $pdo->prepare('DELETE FROM contracts WHERE tenant_profile_id=?')->execute([$id]);
                $pdo->prepare('DELETE FROM tenant_profiles WHERE id=?')->execute([$id]);
                release_tenant_bed($pdo, $oldBedId);
                $pdo->commit();
            } catch (Throwable $t) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $t;
            }
            tenant_remove_photo($row['photo_path']);
            refresh_room_status($oldRoomId);
            audit_log((int)$user['id'], 'Deleted tenant profile ' . $row['full_name'], 'tenants', (string)$id);
            $msg = 'Tenant profile deleted. Its contract was removed and the bed is now available.';
            if ($deletedUserCount > 0) $msg .= ' Its linked user account was also deleted.';
            flash('success', $msg);
            redirect('index.php?page=tenants');
        }
    } catch (Throwable $t) {
        $err = $t->getMessage() ?: 'Something went wrong. Please try again.';
    }
}

// ---------- Filters ----------
$q = trim((string)($_GET['q'] ?? ''));
$fType = (string)($_GET['type'] ?? '');
$fStatus = (string)($_GET['status'] ?? '');
$fFloor = (string)($_GET['floor'] ?? '');
if (!in_array($fType, $TENANT_TYPES, true)) $fType = '';
if (!in_array($fStatus, $PROFILE_STATUSES, true)) $fStatus = '';
if (!in_array($fFloor, $FLOORS, true)) $fFloor = '';

try {
    $where = ['1=1']; $args = [];
    if ($fType !== '') { $where[] = 'tenant_type=?'; $args[] = $fType; }
    if ($fStatus !== '') { $where[] = 'profile_status=?'; $args[] = $fStatus; }
    if ($fFloor !== '') { $where[] = 'floor_level=?'; $args[] = $fFloor; }
    if ($q !== '') {
        $where[] = '(full_name LIKE ? OR email LIKE ? OR phone LIKE ? OR unit_number LIKE ? OR floor_level LIKE ?)';
        array_push($args, "%$q%", "%$q%", "%$q%", "%$q%", "%$q%");
    }
    $st = $pdo->prepare('SELECT tp.*, b.bed_number, (SELECT ar.id FROM tenant_acknowledgment_receipts ar WHERE ar.tenant_profile_id=tp.id) AS acknowledgment_id, (SELECT rv.id FROM reservations rv WHERE rv.tenant_profile_id=tp.id AND rv.status=\'Converted\' ORDER BY rv.id DESC LIMIT 1) AS reservation_receipt_id, (SELECT p.id FROM reservation_additional_payments p JOIN reservations rv ON rv.id=p.reservation_id WHERE rv.tenant_profile_id=tp.id AND rv.status=\'Converted\' ORDER BY p.id DESC LIMIT 1) AS additional_receipt_id FROM tenant_profiles tp LEFT JOIN beds b ON b.id=tp.bed_id WHERE ' . implode(' AND ', $where) . ' ORDER BY tp.created_at DESC, tp.id DESC LIMIT 300');
    $st->execute($args);
    $rows = $st->fetchAll();
    $counts = $pdo->query("SELECT
        COUNT(*) AS total,
        SUM(tenant_type='Tenant/Resident') AS resident_count,
        SUM(tenant_type='Tenant/Commercial') AS commercial_count
        FROM tenant_profiles")->fetch() ?: [];
} catch (Throwable $t) {
    $rows = []; $counts = [];
    error_log('Could not load tenant profiles: ' . $t->getMessage());
    $err = $err ?? 'Could not load tenant profiles. Please check that database migrations have been run.';
}

$dateVal = static fn($v): string => $v ? substr((string)$v, 0, 10) : '';
$reservationFormError = $_SESSION['tenant_reservation_error'] ?? null;
unset($_SESSION['tenant_reservation_error']);
if ($reservationFormError) $err = $reservationFormError['message'];
$createValues = $reservationFormError ? $reservationFormError['values'] : ($err && ($_POST['action'] ?? '') === 'create' ? $clean($_POST) : []);
$openCreate = (bool)$createValues;
if (!$reservationFormError && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && isset($_GET['reservation_id'])) {
    $reservationId = $_GET['reservation_id'];
    $eligibleIds = array_map('strval', array_column($partialReservations, 'id'));
    if (is_string($reservationId) && preg_match('/\A[1-9][0-9]{0,9}\z/', $reservationId) && in_array($reservationId, $eligibleIds, true)) {
        $createValues['reservation_id'] = $reservationId;
        $openCreate = true;
    } else {
        $err = $err ?? 'This reservation is no longer available for tenant profile creation.';
    }
}
$renderUnitOptions = static function (array $unitRooms, string $selectedUnit, string $selectedFloor): void {
    $known = array_column($unitRooms, 'room_number');
    if ($selectedUnit !== '' && !in_array($selectedUnit, $known, true)) {
        $unitRooms[] = ['id' => 0, 'room_number' => $selectedUnit, 'floor_label' => $selectedFloor, 'monthly_rate' => ''];
    }
    foreach ($unitRooms as $u) {
        $sel = $selectedUnit === $u['room_number'] ? ' selected' : '';
        echo '<option value="' . e($u['room_number']) . '" data-floor="' . e($u['floor_label']) . '" data-rate="' . e((string)$u['monthly_rate']) . '"' . $sel . '>' . e($u['room_number']) . '</option>';
    }
};
?>
<?php if ($err): ?><div class="alert error"><?=e($err)?></div><?php endif; ?>

<div class="grid g3" style="margin-bottom:14px">
  <div class="card stat"><b><?=e((string)($counts['total'] ?? 0))?></b><span>Total Tenant Profiles</span></div>
  <div class="card stat"><b><?=e((string)($counts['resident_count'] ?? 0))?></b><span>Tenant / Resident</span></div>
  <div class="card stat"><b><?=e((string)($counts['commercial_count'] ?? 0))?></b><span>Tenant / Commercial</span></div>
</div>

<div class="toolbar">
  <form method="get" action="index.php" class="row" style="flex:1;flex-wrap:wrap">
    <input type="hidden" name="page" value="tenants">
    <input name="q" placeholder="Search name / email / phone / unit…" value="<?=e($q)?>" style="min-width:200px">
    <select name="type">
      <option value="">All roles</option>
      <?php foreach ($TENANT_TYPES as $t): ?><option value="<?=e($t)?>" <?=$fType===$t?'selected':''?>><?=e($t)?></option><?php endforeach; ?>
    </select>
    <select name="floor">
      <option value="">All floors</option>
      <?php foreach ($FLOORS as $fl): ?><option value="<?=e($fl)?>" <?=$fFloor===$fl?'selected':''?>><?=e($fl)?> Floor</option><?php endforeach; ?>
    </select>
    <select name="status">
      <option value="">All statuses</option>
      <?php foreach ($PROFILE_STATUSES as $s): ?><option value="<?=e($s)?>" <?=$fStatus===$s?'selected':''?>><?=e($s)?></option><?php endforeach; ?>
    </select>
    <button class="btn sm ghost" type="submit">Filter</button>
    <a class="btn sm ghost" href="index.php?page=tenants">Reset</a>
  </form>
  <button class="btn sm" onclick="openModal('mTenantCreate');if (!document.getElementById('createReservation').value) tenantFilterUnits('createFloor','createUnit','')">+ Add Tenant Profile</button>
  <a class="btn sm ghost" href="index.php?page=tenant_accounts">Manage Accounts</a>
</div>

<div class="card" style="padding:0;overflow:auto">
<table class="tbl">
  <thead><tr>
    <th>2x2 Photo</th><th>Complete Name</th><th>Role</th><th>Email</th><th>Phone / Cp No.</th>
    <th>Floor</th><th>Unit No.</th><th>Bed</th><th>Contract Start</th><th>Contract End</th><th>Status</th><th class="no-print">Actions</th>
  </tr></thead>
  <tbody>
  <?php if (!$rows): ?><tr><td colspan="12">No tenant profiles found.</td></tr><?php endif; ?>
  <?php foreach ($rows as $r): ?>
    <tr id="tenant-<?=(int)$r['id']?>">
      <td>
        <?php if (!empty($r['photo_path'])): ?>
          <img src="<?=e(tenant_photo_url($r))?>" alt="2x2 photo of <?=e($r['full_name'])?>" style="width:52px;height:52px;object-fit:cover;border-radius:8px;border:2px solid var(--line);display:block">
        <?php else: ?>
          <span style="display:inline-grid;place-items:center;width:52px;height:52px;border-radius:8px;background:#f3e8ff;color:var(--secondary);font-weight:700"><?=e(mb_substr($r['full_name'], 0, 1))?></span>
        <?php endif; ?>
      </td>
      <td><strong><?=e($r['full_name'])?></strong></td>
      <td><span class="pill info"><?=e($r['tenant_type'])?></span></td>
      <td><?=e($r['email'] ?? '—')?></td>
      <td><?=e($r['phone'] ?? '—')?></td>
      <td><?=e($r['floor_level'] ?? '—')?></td>
      <td><?=e($r['unit_number'] ?? '—')?></td>
      <td><span class="pill <?=$r['bed_number'] ? 'info' : ''?>"><?=e($r['bed_number'] ?? '—')?></span></td>
      <td><?=e($dateVal($r['contract_start']) ?: '—')?></td>
      <td><?=e($dateVal($r['contract_end']) ?: '—')?></td>
      <td>
        <?php if ($r['profile_status'] === 'Active'): ?>
          <form method="post" onsubmit="return confirm('Mark this tenant profile inactive? The account, bed assignment, and contract will not change.')">
            <?=csrf_field()?><input type="hidden" name="action" value="deactivate"><input type="hidden" name="id" value="<?=(int)$r['id']?>">
            <button type="submit" class="pill ok" style="border:0;cursor:pointer" aria-label="Mark <?=e($r['full_name'])?> inactive" title="Click to mark inactive">Active</button>
          </form>
        <?php else: ?>
          <span class="pill <?=tenant_profile_status_class($r['profile_status'])?>"><?=e($r['profile_status'])?></span>
        <?php endif; ?>
      </td>
      <td class="no-print" style="white-space:nowrap">
        <a class="btn sm ghost" href="index.php?page=billing&q=<?=urlencode($r['full_name'])?>">Billing</a>
        <?php if ($r['acknowledgment_id']): ?><a class="btn sm ghost" href="index.php?page=acknowledgment_receipt&id=<?=(int)$r['acknowledgment_id']?>">Receipt</a><?php endif; ?>
        <?php if ($r['reservation_receipt_id']): ?><a class="btn sm ghost" href="index.php?page=acknowledgment_receipt&amp;type=reservation&amp;id=<?=(int)$r['reservation_receipt_id']?>">Reservation Receipt</a><?php endif; ?>
        <?php if ($r['additional_receipt_id']): ?><a class="btn sm ghost" href="index.php?page=acknowledgment_receipt&amp;type=reservation_payment&amp;id=<?=(int)$r['additional_receipt_id']?>">Completion Receipt</a><?php endif; ?>
        <button class="btn sm ghost" onclick="openModal('edit-<?=(int)$r['id']?>');tenantFilterUnits('editFloor-<?=(int)$r['id']?>','editUnit-<?=(int)$r['id']?>','<?=e($r['unit_number'])?>')">Edit</button>
        <form method="post" style="display:inline" onsubmit="return confirmDel('Delete this tenant profile, its linked user account, and its tenant acknowledgment receipt? Reservation payment receipts will be retained. Only inactive tenants can be deleted.')">
          <?=csrf_field()?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=(int)$r['id']?>">
          <button class="btn sm danger">Delete</button>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<!-- Create modal -->
<div class="modal" id="mTenantCreate"><div class="box">
  <h3>Add Tenant Profile</h3>
  <form method="post" enctype="multipart/form-data" id="tenantCreateForm">
    <?=csrf_field()?><input type="hidden" name="action" value="create" id="tenantCreateAction">
    <input type="hidden" name="creation_token" value="<?=e($createValues && preg_match('/\A[a-f0-9]{64}\z/', (string)($_POST['creation_token'] ?? '')) ? $_POST['creation_token'] : bin2hex(random_bytes(32)))?>">
    <input type="hidden" name="expected_rate" id="receiptExpectedRate">
    <div id="tenantProfileStep">
    <?php if ($createValues && $err): ?><div class="alert error"><?=e($err)?></div><p>Please review the details and select the photo again if needed.</p><?php endif; ?>
    <div class="field"><label for="createReservation">Partial reservation (optional)</label>
      <select id="createReservation" name="reservation_id" onchange="tenantApplyReservation(true)">
        <option value="">No reservation</option>
        <?php foreach ($partialReservations as $reservation): ?>
        <option value="<?=e($reservation['id'])?>" data-name="<?=e($reservation['full_name'])?>" data-email="<?=e($reservation['email'])?>" data-phone="<?=e($reservation['phone'])?>" data-reference="<?=e($reservation['bank_reference'])?>" data-unit="<?=e($reservation['unit_number'])?>" data-floor="<?=e(tenant_floor_from_unit($reservation['unit_number']))?>" data-amount="<?=e($reservation['amount'])?>" data-date="<?=e(substr((string)$reservation['received_at'], 0, 10))?>" <?=($createValues['reservation_id'] ?? '') === (string)$reservation['id'] ? 'selected' : ''?>><?=e($reservation['full_name'])?> — Unit <?=e($reservation['unit_number'])?> (AR-R<?=e(str_pad((string)$reservation['id'], 6, '0', STR_PAD_LEFT))?>)</option>
        <?php endforeach; ?>
      </select>
      <small style="color:var(--muted)">Only active partial-payment reservations are listed. Their identity, unit, and reserved bed are retained.</small>
    </div>
    <div class="field"><label>2x2 Picture (jpg / png / webp · max 2MB, required)</label>
      <input type="file" name="photo" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" onchange="tenantPreview(this,'createPhotoPreview')" required>
      <img id="createPhotoPreview" alt="2x2 preview" style="display:none;width:96px;height:96px;object-fit:cover;border-radius:8px;border:2px solid var(--line);margin-top:8px">
    </div>
    <div class="form-grid">
      <div class="field"><label>Role</label>
        <select name="tenant_type" required>
          <?php foreach ($TENANT_TYPES as $t): ?><option value="<?=e($t)?>" <?=($createValues['tenant_type'] ?? '') === $t ? 'selected' : ''?>><?=e($t)?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="field"><label>Complete Name</label><input name="full_name" value="<?=e($createValues['full_name'] ?? '')?>" required maxlength="120" placeholder="Full name"></div>
      <div class="field"><label>Status</label>
        <select name="profile_status" required>
          <?php foreach ($PROFILE_STATUSES as $s): ?><option value="<?=e($s)?>" <?=($createValues['profile_status'] ?? 'Active') === $s ? 'selected' : ''?>><?=e($s)?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="field"><label>Email (required for tenant account)</label><input name="email" type="email" value="<?=e($createValues['email'] ?? '')?>" maxlength="160" placeholder="name@example.com" required></div>
      <div class="field"><label>Phone / Cp number</label><input name="phone" value="<?=e($createValues['phone'] ?? '')?>" maxlength="40" placeholder="09XX XXX XXXX" required></div>
      <div class="field"><label for="createBankReference" id="createBankReferenceLabel">Ref No. (bank reference, required)</label><input id="createBankReference" name="bank_reference" type="text" maxlength="100" value="<?=e($createValues['bank_reference'] ?? '')?>" placeholder="Bank reference number" required></div>
      <div id="reservationBalanceFields" style="display:none;grid-column:1/-1">
        <p>Previous partial payment: <strong id="reservationPartialAmount"></strong> · <span id="reservationPartialDate"></span> · <span id="reservationPartialNumber"></span></p>
        <div class="field"><label for="reservationBalance">Balance payment (₱)</label><input id="reservationBalance" name="additional_amount" type="number" min="0.01" max="999999999.99" step="0.01" value="<?=e($createValues['additional_amount'] ?? '')?>" disabled></div>
        <div class="field"><label for="reservationBalanceReference">Balance bank reference no.</label><input id="reservationBalanceReference" name="additional_reference" maxlength="100" value="<?=e($createValues['additional_reference'] ?? '')?>" disabled></div>
        <small>Enter the payment received now. Staff must verify that the total is fully paid.</small>
      </div>
      <div class="field"><label>Floor</label>
        <select name="floor_level" id="createFloor" required onchange="tenantFilterUnits('createFloor','createUnit','')">
          <option value="">— Select floor —</option>
          <?php foreach ($FLOORS as $fl): ?><option value="<?=e($fl)?>" <?=($createValues['floor_level'] ?? '') === $fl ? 'selected' : ''?>><?=e($fl)?> Floor</option><?php endforeach; ?>
        </select>
      </div>
      <div class="field"><label>Unit Number</label>
        <select name="unit_number" id="createUnit" required>
          <option value="">— Select floor first —</option>
          <?php $renderUnitOptions($unitRooms, $createValues['unit_number'] ?? '', $createValues['floor_level'] ?? ''); ?>
        </select>
        <small id="createUnitHelp" style="color:var(--muted)">Units are rooms matching the selected floor (2nd → 2xx, 3rd → 3xx, …). A vacant bed is assigned automatically.</small>
      </div>
      <div class="field"><label>Date start of Contract</label><input name="contract_start" type="date" value="<?=e($createValues['contract_start'] ?? '')?>" required></div>
      <div class="field"><label>Date End of Contract</label><input name="contract_end" type="date" value="<?=e($createValues['contract_end'] ?? '')?>" required></div>
      <div class="field" style="grid-column:1/-1"><small style="color:var(--muted)">Enter both dates to create this tenant's contract automatically. It will appear in Tenant Files and Contract Monitoring.</small></div>
    </div>
    <div class="row"><button type="button" class="btn sm" onclick="tenantReceiptNext()">Next</button><button type="button" class="btn sm ghost" onclick="closeModal('mTenantCreate')">Cancel</button></div>
    </div>
    <div id="tenantReceiptStep" hidden>
      <h3>Acknowledgment Receipt</h3>
      <p>Receipt number and date will be assigned when saved.</p>
      <p>Received from: <strong id="receiptName"></strong><br>
      Address: 7461 Heart Bldg., Brgy. San Antonio, Makati City<br>
      Unit: <strong id="receiptUnit"></strong></p>
      <p>Bank Ref No.: <strong id="receiptBankReference"></strong></p>
      <p id="receiptRegularAmounts">One month advance: <strong id="receiptAdvance"></strong><br>
      One month deposit: <strong id="receiptDeposit"></strong><br>
      Total: <strong id="receiptTotal"></strong></p>
      <p id="receiptReservationAmounts" hidden>Previous partial receipt: <strong id="receiptPartialDetails"></strong><br>
      Previous partial payment: <strong id="receiptPartial"></strong><br>
      Balance payment: <strong id="receiptBalance"></strong><br>
      Total full payment: <strong id="receiptCombined"></strong></p>
      <p><label><input type="checkbox" name="payment_confirmed" value="1" id="receiptConfirmed" disabled required> <span id="receiptConfirmationLabel">I confirm that the full advance and deposit have been received.</span></label></p>
      <p><small>Saving creates the tenant profile and saves this receipt in the tenant account under My Profile.</small></p>
      <p><small>This receipt is a separate record and does not automatically pay a bill or apply the deposit.</small></p>
      <div class="row"><button type="button" class="btn sm ghost" onclick="tenantReceiptBack()">Back</button><button class="btn sm" type="submit">Save Acknowledgment Receipt</button></div>
    </div>
  </form>
</div></div>

<?php foreach ($rows as $r): $rowFloor = $r['floor_level'] ?: tenant_floor_from_unit((string)($r['unit_number'] ?? '')); ?>
<!-- Edit modal -->
<div class="modal" id="edit-<?=(int)$r['id']?>"><div class="box">
  <h3>Edit Tenant Profile</h3>
  <form method="post" enctype="multipart/form-data">
    <?=csrf_field()?><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="<?=(int)$r['id']?>">
    <div class="field"><label>2x2 Picture (replace · optional)</label>
      <?php if (!empty($r['photo_path'])): ?>
        <img src="<?=e(tenant_photo_url($r))?>" alt="2x2 photo of <?=e($r['full_name'])?>" style="width:96px;height:96px;object-fit:cover;border-radius:8px;border:2px solid var(--line);display:block;margin-bottom:8px">
      <?php endif; ?>
      <input type="file" name="photo" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" onchange="tenantPreview(this,'editPhotoPreview-<?=(int)$r['id']?>')">
      <img id="editPhotoPreview-<?=(int)$r['id']?>" alt="2x2 preview" style="display:none;width:96px;height:96px;object-fit:cover;border-radius:8px;border:2px solid var(--line);margin-top:8px">
      <small style="color:var(--muted)">Leave blank to keep the current picture.</small>
    </div>
    <div class="form-grid">
      <div class="field"><label>Role</label>
        <select name="tenant_type" required>
          <?php foreach ($TENANT_TYPES as $t): ?><option value="<?=e($t)?>" <?=$r['tenant_type']===$t?'selected':''?>><?=e($t)?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="field"><label>Complete Name</label><input name="full_name" value="<?=e($r['full_name'])?>" required maxlength="120"></div>
      <div class="field"><label>Status</label>
        <select name="profile_status" required>
          <?php foreach ($PROFILE_STATUSES as $s): ?><option value="<?=e($s)?>" <?=$r['profile_status']===$s?'selected':''?>><?=e($s)?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="field"><label>Email</label><input name="email" type="email" value="<?=e($r['email'])?>" maxlength="160"></div>
      <div class="field"><label>Phone / Cp number</label><input name="phone" value="<?=e($r['phone'])?>" maxlength="40"></div>
      <div class="field"><label>Floor</label>
        <select name="floor_level" id="editFloor-<?=(int)$r['id']?>" required onchange="tenantFilterUnits('editFloor-<?=(int)$r['id']?>','editUnit-<?=(int)$r['id']?>','')">
          <option value="">— Select floor —</option>
          <?php foreach ($FLOORS as $fl): ?><option value="<?=e($fl)?>" <?=$rowFloor===$fl?'selected':''?>><?=e($fl)?> Floor</option><?php endforeach; ?>
        </select>
      </div>
      <div class="field"><label>Unit Number</label>
        <select name="unit_number" id="editUnit-<?=(int)$r['id']?>" required>
          <option value="">— Select floor first —</option>
          <?php $renderUnitOptions($unitRooms, (string)($r['unit_number'] ?? ''), (string)$rowFloor); ?>
        </select>
        <?php if (!empty($r['bed_number'])): ?>
          <small style="color:var(--muted)">Assigned bed: <strong><?=e($r['bed_number'])?></strong>. Changing the unit moves the tenant to a vacant bed in the new room.</small>
        <?php else: ?>
          <small style="color:var(--muted)">A vacant bed will be assigned automatically when you save.</small>
        <?php endif; ?>
      </div>
      <div class="field"><label>Date start of Contract</label><input name="contract_start" type="date" value="<?=e($dateVal($r['contract_start']))?>"></div>
      <div class="field"><label>Date End of Contract</label><input name="contract_end" type="date" value="<?=e($dateVal($r['contract_end']))?>"></div>
      <div class="field" style="grid-column:1/-1"><small style="color:var(--muted)">Both dates keep the linked contract in Tenant Files / Contract Monitoring in sync. Clearing both removes it.</small></div>
    </div>
    <div class="row"><button class="btn sm">Save</button><button type="button" class="btn sm ghost" onclick="closeModal('edit-<?=(int)$r['id']?>')">Cancel</button></div>
  </form>
</div></div>
<?php endforeach; ?>

<script>
function tenantReceiptNext() {
  var form = document.getElementById('tenantCreateForm');
  ['full_name', 'email', 'phone', 'bank_reference'].forEach(function(name) {
    form.elements[name].value = form.elements[name].value.trim();
  });
  if (form.elements.reservation_id.value) form.elements.additional_reference.value = form.elements.additional_reference.value.trim();
  form.elements.contract_end.setCustomValidity(form.elements.contract_start.value && form.elements.contract_end.value && form.elements.contract_start.value > form.elements.contract_end.value ? 'Contract end date must be on or after the start date.' : '');
  if (!form.reportValidity()) return;
  if (form.elements.reservation_id.value && ['full_name', 'email', 'phone', 'bank_reference'].some(function(name) { return !form.elements[name].value; })) {
    alert('The reservation is missing required tenant details. Please contact an administrator.');
    return;
  }
  var unit = document.getElementById('createUnit');
  var rate = unit.options[unit.selectedIndex].getAttribute('data-rate');
  var reservation = document.getElementById('createReservation');
  var selected = reservation.value ? reservation.options[reservation.selectedIndex] : null;
  if (!selected && (!rate || Number(rate) <= 0)) {
    alert('Set a positive monthly rate for this room before issuing a receipt.');
    return;
  }
  var format = function(n) { return '₱' + Number(n).toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2}); };
  document.getElementById('receiptExpectedRate').value = rate || '';
  document.getElementById('receiptName').textContent = form.elements.full_name.value.trim();
  document.getElementById('receiptUnit').textContent = unit.value;
  document.getElementById('receiptBankReference').textContent = selected ? form.elements.additional_reference.value : form.elements.bank_reference.value;
  document.getElementById('receiptRegularAmounts').hidden = !!selected;
  document.getElementById('receiptReservationAmounts').hidden = !selected;
  if (selected) {
    var partial = selected.dataset.amount, balance = form.elements.additional_amount.value;
    var cents = function(value) { var parts = value.split('.'); return Number(parts[0]) * 100 + Number((parts[1] || '').padEnd(2, '0')); };
    document.getElementById('receiptPartial').textContent = format(partial);
    document.getElementById('receiptPartialDetails').textContent = 'AR-R' + selected.value.padStart(6, '0') + ' · ' + selected.dataset.date;
    document.getElementById('receiptBalance').textContent = format(balance);
    document.getElementById('receiptCombined').textContent = format((cents(partial) + cents(balance)) / 100);
    document.getElementById('receiptConfirmationLabel').textContent = 'I confirm the balance has been received and the reservation is fully paid.';
  } else {
    document.getElementById('receiptAdvance').textContent = format(rate);
    document.getElementById('receiptDeposit').textContent = format(rate);
    document.getElementById('receiptTotal').textContent = format(Number(rate) * 2);
    document.getElementById('receiptConfirmationLabel').textContent = 'I confirm that the full advance and deposit have been received.';
  }
  document.getElementById('tenantProfileStep').hidden = true;
  document.getElementById('tenantReceiptStep').hidden = false;
  document.getElementById('receiptConfirmed').disabled = false;
}
function tenantApplyReservation(clear) {
  var form = document.getElementById('tenantCreateForm');
  var select = document.getElementById('createReservation');
  var option = select.value ? select.options[select.selectedIndex] : null;
  var floor = document.getElementById('createFloor'), unit = document.getElementById('createUnit');
  form.action = option ? 'index.php?page=reservations' : 'index.php?page=tenants';
  document.getElementById('tenantCreateAction').value = option ? 'full_payment_from_tenant' : 'create';
  ['full_name', 'email', 'phone', 'bank_reference'].forEach(function(name) { form.elements[name].readOnly = !!option; });
  floor.disabled = !!option;
  unit.disabled = !!option;
  document.getElementById('reservationBalanceFields').style.display = option ? '' : 'none';
  document.getElementById('createBankReferenceLabel').textContent = option ? 'Previous payment bank reference' : 'Ref No. (bank reference, required)';
  document.getElementById('createUnitHelp').textContent = option ? 'The reservation’s existing bed will be used.' : 'Units are rooms matching the selected floor (2nd → 2xx, 3rd → 3xx, …). A vacant bed is assigned automatically.';
  ['additional_amount', 'additional_reference'].forEach(function(name) {
    form.elements[name].disabled = !option;
    form.elements[name].required = !!option;
    if (clear) form.elements[name].value = '';
  });
  if (option) {
    form.elements.full_name.value = option.dataset.name;
    form.elements.email.value = option.dataset.email;
    form.elements.phone.value = option.dataset.phone;
    form.elements.bank_reference.value = option.dataset.reference;
    floor.value = option.dataset.floor;
    tenantFilterUnits('createFloor', 'createUnit', option.dataset.unit);
    document.getElementById('reservationPartialAmount').textContent = '₱' + Number(option.dataset.amount).toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    document.getElementById('reservationPartialDate').textContent = option.dataset.date;
    document.getElementById('reservationPartialNumber').textContent = 'AR-R' + option.value.padStart(6, '0');
  } else if (clear) {
    ['full_name', 'email', 'phone', 'bank_reference'].forEach(function(name) { form.elements[name].value = ''; });
    floor.value = '';
    tenantFilterUnits('createFloor', 'createUnit', '');
  }
}
function tenantReceiptBack() {
  document.getElementById('receiptConfirmed').disabled = true;
  document.getElementById('receiptConfirmed').checked = false;
  document.getElementById('tenantReceiptStep').hidden = true;
  document.getElementById('tenantProfileStep').hidden = false;
}
function tenantPreview(input, imgId) {
  var img = document.getElementById(imgId);
  if (!img) return;
  if (input.files && input.files[0]) {
    img.src = URL.createObjectURL(input.files[0]);
    img.style.display = 'block';
  }
}
function tenantFilterUnits(floorId, unitId, keepValue) {
  var f = document.getElementById(floorId), u = document.getElementById(unitId);
  if (!f || !u) return;
  var floor = f.value, first = null, found = false;
  for (var i = 0; i < u.options.length; i++) {
    var o = u.options[i];
    if (!o.value) { o.hidden = false; continue; }
    var show = floor !== '' && o.getAttribute('data-floor') === floor;
    o.hidden = !show;
    if (show && !first) first = o;
    if (show && keepValue && o.value === keepValue) found = true;
  }
  if (keepValue && found) u.value = keepValue;
  else if (first) u.value = first.value;
  else u.value = '';
}
tenantFilterUnits('createFloor', 'createUnit', <?=json_encode($createValues['unit_number'] ?? '', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)?>);
tenantApplyReservation(false);
<?php if ($openCreate): ?>document.getElementById('mTenantCreate').classList.add('open');<?php endif; ?>
<?php foreach ($rows as $r): ?>
tenantFilterUnits('editFloor-<?=(int)$r['id']?>', 'editUnit-<?=(int)$r['id']?>', '<?=e((string)($r['unit_number'] ?? ''))?>');
<?php endforeach; ?>
</script>
