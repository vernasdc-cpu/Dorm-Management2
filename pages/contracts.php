<?php
// pages/contracts.php — Contracts CRUD (super_admin, admin_staff). Inner content only ($user available).
$pdo = db();
$err = null;

function contract_upload_files(?array $input): array {
    if (!$input || !isset($input['name'])) return [];

    $files = [];
    if (is_array($input['name'])) {
        foreach ($input['name'] as $index => $name) {
            $file = [
                'name' => $name,
                'type' => $input['type'][$index] ?? '',
                'tmp_name' => $input['tmp_name'][$index] ?? '',
                'error' => $input['error'][$index] ?? UPLOAD_ERR_NO_FILE,
                'size' => $input['size'][$index] ?? 0,
            ];
            if ((int)$file['error'] !== UPLOAD_ERR_NO_FILE) $files[] = $file;
        }
    } elseif ((int)($input['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $files[] = $input;
    }

    if (count($files) > 3) throw new RuntimeException('You can upload a maximum of 3 contract documents at a time.');
    foreach ($files as $file) {
        $msg = validate_upload($file, ['pdf','doc','docx','jpg','jpeg','png'], 5);
        if ($msg) throw new RuntimeException('Contract upload failed for ' . basename((string)$file['name']) . ': ' . $msg);
    }
    return $files;
}

$saveContractDocuments = static function (int $contractId, array $files) use ($pdo, $user): array {
    $storedFiles = [];
    $ins = $pdo->prepare('INSERT INTO contract_documents (contract_id, file_name, file_path, file_type, file_size, uploaded_by) VALUES (?,?,?,?,?,?)');
    try {
        foreach ($files as $file) {
            $stored = safe_store_upload($file, __DIR__ . '/../uploads/contracts');
            $storedFiles[] = $stored;
            $ins->execute([
                $contractId,
                $file['name'],
                $stored,
                pathinfo((string)$file['name'], PATHINFO_EXTENSION),
                (int)$file['size'],
                (int)$user['id'],
            ]);
        }
    } catch (Throwable $t) {
        foreach ($storedFiles as $stored) {
            $path = __DIR__ . '/../uploads/contracts/' . basename($stored);
            if (is_file($path)) unlink($path);
        }
        throw $t;
    }
    return $storedFiles;
};

// ---------- POST handlers ----------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'create') {
            $residentId = (int)($_POST['resident_id'] ?? 0);
            $roomId = (int)($_POST['room_id'] ?? 0);
            $start = trim($_POST['start_date'] ?? '');
            $end = trim($_POST['end_date'] ?? '');
            $rate = (float)($_POST['monthly_rate'] ?? 0);
            $status = trim($_POST['status'] ?? 'Active');
            $terms = trim($_POST['terms'] ?? '');
            $allowedStatus = ['Draft','Pending','Active','Expiring Soon','Expired','Terminated'];
            if (!in_array($status, $allowedStatus, true)) $status = 'Active';
            if ($residentId <= 0 || $roomId <= 0 || $start === '' || $end === '') {
                throw new RuntimeException('Please complete resident, room, start and end dates.');
            }
            if ($start > $end) throw new RuntimeException('Start date must be on or before end date.');
            if ($rate < 0) throw new RuntimeException('Monthly rate must be zero or more.');
            // Verify resident active + exists
            $chk = $pdo->prepare('SELECT residents_id FROM residents WHERE residents_id=? AND status=? LIMIT 1');
            $chk->execute([$residentId, 'active']);
            if (!$chk->fetch()) throw new RuntimeException('Selected resident is not active.');
            $contractNo = trim($_POST['contract_number'] ?? '');
            if ($contractNo === '') $contractNo = 'CTR-' . date('Y') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
            $files = contract_upload_files($_FILES['documents'] ?? $_FILES['document'] ?? null);
            $storedFiles = [];
            try {
                $pdo->beginTransaction();
                $st = $pdo->prepare('INSERT INTO contracts (contract_number, resident_id, room_id, start_date, end_date, monthly_rate, status, terms, created_by) VALUES (?,?,?,?,?,?,?,?,?)');
                $st->execute([$contractNo, $residentId, $roomId, $start, $end, $rate, $status, $terms ?: null, (int)$user['id']]);
                $cid = (int)$pdo->lastInsertId();
                $storedFiles = $saveContractDocuments($cid, $files);
                $pdo->commit();
            } catch (Throwable $t) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                foreach ($storedFiles as $stored) {
                    $path = __DIR__ . '/../uploads/contracts/' . basename($stored);
                    if (is_file($path)) unlink($path);
                }
                throw $t;
            }
            audit_log((int)$user['id'], 'Created contract ' . $contractNo, 'contracts', (string)$cid);
            flash('success', 'Contract created.' . (count($files) ? ' ' . count($files) . ' document(s) uploaded.' : ''));
            redirect('index.php?page=contracts');
        }
        if ($action === 'update') {
            $id = (int)($_POST['id'] ?? 0);
            $start = trim($_POST['start_date'] ?? '');
            $end = trim($_POST['end_date'] ?? '');
            $rate = (float)($_POST['monthly_rate'] ?? 0);
            $status = trim($_POST['status'] ?? 'Active');
            $terms = trim($_POST['terms'] ?? '');
            $roomId = (int)($_POST['room_id'] ?? 0);
            $allowedStatus = ['Draft','Pending','Active','Expiring Soon','Expired','Terminated'];
            if (!in_array($status, $allowedStatus, true)) $status = 'Active';
            if ($id <= 0 || $start === '' || $end === '') throw new RuntimeException('Invalid contract update.');
            if ($start > $end) throw new RuntimeException('Start date must be on or before end date.');
            if ($rate < 0) throw new RuntimeException('Monthly rate must be zero or more.');

            $cur = $pdo->prepare('SELECT tenant_profile_id, room_id FROM contracts WHERE id=?');
            $cur->execute([$id]);
            $curC = $cur->fetch();
            if (!$curC) throw new RuntimeException('Contract not found.');
            $linkedProfileId = (int)($curC['tenant_profile_id'] ?? 0);
            // Room moves are managed from Tenant Profiles for linked contracts.
            if ($linkedProfileId > 0) $roomId = (int)$curC['room_id'];

            if ($roomId > 0) {
                $st = $pdo->prepare('UPDATE contracts SET room_id=?, start_date=?, end_date=?, monthly_rate=?, status=?, terms=? WHERE id=?');
                $st->execute([$roomId, $start, $end, $rate, $status, $terms ?: null, $id]);
            } else {
                $st = $pdo->prepare('UPDATE contracts SET start_date=?, end_date=?, monthly_rate=?, status=?, terms=? WHERE id=?');
                $st->execute([$start, $end, $rate, $status, $terms ?: null, $id]);
            }
            if ($linkedProfileId > 0) {
                $pdo->prepare('UPDATE tenant_profiles SET contract_start=?, contract_end=? WHERE id=?')
                    ->execute([$start, $end, $linkedProfileId]);
            }
            audit_log((int)$user['id'], 'Updated contract #' . $id, 'contracts', (string)$id);
            flash('success', 'Contract updated.' . ($linkedProfileId > 0 ? ' Tenant profile dates synchronized.' : ''));
            redirect('index.php?page=contracts');
        }
        if ($action === 'replace_doc') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('Invalid contract.');
            $files = contract_upload_files($_FILES['documents'] ?? $_FILES['document'] ?? null);
            if (!$files) throw new RuntimeException('Please select at least one contract document.');
            $exists = $pdo->prepare('SELECT id FROM contracts WHERE id=?');
            $exists->execute([$id]);
            if (!$exists->fetchColumn()) throw new RuntimeException('Contract not found.');
            $storedFiles = [];
            try {
                $pdo->beginTransaction();
                $storedFiles = $saveContractDocuments($id, $files);
                $pdo->commit();
            } catch (Throwable $t) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                foreach ($storedFiles as $stored) {
                    $path = __DIR__ . '/../uploads/contracts/' . basename($stored);
                    if (is_file($path)) unlink($path);
                }
                throw $t;
            }
            audit_log((int)$user['id'], 'Uploaded contract documents #' . $id, 'contracts', (string)$id);
            flash('success', count($files) . ' document(s) uploaded.');
            redirect('index.php?page=contracts');
        }
        if ($action === 'archive') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('Invalid contract.');
            $st = $pdo->prepare("UPDATE contracts SET status='Terminated' WHERE id=?");
            $st->execute([$id]);
            audit_log((int)$user['id'], 'Archived (terminated) contract #' . $id, 'contracts', (string)$id);
            flash('success', 'Contract archived (Terminated).');
            redirect('index.php?page=contracts');
        }
        if ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('Invalid contract.');
            $chk = $pdo->prepare('SELECT tenant_profile_id FROM contracts WHERE id=?');
            $chk->execute([$id]);
            $linked = $chk->fetchColumn();
            if ($linked !== false && $linked !== null && (int)$linked > 0) {
                throw new RuntimeException('This contract is managed from Tenant Profiles. Delete the tenant profile or clear both contract dates instead.');
            }
            $st = $pdo->prepare('DELETE FROM contracts WHERE id=?');
            $st->execute([$id]);
            audit_log((int)$user['id'], 'Deleted contract #' . $id, 'contracts', (string)$id);
            flash('success', 'Contract deleted.');
            redirect('index.php?page=contracts');
        }
    } catch (Throwable $t) {
        $err = $t->getMessage() ?: 'Something went wrong. Please try again.';
    }
}

