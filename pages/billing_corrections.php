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
    $id = (int)($_POST['id'] ?? 0);
    try {
        if ($id <= 0) throw new RuntimeException('Invalid request.');
        $st = $pdo->prepare('SELECT * FROM billing_corrections WHERE id = ?');
        $st->execute([$id]);
        $req = $st->fetch();
        if (!$req) throw new RuntimeException('Request not found.');
        if ($action === 'approve') {
            $notes = trim((string)($_POST['review_notes'] ?? ''));
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE billing_corrections SET status='Approved', reviewed_by=?, reviewed_at=NOW(), review_notes=?, applied_at=NOW() WHERE id=?")
                ->execute([(int)$user['id'], $notes ?: null, $id]);
            // Note: actual bill modification would need specific fields; record approval for audit
            audit_log((int)$user['id'], "Approved billing correction #$id for bill #{$req['bill_id']}", 'billing', (string)$id);
            $pdo->commit();
            flash('success', 'Billing correction approved.');
            redirect('index.php?page=billing_corrections');
        } elseif ($action === 'reject') {
            $notes = trim((string)($_POST['review_notes'] ?? ''));
            if ($notes === '') throw new RuntimeException('Provide reason for rejection.');
            $pdo->prepare("UPDATE billing_corrections SET status='Rejected', reviewed_by=?, reviewed_at=NOW(), review_notes=? WHERE id=?")
                ->execute([(int)$user['id'], $notes, $id]);
            audit_log((int)$user['id'], "Rejected billing correction #$id", 'billing', (string)$id);
            flash('success', 'Billing correction rejected.');
            redirect('index.php?page=billing_corrections');
        }
    } catch (Throwable $t) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('error', $t->getMessage());
        redirect('index.php?page=billing_corrections');
    }
}

$reqs = [];
try {
    $reqs = $pdo->query("SELECT bc.*, b.invoice_number, b.billing_period FROM billing_corrections bc JOIN bills b ON b.id = bc.bill_id ORDER BY FIELD(bc.status,'Pending','Approved','Rejected','Completed'), bc.id DESC")->fetchAll();
} catch (Throwable $t) {
    $reqs = [];
}
?>
<div class="card">
  <h3>Billing Correction Requests</h3>
  <div style="overflow-x:auto">
  <table class="tbl">
    <thead><tr><th>Ref</th><th>Bill</th><th>Period</th><th>Field</th><th>Current</th><th>Requested</th><th>Explanation</th><th>Status</th><th>Action</th></tr></thead>
    <tbody>
    <?php if (!$reqs): ?><tr><td colspan="9">No requests.</td></tr><?php endif; ?>
    <?php foreach ($reqs as $r): ?>
      <tr>
        <td>#<?=e((string)$r['id'])?></td>
        <td><?=e($r['invoice_number'])?></td>
        <td><?=e($r['billing_period'])?></td>
        <td><?=e($r['disputed_field'])?></td>
        <td><?=e($r['current_value'])?></td>
        <td><?=e($r['requested_value'])?></td>
        <td><?=nl2br(e($r['explanation']))?></td>
        <td><?=e($r['status'])?></td>
        <td style="white-space:nowrap">
          <?php if ($r['status'] === 'Pending'): ?>
            <form method="post" style="display:inline">
              <?=csrf_field()?><input type="hidden" name="action" value="approve"><input type="hidden" name="id" value="<?=(int)$r['id']?>">
              <input type="text" name="review_notes" placeholder="Notes" style="width:100px">
              <button class="btn sm">Approve</button>
            </form>
            <form method="post" style="display:inline" onsubmit="return confirm('Reject?')">
              <?=csrf_field()?><input type="hidden" name="action" value="reject"><input type="hidden" name="id" value="<?=(int)$r['id']?>">
              <input type="text" name="review_notes" placeholder="Reason" required style="width:100px">
              <button class="btn sm danger">Reject</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
