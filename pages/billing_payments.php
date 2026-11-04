<?php
// Admin billing payments management (super_admin, admin_staff)
if (!in_array($user['role'] ?? '', ['super_admin', 'admin_staff'], true)) {
    http_response_code(403);
    echo '<div class="card"><h3>Forbidden</h3><p>Your role cannot access this page.</p></div>';
    return;
}

$pdo = db();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    try {
        if ($id <= 0) throw new RuntimeException('Invalid submission.');
        $st = $pdo->prepare('SELECT * FROM tenant_billing_payments WHERE id = ?');
        $st->execute([$id]);
        $sub = $st->fetch();
        if (!$sub) throw new RuntimeException('Submission not found.');

        if ($action === 'approve') {
            $note = trim((string)($_POST['review_note'] ?? ''));
            $pdo->prepare("UPDATE tenant_billing_payments SET status='Approved', reviewed_by=?, reviewed_at=NOW(), review_note=? WHERE id=?")
                ->execute([(int)$user['id'], $note ?: null, $id]);

            // Try to update tenant profile payment status if linked
            if (!empty($sub['tenant_profile_id'])) {
                try {
                    // Mark as Paid/Partial based on submission
                    $pstatus = ($sub['payment_type'] === 'Partial payment') ? 'Partial' : 'Paid';
                    $pdo->prepare('UPDATE tenant_profiles SET payment_status=? WHERE id=?')->execute([$pstatus, (int)$sub['tenant_profile_id']]);
                } catch (Throwable $t) {}
            }

            audit_log((int)$user['id'], "Approved billing payment #$id", 'billing', (string)$id);
            flash('success', 'Billing payment approved.');
            redirect('index.php?page=billing_payments');
        } elseif ($action === 'reject') {
            $note = trim((string)($_POST['review_note'] ?? ''));
            if ($note === '') throw new RuntimeException('Please provide a note for rejection.');
            $pdo->prepare("UPDATE tenant_billing_payments SET status='Rejected', reviewed_by=?, reviewed_at=NOW(), review_note=? WHERE id=?")
                ->execute([(int)$user['id'], $note, $id]);
            audit_log((int)$user['id'], "Rejected billing payment #$id", 'billing', (string)$id);
            flash('success', 'Billing payment rejected.');
            redirect('index.php?page=billing_payments');
        } elseif ($action === 'request_edit') {
            $note = trim((string)($_POST['review_note'] ?? ''));
            if ($note === '') throw new RuntimeException('Please provide a note for edit request.');
            $pdo->prepare("UPDATE tenant_billing_payments SET status='Edit Requested', edit_request_note=?, edit_requested_by=?, edit_requested_at=NOW(), reviewed_by=?, reviewed_at=NOW(), review_note=? WHERE id=?")
                ->execute([$note, (int)$user['id'], (int)$user['id'], $note, $id]);
            audit_log((int)$user['id'], "Requested edit for billing payment #$id", 'billing', (string)$id);
            flash('success', 'Edit requested from tenant.');
            redirect('index.php?page=billing_payments');
        }
    } catch (Throwable $t) {
        flash('error', $t->getMessage());
        redirect('index.php?page=billing_payments');
    }
}

$subs = [];
try {
    $subs = $pdo->query("SELECT * FROM tenant_billing_payments ORDER BY FIELD(status,'Pending','Edit Requested','Approved','Rejected'), id DESC")->fetchAll();
} catch (Throwable $t) {
    $subs = [];
}
?>
<style>
.pill{display:inline-block;padding:2px 10px;border-radius:20px;font-size:12px;font-weight:600}
.pill.Pending{background:#fef3c7;color:#92400e}
.pill.Approved{background:#dcfce7;color:#166534}
.pill.Rejected{background:#fee2e2;color:#991b1b}
.pill.Edit.Requested{background:#ede9fe;color:#5b21b6}
</style>

<div class="card">
  <h3>Billing Payment Submissions</h3>
  <div style="overflow-x:auto">
  <table class="tbl">
    <thead><tr><th>Ref</th><th>Tenant</th><th>Unit</th><th>Contact</th><th>Details</th><th>Total</th><th>Type/Amount</th><th>Date</th><th>Screenshot</th><th>Status</th><th>Notes</th><th>Action</th></tr></thead>
    <tbody>
    <?php if (!$subs): ?><tr><td colspan="12">No submissions.</td></tr><?php endif; ?>
    <?php foreach ($subs as $s): ?>
      <tr>
        <td>#<?=e((string)$s['id'])?></td>
        <td><strong><?=e($s['full_name'])?></strong><br><small><?=e($s['email']??'')?></small></td>
        <td><?=e($s['unit_number']??'')?></td>
        <td><?=e($s['phone']??'')?></td>
        <td>
          <small>Room: <?=money($s['unit_room_price'])?><br>
          Elec: <?=money($s['electricity'])?> | Water: <?=money($s['water'])?><br>
          Others: <?=e($s['others_fee_type']??'None')?> <?= $s['others_fee_label']? '('.e($s['others_fee_label']).')':''?> <?=money($s['others_fee_amount'])?></small>
        </td>
        <td><?=money($s['total_price'])?></td>
        <td><?=e($s['payment_type'])?><br><?=money($s['amount_paid'])?></td>
        <td><?=e($s['date_of_payment'])?></td>
        <td>
          <?php if (!empty($s['screenshot_path'])): ?>
            <a class="btn ghost sm" href="index.php?page=download&type=billing_proof&pid=<?=(int)$s['id']?>" target="_blank">View</a>
          <?php endif; ?>
        </td>
        <td><span class="pill <?=e(str_replace(' ','.',$s['status']))?>"><?=e($s['status'])?></span></td>
        <td>
          <?php if (!empty($s['review_note'])): ?><small>Review: <?=e($s['review_note'])?></small><br><?php endif; ?>
          <?php if (!empty($s['edit_request_note'])): ?><small>Edit Req: <?=e($s['edit_request_note'])?></small><?php endif; ?>
        </td>
        <td style="white-space:nowrap">
          <?php if ($s['status'] !== 'Approved'): ?>
            <form method="post" style="display:inline">
              <?=csrf_field()?><input type="hidden" name="action" value="approve"><input type="hidden" name="id" value="<?=(int)$s['id']?>">
              <button class="btn sm">Approve</button>
            </form>
          <?php endif; ?>
          <?php if ($s['status'] !== 'Rejected'): ?>
            <form method="post" style="display:inline" onsubmit="return confirm('Reject this submission?')">
              <?=csrf_field()?><input type="hidden" name="action" value="reject"><input type="hidden" name="id" value="<?=(int)$s['id']?>">
              <input type="text" name="review_note" placeholder="Reason" required style="width:120px">
              <button class="btn sm danger">Reject</button>
            </form>
          <?php endif; ?>
          <?php if ($s['status'] === 'Pending'): ?>
            <form method="post" style="display:inline">
              <?=csrf_field()?><input type="hidden" name="action" value="request_edit"><input type="hidden" name="id" value="<?=(int)$s['id']?>">
              <input type="text" name="review_note" placeholder="What to edit" required style="width:120px">
              <button class="btn sm ghost">Request Edit</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
