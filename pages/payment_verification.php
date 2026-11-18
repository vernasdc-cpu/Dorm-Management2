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
        if ($action === 'verify_billing_payment') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('Invalid submission.');
            redirect('index.php?page=approve_billing_payment&id=' . $id . '&source=verification');
        } elseif ($action === 'reject_billing_payment') {
            $id = (int)($_POST['id'] ?? 0);
            $note = trim((string)($_POST['review_note'] ?? ''));
            if ($id <= 0) throw new RuntimeException('Invalid submission.');
            if ($note === '') throw new RuntimeException('Rejection reason required.');
            $update = $pdo->prepare("UPDATE tenant_billing_payments SET status='Rejected', reviewed_by=?, reviewed_at=NOW(), review_note=? WHERE id=? AND status IN ('Pending','Edit Requested')");
            $update->execute([(int)$user['id'], $note, $id]);
            if ($update->rowCount() !== 1) throw new RuntimeException('This submission is no longer pending review.');
            audit_log((int)$user['id'], "Rejected billing payment proof #$id", 'billing', (string)$id);
            flash('success', 'Payment proof rejected.');
            redirect('index.php?page=payment_verification');
        }
    } catch (Throwable $t) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('error', $t->getMessage());
        redirect('index.php?page=payment_verification');
    }
}

$bp = [];
try {
    $bp = $pdo->query("SELECT * FROM tenant_billing_payments WHERE status IN ('Pending','Edit Requested') ORDER BY id ASC")->fetchAll();
} catch (Throwable $t) {
    $bp = [];
}
?>
<style>
.pill{display:inline-block;padding:2px 10px;border-radius:20px;font-size:12px;font-weight:600}
.pill.Pending{background:#fef3c7;color:#92400e}
.pill.Edit.Requested{background:#ede9fe;color:#5b21b6}
</style>

<div class="card">
  <h3>Payment Proof Verification Queue</h3>
  <p style="color:#7c6f8a">Review tenant-uploaded payment screenshots. Only verified/approved proofs affect official records.</p>
  <div style="overflow-x:auto">
  <table class="tbl">
    <thead><tr><th>Ref</th><th>Tenant</th><th>Unit</th><th>Total</th><th>Type/Amount</th><th>Date</th><th>Proof</th><th>Status</th><th>Notes</th><th>Action</th></tr></thead>
    <tbody>
    <?php if (!$bp): ?><tr><td colspan="10">No pending payment proofs.</td></tr><?php endif; ?>
    <?php foreach ($bp as $s): ?>
      <tr>
        <td>#<?=e((string)$s['id'])?></td>
        <td><strong><?=e($s['full_name'])?></strong><br><small><?=e($s['email']??'')?></small></td>
        <td><?=e($s['unit_number']??'')?></td>
        <td><?=money($s['total_price'])?></td>
        <td><?=e($s['payment_type'])?><br><?=money($s['amount_paid'])?></td>
        <td><?=e($s['date_of_payment'])?></td>
        <td><?php if(!empty($s['screenshot_path'])): ?><a class="btn ghost sm" href="index.php?page=download&type=billing_proof&pid=<?=(int)$s['id']?>" target="_blank">View Proof</a><?php endif; ?></td>
        <td><span class="pill <?=e(str_replace(' ','.',$s['status']))?>"><?=e($s['status'])?></span></td>
        <td><?php if(!empty($s['edit_request_note'])): ?><small>Edit: <?=e($s['edit_request_note'])?></small><?php endif; ?></td>
        <td style="white-space:nowrap">
          <a class="btn sm" href="index.php?page=approve_billing_payment&amp;id=<?=(int)$s['id']?>&amp;source=verification">Verify &amp; Approve</a>
          <form method="post" style="display:inline" onsubmit="return confirm('Reject this payment proof?')">
            <?=csrf_field()?><input type="hidden" name="action" value="reject_billing_payment"><input type="hidden" name="id" value="<?=(int)$s['id']?>">
            <input type="text" name="review_note" placeholder="Reason" required style="width:110px">
            <button class="btn sm danger">Reject</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
