<?php
// Official Receipt — super_admin, admin_staff, resident (resident: own payments only). Inner content; $user available.
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { echo '<div class="card"><p>Invalid receipt.</p><p><a class="btn ghost sm" href="javascript:history.back()">Back</a></p></div>'; return; }
try {
    $st = db()->prepare(
        "SELECT p.*, b.billing_period, b.invoice_number, u.name AS resident_name, rm.room_number,
                r.user_id AS owner_uid, r.residents_id, rv.name AS received_by_name
         FROM payments p
         JOIN bills b ON b.id = p.bill_id
         JOIN residents r ON r.residents_id = p.resident_id
         JOIN users u ON u.id = r.user_id
         JOIN rooms rm ON rm.id = b.room_id
         LEFT JOIN users rv ON rv.id = p.received_by
         WHERE p.id = ? LIMIT 1"
    );
    $st->execute([$id]);
    $p = $st->fetch();
    if (!$p) { http_response_code(404); echo '<div class="card"><p>Receipt not found.</p></div>'; return; }
    if (($user['role'] ?? '') === 'resident') {
        $ok = false;
        if ((int)$p['owner_uid'] === (int)$user['id']) $ok = true;
        if (!$ok) {
            http_response_code(403); echo '<div class="card"><h3>Forbidden</h3><p>You may only view your own receipts.</p></div>'; return;
        }
    }
} catch (Throwable $t) {
    echo '<div class="alert error">' . e('Failed to load receipt: ' . $t->getMessage()) . '</div>'; return;
}
?>
<style>
.rc{max-width:640px;margin:0 auto;background:#fff;border:2px solid #7c3aed;border-radius:12px;padding:28px;color:#2b2140}
.rc-head{display:flex;gap:14px;align-items:center;border-bottom:2px dashed #7c3aed;padding-bottom:12px;margin-bottom:14px}
.rc-head img{width:56px;height:56px;object-fit:cover;border-radius:10px}
.rc table{width:100%;border-collapse:collapse;margin-top:8px}
.rc td{padding:7px 6px;font-size:14px;border-bottom:1px solid #eee}
.rc td.k{color:#7c6f8a;width:180px}
@media print{.no-print{display:none!important}body{background:#fff}.rc{border:1px solid #000;border-radius:0;max-width:none}}
</style>
<div class="no-print" style="margin-bottom:10px;display:flex;gap:8px">
  <button class="btn sm" onclick="window.print()">Print</button>
  <a class="btn ghost sm" href="<?=($user['role'] ?? '') === 'resident' ? 'index.php?page=my_billing' : 'index.php?page=billing'?>">Back</a>
</div>
<div class="rc">
  <div class="rc-head">
    <img src="assets/img/logo.jpg" alt="logo">
    <div>
      <div style="font-weight:800;font-size:18px">Heart Ladies Dormitory</div>
      <div style="font-size:12px;color:#7c6f8a">Ladies Dormitory Management System</div>
    </div>
    <div style="margin-left:auto;text-align:right">
      <div style="font-weight:800;letter-spacing:2px">OFFICIAL RECEIPT</div>
      <div><strong><?=e((string)$p['receipt_number'])?></strong></div>
    </div>
  </div>
  <table>
    <tr><td class="k">Received from</td><td><strong><?=e($p['resident_name'])?></strong></td></tr>
    <tr><td class="k">Room</td><td><?=e($p['room_number'])?></td></tr>
    <tr><td class="k">Billing period</td><td><?=e($p['billing_period'])?> (<?=e((string)$p['invoice_number'])?>)</td></tr>
    <tr><td class="k">Amount received</td><td style="font-size:20px;font-weight:800"><?=money($p['amount'])?></td></tr>
    <tr><td class="k">Payment method</td><td><?=e((string)$p['payment_method'])?></td></tr>
    <tr><td class="k">Reference no.</td><td><?=e((string)$p['reference_no'] ?: '—')?></td></tr>
    <tr><td class="k">Payment date</td><td><?=e(substr((string)$p['payment_date'], 0, 10))?></td></tr>
    <tr><td class="k">Remarks</td><td><?=e((string)$p['remarks'] ?: '—')?></td></tr>
    <tr><td class="k">Received by</td><td><?=e((string)($p['received_by_name'] ?? ''))?></td></tr>
  </table>
  <p style="font-size:12px;color:#7c6f8a;margin-top:14px">This serves as proof of payment. Keep this receipt for your records.</p>
</div>
