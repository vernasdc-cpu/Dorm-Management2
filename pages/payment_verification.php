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
            $note = trim((string)($_POST['review_note'] ?? ''));
            if ($id <= 0) throw new RuntimeException('Invalid submission.');
            $st = $pdo->prepare('SELECT * FROM tenant_billing_payments WHERE id = ?');
            $st->execute([$id]);
            $sub = $st->fetch();
            if (!$sub) throw new RuntimeException('Submission not found.');

            $pdo->beginTransaction();
            $pdo->prepare("UPDATE tenant_billing_payments SET status='Approved', reviewed_by=?, reviewed_at=NOW(), review_note=? WHERE id=?")
                ->execute([(int)$user['id'], $note ?: null, $id]);

            // Try to update linked users
            if (!empty($sub['user_id'])) {
                try {
                    $pdo->prepare("UPDATE users SET status='active' WHERE id=? AND status IN ('Pending Activation','inactive')")->execute([(int)$sub['user_id']]);
                } catch (Throwable $t) {}
            }
            
            // If this is for an initial/application bill, activate tenant
            try {
                if (!empty($sub['user_id'])) {
                    $uid = (int)$sub['user_id'];
                    $ust = $pdo->prepare('SELECT * FROM users WHERE id = ?');
                    $ust->execute([$uid]);
                    $urow = $ust->fetch();
                    if ($urow && $urow['role'] === 'resident') {
                        // Check if already has resident record
                        $rst = $pdo->prepare('SELECT residents_id FROM residents WHERE user_id = ?');
                        $rst->execute([$uid]);
                        $rid = $rst->fetchColumn();
                        if (!$rid) {
                            // Try to find application by email/name
                            $ast = $pdo->prepare('SELECT * FROM tenant_applications WHERE LOWER(email)=LOWER(?) OR full_name=? ORDER BY id DESC LIMIT 1');
                            $ast->execute([$urow['email'] ?? ($sub['email'] ?? ''), $urow['name'] ?? ($sub['full_name'] ?? '')]);
                            $app = $ast->fetch();
                            if ($app) {
                                // Find room
                                $roomId = $app['intended_room_id'];
                                if (!$roomId && $app['intended_unit_number']) {
                                    $rr = $pdo->prepare('SELECT id FROM rooms WHERE room_number = ?');
                                    $rr->execute([$app['intended_unit_number']]);
                                    $roomId = $rr->fetchColumn() ?: null;
                                }
                                if ($roomId) {
                                    require_once __DIR__ . '/../includes/helpers.php';
                                    ensure_room_beds($pdo, (int)$roomId);
                                    $bid = assign_tenant_bed($pdo, (int)$roomId);
                                    // Create resident
                                    $rins = $pdo->prepare('INSERT INTO residents (user_id, room_id, bed_id, status, move_in_date) VALUES (?,?,?,?,CURDATE())');
                                    $rins->execute([$uid, (int)$roomId, $bid, 'active']);
                                    $newRid = (int)$pdo->lastInsertId();
                                    // Create contract if not exists
                                    try {
                                        $cnum = 'CTR-A' . $app['id'];
                                        $rate = 0;
                                        $rr2 = $pdo->prepare('SELECT monthly_rate FROM rooms WHERE id = ?');
                                        $rr2->execute([(int)$roomId]);
                                        $rate = (float)$rr2->fetchColumn();
                                        $cins = $pdo->prepare("INSERT IGNORE INTO contracts (contract_number, resident_id, room_id, start_date, end_date, monthly_rate, status, created_by) VALUES (?,?,?,?,?,?,?,?)");
                                        $cins->execute([$cnum, $newRid, (int)$roomId, date('Y-m-d'), date('Y-m-d', strtotime('+12 months')), $rate, 'Active', (int)$user['id']]);
                                    } catch (Throwable $t2) {}
                                    // Update application
                                    $pdo->prepare("UPDATE tenant_applications SET application_status='Activated', activated_at=NOW() WHERE id=?")->execute([(int)$app['id']]);
                                    // Link bills
                                    $pdo->prepare('UPDATE bills SET resident_id = ? WHERE application_id = ?')->execute([$newRid, (int)$app['id']]);
                                    refresh_room_status((int)$roomId);
                                    audit_log((int)$user['id'], "Activated tenant from application #{$app['id']}", 'tenants', (string)$app['id']);
                                }
                            }
                        }
                    }
                }
            } catch (Throwable $tact) {
                // Don't fail verification if activation fails
            }

            audit_log((int)$user['id'], "Verified billing payment proof #$id", 'billing', (string)$id);
            $pdo->commit();
            flash('success', 'Payment proof verified and approved.');
            redirect('index.php?page=payment_verification');
        } elseif ($action === 'reject_billing_payment') {
            $id = (int)($_POST['id'] ?? 0);
            $note = trim((string)($_POST['review_note'] ?? ''));
            if ($id <= 0) throw new RuntimeException('Invalid submission.');
            if ($note === '') throw new RuntimeException('Rejection reason required.');
            $pdo->prepare("UPDATE tenant_billing_payments SET status='Rejected', reviewed_by=?, reviewed_at=NOW(), review_note=? WHERE id=?")
                ->execute([(int)$user['id'], $note, $id]);
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
          <form method="post" style="display:inline">
            <?=csrf_field()?><input type="hidden" name="action" value="verify_billing_payment"><input type="hidden" name="id" value="<?=(int)$s['id']?>">
            <input type="text" name="review_note" placeholder="Notes (optional)" style="width:110px">
            <button class="btn sm">Verify & Approve</button>
          </form>
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
