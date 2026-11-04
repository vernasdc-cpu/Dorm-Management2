<?php
if (!in_array($user['role'] ?? '', ['resident'], true)) {
    http_response_code(403);
    echo '<div class="card"><h3>Forbidden</h3><p>Residents only.</p></div>';
    return;
}
$pdo = db();
$ctx = get_tenant_context($user);
$violations = [];
try {
    $where = '1=0';
    $args = [];
    if ($ctx['tenant_profile']) {
        $where = 'v.tenant_profile_id = ?';
        $args[] = (int)$ctx['tenant_profile']['id'];
    }
    if ($ctx['resident']) {
        if ($where === '1=0') { $where = 'v.resident_id = ?'; $args[] = (int)$ctx['resident']['residents_id']; }
        else { $where .= ' OR v.resident_id = ?'; $args[] = (int)$ctx['resident']['residents_id']; }
    }
    if ($where === '1=0') { $where = 'v.user_id = ?'; $args[] = (int)$user['id']; }
    $st = $pdo->prepare("SELECT v.*, b.invoice_number FROM violations v LEFT JOIN bills b ON b.id = v.bill_id WHERE $where ORDER BY v.violation_date DESC, v.id DESC");
    $st->execute($args);
    $violations = $st->fetchAll();
} catch (Throwable $t) {
    $violations = [];
}
?>
<div class="card">
  <h3>My Violations</h3>
  <div style="overflow-x:auto">
  <table class="tbl">
    <thead><tr><th>Ref</th><th>Date</th><th>Category</th><th>Description</th><th>Penalty</th><th>Status</th><th>Bill</th><th>Notes</th></tr></thead>
    <tbody>
    <?php if (!$violations): ?><tr><td colspan="8">No violations found.</td></tr><?php endif; ?>
    <?php foreach ($violations as $v): ?>
      <tr>
        <td><?=e($v['violation_ref']??'')?></td>
        <td><?=e($v['violation_date'])?></td>
        <td><?=e($v['category'])?></td>
        <td><?=nl2br(e($v['description']))?></td>
        <td><?=money($v['penalty_amount'])?></td>
        <td><?=e($v['status'])?></td>
        <td><?= $v['invoice_number'] ? '<a href="index.php?page=invoice&id='.(int)$v['bill_id'].'">'.e($v['invoice_number']).'</a>' : '—' ?></td>
        <td><?= !empty($v['tenant_visible_notes']) ? nl2br(e($v['tenant_visible_notes'])) : '—' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
