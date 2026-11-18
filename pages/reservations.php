<?php
require_once __DIR__ . '/../includes/tenant_profile_helpers.php';
$pdo = db();
$error = null;
$values = [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_check();
    try {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'create') {
            foreach (['full_name', 'email', 'phone', 'bank_reference', 'floor_id', 'room_id', 'amount', 'creation_token'] as $field) {
                if (!isset($_POST[$field]) || !is_string($_POST[$field])) throw new RuntimeException('Please complete all reservation fields.');
                $values[$field] = trim($_POST[$field]);
            }
            if ($values['full_name'] === '' || mb_strlen($values['full_name']) > 120) throw new RuntimeException('Enter a complete name of at most 120 characters.');
            if (!filter_var($values['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($values['email']) > 160) throw new RuntimeException('Enter a valid email address.');
            if ($values['phone'] === '' || mb_strlen($values['phone']) > 40) throw new RuntimeException('Enter a contact number of at most 40 characters.');
            if ($values['bank_reference'] === '' || mb_strlen($values['bank_reference']) > 100) throw new RuntimeException('Enter a bank reference number of at most 100 characters.');
            if (!ctype_digit($values['floor_id']) || !ctype_digit($values['room_id']) || (int)$values['floor_id'] <= 0 || (int)$values['room_id'] <= 0) throw new RuntimeException('Select a floor and unit.');
            if (!preg_match('/\A\d{1,9}(?:\.\d{1,2})?\z/', $values['amount']) || (float)$values['amount'] <= 0) throw new RuntimeException('Enter a payment amount greater than zero, with at most two decimal places.');
            if (($_POST['payment_confirmed'] ?? '') !== '1') throw new RuntimeException('Confirm that the reservation payment was received.');
            if (!preg_match('/\A[a-f0-9]{64}\z/', $values['creation_token'])) throw new RuntimeException('Invalid reservation submission. Please try again.');
            $find = $pdo->prepare('SELECT id FROM reservations WHERE creation_token=?');
            $find->execute([$values['creation_token']]);
            if ($existing = $find->fetchColumn()) {
                flash('success', 'This reservation and receipt were already saved.');
                redirect('index.php?page=acknowledgment_receipt&type=reservation&id=' . (int)$existing);
            }
            try {
                $pdo->beginTransaction();
                $st = $pdo->prepare('SELECT r.id, r.room_number, r.status, f.name AS floor_name FROM rooms r JOIN floors f ON f.id=r.floor_id WHERE r.id=? AND r.floor_id=? FOR UPDATE');
                $st->execute([(int)$values['room_id'], (int)$values['floor_id']]);
                $room = $st->fetch();
                if (!$room) throw new RuntimeException('The selected unit does not belong to the selected floor.');
                if (in_array($room['status'], ['Reserved', 'Under Maintenance', 'Unavailable'], true)) throw new RuntimeException('This unit is not available for reservation.');
                ensure_room_beds($pdo, (int)$room['id']);
                $bedSt = $pdo->prepare("SELECT id, bed_number FROM beds WHERE room_id=? AND status='vacant' AND resident_id IS NULL ORDER BY CAST(SUBSTRING(bed_number,2) AS UNSIGNED), id LIMIT 1 FOR UPDATE");
                $bedSt->execute([(int)$room['id']]);
                $bed = $bedSt->fetch();
                if (!$bed) throw new RuntimeException('No vacant bed is available in this unit.');
                $take = $pdo->prepare("UPDATE beds SET status='reserved' WHERE id=? AND status='vacant' AND resident_id IS NULL");
                $take->execute([(int)$bed['id']]);
                if ($take->rowCount() !== 1) throw new RuntimeException('That bed was just taken. Please try again.');
                $ins = $pdo->prepare('INSERT INTO reservations (creation_token, full_name, email, phone, bank_reference, room_id, unit_number, floor_name, bed_id, bed_number, amount, address, received_by_name, received_by, received_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())');
                $ins->execute([$values['creation_token'], $values['full_name'], $values['email'], $values['phone'], $values['bank_reference'], (int)$room['id'], $room['room_number'], $room['floor_name'], (int)$bed['id'], $bed['bed_number'], $values['amount'], '7461 Heart Bldg., Brgy. San Antonio, Makati City', $user['name'], (int)$user['id']]);
                $id = (int)$pdo->lastInsertId();
                $pdo->commit();
            } catch (Throwable $t) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                if ($t instanceof PDOException && (int)($t->errorInfo[1] ?? 0) === 1062) {
                    $find->execute([$values['creation_token']]);
                    if ($existing = $find->fetchColumn()) {
                        flash('success', 'This reservation and receipt were already saved.');
                        redirect('index.php?page=acknowledgment_receipt&type=reservation&id=' . (int)$existing);
                    }
                }
                throw $t;
            }
            audit_log((int)$user['id'], 'Created reservation for ' . $values['full_name'], 'reservations', (string)$id);
            flash('success', 'Reservation saved and bed ' . $bed['bed_number'] . ' reserved.');
            redirect('index.php?page=acknowledgment_receipt&type=reservation&id=' . $id);
        }
        if (in_array($action, ['partial_payment', 'full_payment', 'full_payment_from_tenant', 'email_receipt'], true)) {
            $fromTenant = $action === 'full_payment_from_tenant';
            if ($fromTenant && (!is_string($_POST['reservation_id'] ?? null) || !preg_match('/\A[1-9][0-9]{0,9}\z/', $_POST['reservation_id']))) throw new RuntimeException('Invalid reservation selection.');
            $id = (int)($fromTenant ? ($_POST['reservation_id'] ?? 0) : ($_POST['id'] ?? 0));
            if ($id <= 0) throw new RuntimeException('Reservation not found.');
            $account = null;
            $additionalId = 0;
            $mailReceipt = null;
            $photo = null;
            try {
                $pdo->beginTransaction();
                $st = $pdo->prepare('SELECT * FROM reservations WHERE id=? FOR UPDATE');
                $st->execute([$id]);
                $reservation = $st->fetch();
                if (!$reservation) throw new RuntimeException('Reservation not found.');
                if ($action === 'partial_payment') {
                    if ($reservation['status'] !== 'Reserved') throw new RuntimeException('Only an active reservation can be marked partial.');
                    $pdo->prepare("UPDATE reservations SET payment_classification='Partial' WHERE id=?")->execute([$id]);
                    $reservation['payment_classification'] = 'Partial';
                } elseif ($action === 'full_payment' || $fromTenant) {
                    if ($reservation['status'] !== 'Reserved' || !$reservation['bed_id']) throw new RuntimeException('This reservation is no longer available for conversion.');
                    $hasPartial = $reservation['payment_classification'] === 'Partial';
                    if ($fromTenant && !$hasPartial) throw new RuntimeException('Only active partial-payment reservations can be selected.');
                    if ($hasPartial) {
                        $amount = is_string($_POST['additional_amount'] ?? null) ? trim($_POST['additional_amount']) : '';
                        $reference = is_string($_POST['additional_reference'] ?? null) ? trim($_POST['additional_reference']) : '';
                        if (!preg_match('/\A\d{1,9}(?:\.\d{1,2})?\z/', $amount) || (float)$amount <= 0) throw new RuntimeException('Enter an additional payment greater than zero, with at most two decimal places.');
                        if ($reference === '' || mb_strlen($reference) > 100) throw new RuntimeException('Enter an additional payment bank reference number of at most 100 characters.');
                    }
                    $profileType = 'Tenant/Resident';
                    $profileStatus = 'Active';
                    $start = '';
                    $end = '';
                    if ($fromTenant) {
                        if (($_POST['payment_confirmed'] ?? '') !== '1') throw new RuntimeException('Confirm that the balance has been received.');
                        foreach (['tenant_type', 'profile_status', 'contract_start', 'contract_end'] as $field) {
                            if (!is_string($_POST[$field] ?? null)) throw new RuntimeException('Please complete the tenant profile fields.');
                        }
                        $profileType = $_POST['tenant_type'];
                        $profileStatus = $_POST['profile_status'];
                        $start = trim($_POST['contract_start']);
                        $end = trim($_POST['contract_end']);
                        if (!in_array($profileType, ['Tenant/Resident', 'Tenant/Commercial'], true)) throw new RuntimeException('Please choose a valid role.');
                        if (!in_array($profileStatus, ['Active', 'Inactive'], true)) throw new RuntimeException('Please choose a valid tenant status.');
                        if (trim((string)$reservation['full_name']) === '' || !filter_var($reservation['email'], FILTER_VALIDATE_EMAIL) || trim((string)$reservation['bank_reference']) === '') throw new RuntimeException('The reservation is missing required tenant details. Please contact an administrator.');
                        tenant_validate_new_profile_details((string)$reservation['phone'], $start, $end, $_FILES['photo'] ?? null);
                    }
                    $roomSt = $pdo->prepare('SELECT room_number FROM rooms WHERE id=? FOR UPDATE');
                    $roomSt->execute([(int)$reservation['room_id']]);
                    $roomNumber = $roomSt->fetchColumn();
                    if ($roomNumber === false) throw new RuntimeException('The reserved unit no longer exists.');
                    if ($fromTenant && (string)$roomNumber !== (string)$reservation['unit_number']) throw new RuntimeException('The reserved unit has changed. Please resolve the reservation before converting it.');
                    $floor = tenant_floor_from_unit((string)$roomNumber);
                    if ($floor === '') throw new RuntimeException('This unit cannot be converted to a tenant profile because its floor is unsupported.');
                    $bedSt = $pdo->prepare('SELECT status, resident_id FROM beds WHERE id=? AND room_id=? FOR UPDATE');
                    $bedSt->execute([(int)$reservation['bed_id'], (int)$reservation['room_id']]);
                    $bed = $bedSt->fetch();
                    if (!$bed || $bed['status'] !== 'reserved' || $bed['resident_id'] !== null) throw new RuntimeException('The reserved bed has changed. Please contact an administrator.');
                    $accountSt = $pdo->prepare("SELECT u.id, res.room_id, res.bed_no FROM users u LEFT JOIN residents res ON res.user_id=u.id WHERE LOWER(u.email)=LOWER(?) AND u.deleted_at IS NULL FOR UPDATE");
                    $accountSt->execute([$reservation['email']]);
                    $existingAccount = $accountSt->fetch();
                    if ($existingAccount) {
                        $allocSt = $pdo->prepare("SELECT COUNT(*) FROM room_allocations a JOIN residents r ON r.residents_id=a.resident_id WHERE r.user_id=? AND a.status IN ('active','reserved')");
                        $allocSt->execute([(int)$existingAccount['id']]);
                        if ($existingAccount['room_id'] !== null || $existingAccount['bed_no'] !== null || (int)$allocSt->fetchColumn() > 0) throw new RuntimeException('This account already has a room or allocation. Resolve it before converting the reservation.');
                    }
                    $ins = $pdo->prepare('INSERT INTO tenant_profiles (tenant_type, full_name, email, phone, unit_number, floor_level, room_id, bed_id, profile_status, created_by, contract_start, contract_end, photo_path) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
                    $ins->execute([$profileType, $reservation['full_name'], $reservation['email'], $reservation['phone'], $roomNumber, $floor, (int)$reservation['room_id'], (int)$reservation['bed_id'], $profileStatus, (int)$user['id'], $start ?: null, $end ?: null, $photo]);
                    $profileId = (int)$pdo->lastInsertId();
                    tenant_file_folder($pdo, $profileId);
                    if ($fromTenant) {
                        $photo = tenant_store_photo($_FILES['photo'] ?? null, null, $profileId);
                        $pdo->prepare('UPDATE tenant_profiles SET photo_path=? WHERE id=?')->execute([$photo, $profileId]);
                    }
                    if ($fromTenant) sync_tenant_contract($pdo, $profileId, (int)$reservation['room_id'], $start, $end, (int)$user['id']);
                    $account = tenant_provision_account($pdo, [
                        'id' => $profileId, 'full_name' => $reservation['full_name'], 'email' => $reservation['email'],
                        'phone' => $reservation['phone'], 'profile_status' => $profileStatus, 'room_id' => (int)$reservation['room_id'],
                    ]);
                    $pdo->prepare('UPDATE residents SET room_id=?, bed_no=? WHERE user_id=?')->execute([(int)$reservation['room_id'], $reservation['bed_number'], (int)$account['user_id']]);
                    $pdo->prepare("UPDATE beds SET status='occupied' WHERE id=?")->execute([(int)$reservation['bed_id']]);
                    if ($hasPartial) {
                        $payment = $pdo->prepare('INSERT INTO reservation_additional_payments (reservation_id, amount, bank_reference, received_by_name, received_by, received_at, is_full_summary) VALUES (?,?,?,?,?,NOW(),1)');
                        $payment->execute([$id, $amount, $reference, $user['name'], (int)$user['id']]);
                        $additionalId = (int)$pdo->lastInsertId();
                        $additionalSt = $pdo->prepare('SELECT * FROM reservation_additional_payments WHERE id=?');
                        $additionalSt->execute([$additionalId]);
                        $additional = $additionalSt->fetch();
                        $mailReceipt = reservation_full_receipt_data($reservation, $additional);
                        $mailReceipt['status'] = 'Converted';
                    }
                    $pdo->prepare("UPDATE reservations SET status='Converted', payment_classification=?, tenant_profile_id=?, bed_id=NULL WHERE id=?")->execute([$hasPartial ? 'Partial' : 'Full', $profileId, $id]);
                    $reservation['status'] = 'Converted';
                    $reservation['payment_classification'] = $hasPartial ? 'Partial' : 'Full';
                } elseif ($reservation['payment_classification'] === null) {
                    throw new RuntimeException('Select partial or full payment before emailing this receipt.');
                } elseif (!empty($_POST['payment_id'])) {
                    $paymentId = (int)$_POST['payment_id'];
                    $additionalSt = $pdo->prepare('SELECT * FROM reservation_additional_payments WHERE id=? AND reservation_id=?');
                    $additionalSt->execute([$paymentId, $id]);
                    $additional = $additionalSt->fetch();
                    if (!$additional) throw new RuntimeException('Additional payment receipt not found.');
                    $additionalId = $paymentId;
                    $mailReceipt = reservation_full_receipt_data($reservation, $additional);
                }
                $pdo->commit();
            } catch (Throwable $t) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                if ($photo !== null) tenant_remove_photo($photo);
                throw $t;
            }
            if ($account) {
                refresh_room_status((int)$reservation['room_id']);
                audit_log((int)$user['id'], 'Converted reservation to tenant profile #' . $profileId, 'reservations', (string)$id);
                $accountError = null;
                if (!tenant_send_account_email([
                    'full_name' => $reservation['full_name'], 'email' => $account['email'],
                    'profile_status' => $profileStatus, 'account_status' => $account['status'],
                ], $account['password'], $accountError)) {
                    flash('error', 'Tenant profile and account were saved, but the welcome email failed: ' . ($accountError ?: 'Unknown mail error.') . ' Contact the tenant about account access.');
                }
            } elseif ($action === 'partial_payment') {
                audit_log((int)$user['id'], 'Marked reservation payment partial', 'reservations', (string)$id);
            }
            $mailError = null;
            if (reservation_send_receipt_email($mailReceipt ?? $reservation, $additionalId > 0, $mailError)) {
                audit_log((int)$user['id'], 'Emailed reservation acknowledgment receipt', 'reservations', (string)$id);
                flash('success', ($account ? 'Tenant profile and account saved. ' : '') . 'Receipt emailed to ' . $reservation['email'] . '.');
            } else {
                $message = ($account ? 'Tenant profile and account were saved, but the receipt' : 'The receipt') . ' email failed: ' . ($mailError ?: 'Unknown mail error.') . ' Use the receipt email button to retry.';
                $previousError = flash('error');
                flash('error', ($previousError ? $previousError . ' ' : '') . $message);
            }
            redirect('index.php?page=acknowledgment_receipt&type=' . ($additionalId ? 'reservation_payment&id=' . $additionalId : 'reservation&id=' . $id));
        }
        if ($action === 'cancel') {
            $id = (int)($_POST['id'] ?? 0);
            try {
                $pdo->beginTransaction();
                $st = $pdo->prepare('SELECT id, bed_id, status FROM reservations WHERE id=? FOR UPDATE');
                $st->execute([$id]);
                $reservation = $st->fetch();
                if (!$reservation || $reservation['status'] !== 'Reserved' || !$reservation['bed_id']) throw new RuntimeException('Active reservation not found.');
                $release = $pdo->prepare("UPDATE beds SET status='vacant' WHERE id=? AND status='reserved' AND resident_id IS NULL");
                $release->execute([(int)$reservation['bed_id']]);
                if ($release->rowCount() !== 1) throw new RuntimeException('The reserved bed has changed. Please contact an administrator.');
                $pdo->prepare("UPDATE reservations SET status='Cancelled', bed_id=NULL, cancelled_at=NOW() WHERE id=?")->execute([$id]);
                $pdo->commit();
            } catch (Throwable $t) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $t;
            }
            audit_log((int)$user['id'], 'Cancelled reservation and released bed', 'reservations', (string)$id);
            flash('success', 'Reservation cancelled and bed released. No refund was recorded.');
            redirect('index.php?page=reservations');
        }
        throw new RuntimeException('Invalid reservation action.');
    } catch (PDOException $t) {
        error_log('Reservation database operation failed: ' . $t->getMessage());
        $error = 'Could not save the reservation. Please check the database and try again.';
    } catch (RuntimeException $t) {
        $error = $t->getMessage();
    } catch (Throwable $t) {
        error_log('Reservation operation failed: ' . $t->getMessage());
        $error = 'Could not save the reservation. Please try again.';
    }
    if ($error && $action === 'full_payment_from_tenant') {
        $formValues = [];
        foreach (['reservation_id', 'tenant_type', 'profile_status', 'contract_start', 'contract_end', 'additional_amount', 'additional_reference'] as $field) {
            $formValues[$field] = is_string($_POST[$field] ?? null) ? $_POST[$field] : '';
        }
        $_SESSION['tenant_reservation_error'] = ['values' => $formValues, 'message' => $error];
        redirect('index.php?page=tenants');
    }
    if ($error && in_array($action, ['partial_payment', 'full_payment', 'email_receipt'], true)) {
        flash('error', $error);
        redirect('index.php?page=acknowledgment_receipt&type=reservation&id=' . (int)($_POST['id'] ?? 0));
    }
}

