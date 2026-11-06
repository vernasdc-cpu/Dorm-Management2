<?php
if (($user['role'] ?? '') !== 'resident') {
    http_response_code(403);
    echo '<div class="card"><h3>Forbidden</h3><p>Residents only.</p></div>';
    return;
}
$pdo = db();
$ctx = get_tenant_context($user);
$contracts = [];

try {
    // Try via resident
    if ($ctx['resident']) {
        $st = $pdo->prepare('SELECT c.* FROM contracts c WHERE c.resident_id = ? ORDER BY c.id DESC');
        $st->execute([(int)$ctx['resident']['residents_id']]);
        $contracts = $st->fetchAll();
    }
    // Try via tenant profile
    if (!$contracts && $ctx['tenant_profile']) {
        $st = $pdo->prepare('SELECT c.* FROM contracts c WHERE c.tenant_profile_id = ? ORDER BY c.id DESC');
        $st->execute([(int)$ctx['tenant_profile']['id']]);
        $contracts = $st->fetchAll();
    }
    if ($contracts) {
        $contractIds = array_map(static fn(array $contract): int => (int)$contract['id'], $contracts);
        $placeholders = implode(',', array_fill(0, count($contractIds), '?'));
        $docSt = $pdo->prepare("SELECT id, contract_id, file_name FROM contract_documents WHERE contract_id IN ($placeholders) ORDER BY id");
        $docSt->execute($contractIds);
        $documentsByContract = [];
        foreach ($docSt->fetchAll() as $doc) {
            $documentsByContract[(int)$doc['contract_id']][] = $doc;
        }
    } else {
        $documentsByContract = [];
    }
} catch (Throwable $t) {
    $contracts = [];
    $documentsByContract = [];
}
?>
<div class="card">
  <h3>My Contracts</h3>
  <div style="overflow-x:auto">
  <table class="tbl">
    <thead><tr><th>Contract No.</th><th>Room</th><th>Start</th><th>End</th><th>Rate</th><th>Status</th><th>Document</th></tr></thead>
    <tbody>
    <?php if (!$contracts): ?><tr><td colspan="7">No contracts found.</td></tr><?php endif; ?>
    <?php foreach ($contracts as $c): ?>
      <tr>
        <td><?=e($c['contract_number'])?></td>
        <td><?=e((string)($c['room_id'] ?? ''))?></td>
        <td><?=e($c['start_date'])?></td>
        <td><?=e($c['end_date'])?></td>
        <td><?=money($c['monthly_rate'])?></td>
        <td><?=e($c['status'])?></td>
        <td>
          <?php $contractDocs = $documentsByContract[(int)$c['id']] ?? []; ?>
          <?php if ($contractDocs): ?>
            <?php foreach ($contractDocs as $doc): ?>
              <a class="btn ghost sm" href="index.php?page=download&amp;type=contract&amp;id=<?=(int)$doc['id']?>"><?=e($doc['file_name'])?></a>
            <?php endforeach; ?>
          <?php else: ?>—<?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
