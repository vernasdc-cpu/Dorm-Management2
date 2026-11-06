<?php
if (!in_array($user['role'] ?? '', ['resident'], true)) {
    http_response_code(403);
    echo '<div class="card"><h3>Forbidden</h3><p>Residents only.</p></div>';
    return;
}
$pdo = db();
$ctx = get_tenant_context($user);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_check();
    try {
        $billId = (int)($_POST['bill_id'] ?? 0);
        if ($billId <= 0) throw new RuntimeException('Invalid bill.');
        // Verify ownership
        $ok = false;
        if ($ctx['resident']) {
            $chk = $pdo->prepare('SELECT COUNT(*) FROM bills WHERE id = ? AND resident_id = ?');
            $chk->execute([$billId, (int)$ctx['resident']['residents_id']]);
            if ((int)$chk->fetchColumn() > 0) $ok = true;
        }
        $chk = $pdo->prepare('SELECT COUNT(*) FROM bills b JOIN tenant_applications ta ON ta.id = b.application_id WHERE b.id = ? AND (b.resident_id IS NULL OR b.resident_id = 0) AND ta.user_id = ?');
        $chk->execute([$billId, (int)$user['id']]);
        if ((int)$chk->fetchColumn() > 0) $ok = true;
        if (!$ok) throw new RuntimeException('You may only request corrections for your own bills.');
        
        $field = trim((string)($_POST['disputed_field'] ?? ''));
        $current = trim((string)($_POST['current_value'] ?? ''));
        $requested = trim((string)($_POST['requested_value'] ?? ''));
        $explanation = trim((string)($_POST['explanation'] ?? ''));
        if ($field === '' || $requested === '' || $explanation === '') throw new RuntimeException('All required fields must be filled.');
        
        $ins = $pdo->prepare('INSERT INTO billing_corrections (bill_id, tenant_user_id, tenant_profile_id, resident_id, disputed_field, current_value, requested_value, explanation) VALUES (?,?,?,?,?,?,?,?)');
        $ins->execute([
            $billId, (int)$user['id'],
            $ctx['tenant_profile'] ? (int)$ctx['tenant_profile']['id'] : null,
            $ctx['resident'] ? (int)$ctx['resident']['residents_id'] : null,
            $field, $current ?: null, $requested, $explanation
        ]);
        audit_log((int)$user['id'], 'Submitted billing correction request', 'billing', (string)$pdo->lastInsertId());
        flash('success', 'Correction request submitted.');
        redirect('index.php?page=my_billing_correction');
    } catch (Throwable $t) {
        flash('error', $t->getMessage());
        redirect('index.php?page=my_billing_correction');
    }
}

$bills = [];
$reqs = [];
try {
    if ($ctx['resident']) {
        $st = $pdo->prepare('SELECT * FROM bills WHERE resident_id = ? ORDER BY billing_period DESC');
        $st->execute([(int)$ctx['resident']['residents_id']]);
        $bills = $st->fetchAll();
    }
    $st = $pdo->prepare('SELECT b.* FROM bills b JOIN tenant_applications ta ON ta.id = b.application_id WHERE (b.resident_id IS NULL OR b.resident_id = 0) AND ta.user_id = ? ORDER BY b.billing_period DESC');
    $st->execute([(int)$user['id']]);
    $appBills = $st->fetchAll();
    $knownBillIds = array_fill_keys(array_map('intval', array_column($bills, 'id')), true);
    foreach ($appBills as $bill) {
        if (!isset($knownBillIds[(int)$bill['id']])) $bills[] = $bill;
    }
    $rst = $pdo->prepare('SELECT bc.*, b.invoice_number, b.billing_period FROM billing_corrections bc JOIN bills b ON b.id = bc.bill_id WHERE bc.tenant_user_id = ? ORDER BY bc.id DESC');
    $rst->execute([(int)$user['id']]);
    $reqs = $rst->fetchAll();
} catch (Throwable $t) {
    $bills = []; $reqs = [];
}
?>
<div class="card" style="margin-bottom:14px">
  <h3>Request Billing Correction</h3>
  <form method="post">
    <?=csrf_field()?>
    <div class="form-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
      <div class="field"><label>Bill</label>
        <select name="bill_id" required>
          <option value="">Select bill</option>
          <?php foreach ($bills as $b): ?><option value="<?=(int)$b['id']?>"><?=e($b['invoice_number'])?> - <?=e($b['billing_period'])?> (<?=money($b['total_amount'])?>)</option><?php endforeach; ?>
        </select>
      </div>
      <div class="field"><label>Field/Charge Being Disputed</label><input name="disputed_field" placeholder="e.g., Electricity Fee" required></div>
      <div class="field"><label>Current Value</label><input name="current_value" placeholder="As shown"></div>
      <div class="field"><label>Requested Corrected Value</label><input name="requested_value" required></div>
      <div class="field" style="grid-column:1/-1"><label>Explanation</label><textarea name="explanation" rows="4" required></textarea></div>
    </div>
    <div style="margin-top:10px"><button class="btn">Submit Request</button></div>
  </form>
</div>

<div class="card">
  <h3>My Correction Requests</h3>
  <div style="overflow-x:auto">
  <table class="tbl">
    <thead><tr><th>Ref</th><th>Bill</th><th>Period</th><th>Field</th><th>Requested</th><th>Status</th><th>Review Notes</th></tr></thead>
    <tbody>
    <?php if (!$reqs): ?><tr><td colspan="7">No requests yet.</td></tr><?php endif; ?>
    <?php foreach ($reqs as $r): ?>
      <tr>
        <td>#<?=e((string)$r['id'])?></td>
        <td><?=e($r['invoice_number'])?></td>
        <td><?=e($r['billing_period'])?></td>
        <td><?=e($r['disputed_field'])?></td>
        <td><?=e($r['requested_value'])?></td>
        <td><?=e($r['status'])?></td>
        <td><?= $r['review_notes'] ? nl2br(e($r['review_notes'])) : '—' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
