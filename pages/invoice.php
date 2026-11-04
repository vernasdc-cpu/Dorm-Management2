<?php
// Invoice — super_admin, admin_staff, resident (resident: own bills only). Inner content; $user available.
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { echo '<div class="card"><p>Invalid invoice.</p><p><a class="btn ghost sm" href="javascript:history.back()">Back</a></p></div>'; return; }
try {
    $st = db()->prepare(
        "SELECT b.*,
                COALESCE(u.name, ta.full_name, 'Resident') AS resident_name,
                COALESCE(u.email, ta.email, '') AS resident_email,
                COALESCE(u.phone, ta.phone, '') AS resident_phone,
                COALESCE(rm.room_number, ta.intended_unit_number, '—') AS room_number,
                COALESCE(rm.monthly_rate, 0) AS monthly_rate,
                r.user_id AS owner_uid, r.residents_id
         FROM bills b
         LEFT JOIN residents r ON r.residents_id = b.resident_id
         LEFT JOIN users u ON u.id = r.user_id
         LEFT JOIN rooms rm ON rm.id = b.room_id
         LEFT JOIN tenant_applications ta ON ta.id = b.application_id
         WHERE b.id = ? LIMIT 1"
    );
    $st->execute([$id]);
    $b = $st->fetch();
    if (!$b) { http_response_code(404); echo '<div class="card"><p>Invoice not found.</p></div>'; return; }
    if (($user['role'] ?? '') === 'resident') {
        $ok = false;
        if ((int)$b['owner_uid'] === (int)$user['id']) $ok = true;
        // Also check linked context
        if (!$ok) {
            try {
                $ctx = get_tenant_context($user);
                if ($ctx['resident'] && (int)$ctx['resident']['residents_id'] === (int)$b['residents_id']) $ok = true;
                if ($ctx['tenant_profile']) {
                    $chk = db()->prepare('SELECT COUNT(*) FROM bills b2 JOIN residents r2 ON r2.residents_id = b2.resident_id JOIN users u2 ON u2.id = r2.user_id WHERE b2.id = ? AND (LOWER(u2.email)=LOWER(?) OR LOWER(u2.name)=LOWER(?))');
                    $chk->execute([$id, $ctx['tenant_profile']['email'] ?? ($user['email'] ?? ''), $ctx['tenant_profile']['full_name'] ?? ($user['name'] ?? '')]);
                    if ((int)$chk->fetchColumn() > 0) $ok = true;
                }
            } catch (Throwable $t) {}
        }
        if (!$ok) {
            http_response_code(403); echo '<div class="card"><h3>Forbidden</h3><p>You may only view your own invoices.</p></div>'; return;
        }
    }
    $disp = ($b['payment_status'] === 'Paid') ? 'Paid'
        : ((substr((string)$b['due_date'], 0, 10) !== '' && substr((string)$b['due_date'], 0, 10) < date('Y-m-d')) ? 'Overdue' : (string)$b['payment_status']);
    try {
        $pst = db()->prepare('SELECT * FROM payments WHERE bill_id = ? ORDER BY id ASC');
        $pst->execute([$id]);
        $payRows = $pst->fetchAll();
    } catch (Throwable $t) { $payRows = []; }
} catch (Throwable $t) {
    echo '<div class="alert error">' . e('Failed to load invoice: ' . $t->getMessage()) . '</div>'; return;
}
?>
<style>
.inv{max-width:760px;margin:0 auto;background:#fff;border:1px solid #e5d9f2;border-radius:12px;padding:28px;color:#2b2140}
.inv-head{display:flex;gap:16px;align-items:center;border-bottom:3px solid #7c3aed;padding-bottom:14px;margin-bottom:16px}
.inv-head img{width:64px;height:64px;object-fit:cover;border-radius:10px}
.inv-meta{display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;margin:14px 0}
.inv table{width:100%;border-collapse:collapse;margin-top:10px}
.inv th,.inv td{border:1px solid #e5d9f2;padding:8px 10px;text-align:left;font-size:14px}
.inv th{background:#f6f0fd}.inv td.n,.inv th.n{text-align:right}
.inv .tot td{font-weight:700;background:#faf7ff}
@media print{.no-print{display:none!important}body{background:#fff}.inv{border:none;border-radius:0;max-width:none}}
</style>
<div class="no-print" style="margin-bottom:10px;display:flex;gap:8px">
  <button class="btn sm" onclick="window.print()">Print</button>
  <a class="btn ghost sm" href="<?=($user['role'] ?? '') === 'resident' ? 'index.php?page=my_billing' : 'index.php?page=billing'?>">Back</a>
</div>
<div class="inv">
  <div class="inv-head">
    <img src="assets/img/logo.jpg" alt="logo">
    <div>
      <div style="font-size:20px;font-weight:800">Heart Ladies Dormitory</div>
      <div style="color:#7c6f8a;font-size:13px">Ladies Dormitory Management System</div>
    </div>
    <div style="margin-left:auto;text-align:right">
      <div style="font-size:22px;font-weight:800;letter-spacing:2px">INVOICE</div>
      <div><strong><?=e((string)$b['invoice_number'])?></strong></div>
    </div>
  </div>
  <div class="inv-meta">
    <div>
      <div><strong>Billed to:</strong> <?=e($b['resident_name'])?></div>
      <div style="font-size:13px;color:#555"><?=e((string)($b['resident_email'] ?? ''))?><?=!empty($b['resident_phone']) ? ' · ' . e((string)$b['resident_phone']) : ''?></div>
      <div><strong>Room:</strong> <?=e($b['room_number'])?></div>
      <div><strong>Billing period:</strong> <?=e($b['billing_period'])?><?=!empty($b['bill_type']) && $b['bill_type'] !== 'Monthly' ? ' (' . e($b['bill_type']) . ')' : ''?></div>
    </div>
    <div style="text-align:right">
      <div><strong>Date issued:</strong> <?=e(substr((string)$b['created_at'], 0, 10))?></div>
      <div><strong>Due date:</strong> <?=e(substr((string)$b['due_date'], 0, 10) ?: '—')?></div>
      <div><strong>Status:</strong> <?=e($disp)?></div>
    </div>
  </div>
  <table>
    <thead><tr><th>Description</th><th class="n">Amount</th></tr></thead>
    <tbody>
      <tr><td>Room rental — Room <?=e($b['room_number'])?> (<?=e($b['billing_period'])?>)</td><td class="n"><?=money($b['room_rental'])?></td></tr>
      <tr><td>Utilities</td><td class="n"><?=money($b['utilities'])?></td></tr>
      <tr><td>Additional charges</td><td class="n"><?=money($b['additional_charges'])?></td></tr>
      <tr><td>Less: Discounts</td><td class="n">(<?=money($b['discounts'])?>)</td></tr>
      <tr><td>Penalties</td><td class="n"><?=money($b['penalties'])?></td></tr>
      <tr class="tot"><td>Total amount due</td><td class="n"><?=money($b['total_amount'])?></td></tr>
      <tr><td>Amount paid</td><td class="n"><?=money($b['amount_paid'])?></td></tr>
      <tr class="tot"><td>Balance</td><td class="n"><?=money($b['balance'])?></td></tr>
    </tbody>
  </table>
  <?php if ($payRows): ?>
  <h4 style="margin:16px 0 6px">Payments received</h4>
  <table>
    <thead><tr><th>Date</th><th>Receipt</th><th>Method</th><th class="n">Amount</th></tr></thead>
    <tbody>
    <?php foreach ($payRows as $p): ?>
      <tr><td><?=e(substr((string)$p['payment_date'], 0, 10))?></td><td><?=e((string)$p['receipt_number'])?></td><td><?=e((string)$p['payment_method'])?></td><td class="n"><?=money($p['amount'])?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
  <p style="font-size:12px;color:#7c6f8a;margin-top:16px">Please pay on or before the due date. Present this invoice number when paying. Thank you for staying at Heart Ladies Dormitory.</p>
</div>
