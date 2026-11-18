<?php
$id = (int)($_GET['id'] ?? 0);
$isAdditional = ($_GET['type'] ?? '') === 'reservation_payment';
$isReservation = $isAdditional || ($_GET['type'] ?? '') === 'reservation';
try {
    if ($isAdditional) {
        $sql = "SELECT p.*, r.full_name AS tenant_name, r.address, r.unit_number, r.status, r.tenant_profile_id, p.amount AS balance_amount, r.amount AS partial_amount, r.received_at AS partial_received_at, r.id AS partial_receipt_id, CASE WHEN p.is_full_summary=1 THEN r.amount + p.amount ELSE p.amount END AS total_amount, 'Full' AS payment_classification FROM reservation_additional_payments p JOIN reservations r ON r.id=p.reservation_id WHERE p.id=?";
    } elseif ($isReservation) {
        $sql = 'SELECT r.*, r.full_name AS tenant_name, r.amount AS total_amount FROM reservations r WHERE r.id=?';
    } else {
        $sql = 'SELECT * FROM tenant_acknowledgment_receipts WHERE id=?';
    }
    $st = db()->prepare($sql);
    $st->execute([$id]);
    $receipt = $st->fetch();
    if (!$receipt) {
        http_response_code(404);
        echo '<div class="card"><p>Acknowledgment receipt not found.</p></div>';
        return;
    }
    if ($user['role'] === 'resident' && ((int)($user['tenant_profile_id'] ?? 0) <= 0 || (int)$user['tenant_profile_id'] !== (int)($receipt['tenant_profile_id'] ?? 0))) {
        http_response_code(403);
        echo '<div class="card"><p>You may only view your own acknowledgment receipt.</p></div>';
        return;
    }
} catch (Throwable $t) {
    http_response_code(500);
    error_log('Could not load acknowledgment receipt: ' . $t->getMessage());
    echo '<div class="alert error">Could not load the acknowledgment receipt. Please contact the administrator.</div>';
    return;
}

