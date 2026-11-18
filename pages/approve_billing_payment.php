<?php
require_once __DIR__ . '/../includes/billing_approval.php';
$pdo = db();
$id = (int)($_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['id'] ?? 0) : ($_GET['id'] ?? 0));
if ($id <= 0) {
    http_response_code(400);
    echo '<div class="alert error">Invalid payment submission.</div>';
    return;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    try {
        billing_approve_submission($pdo, $id, $user, $_POST);
        flash('success', 'Payment approved, recorded on the bill, and saved with its invoice and proof.');
        redirect('index.php?page=billing_payments');
    } catch (Throwable $t) {
        error_log('Billing approval failed: ' . $t->getMessage());
        $message = $t instanceof RuntimeException && !($t instanceof PDOException)
            ? $t->getMessage() : 'An unexpected error occurred. Please contact the administrator.';
        flash('error', 'Approval failed: ' . $message);
        redirect('index.php?page=approve_billing_payment&id=' . $id . (($_POST['source'] ?? '') === 'verification' ? '&source=verification' : ''));
    }
}
try {
    $st = $pdo->prepare('SELECT * FROM tenant_billing_payments WHERE id=?');
    $st->execute([$id]);
    $sub = $st->fetch();
    if (!$sub) {
        http_response_code(404);
        echo '<div class="alert error">Payment submission not found.</div>';
        return;
    }
    $st = $pdo->prepare('SELECT b.id, b.invoice_number, b.billing_period, b.total_amount, b.balance FROM bills b JOIN residents r ON r.residents_id=b.resident_id WHERE r.user_id=? ORDER BY b.billing_period DESC, b.id DESC');
    $st->execute([(int)$sub['user_id']]);
    $bills = $st->fetchAll();
} catch (Throwable $t) {
    error_log('Could not load billing approval: ' . $t->getMessage());
    echo '<div class="alert error">Could not load the payment submission. Please contact the administrator.</div>';
    return;
}
?>
<div class="card">
  <h3>Approve Billing Payment #<?=$id?></h3>
  <p><strong>Tenant:</strong> <?=e($sub['full_name'])?> · <strong>Unit:</strong> <?=e($sub['unit_number'])?><br>
  <strong>Submitted total:</strong> <?=money($sub['total_price'])?> · <strong>Payment:</strong> <?=money($sub['amount_paid'])?> (<?=e($sub['payment_type'])?>) · <strong>Date:</strong> <?=e($sub['date_of_payment'])?></p>
  <p>Room: <?=money($sub['unit_room_price'])?> · Electricity: <?=money($sub['electricity'])?> · Water: <?=money($sub['water'])?> · Other: <?=money($sub['others_fee_amount'])?></p>
  <p><a class="btn sm ghost" href="index.php?page=download&amp;type=billing_proof&amp;pid=<?=$id?>" target="_blank">View submitted screenshot</a></p>
  <?php if ($sub['status'] === 'Approved'): ?>
    <p>This submission has already been approved.</p>
  <?php else: ?>
  <form method="post">
    <?=csrf_field()?><input type="hidden" name="id" value="<?=$id?>">
    <?php if (($_GET['source'] ?? '') === 'verification'): ?><input type="hidden" name="source" value="verification"><?php endif; ?>
    <div class="field"><label for="approvalBill">Bill</label>
      <select name="bill_id" id="approvalBill" required onchange="document.getElementById('approvalPeriod').required=this.value==='new'">
        <option value="">Select a bill</option>
        <?php foreach ($bills as $bill): ?><option value="<?=(int)$bill['id']?>"><?=e($bill['invoice_number'] ?: 'Bill #' . $bill['id'])?> · <?=e($bill['billing_period'])?> · balance <?=money($bill['balance'])?></option><?php endforeach; ?>
        <option value="new">Create a new bill from this submission</option>
      </select>
    </div>
    <div class="field"><label for="approvalPeriod">Billing period (required for a new bill)</label><input id="approvalPeriod" name="billing_period" type="month"></div>
    <div class="field"><label for="approvalMethod">Payment method</label><select id="approvalMethod" name="payment_method" required><option value="">Select method</option><option>Cash</option><option>GCash</option><option>Bank Transfer</option></select></div>
    <div class="field"><label for="approvalReference">Payment reference number (optional)</label><input id="approvalReference" name="reference_no" maxlength="60"></div>
    <div class="field"><label for="approvalNote">Review note (optional)</label><input id="approvalNote" name="review_note"></div>
    <p>Approval records this payment against the selected bill and saves an invoice PDF and JPG proof. Check the bill and amount before approving.</p>
    <button class="btn sm">Confirm Approval</button>
  </form>
  <?php endif; ?>
  <p><a class="btn sm ghost" href="index.php?page=billing_payments">Back to submissions</a></p>
</div>
