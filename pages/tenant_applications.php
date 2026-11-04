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
            $name = trim((string)($_POST['full_name'] ?? ''));
            $email = trim((string)($_POST['email'] ?? ''));
            $phone = trim((string)($_POST['phone'] ?? ''));
            $tenantType = trim((string)($_POST['tenant_type'] ?? 'Tenant/Resident'));
            $unit = trim((string)($_POST['intended_unit_number'] ?? ''));
            if ($name === '') throw new RuntimeException('Full name required.');
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Invalid email.');
            if (!in_array($tenantType, ['Tenant/Resident','Tenant/Commercial'], true)) $tenantType = 'Tenant/Resident';
            $roomId = null;
            if ($unit !== '') {
                $rst = $pdo->prepare('SELECT id FROM rooms WHERE room_number = ?');
                $rst->execute([$unit]);
                $roomId = $rst->fetchColumn() ?: null;
            }
            $ins = $pdo->prepare('INSERT INTO tenant_applications (full_name,email,phone,tenant_type,intended_unit_number,intended_room_id,submitted_by,application_status) VALUES (?,?,?,?,?,?,?,\'Pending\')');
            $ins->execute([$name, $email ?: null, $phone ?: null, $tenantType, $unit ?: null, $roomId, (int)$user['id']]);
            $aid = (int)$pdo->lastInsertId();
            audit_log((int)$user['id'], "Created tenant application #$aid", 'tenants', (string)$aid);
            flash('success', 'Application created.');
            redirect('index.php?page=tenant_applications');
        } elseif ($action === 'generate_initial_bill') {
            $aid = (int)($_POST['application_id'] ?? 0);
            if ($aid <= 0) throw new RuntimeException('Invalid application.');
            $app = $pdo->prepare('SELECT * FROM tenant_applications WHERE id = ?');
            $app->execute([$aid]);
            $a = $app->fetch();
            if (!$a) throw new RuntimeException('Application not found.');
            // Check existing initial bill
            $chk = $pdo->prepare("SELECT COUNT(*) FROM bills WHERE application_id = ? AND bill_type='Initial'");
            $chk->execute([$aid]);
            if ((int)$chk->fetchColumn() > 0) throw new RuntimeException('Initial bill already exists for this application.');
            $roomId = $a['intended_room_id'];
            $rate = 0;
            if ($roomId) {
                $r = $pdo->prepare('SELECT monthly_rate FROM rooms WHERE id = ?');
                $r->execute([(int)$roomId]);
                $rate = (float)$r->fetchColumn();
            }
            $utilities = max(0, (float)($_POST['utilities'] ?? 0));
            $deposit = max(0, (float)($_POST['deposit'] ?? 0));
            $advance = max(0, (float)($_POST['advance_rent'] ?? 0));
            $other = max(0, (float)($_POST['other_charges'] ?? 0));
            $total = round($rate + $utilities + $deposit + $advance + $other, 2);
            $ym = date('Ym');
            // generate invoice
            $inv = 'INV-INIT-' . $ym . '-' . str_pad((string)random_int(0,9999), 4, '0', STR_PAD_LEFT);
            $pdo->beginTransaction();
            $ins = $pdo->prepare("INSERT INTO bills (resident_id, room_id, billing_period, room_rental, utilities, additional_charges, discounts, penalties, total_amount, amount_paid, balance, due_date, payment_status, invoice_number, created_by, application_id, bill_type) VALUES (NULL,?,?,?,?,?,?,?,?,?,0,?,'Unpaid',?,?,?,?)");
            $ins->execute([
                $roomId, date('Y-m'), $rate, $utilities, ($deposit + $advance + $other), 0, 0, $total,
                date('Y-m-d', strtotime('+7 days')), $inv, (int)$user['id'], $aid, 'Initial'
            ]);
            $pdo->commit();
            audit_log((int)$user['id'], "Generated initial bill for application #$aid", 'billing', (string)$pdo->lastInsertId());
            flash('success', 'Initial bill generated.');
            redirect('index.php?page=tenant_applications');
        } elseif ($action === 'approve') {
            $aid = (int)($_POST['application_id'] ?? 0);
            $pdo->prepare("UPDATE tenant_applications SET application_status='Approved', approved_by=?, approved_at=NOW() WHERE id=?")
                ->execute([(int)$user['id'], $aid]);
            audit_log((int)$user['id'], "Approved application #$aid", 'tenants', (string)$aid);
            flash('success', 'Application approved.');
            redirect('index.php?page=tenant_applications');
        }
    } catch (Throwable $t) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('error', $t->getMessage());
        redirect('index.php?page=tenant_applications');
    }
}