// ---------- Filters ----------
$fStatus = $_GET['status'] ?? 'All';
$fSearch = trim($_GET['q'] ?? '');
$allowedF = ['All','Active','Expiring Soon','Expired','Terminated','Draft','Pending'];
if (!in_array($fStatus, $allowedF, true)) $fStatus = 'All';

try {
    $rows = $pdo->query("SELECT c.*,
        COALESCE(tp.full_name, u.name) AS tenant_name,
        COALESCE(tp.email, u.email) AS tenant_email,
        COALESCE(tp.phone, u.phone) AS tenant_phone,
        rm.room_number, rm.monthly_rate AS room_rate,
        (SELECT COUNT(*) FROM contract_documents cd WHERE cd.contract_id=c.id) AS doc_count,
        (SELECT cd.id FROM contract_documents cd WHERE cd.contract_id=c.id ORDER BY cd.id DESC LIMIT 1) AS latest_doc_id,
        (SELECT cd.file_name FROM contract_documents cd WHERE cd.contract_id=c.id ORDER BY cd.id DESC LIMIT 1) AS latest_doc_name
        FROM contracts c
        LEFT JOIN tenant_profiles tp ON tp.id=c.tenant_profile_id
        LEFT JOIN residents r ON r.residents_id=c.resident_id
        LEFT JOIN users u ON u.id=r.user_id
        JOIN rooms rm ON rm.id=c.room_id
        ORDER BY c.end_date ASC, c.id DESC")->fetchAll();
} catch (Throwable $t) { $rows = []; $err = $err ?? 'Could not load contracts.'; }

// Apply search + computed-status filter in PHP (status is computed)
$filtered = [];
foreach ($rows as $c) {
    $computed = contract_computed_status($c);
    if ($fStatus !== 'All' && $computed !== $fStatus) continue;
    if ($fSearch !== '') {
        $hay = strtolower($c['contract_number'] . ' ' . ($c['tenant_name'] ?? '') . ' ' . $c['room_number']);
        if (strpos($hay, strtolower($fSearch)) === false) continue;
    }
    $c['_computed'] = $computed;
    $c['_days'] = days_remaining(substr((string)$c['end_date'], 0, 10));
    $filtered[] = $c;
}

$documentsByContract = [];
if ($filtered) {
    $contractIds = array_map(static fn(array $contract): int => (int)$contract['id'], $filtered);
    $placeholders = implode(',', array_fill(0, count($contractIds), '?'));
    $docSt = $pdo->prepare("SELECT id, contract_id, file_name FROM contract_documents WHERE contract_id IN ($placeholders) ORDER BY id");
    $docSt->execute($contractIds);
    foreach ($docSt->fetchAll() as $doc) {
        $documentsByContract[(int)$doc['contract_id']][] = $doc;
    }
}

// Expiration warnings
$exp30 = array_values(array_filter($filtered, fn($c) => $c['_computed'] === 'Expiring Soon'));
$exp15 = array_values(array_filter($filtered, fn($c) => $c['_days'] >= 0 && $c['_days'] <= 15 && $c['_computed'] !== 'Terminated' && $c['_computed'] !== 'Expired' && $c['_computed'] !== 'Draft' && $c['_computed'] !== 'Pending'));
$exp7  = array_values(array_filter($filtered, fn($c) => $c['_days'] >= 0 && $c['_days'] <= 7 && $c['_computed'] !== 'Terminated' && $c['_computed'] !== 'Expired' && $c['_computed'] !== 'Draft' && $c['_computed'] !== 'Pending'));

// Dropdown data
try {
    $residents = $pdo->query("SELECT r.residents_id, r.room_id, u.name, u.email, rm.room_number, rm.monthly_rate FROM residents r JOIN users u ON u.id=r.user_id LEFT JOIN rooms rm ON rm.id=r.room_id WHERE r.status='active' AND u.status='active' AND u.deleted_at IS NULL ORDER BY u.name")->fetchAll();
    $rooms = $pdo->query('SELECT id, room_number, monthly_rate FROM rooms ORDER BY room_number')->fetchAll();
} catch (Throwable $t) { $residents = []; $rooms = []; }

function pill_class(string $s): string {
    return match($s) {
        'Active' => 'ok',
        'Expiring Soon' => 'warn',
        'Expired' => 'bad',
        'Terminated' => 'info',
        default => '',
    };
}
?>

<?php if ($err): ?><div class="alert error"><?=e($err)?></div><?php endif; ?>

<?php if ($exp30): ?>
<div class="card" style="margin-bottom:12px;border-left:5px solid #d97706">
  <h3>⚠ Expiration Warnings (<?=count($exp30)?> expiring within 30 days)</h3>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <?php foreach ($exp30 as $c): ?>
      <?php $sev = $c['_days'] <= 7 ? 'bad' : ($c['_days'] <= 15 ? 'warn' : 'info'); ?>
      <span class="pill <?=$sev?>"><?=e($c['tenant_name'] ?? '—')?> · Room <?=e($c['room_number'])?> · <?= (int)$c['_days'] ?>d left (<?=e($c['contract_number'])?>)</span>
    <?php endforeach; ?>
  </div>
  <p style="font-size:12px;color:#7c6f8a;margin:8px 0 0">≤7 days: critical · ≤15 days: urgent · ≤30 days: watch.</p>
</div>
<?php endif; ?>

<div class="toolbar">
  <form method="get" action="index.php" class="row" style="flex:1;flex-wrap:wrap">
    <input type="hidden" name="page" value="contracts">
    <input name="q" placeholder="Search tenant / contract no…" value="<?=e($fSearch)?>" style="min-width:220px">
    <select name="status">
      <?php foreach ($allowedF as $s): ?><option value="<?=e($s)?>" <?=$fStatus===$s?'selected':''?>><?=e($s)?></option><?php endforeach; ?>
    </select>
    <button class="btn sm ghost" type="submit">Filter</button>
    <a class="btn sm ghost" href="index.php?page=contracts">Reset</a>
  </form>
  <button class="btn sm" onclick="openModal('mCreate')">+ New Contract</button>
</div>

<div class="card" style="padding:0;overflow:auto">
<table class="tbl">
  <thead><tr><th>Contract No</th><th>Tenant</th><th>Room</th><th>Start</th><th>End</th><th>Days Left</th><th>Status</th><th>Doc</th><th class="no-print">Actions</th></tr></thead>
  <tbody>
  <?php if (!$filtered): ?><tr><td colspan="9">No contracts found.</td></tr><?php endif; ?>
  <?php foreach ($filtered as $c): ?>
    <tr>
      <td><strong><?=e($c['contract_number'])?></strong></td>
      <td><?=e($c['tenant_name'] ?? '—')?><?php if (!empty($c['tenant_profile_id'])): ?><br><small style="color:#7c6f8a">Tenant profile</small><?php endif; ?></td>
      <td><?=e($c['room_number'])?></td>
      <td><?=e(substr((string)$c['start_date'],0,10))?></td>
      <td><?=e(substr((string)$c['end_date'],0,10))?></td>
      <td><?= (int)$c['_days'] >= 0 ? (int)$c['_days'] . ' days' : '<span style="color:#dc2626">' . (int)$c['_days'] . ' (overdue)</span>' ?></td>
      <td><span class="pill <?=pill_class($c['_computed'])?>"><?=e($c['_computed'])?></span></td>
      <td><?= ((int)$c['doc_count'] > 0) ? '📎 ' . (int)$c['doc_count'] : '<span style="color:#999">—</span>' ?></td>
      <td class="no-print" style="white-space:nowrap">
        <button class="btn sm ghost" onclick="openModal('view-<?=(int)$c['id']?>')">View</button>
        <button class="btn sm ghost" onclick="openModal('edit-<?=(int)$c['id']?>')">Edit</button>
        <?php if ($c['latest_doc_id']): ?>
          <a class="btn sm ghost" href="index.php?page=download&amp;type=contract&amp;id=<?=(int)$c['latest_doc_id']?>">Download</a>
        <?php endif; ?>
        <button class="btn sm ghost" onclick="openModal('doc-<?=(int)$c['id']?>')">Add documents</button>
        <?php if ($c['_computed'] !== 'Terminated'): ?>
        <form method="post" style="display:inline" onsubmit="return confirm('Archive this contract (set Terminated)?')">
          <?=csrf_field()?><input type="hidden" name="action" value="archive"><input type="hidden" name="id" value="<?=(int)$c['id']?>">
          <button class="btn sm ghost">Archive</button>
        </form>
        <?php endif; ?>
        <?php if (empty($c['tenant_profile_id'])): ?>
        <form method="post" style="display:inline" onsubmit="return confirm('Delete this contract permanently?')">
          <?=csrf_field()?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=(int)$c['id']?>">
          <button class="btn sm danger">Delete</button>
        </form>
        <?php else: ?>
          <span class="pill info" title="Managed from Tenant Profiles">Tenant profile</span>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<!-- Create modal -->
<div class="modal" id="mCreate"><div class="box">
  <h3>New Contract</h3>
  <p style="font-size:13px;color:#7c6f8a">Contracts for tenant profiles are created automatically from <strong>Tenant Profiles</strong>. Use this form for portal residents or manually managed contracts.</p>
  <form method="post" enctype="multipart/form-data">
    <?=csrf_field()?><input type="hidden" name="action" value="create">
    <div class="form-grid">
      <div class="field"><label>Resident (active portal account)</label>
        <select name="resident_id" id="crtResident" required>
          <option value="">— Select —</option>
          <?php foreach ($residents as $r): ?>
            <option value="<?=(int)$r['residents_id']?>" data-room="<?=(int)($r['room_id'] ?? 0)?>" data-rate="<?=e((string)($r['monthly_rate'] ?? 0))?>"><?=e($r['name'])?> (Room <?=e($r['room_number'] ?? '—')?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field"><label>Room</label>
        <select name="room_id" id="crtRoom" required>
          <option value="">— Select —</option>
          <?php foreach ($rooms as $rm): ?><option value="<?=(int)$rm['id']?>" data-rate="<?=e((string)$rm['monthly_rate'])?>"><?=e($rm['room_number'])?> — ₱<?=e(number_format((float)$rm['monthly_rate'],2))?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="field"><label>Start date</label><input type="date" name="start_date" required></div>
      <div class="field"><label>End date</label><input type="date" name="end_date" required></div>
      <div class="field"><label>Monthly rate (₱)</label><input type="number" step="0.01" min="0" name="monthly_rate" id="crtRate" required></div>
      <div class="field"><label>Status</label>
        <select name="status"><option>Active</option><option>Pending</option><option>Draft</option><option>Expired</option><option>Terminated</option></select>
      </div>
    </div>
    <div class="field"><label>Terms</label><textarea name="terms" rows="3" placeholder="Contract terms…"></textarea></div>
    <div class="field"><label>Documents (optional · select up to 3 files, each ≤5MB)</label><input type="file" name="documents[]" multiple accept=".pdf,.doc,.docx,.jpg,.jpeg,.png"></div>
    <div class="row"><button class="btn sm">Save</button><button type="button" class="btn sm ghost" onclick="closeModal('mCreate')">Cancel</button></div>
  </form>
</div></div>

<?php foreach ($filtered as $c): ?>
<!-- View modal -->
<div class="modal" id="view-<?=(int)$c['id']?>"><div class="box">
  <h3>Contract <?=e($c['contract_number'])?></h3>
  <p><strong>Tenant:</strong> <?=e($c['tenant_name'] ?? '—')?><?php if (!empty($c['tenant_email'])): ?> &lt;<?=e($c['tenant_email'])?>&gt;<?php endif; ?><br>
  <strong>Room:</strong> <?=e($c['room_number'])?><br>
  <strong>Period:</strong> <?=e(substr((string)$c['start_date'],0,10))?> → <?=e(substr((string)$c['end_date'],0,10))?> (<?= (int)$c['_days'] ?> days left)<br>
  <strong>Rate:</strong> ₱<?=e(number_format((float)$c['monthly_rate'],2))?><br>
  <strong>Status:</strong> <span class="pill <?=pill_class($c['_computed'])?>"><?=e($c['_computed'])?></span></p>
  <p><strong>Terms:</strong><br><?=nl2br(e($c['terms'] ?? '—'))?></p>
  <p><strong>Documents:</strong></p>
  <?php $contractDocs = $documentsByContract[(int)$c['id']] ?? []; ?>
  <?php if ($contractDocs): ?>
    <ul>
      <?php foreach ($contractDocs as $doc): ?>
        <li><a href="index.php?page=download&amp;type=contract&amp;id=<?=(int)$doc['id']?>"><?=e($doc['file_name'])?></a></li>
      <?php endforeach; ?>
    </ul>
  <?php else: ?>
    <p>—</p>
  <?php endif; ?>
  <button class="btn sm ghost" onclick="closeModal('view-<?=(int)$c['id']?>')">Close</button>
</div></div>
<!-- Edit modal -->
<div class="modal" id="edit-<?=(int)$c['id']?>"><div class="box">
  <h3>Edit <?=e($c['contract_number'])?></h3>
  <form method="post">
    <?=csrf_field()?><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="<?=(int)$c['id']?>">
    <div class="form-grid">
      <div class="field"><label>Room</label>
        <?php if (!empty($c['tenant_profile_id'])): ?>
          <input value="<?=e($c['room_number'])?>" disabled>
          <input type="hidden" name="room_id" value="<?=(int)$c['room_id']?>">
          <small style="color:#7c6f8a">Room changes are managed from Tenant Profiles.</small>
        <?php else: ?>
          <select name="room_id">
            <?php foreach ($rooms as $rm): ?><option value="<?=(int)$rm['id']?>" <?=(int)$rm['id']===(int)$c['room_id']?'selected':''?>><?=e($rm['room_number'])?></option><?php endforeach; ?>
          </select>
        <?php endif; ?>
      </div>
      <div class="field"><label>Status</label>
        <select name="status"><?php foreach (['Draft','Pending','Active','Expiring Soon','Expired','Terminated'] as $s): ?><option <?=$c['status']===$s?'selected':''?>><?=e($s)?></option><?php endforeach; ?></select>
      </div>
      <div class="field"><label>Start date</label><input type="date" name="start_date" value="<?=e(substr((string)$c['start_date'],0,10))?>" required></div>
      <div class="field"><label>End date</label><input type="date" name="end_date" value="<?=e(substr((string)$c['end_date'],0,10))?>" required></div>
    </div>
    <div class="field"><label>Monthly rate (₱)</label><input type="number" step="0.01" min="0" name="monthly_rate" value="<?=e((string)$c['monthly_rate'])?>" required></div>
    <div class="field"><label>Terms</label><textarea name="terms" rows="3"><?=e($c['terms'] ?? '')?></textarea></div>
    <div class="row"><button class="btn sm">Save</button><button type="button" class="btn sm ghost" onclick="closeModal('edit-<?=(int)$c['id']?>')">Cancel</button></div>
  </form>
</div></div>
<!-- Add documents modal -->
<div class="modal" id="doc-<?=(int)$c['id']?>"><div class="box">
  <h3>Add documents — <?=e($c['contract_number'])?></h3>
  <p style="font-size:13px;color:#7c6f8a">Select up to 3 files. Existing documents will be kept.</p>
  <form method="post" enctype="multipart/form-data">
    <?=csrf_field()?><input type="hidden" name="action" value="replace_doc"><input type="hidden" name="id" value="<?=(int)$c['id']?>">
    <div class="field"><label>Files (pdf/doc/docx/jpg/png, each ≤5MB)</label><input type="file" name="documents[]" required multiple accept=".pdf,.doc,.docx,.jpg,.jpeg,.png"></div>
    <div class="row"><button class="btn sm">Upload</button><button type="button" class="btn sm ghost" onclick="closeModal('doc-<?=(int)$c['id']?>')">Cancel</button></div>
  </form>
</div></div>
<?php endforeach; ?>

<script>
function openModal(id){document.getElementById(id)?.classList.add('open');}
function closeModal(id){document.getElementById(id)?.classList.remove('open');}
document.querySelectorAll('.modal').forEach(m=>m.addEventListener('click',e=>{if(e.target===m)m.classList.remove('open');}));
const crtRes=document.getElementById('crtResident'),crtRoom=document.getElementById('crtRoom'),crtRate=document.getElementById('crtRate');
crtRes?.addEventListener('change',()=>{
  const o=crtRes.selectedOptions[0]; if(!o||!o.value)return;
  if(o.dataset.room){crtRoom.value=o.dataset.room;}
  if(o.dataset.rate){crtRate.value=o.dataset.rate;}
});
crtRoom?.addEventListener('change',()=>{
  const o=crtRoom.selectedOptions[0];
  if(o&&o.dataset.rate&&!crtRate.value)crtRate.value=o.dataset.rate;
});
</script>
