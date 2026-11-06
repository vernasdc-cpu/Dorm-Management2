<?php
// My Billing — resident only. Inner content; $user available.
if (($user['role'] ?? '') !== 'resident') {
    http_response_code(403);
    echo '<div class="card"><h3>Forbidden</h3><p>Residents only.</p></div>';
    return;
}
try {
    $pdo = db();
    $st = $pdo->prepare('SELECT residents_id, room_id FROM residents WHERE user_id = ? LIMIT 1');
    $st->execute([(int)$user['id']]);
    $me = $st->fetch();
    $rid = $me ? (int)$me['residents_id'] : 0;
    $bills = [];
    $pays = [];
    $byBill = [];
    $balance = 0.0;
    
    if ($rid > 0) {
        $balQ = $pdo->prepare("SELECT COALESCE(SUM(balance),0) FROM bills WHERE resident_id = ? AND payment_status IN ('Unpaid','Partially Paid','Overdue')");
        $balQ->execute([$rid]);
        $balance = (float)($balQ->fetchColumn() ?: 0);
        $bst = $pdo->prepare(
            "SELECT b.*, rm.room_number FROM bills b JOIN rooms rm ON rm.id = b.room_id
             WHERE b.resident_id = ? ORDER BY b.billing_period DESC, b.id DESC"
        );
        $bst->execute([$rid]);
        $bills = $bst->fetchAll();
        $payst = $pdo->prepare(
            'SELECT p.*, b.billing_period FROM payments p JOIN bills b ON b.id = p.bill_id
             WHERE p.resident_id = ? ORDER BY p.id DESC'
        );
        $payst->execute([$rid]);
        $pays = $payst->fetchAll();
        foreach ($pays as $p) $byBill[(int)$p['bill_id']][] = $p;
    }
    
    // Include application bills only when the application is linked to this account.
    try {
        $bst2 = $pdo->prepare("SELECT b.*, rm.room_number, ta.full_name, ta.email FROM bills b LEFT JOIN rooms rm ON rm.id = b.room_id JOIN tenant_applications ta ON ta.id = b.application_id WHERE (b.resident_id IS NULL OR b.resident_id = 0) AND ta.user_id = ? ORDER BY b.id DESC");
        $bst2->execute([(int)$user['id']]);
        $bills2 = $bst2->fetchAll();
        foreach ($bills2 as $bb) {
            // avoid duplicates
            $exists = false;
            foreach ($bills as $be) { if ((int)$be['id'] === (int)$bb['id']) { $exists = true; break; } }
            if (!$exists) $bills[] = $bb;
        }
        if ($rid == 0 && $bills2) {
            $balQ2 = $pdo->prepare("SELECT COALESCE(SUM(b.balance),0) FROM bills b JOIN tenant_applications ta ON ta.id = b.application_id WHERE (b.resident_id IS NULL OR b.resident_id = 0) AND ta.user_id = ? AND b.payment_status IN ('Unpaid','Partially Paid','Overdue')");
            $balQ2->execute([(int)$user['id']]);
            $balance = (float)($balQ2->fetchColumn() ?: 0);
        }
    } catch (Throwable $t) {}
} catch (Throwable $t) {
    echo '<div class="alert error">' . e('Failed to load billing: ' . $t->getMessage()) . '</div>'; return;
}
$today = date('Y-m-d');
?>
<style>
.stat-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-bottom:14px}
.stat{background:#fff;border:1px solid #eadcf5;border-radius:10px;padding:12px 14px}
.stat small{color:#7c6f8a}.stat strong{font-size:20px;display:block}
.pill{display:inline-block;padding:2px 10px;border-radius:999px;font-size:12px;font-weight:600}
.pill.Unpaid{background:#fde8e8;color:#b42318}.pill.Partially{background:#fef3c7;color:#92400e}
.pill.Paid{background:#dcfce7;color:#166534}.pill.Overdue{background:#fee2e2;color:#991b1b;border:1px solid #fca5a5}
.tbl-wrap{overflow-x:auto}
</style>
<div class="stat-grid">
  <div class="stat"><small>My outstanding balance</small><strong><?=money($balance)?></strong></div>
</div>
<div class="card">
  <h3>My bills</h3>
  <div class="tbl-wrap">
  <table class="tbl">
    <thead><tr><th>Period</th><th>Invoice</th><th>Total</th><th>Paid</th><th>Balance</th><th>Status</th><th>Invoice</th><th>Receipts</th></tr></thead>
    <tbody>
    <?php if (!$bills): ?><tr><td colspan="8">No bills yet.</td></tr><?php endif; ?>
    <?php foreach ($bills as $b):
        $disp = $b['payment_status'] === 'Paid' ? 'Paid'
            : ((substr((string)$b['due_date'], 0, 10) !== '' && substr((string)$b['due_date'], 0, 10) < $today) ? 'Overdue' : (string)$b['payment_status']);
        $cls = $disp === 'Partially Paid' ? 'Partially' : $disp;
    ?>
      <tr>
        <td><?=e($b['billing_period'])?><br><small style="color:#7c6f8a">Due <?=e(substr((string)$b['due_date'], 0, 10) ?: '—')?></small></td>
        <td><?=e((string)$b['invoice_number'])?></td>
        <td><?=money($b['total_amount'])?></td>
        <td><?=money($b['amount_paid'])?></td>
        <td><strong><?=money($b['balance'])?></strong></td>
        <td><span class="pill <?=e($cls)?>"><?=e($disp)?></span></td>
        <td><a class="btn ghost sm" href="index.php?page=invoice&id=<?=(int)$b['id']?>">View</a></td>
        <td><?php foreach (($byBill[(int)$b['id']] ?? []) as $rc): ?>
          <a class="btn ghost sm" href="index.php?page=receipt&id=<?=(int)$rc['id']?>" title="<?=money($rc['amount'])?>"><?=e((string)$rc['receipt_number'])?></a>
        <?php endforeach; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<div class="card">
  <h3>My payment history</h3>
  <div class="tbl-wrap">
  <table class="tbl">
    <thead><tr><th>Receipt</th><th>Period</th><th>Amount</th><th>Method</th><th>Date</th><th>Reference</th><th>View</th></tr></thead>
    <tbody>
    <?php if (!$pays): ?><tr><td colspan="7">No payments yet.</td></tr><?php endif; ?>
    <?php foreach ($pays as $p): ?>
      <tr>
        <td><?=e((string)$p['receipt_number'])?></td>
        <td><?=e($p['billing_period'])?></td>
        <td><?=money($p['amount'])?></td>
        <td><?=e((string)$p['payment_method'])?></td>
        <td><?=e(substr((string)$p['payment_date'], 0, 10))?></td>
        <td><?=e((string)$p['reference_no'])?></td>
        <td><a class="btn ghost sm" href="index.php?page=receipt&id=<?=(int)$p['id']?>">Receipt</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