[$pesos, $centavos] = array_map('intval', explode('.', (string)$receipt['total_amount']));
$amountWords = ucfirst(acknowledgment_number_words($pesos)) . ' pesos and ' . str_pad((string)$centavos, 2, '0', STR_PAD_LEFT) . '/100';
$receiptNumber = ($isAdditional ? 'AR-RP' : ($isReservation ? 'AR-R' : 'AR-')) . str_pad((string)$receipt['id'], 6, '0', STR_PAD_LEFT);
?>
<style>
.ack-receipt{max-width:980px;margin:auto;padding:35px;background:#fff;color:#111;font-family:Arial,sans-serif}
.ack-header{display:flex;align-items:center;justify-content:center;gap:24px;text-align:center}
.ack-header img{width:85px;height:85px;object-fit:contain}
.ack-header h2{margin:0 0 8px;font-size:26px}.ack-header p{margin:0;font-size:18px}
.ack-title{text-align:center;margin:36px 0 20px;font-size:24px}
.ack-meta{text-align:right;margin-bottom:24px}.ack-meta strong{color:#bd1616}
.ack-line{display:flex;gap:10px;align-items:baseline;margin:15px 0;font-size:17px}
.ack-line span:last-child{flex:1;border-bottom:1px solid #222;padding:0 4px 4px}
.ack-footer{display:flex;justify-content:space-between;align-items:end;gap:30px;margin-top:35px}
.ack-box{width:190px;height:105px;border:1px solid #222}
.ack-sign{text-align:center;width:290px}.ack-sign strong{display:block;border-bottom:1px solid #222;padding:8px}
@media(max-width:600px){.ack-receipt{padding:18px}.ack-header img{width:55px;height:55px}.ack-header h2{font-size:19px}.ack-line{font-size:14px}.ack-footer{flex-wrap:wrap}}
@media print{body{background:#fff}.ack-receipt{max-width:none;padding:0}.footer{display:none}}
</style>
<div class="no-print" style="margin-bottom:12px">
  <button class="btn sm" onclick="window.print()">Print</button>
  <a class="btn sm ghost" href="<?=($user['role'] === 'resident') ? 'index.php?page=profile' : ($isReservation ? 'index.php?page=reservations' : 'index.php?page=tenants')?>">Back</a>
  <?php if ($isReservation && $user['role'] !== 'resident'): ?>
    <?php if (!$isAdditional && $receipt['status'] === 'Reserved'): ?>
      <form method="post" action="index.php?page=reservations" style="display:inline" onsubmit="return confirm('Mark this payment partial and email the receipt?')"><?=csrf_field()?><input type="hidden" name="action" value="partial_payment"><input type="hidden" name="id" value="<?=$id?>"><button class="btn sm ghost">Partial Payment: Email Receipt</button></form>
      <?php if ($receipt['payment_classification'] === 'Partial'): ?>
        <a class="btn sm" href="index.php?page=tenants&amp;reservation_id=<?=$id?>">Create Profile</a>
      <?php else: ?>
        <form method="post" action="index.php?page=reservations" style="display:inline" onsubmit="return confirm('Confirm this is a full payment and create the tenant profile and account using the reserved bed?')"><?=csrf_field()?><input type="hidden" name="action" value="full_payment"><input type="hidden" name="id" value="<?=$id?>"><button class="btn sm">Full Payment: Create Tenant &amp; Email</button></form>
      <?php endif; ?>
    <?php elseif ($receipt['payment_classification'] !== null): ?>
      <form method="post" action="index.php?page=reservations" style="display:inline"><?=csrf_field()?><input type="hidden" name="action" value="email_receipt"><input type="hidden" name="id" value="<?=(int)($isAdditional ? $receipt['reservation_id'] : $id)?>"><?php if ($isAdditional): ?><input type="hidden" name="payment_id" value="<?=$id?>"><?php endif; ?><button class="btn sm ghost">Resend Receipt Email</button></form>
    <?php endif; ?>
  <?php endif; ?>
</div>
<div class="ack-receipt">
  <?php if ($isReservation && $receipt['status'] === 'Cancelled'): ?><p><strong>Reservation cancelled. This receipt records the original payment; no refund is recorded here.</strong></p><?php endif; ?>
  <div class="ack-header">
    <img src="assets/img/logo.jpg" alt="Heart Ladies Dormitory logo">
    <div><h2>SEGOVIA DEVELOPMENT CORP.</h2><p>7461 Heart Bldg., Brgy. San Antonio, Makati City</p></div>
  </div>
  <h2 class="ack-title">ACKNOWLEDGEMENT RECEIPT</h2>
  <div class="ack-meta"><strong>No. <?=e($receiptNumber)?></strong><br>Date: <?=e(substr((string)$receipt['received_at'], 0, 10))?></div>
  <div class="ack-line"><span>Received From:</span><span><?=e($receipt['tenant_name'])?></span></div>
  <div class="ack-line"><span>Address at:</span><span><?=e($receipt['address'])?></span></div>
  <div class="ack-line"><span>The sum of:</span><span><?=e($amountWords)?></span></div>
  <div class="ack-line"><span>Amount in Figures:</span><span><?=money($receipt['total_amount'])?></span></div>
  <?php if ($receipt['bank_reference'] !== null && $receipt['bank_reference'] !== ''): ?>
  <div class="ack-line"><span><?=($isAdditional && !empty($receipt['is_full_summary'])) ? 'Balance Bank Ref No.' : 'Bank Ref No.'?>:</span><span><?=e($receipt['bank_reference'])?></span></div>
  <?php endif; ?>
  <div class="ack-line"><span>In partial/full payment for:</span><span><?php if ($isAdditional): ?><?=!empty($receipt['is_full_summary']) ? 'Full payment for reservation' : 'Additional payment completing the reservation'?>, Unit <?=e($receipt['unit_number'])?><?php elseif ($isReservation): ?><?=e($receipt['payment_classification'] ?: 'Unclassified')?> payment for reservation, Unit <?=e($receipt['unit_number'])?><?php else: ?>One month advance (<?=money($receipt['monthly_rate'])?>) and one month deposit (<?=money($receipt['monthly_rate'])?>), Unit <?=e($receipt['unit_number'])?><?php endif; ?></span></div>
  <?php if ($isAdditional && !empty($receipt['is_full_summary'])): ?>
  <div class="ack-line"><span>Partial payment receipt:</span><span>AR-R<?=e(str_pad((string)$receipt['partial_receipt_id'], 6, '0', STR_PAD_LEFT))?> &middot; <?=money($receipt['partial_amount'])?> &middot; <?=e(substr((string)$receipt['partial_received_at'], 0, 10))?></span></div>
  <div class="ack-line"><span>Balance payment:</span><span><?=money($receipt['balance_amount'])?></span></div>
  <?php endif; ?>
  <div class="ack-footer">
    <div class="ack-box"></div>
    <div class="ack-sign">Payment Received:<strong><?=e($receipt['received_by_name'])?></strong><small>(Signature over Printed Name)<br>Authorized Person</small></div>
  </div>
</div>
