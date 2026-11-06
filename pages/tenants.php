<?php
// pages/tenants.php — Tenant profile registry (Tenant/Resident & Tenant/Commercial).
// Profiles carry a 2x2 picture, floor/unit assignment and contract details.
// Saving a profile with a unit automatically links it to that room (Rooms → View Details).
$pdo = db();
$err = null;

$TENANT_TYPES = ['Tenant/Resident', 'Tenant/Commercial'];
$PROFILE_STATUSES = ['Active', 'Inactive'];
$FLOORS = ['G', '2nd', '3rd', '4th', '5th', '6th'];

function tenant_floor_from_unit(string $unit): string {
    $c = strtoupper(substr(trim($unit), 0, 1));
    if ($c === 'G') return 'G';
    return ['2' => '2nd', '3' => '3rd', '4' => '4th', '5' => '5th', '6' => '6th'][$c] ?? '';
}
function tenant_profile_status_class(string $s): string {
    return $s === 'Active' ? 'ok' : 'bad';
}
function tenant_photo_url(array $row): string {
    return 'index.php?page=download&type=tenant_photo&id=' . (int)$row['id'];
}
function tenant_store_photo(?array $file, ?string $existing = null): ?string {
    if (!$file || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return $existing;
    $msg = validate_upload($file, ['jpg', 'jpeg', 'png', 'webp'], 2);
    if ($msg) throw new RuntimeException('2x2 picture upload failed: ' . $msg);
    try {
        return safe_store_upload($file, __DIR__ . '/../uploads/tenants');
    } catch (Throwable $t) {
        throw new RuntimeException('2x2 picture could not be saved. Please try again.');
    }
}
function tenant_remove_photo(?string $photo): void {
    if (!$photo) return;
    $path = __DIR__ . '/../uploads/tenants/' . basename($photo);
    if (is_file($path)) @unlink($path);
}

$clean = static function (array $src): array {
    return [
        'tenant_type' => (string)($src['tenant_type'] ?? ''),
        'full_name' => trim((string)($src['full_name'] ?? '')),
        'email' => trim((string)($src['email'] ?? '')),
        'phone' => trim((string)($src['phone'] ?? '')),
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
    foreach (['contract_start', 'contract_end'] as $k) {
        if ($d[$k] === '') continue;
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $d[$k]);
        if (!$dt || $dt->format('Y-m-d') !== $d[$k]) throw new RuntimeException('Contract dates must be valid dates.');
    }
    if (($d['contract_start'] === '') !== ($d['contract_end'] === '')) {
        throw new RuntimeException('Please enter both contract dates, or leave both blank.');
    }
    if ($d['contract_start'] !== '' && $d['contract_end'] !== '' && $d['contract_start'] > $d['contract_end']) {
        throw new RuntimeException('Contract start date must be on or before the end date.');
    }
    return $d;
};

// Unit numbers are actual rooms; the leading digit decides the floor (2xx → 2nd, etc.).
$unitRooms = [];
$unitFloors = [];
try {
    foreach ($pdo->query('SELECT id, room_number FROM rooms ORDER BY room_number')->fetchAll() as $rm) {
        $label = tenant_floor_from_unit($rm['room_number']);
        if ($label === '') continue;
        $rm['floor_label'] = $label;
        $unitRooms[] = $rm;
        $unitFloors[$rm['room_number']] = $label;
    }
} catch (Throwable $t) { $unitRooms = []; }

// ---------- POST handlers ----------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'create') {
            $d = $validate($clean($_POST));
            $chk = $pdo->prepare('SELECT id FROM rooms WHERE room_number=?');
            $chk->execute([$d['unit_number']]);
            $roomId = (int)$chk->fetchColumn();
            if (!$roomId) throw new RuntimeException('Unit number was not found in Rooms.');
            $photo = tenant_store_photo($_FILES['photo'] ?? null);
            $newPhoto = $photo !== null;
            $id = 0; $bedNo = '—'; $contractSynced = false; $account = null;
            try {
                $pdo->beginTransaction();
                $st = $pdo->prepare('INSERT INTO tenant_profiles (tenant_type, full_name, email, phone, contract_start, contract_end, unit_number, floor_level, room_id, photo_path, profile_status, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
                $st->execute([
                    $d['tenant_type'], $d['full_name'], $d['email'] ?: null, $d['phone'] ?: null,
                    $d['contract_start'] ?: null, $d['contract_end'] ?: null,
                    $d['unit_number'], $d['floor_level'], $roomId, $photo,
                    $d['profile_status'], (int)$user['id'],
                ]);
                $id = (int)$pdo->lastInsertId();
                $bedId = assign_tenant_bed($pdo, $roomId);
                $pdo->prepare('UPDATE tenant_profiles SET bed_id=? WHERE id=?')->execute([$bedId, $id]);
                $bedSt = $pdo->prepare('SELECT bed_number FROM beds WHERE id=?');
                $bedSt->execute([$bedId]);
                $bedNo = (string)$bedSt->fetchColumn();
                $contractSynced = sync_tenant_contract($pdo, $id, $roomId, $d['contract_start'], $d['contract_end'], (int)$user['id']);
                if ($d['email'] !== '') {
                    $account = tenant_provision_account($pdo, [
                        'id' => $id,
                        'full_name' => $d['full_name'],
                        'email' => $d['email'],
                        'phone' => $d['phone'],
                        'profile_status' => $d['profile_status'],
                        'room_id' => $roomId,
                    ]);
                }
                $pdo->commit();
            } catch (Throwable $t) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                if ($newPhoto) tenant_remove_photo($photo);
                throw $t;
            }
            refresh_room_status($roomId);
            audit_log((int)$user['id'], 'Created tenant profile ' . $d['full_name'] . ' (unit ' . $d['unit_number'] . ', bed ' . $bedNo . ')', 'tenants', (string)$id);
            $msg = 'Tenant profile created and added to Room ' . $d['unit_number'] . ' (bed ' . $bedNo . ').';
            $msg .= $contractSynced ? ' Contract added to Contract Monitoring.' : ' Add both contract dates to create its contract.';
            if ($account) {
                $msg .= $account['created'] ? ' Tenant portal account created.' : ' Existing tenant portal account linked.';
                $mailError = null;
                if (tenant_send_account_email([
                    'full_name' => $d['full_name'],
                    'email' => $d['email'],
                    'profile_status' => $d['profile_status'],
                    'account_status' => $account['status'],
                ], $account['password'], $mailError)) {
                    $msg .= ' Welcome email sent.';
                } else {
                    flash('error', 'Tenant profile and account were created, but the welcome email could not be sent: ' . ($mailError ?: 'Unknown mail error.'));
                }
            } elseif ($d['email'] === '') {
                $msg .= ' Add an email address to create a tenant portal account.';
            }
            flash('success', $msg);
            redirect('index.php?page=tenants');
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
            $photo = tenant_store_photo($_FILES['photo'] ?? null, $existing['photo_path']);
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
    $st = $pdo->prepare('SELECT tp.*, b.bed_number FROM tenant_profiles tp LEFT JOIN beds b ON b.id=tp.bed_id WHERE ' . implode(' AND ', $where) . ' ORDER BY tp.created_at DESC, tp.id DESC LIMIT 300');
    $st->execute($args);
    $rows = $st->fetchAll();
    $counts = $pdo->query("SELECT
        COUNT(*) AS total,
        SUM(tenant_type='Tenant/Resident') AS resident_count,
        SUM(tenant_type='Tenant/Commercial') AS commercial_count
        FROM tenant_profiles")->fetch() ?: [];
} catch (Throwable $t) {
    $rows = []; $counts = [];
    $err = $err ?? 'Could not load tenant profiles. Have you run the tenant_profiles database migration?';
}

$dateVal = static fn($v): string => $v ? substr((string)$v, 0, 10) : '';
$renderUnitOptions = static function (array $unitRooms, string $selectedUnit, string $selectedFloor): void {
    $known = array_column($unitRooms, 'room_number');
    if ($selectedUnit !== '' && !in_array($selectedUnit, $known, true)) {
        $unitRooms[] = ['id' => 0, 'room_number' => $selectedUnit, 'floor_label' => $selectedFloor];
    }
    foreach ($unitRooms as $u) {
        $sel = $selectedUnit === $u['room_number'] ? ' selected' : '';
        echo '<option value="' . e($u['room_number']) . '" data-floor="' . e($u['floor_label']) . '"' . $sel . '>' . e($u['room_number']) . '</option>';
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
  <button class="btn sm" onclick="openModal('mTenantCreate');tenantFilterUnits('createFloor','createUnit','')">+ Add Tenant Profile</button>
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
    <tr>
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
      <td><span class="pill <?=tenant_profile_status_class($r['profile_status'])?>"><?=e($r['profile_status'])?></span></td>
      <td class="no-print" style="white-space:nowrap">
        <a class="btn sm ghost" href="index.php?page=billing&q=<?=urlencode($r['full_name'])?>">Billing</a>
        <button class="btn sm ghost" onclick="openModal('edit-<?=(int)$r['id']?>');tenantFilterUnits('editFloor-<?=(int)$r['id']?>','editUnit-<?=(int)$r['id']?>','<?=e($r['unit_number'])?>')">Edit</button>
        <form method="post" style="display:inline" onsubmit="return confirmDel('Delete this tenant profile and its linked user account? Only inactive tenants can be deleted.')">
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
  <form method="post" enctype="multipart/form-data">
    <?=csrf_field()?><input type="hidden" name="action" value="create">
    <div class="field"><label>2x2 Picture (jpg / png / webp · max 2MB)</label>
      <input type="file" name="photo" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" onchange="tenantPreview(this,'createPhotoPreview')">
      <img id="createPhotoPreview" alt="2x2 preview" style="display:none;width:96px;height:96px;object-fit:cover;border-radius:8px;border:2px solid var(--line);margin-top:8px">
    </div>
    <div class="form-grid">
      <div class="field"><label>Role</label>
        <select name="tenant_type" required>
          <?php foreach ($TENANT_TYPES as $t): ?><option value="<?=e($t)?>"><?=e($t)?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="field"><label>Complete Name</label><input name="full_name" required maxlength="120" placeholder="Full name"></div>
      <div class="field"><label>Status</label>
        <select name="profile_status" required>
          <?php foreach ($PROFILE_STATUSES as $s): ?><option value="<?=e($s)?>" <?=$s==='Active'?'selected':''?>><?=e($s)?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="field"><label>Email</label><input name="email" type="email" maxlength="160" placeholder="name@example.com"></div>
      <div class="field"><label>Phone / Cp number</label><input name="phone" maxlength="40" placeholder="09XX XXX XXXX"></div>
      <div class="field"><label>Floor</label>
        <select name="floor_level" id="createFloor" required onchange="tenantFilterUnits('createFloor','createUnit','')">
          <option value="">— Select floor —</option>
          <?php foreach ($FLOORS as $fl): ?><option value="<?=e($fl)?>"><?=e($fl)?> Floor</option><?php endforeach; ?>
        </select>
      </div>
      <div class="field"><label>Unit Number</label>
        <select name="unit_number" id="createUnit" required>
          <option value="">— Select floor first —</option>
          <?php $renderUnitOptions($unitRooms, '', ''); ?>
        </select>
        <small style="color:var(--muted)">Units are rooms matching the selected floor (2nd → 2xx, 3rd → 3xx, …). A vacant bed is assigned automatically.</small>
      </div>
      <div class="field"><label>Date start of Contract</label><input name="contract_start" type="date"></div>
      <div class="field"><label>Date End of Contract</label><input name="contract_end" type="date"></div>
      <div class="field" style="grid-column:1/-1"><small style="color:var(--muted)">Enter both dates to create this tenant's contract automatically. It will appear in Contracts and Contract Monitoring.</small></div>
    </div>
    <div class="row"><button class="btn sm">Save</button><button type="button" class="btn sm ghost" onclick="closeModal('mTenantCreate')">Cancel</button></div>
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
      <div class="field" style="grid-column:1/-1"><small style="color:var(--muted)">Both dates keep the linked contract in Contracts / Contract Monitoring in sync. Clearing both removes it.</small></div>
    </div>
    <div class="row"><button class="btn sm">Save</button><button type="button" class="btn sm ghost" onclick="closeModal('edit-<?=(int)$r['id']?>')">Cancel</button></div>
  </form>
</div></div>
<?php endforeach; ?>

<script>
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
tenantFilterUnits('createFloor', 'createUnit', '');
<?php foreach ($rows as $r): ?>
tenantFilterUnits('editFloor-<?=(int)$r['id']?>', 'editUnit-<?=(int)$r['id']?>', '<?=e((string)($r['unit_number'] ?? ''))?>');
<?php endforeach; ?>
</script>