$rooms = []; $rows = [];
try {
    $rooms = $pdo->query("SELECT r.id, r.room_number, r.floor_id, f.name AS floor_name FROM rooms r JOIN floors f ON f.id=r.floor_id WHERE r.status NOT IN ('Reserved','Under Maintenance','Unavailable') ORDER BY f.name, r.room_number")->fetchAll();
    $rows = $pdo->query('SELECT r.*, p.id AS additional_payment_id FROM reservations r LEFT JOIN reservation_additional_payments p ON p.reservation_id=r.id ORDER BY r.id DESC LIMIT 300')->fetchAll();
} catch (Throwable $t) {
    error_log('Could not load reservations: ' . $t->getMessage());
    $error = $error ?? 'Could not load reservations. Please check that database migrations have been run.';
}
$floors = [];
foreach ($rooms as $room) $floors[$room['floor_id']] = $room['floor_name'];
$token = isset($values['creation_token']) && preg_match('/\A[a-f0-9]{64}\z/', $values['creation_token']) ? $values['creation_token'] : bin2hex(random_bytes(32));
?>
<?php if ($error): ?><div class="alert error"><?=e($error)?></div><?php endif; ?>
<div class="toolbar"><button class="btn sm" onclick="openModal('reservationCreate')">+ Add Reservation</button></div>
<div class="card" style="padding:0;overflow:auto"><table class="tbl">
  <thead><tr><th>Name</th><th>Email</th><th>Contact</th><th>Bank Ref No.</th><th>Floor</th><th>Unit</th><th>Bed</th><th>Amount</th><th>Status</th><th>Actions</th></tr></thead>
  <tbody>
  <?php if (!$rows): ?><tr><td colspan="10">No reservations found.</td></tr><?php endif; ?>
  <?php foreach ($rows as $row): ?>
    <tr><td><?=e($row['full_name'])?></td><td><?=e($row['email'])?></td><td><?=e($row['phone'])?></td><td><?=e($row['bank_reference'])?></td><td><?=e($row['floor_name'])?></td><td><?=e($row['unit_number'])?></td><td><?=e($row['bed_number'])?></td><td><?=money($row['amount'])?></td><td><?=e($row['status'])?></td>
    <td><a class="btn sm ghost" href="index.php?page=acknowledgment_receipt&amp;type=reservation&amp;id=<?=(int)$row['id']?>">Receipt</a>
    <?php if (!empty($row['additional_payment_id'])): ?><a class="btn sm ghost" href="index.php?page=acknowledgment_receipt&amp;type=reservation_payment&amp;id=<?=(int)$row['additional_payment_id']?>">Completion Receipt</a><?php endif; ?>
    <?php if ($row['status'] === 'Reserved'): ?><form method="post" style="display:inline" onsubmit="return confirm('Cancel this reservation and release its bed? This does not record a refund.')"><?=csrf_field()?><input type="hidden" name="action" value="cancel"><input type="hidden" name="id" value="<?=(int)$row['id']?>"><button class="btn sm danger">Cancel Reservation</button></form><?php endif; ?></td></tr>
  <?php endforeach; ?>
  </tbody></table></div>

