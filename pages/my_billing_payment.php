<?php
// My Billing Payment (resident/tenant) - submit payment with screenshot
if (!in_array($user['role'] ?? '', ['resident'], true)) {
    http_response_code(403);
    echo '<div class="card"><h3>Forbidden</h3><p>Residents only.</p></div>';
    return;
}

$pdo = db();
$ctx = get_tenant_context($user);
$me = null;
$tenantProfile = $ctx['tenant_profile'] ?? null;
$room = null;

try {
    // Try to get resident record
    $st = $pdo->prepare('SELECT * FROM residents WHERE user_id = ? LIMIT 1');
    $st->execute([(int)$user['id']]);
    $me = $st->fetch();
    
    if ($me) {
        if ($me['room_id']) {
            $rst = $pdo->prepare('SELECT * FROM rooms WHERE id = ?');
            $rst->execute([(int)$me['room_id']]);
            $room = $rst->fetch();
        }
    }
    
    if ($tenantProfile && !$room && $tenantProfile['room_id']) {
        $rst = $pdo->prepare('SELECT * FROM rooms WHERE id = ?');
        $rst->execute([(int)$tenantProfile['room_id']]);
        $room = $rst->fetch();
    }
} catch (Throwable $t) {
    $err = $t->getMessage();
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    
    try {
        if ($action === 'submit') {
            $fullName = trim((string)($_POST['full_name'] ?? ($user['name'] ?? '')));
            $email = trim((string)($_POST['email'] ?? ($user['email'] ?? '')));
            $phone = trim((string)($_POST['phone'] ?? ''));
            $unitNumber = trim((string)($_POST['unit_number'] ?? ($tenantProfile['unit_number'] ?? $room['room_number'] ?? '')));
            $unitRoomPrice = max(0, (float)($_POST['unit_room_price'] ?? ($room['monthly_rate'] ?? 0)));
            $electricity = max(0, (float)($_POST['electricity'] ?? 0));
            $water = max(0, (float)($_POST['water'] ?? 0));
            $othersFeeType = trim((string)($_POST['others_fee_type'] ?? ''));
            $othersFeeLabel = trim((string)($_POST['others_fee_label'] ?? ''));
            $othersFeeAmount = max(0, (float)($_POST['others_fee_amount'] ?? 0));
            $paymentType = trim((string)($_POST['payment_type'] ?? 'Fully paid'));
            $amountPaid = max(0, (float)($_POST['amount_paid'] ?? 0));
            $dateOfPayment = trim((string)($_POST['date_of_payment'] ?? ''));
            
            if ($fullName === '') throw new RuntimeException('Name of Tenant is required.');
            if ($unitNumber === '') throw new RuntimeException('Unit/Room Number is required.');
            if ($dateOfPayment === '') throw new RuntimeException('Date of Payment is required.');
            if (!in_array($paymentType, ['Fully paid', 'Partial payment'], true)) throw new RuntimeException('Invalid payment type.');
            
            $d = DateTime::createFromFormat('Y-m-d', $dateOfPayment);
            if (!$d || $d->format('Y-m-d') !== $dateOfPayment) throw new RuntimeException('Invalid payment date.');
            
            if ($othersFeeType === 'Others' && $othersFeeLabel === '') {
                throw new RuntimeException('Please specify the other fee description.');
            }
            if ($othersFeeType !== '' && !in_array($othersFeeType, ['Add Device', 'Penalty', 'Others'], true)) {
                throw new RuntimeException('Invalid others fee type.');
            }
            if ($othersFeeType === '' || $othersFeeType === 'None') {
                $othersFeeType = null;
                $othersFeeLabel = null;
                $othersFeeAmount = 0;
            }
            
            $totalPrice = round($unitRoomPrice + $electricity + $water + $othersFeeAmount, 2);
            if ($totalPrice < 0) $totalPrice = 0;
            if ($amountPaid > $totalPrice + 0.001) {
                $amountPaid = $totalPrice;
            }
            
            $screenshotPath = null;
            if (!empty($_FILES['screenshot']) && (($_FILES['screenshot']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE)) {
                $err = validate_upload($_FILES['screenshot'], ['jpg', 'jpeg', 'png', 'webp'], 5);
                if ($err) throw new RuntimeException('Screenshot upload failed: ' . $err);
                $screenshotPath = safe_store_upload($_FILES['screenshot'], __DIR__ . '/../uploads/billing');
            } else {
                throw new RuntimeException('Please upload a billing screenshot.');
            }
            
            $ins = $pdo->prepare('INSERT INTO tenant_billing_payments (tenant_profile_id, resident_id, user_id, full_name, email, phone, unit_number, room_id, unit_room_price, electricity, water, others_fee_type, others_fee_label, others_fee_amount, total_price, payment_type, amount_paid, date_of_payment, screenshot_path) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $ins->execute([
                $tenantProfile ? (int)$tenantProfile['id'] : null,
                $me ? (int)$me['residents_id'] : null,
                (int)$user['id'],
                $fullName, $email ?: null, $phone ?: null, $unitNumber,
                $room ? (int)$room['id'] : (!empty($tenantProfile['room_id']) ? (int)$tenantProfile['room_id'] : null),
                $unitRoomPrice, $electricity, $water,
                $othersFeeType, $othersFeeLabel, $othersFeeAmount,
                $totalPrice, $paymentType, $amountPaid, $dateOfPayment, $screenshotPath
            ]);
            
            audit_log((int)$user['id'], 'Submitted billing payment proof', 'billing', (string)$pdo->lastInsertId());
            flash('success', 'Billing payment submitted successfully. Awaiting review.');
            redirect('index.php?page=my_billing_payment');
        }
        
        if ($action === 'request_edit') {
            $subId = (int)($_POST['submission_id'] ?? 0);
            $note = trim((string)($_POST['edit_note'] ?? ''));
            if ($subId <= 0) throw new RuntimeException('Invalid submission.');
            $chk = $pdo->prepare('SELECT id, status, user_id FROM tenant_billing_payments WHERE id = ? AND user_id = ?');
            $chk->execute([$subId, (int)$user['id']]);
            $sub = $chk->fetch();
            if (!$sub) throw new RuntimeException('Submission not found.');
            if ($sub['status'] !== 'Pending' && $sub['status'] !== 'Rejected') {
                throw new RuntimeException('You can only request edit for pending or rejected submissions.');
            }
            $pdo->prepare("UPDATE tenant_billing_payments SET status='Edit Requested', edit_request_note=?, edit_requested_by=?, edit_requested_at=NOW() WHERE id=?")
                ->execute([$note ?: null, (int)$user['id'], $subId]);
            flash('success', 'Edit request sent to Super Admin.');
            redirect('index.php?page=my_billing_payment');
        }
    } catch (Throwable $t) {
        flash('error', $t->getMessage());
        redirect('index.php?page=my_billing_payment');
    }
}