$apps = [];
$bills = [];
try {
    $apps = $pdo->query("SELECT * FROM tenant_applications ORDER BY FIELD(application_status,'Pending','Approved','Activated','Rejected'), id DESC")->fetchAll();
    $bills = $pdo->query("SELECT b.*, ta.full_name FROM bills b LEFT JOIN tenant_applications ta ON ta.id = b.application_id WHERE b.bill_type='Initial' ORDER BY b.id DESC")->fetchAll();
} catch (Throwable $t) {
    $apps = []; $bills = [];
}
$rooms = $pdo->query("SELECT id, room_number FROM rooms ORDER BY room_number")->fetchAll();
?>
<div class="card" style="margin-bottom:14px">
  <h3>Create Pending Tenant Application</h3>
  <form method="post">
    <?=csrf_field()?><input type="hidden" name="action" value="create">
    <div class="form-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
      <div class="field"><label>Full Name</label><input name="full_name" required></div>
      <div class="field"><label>Email</label><input name="email" type="email"></div>
      <div class="field"><label>Phone</label><input name="phone"></div>
      <div class="field"><label>Tenant Type</label><select name="tenant_type"><option>Tenant/Resident</option><option>Tenant/Commercial</option></select></div>
      <div class="field"><label>Intended Unit/Room</label><input name="intended_unit_number" placeholder="e.g., 201"></div>
    </div>
    <div style="margin-top:10px"><button class="btn">Create Application</button></div>
  </form>
</div>

<div class="card" style="margin-bottom:14px">
  <h3>Applications</h3>
  <div style="overflow-x:auto">
  <table class="tbl">
    <thead><tr><th>ID</th><th>Name</th><th>Email</th><th>Phone</th><th>Type</th><th>Intended Unit</th><th>Status</th><th>Action</th></tr></thead>
    <tbody>
    <?php if (!$apps): ?><tr><td colspan="8">No applications.</td></tr><?php endif; ?>
    <?php foreach ($apps as $a): ?>
      <tr>
        <td>#<?=e((string)$a['id'])?></td>
        <td><?=e($a['full_name'])?></td>
        <td><?=e($a['email']??'')?></td>
        <td><?=e($a['phone']??'')?></td>
        <td><?=e($a['tenant_type'])?></td>
        <td><?=e($a['intended_unit_number']??'')?></td>
        <td><?=e($a['application_status'])?></td>
        <td style="white-space:nowrap">
          <?php if ($a['application_status'] === 'Pending'): ?>
            <form method="post" style="display:inline">
              <?=csrf_field()?><input type="hidden" name="action" value="approve"><input type="hidden" name="application_id" value="<?=(int)$a['id']?>">
              <button class="btn sm">Approve</button>
            </form>
            <details style="display:inline"><summary class="btn sm ghost">Generate Initial Bill</summary>
              <form method="post" style="margin-top:6px">
                <?=csrf_field()?><input type="hidden" name="action" value="generate_initial_bill"><input type="hidden" name="application_id" value="<?=(int)$a['id']?>">
                <input name="utilities" placeholder="Utilities" value="0" style="width:90px">
                <input name="deposit" placeholder="Deposit" value="0" style="width:90px">
                <input name="advance_rent" placeholder="Advance Rent" value="0" style="width:110px">
                <input name="other_charges" placeholder="Other" value="0" style="width:90px">
                <button class="btn sm">Generate</button>
              </form>
            </details>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>

<div class="card">
  <h3>Initial Bills</h3>
  <div style="overflow-x:auto">
  <table class="tbl">
    <thead><tr><th>Invoice</th><th>Applicant</th><th>Period</th><th>Total</th><th>Balance</th><th>Status</th><th>Action</th></tr></thead>
    <tbody>
    <?php if (!$bills): ?><tr><td colspan="7">No initial bills.</td></tr><?php endif; ?>
    <?php foreach ($bills as $b): ?>
      <tr>
        <td><?=e($b['invoice_number'])?></td>
        <td><?=e($b['full_name']??'')?></td>
        <td><?=e($b['billing_period'])?></td>
        <td><?=money($b['total_amount'])?></td>
        <td><?=money($b['balance'])?></td>
        <td><?=e($b['payment_status'])?></td>
        <td><a class="btn ghost sm" href="index.php?page=invoice&id=<?=(int)$b['id']?>">View</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