<div class="modal" id="reservationCreate"><div class="box">
  <h3>Add Reservation</h3>
  <?php if ($error && $values): ?><div class="alert error"><?=e($error)?></div><?php endif; ?>
  <form method="post" id="reservationForm">
    <?=csrf_field()?><input type="hidden" name="action" value="create"><input type="hidden" name="creation_token" value="<?=e($token)?>">
    <div id="reservationProfileStep">
      <div class="form-grid">
        <div class="field"><label for="reservationName">Complete Name</label><input id="reservationName" name="full_name" maxlength="120" value="<?=e($values['full_name'] ?? '')?>" required></div>
        <div class="field"><label for="reservationEmail">Email address</label><input id="reservationEmail" name="email" type="email" maxlength="160" value="<?=e($values['email'] ?? '')?>" required></div>
        <div class="field"><label for="reservationPhone">Contact number</label><input id="reservationPhone" name="phone" maxlength="40" value="<?=e($values['phone'] ?? '')?>" required></div>
        <div class="field"><label for="reservationReference">Bank reference no.</label><input id="reservationReference" name="bank_reference" maxlength="100" value="<?=e($values['bank_reference'] ?? '')?>" required></div>
        <div class="field"><label for="reservationFloor">Floor</label><select name="floor_id" id="reservationFloor" required onchange="reservationFilterUnits()"><option value="">Select floor</option><?php foreach ($floors as $floorId => $name): ?><option value="<?=(int)$floorId?>" <?=($values['floor_id'] ?? '') === (string)$floorId ? 'selected' : ''?>><?=e($name)?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="reservationUnit">Unit</label><select name="room_id" id="reservationUnit" required><option value="">Select unit</option><?php foreach ($rooms as $room): ?><option value="<?=(int)$room['id']?>" data-floor="<?=(int)$room['floor_id']?>" <?=($values['room_id'] ?? '') === (string)$room['id'] ? 'selected' : ''?>><?=e($room['room_number'])?></option><?php endforeach; ?></select><small>A vacant bed will be reserved when saved.</small></div>
      </div>
      <div class="row"><button class="btn sm" type="button" onclick="reservationNext()">Next</button><button class="btn sm ghost" type="button" onclick="closeModal('reservationCreate')">Cancel</button></div>
    </div>
    <div id="reservationReceiptStep" hidden>
      <h3>Acknowledgment Receipt</h3>
      <p>Receipt number and date will be assigned on save.</p>
      <p>Received from: <strong id="reservationReceiptName"></strong><br>Address: 7461 Heart Bldg., Brgy. San Antonio, Makati City<br>Unit: <strong id="reservationReceiptUnit"></strong><br>Bank Ref No.: <strong id="reservationReceiptReference"></strong><br>Payment for reservation</p>
      <div class="field"><label for="reservationAmount">Amount in Figures (₱)</label><input id="reservationAmount" name="amount" type="number" min="0.01" max="999999999.99" step="0.01" value="<?=e($values['amount'] ?? '')?>" required disabled></div>
      <p><label><input id="reservationConfirmed" name="payment_confirmed" type="checkbox" value="1" required disabled> I confirm that this reservation payment was received.</label></p>
      <p><small>After saving, choose partial payment to email the receipt or full payment to create the tenant profile and email the receipt. No bill payment is created.</small></p>
      <div class="row"><button type="button" class="btn sm ghost" onclick="reservationBack()">Back</button><button class="btn sm" type="submit">Save Reservation &amp; Receipt</button></div>
    </div>
  </form>