$subs = [];
try {
    $sst = $pdo->prepare('SELECT * FROM tenant_billing_payments WHERE user_id = ? ORDER BY id DESC');
    $sst->execute([(int)$user['id']]);
    $subs = $sst->fetchAll();
} catch (Throwable $t) {
    $subs = [];
}

$defaultName = $user['name'] ?? ($tenantProfile['full_name'] ?? '');
$defaultEmail = $user['email'] ?? ($tenantProfile['email'] ?? '');
$defaultPhone = $tenantProfile['phone'] ?? '';
$defaultUnit = $tenantProfile['unit_number'] ?? ($room['room_number'] ?? '');
$defaultPrice = $room['monthly_rate'] ?? 0;
?>
<style>
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}
@media(max-width:900px){.form-grid{grid-template-columns:1fr}}
.pill{display:inline-block;padding:2px 10px;border-radius:20px;font-size:12px;font-weight:600}
.pill.Pending{background:#fef3c7;color:#92400e}
.pill.Approved{background:#dcfce7;color:#166534}
.pill.Rejected{background:#fee2e2;color:#991b1b}
.pill.Edit.Requested{background:#ede9fe;color:#5b21b6}
</style>

<div class="card" style="margin-bottom:14px">
  <h3>Billing Payment</h3>
  <p style="color:#7c6f8a;margin:4px 0 0">Upload your billing payment screenshot with the details below.</p>
</div>

<div class="card" style="margin-bottom:14px">
  <h3>Submit Billing Payment</h3>
  <form method="post" enctype="multipart/form-data">
    <?=csrf_field()?>
    <input type="hidden" name="action" value="submit">
    <div class="form-grid">
      <div class="field"><label>Name of Tenant</label><input name="full_name" value="<?=e($defaultName)?>" required></div>
      <div class="field"><label>Email</label><input name="email" type="email" value="<?=e($defaultEmail)?>"></div>
      <div class="field"><label>Cp number</label><input name="phone" value="<?=e($defaultPhone)?>"></div>
      <div class="field"><label>Unit/Room Number</label><input name="unit_number" value="<?=e($defaultUnit)?>" required></div>
      <div class="field"><label>Unit/Room Price</label><input type="number" step="0.01" min="0" name="unit_room_price" value="<?=e((string)$defaultPrice)?>" required></div>
      <div class="field"><label>Electricity</label><input type="number" step="0.01" min="0" name="electricity" value="0"></div>
      <div class="field"><label>Water</label><input type="number" step="0.01" min="0" name="water" value="0"></div>
      <div class="field"><label>Others Fee - Type</label>
        <select name="others_fee_type" id="othersFeeType" onchange="toggleOthers()">
          <option value="">None</option>
          <option value="Add Device">Add Device</option>
          <option value="Penalty">Penalty</option>
          <option value="Others">Others</option>
        </select>
      </div>
      <div class="field" id="othersLabelField" style="display:none"><label>Others Fee - Description</label><input name="others_fee_label" placeholder="Type the text for others"></div>
      <div class="field" id="othersAmountField" style="display:none"><label>Others Fee Amount</label><input type="number" step="0.01" min="0" name="others_fee_amount" value="0"></div>
      <div class="field"><label>Total Price</label><input type="number" step="0.01" name="total_price" id="totalPrice" readonly style="background:#f7f3fb"></div>
      <div class="field"><label>Payment Type</label>
        <select name="payment_type" id="paymentType" onchange="calc()">
          <option value="Fully paid">Fully paid</option>
          <option value="Partial payment">Partial payment</option>
        </select>
      </div>
      <div class="field"><label>Amount Paid</label><input type="number" step="0.01" min="0" name="amount_paid" id="amountPaid" value="0" onchange="calc()"></div>
      <div class="field"><label>Date of Payment</label><input type="date" name="date_of_payment" required></div>
      <div class="field" style="grid-column:1/-1"><label>Upload Billing Screenshot</label><input type="file" name="screenshot" accept=".jpg,.jpeg,.png,.webp" required></div>
    </div>
    <div style="margin-top:10px"><button class="btn">Submit</button></div>
  </form>
</div>

<div class="card">
  <h3>My Billing Payment Submissions</h3>
  <div style="overflow-x:auto">
  <table class="tbl">
    <thead><tr><th>Ref</th><th>Unit</th><th>Total</th><th>Type</th><th>Amount Paid</th><th>Date</th><th>Status</th><th>Screenshot</th><th>Action</th></tr></thead>
    <tbody>
    <?php if (!$subs): ?><tr><td colspan="9">No submissions yet.</td></tr><?php endif; ?>
    <?php foreach ($subs as $s): ?>
      <tr>
        <td>#<?=e((string)$s['id'])?></td>
        <td><?=e($s['unit_number'])?></td>
        <td><?=money($s['total_price'])?></td>
        <td><?=e($s['payment_type'])?></td>
        <td><?=money($s['amount_paid'])?></td>
        <td><?=e($s['date_of_payment'])?></td>
        <td><span class="pill <?=e(str_replace(' ','.', $s['status']))?>"><?=e($s['status'])?></span></td>
        <td>
          <?php if (!empty($s['screenshot_path'])): ?>
            <a class="btn ghost sm" href="index.php?page=download&type=billing_proof&pid=<?=(int)$s['id']?>" target="_blank">View</a>
          <?php endif; ?>
        </td>
        <td>
          <?php if ($s['status'] === 'Pending' || $s['status'] === 'Rejected'): ?>
            <form method="post" style="display:inline">
              <?=csrf_field()?><input type="hidden" name="action" value="request_edit"><input type="hidden" name="submission_id" value="<?=(int)$s['id']?>">
              <button class="btn sm">Request for Edit</button>
            </form>
          <?php elseif ($s['status'] === 'Edit Requested'): ?>
            <small style="color:#7c6f8a">Edit requested</small>
          <?php endif; ?>
          <?php if (!empty($s['review_note'])): ?>
            <br><small style="color:#7c6f8a">Note: <?=e($s['review_note'])?></small>
          <?php endif; ?>
          <?php if (!empty($s['edit_request_note'])): ?>
            <br><small style="color:#5b21b6">Your request: <?=e($s['edit_request_note'])?></small>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>

<script>
function toggleOthers(){
  var t = document.getElementById('othersFeeType').value;
  var lf = document.getElementById('othersLabelField');
  var af = document.getElementById('othersAmountField');
  if(t === 'Others'){
    lf.style.display='block'; af.style.display='block';
  }else if(t === 'Add Device' || t === 'Penalty'){
    lf.style.display='none'; af.style.display='block';
    var inp = lf.querySelector('input'); if(inp) inp.value='';
  }else{
    lf.style.display='none'; af.style.display='none';
    var inp2 = lf.querySelector('input'); if(inp2) inp2.value='';
    var inp3 = af.querySelector('input'); if(inp3) inp3.value='0';
  }
  calc();
}
function calc(){
  var urp = parseFloat(document.querySelector('input[name="unit_room_price"]').value||0);
  var el = parseFloat(document.querySelector('input[name="electricity"]').value||0);
  var wa = parseFloat(document.querySelector('input[name="water"]').value||0);
  var ot = 0;
  var t = document.getElementById('othersFeeType').value;
  if(t && t!==''){
    ot = parseFloat(document.querySelector('input[name="others_fee_amount"]').value||0);
  }
  var total = urp+el+wa+ot;
  if(total<0) total=0;
  document.getElementById('totalPrice').value = total.toFixed(2);
  var pt = document.getElementById('paymentType').value;
  var ap = document.getElementById('amountPaid');
  if(pt === 'Fully paid'){
    ap.value = total.toFixed(2);
  }
}
document.querySelectorAll('input[name="unit_room_price"], input[name="electricity"], input[name="water"], input[name="others_fee_amount"]').forEach(function(el){
  el.addEventListener('input', calc);
});
calc();
toggleOthers();
</script>