</div></div>
<script>
function reservationFilterUnits() {
  var floor = document.getElementById('reservationFloor'), unit = document.getElementById('reservationUnit');
  for (var i = 1; i < unit.options.length; i++) unit.options[i].hidden = unit.options[i].getAttribute('data-floor') !== floor.value;
  if (unit.selectedIndex > 0 && unit.options[unit.selectedIndex].hidden) unit.value = '';
}
function reservationNext() {
  var form = document.getElementById('reservationForm');
  ['full_name', 'email', 'phone', 'bank_reference'].forEach(function(name) { form.elements[name].value = form.elements[name].value.trim(); });
  if (!form.reportValidity()) return;
  var unit = document.getElementById('reservationUnit');
  document.getElementById('reservationReceiptName').textContent = form.elements.full_name.value;
  document.getElementById('reservationReceiptUnit').textContent = unit.options[unit.selectedIndex].text;
  document.getElementById('reservationReceiptReference').textContent = form.elements.bank_reference.value;
  document.getElementById('reservationProfileStep').hidden = true;
  document.getElementById('reservationReceiptStep').hidden = false;
  document.getElementById('reservationAmount').disabled = false;
  document.getElementById('reservationConfirmed').disabled = false;
}
function reservationBack() {
  document.getElementById('reservationAmount').disabled = true;
  document.getElementById('reservationConfirmed').disabled = true;
  document.getElementById('reservationConfirmed').checked = false;
  document.getElementById('reservationReceiptStep').hidden = true;
  document.getElementById('reservationProfileStep').hidden = false;
}
reservationFilterUnits();
<?php if ($error && $values): ?>document.getElementById('reservationCreate').classList.add('open');<?php endif; ?>
</script>
