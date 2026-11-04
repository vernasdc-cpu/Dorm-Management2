<?php
// pages/reports.php — printable report cards + CSV export (?report=X&export=csv). Included AFTER layout_header.
$defs = [
  'occupancy'   => 'Room Occupancy',
  'vacant'      => 'Vacant Rooms',
  'residents'   => 'Resident List',
  'contracts'   => 'Contract Expiration',
  'billing'     => 'Monthly Billing',
  'collection'  => 'Payment Collection',
  'outstanding' => 'Outstanding Balances',
  'inventory'   => 'Inventory',
  'lowstock'    => 'Low-Stock Items',
  'repairs'     => 'Repairs & Maintenance History',
];
$rep = $_GET['report'] ?? '';
if ($rep !== '' && !isset($defs[$rep])) $rep = '';
$period = preg_match('/^\d{4}-\d{2}$/', $_GET['period'] ?? '') ? $_GET['period'] : date('Y-m');

// Fetch report data (prepared queries, simple)
function report_data(string $rep, string $period): array {
  switch ($rep) {
    case 'occupancy':
      return [ ['Room','Floor','Type','Aircon','Capacity','Occupied','Status'],
        db()->query("SELECT r.room_number,f.name fl,t.name tp,r.aircon,r.max_occupants,(SELECT COUNT(*) FROM beds b WHERE b.room_id=r.id AND b.status='occupied') occ,r.status FROM rooms r JOIN floors f ON f.id=r.floor_id JOIN room_types t ON t.id=r.room_type_id ORDER BY r.room_number")->fetchAll(PDO::FETCH_NUM) ];
    case 'vacant':
      return [ ['Room','Floor','Type','Aircon','Rate','Status'],
        db()->query("SELECT r.room_number,f.name,t.name,r.aircon,r.monthly_rate,r.status FROM rooms r JOIN floors f ON f.id=r.floor_id JOIN room_types t ON t.id=r.room_type_id WHERE r.status IN ('Available','Partially Occupied') ORDER BY r.room_number")->fetchAll(PDO::FETCH_NUM) ];
    case 'residents':
      return [ ['Name','Email','Phone','Room','Bed','Status'],
        db()->query("SELECT u.name,u.email,u.phone,rm.room_number,res.bed_no,res.status FROM residents res JOIN users u ON u.id=res.user_id LEFT JOIN rooms rm ON rm.id=res.room_id WHERE u.deleted_at IS NULL ORDER BY u.name")->fetchAll(PDO::FETCH_NUM) ];
    case 'contracts':
      $s = db()->prepare("SELECT c.contract_number,COALESCE(tp.full_name,u.name) AS tenant_name,rm.room_number,c.start_date,c.end_date,c.monthly_rate,c.status FROM contracts c LEFT JOIN tenant_profiles tp ON tp.id=c.tenant_profile_id LEFT JOIN residents res ON res.residents_id=c.resident_id LEFT JOIN users u ON u.id=res.user_id JOIN rooms rm ON rm.id=c.room_id WHERE c.status NOT IN ('Expired','Terminated') ORDER BY c.end_date LIMIT 200");
      $s->execute();
      return [ ['Contract #','Tenant','Room','Start','End','Rate','Status'], $s->fetchAll(PDO::FETCH_NUM) ];
    case 'billing': {
      $s = db()->prepare("SELECT b.billing_period,u.name,rm.room_number,b.total_amount,b.amount_paid,b.balance,b.payment_status FROM bills b JOIN residents res ON res.residents_id=b.resident_id JOIN users u ON u.id=res.user_id JOIN rooms rm ON rm.id=b.room_id WHERE b.billing_period=? ORDER BY rm.room_number");
      $s->execute([$period]);
      return [ ['Period','Resident','Room','Total','Paid','Balance','Status'], $s->fetchAll(PDO::FETCH_NUM) ];
    }
    case 'collection': {
      $s = db()->prepare("SELECT p.payment_date,p.receipt_number,u.name,b.billing_period,p.amount,p.payment_method FROM payments p JOIN residents res ON res.residents_id=p.resident_id JOIN users u ON u.id=res.user_id JOIN bills b ON b.id=p.bill_id WHERE DATE_FORMAT(p.payment_date,'%Y-%m')=? ORDER BY p.payment_date");
      $s->execute([$period]);
      return [ ['Date','Receipt','Resident','Period','Amount','Method'], $s->fetchAll(PDO::FETCH_NUM) ];
    }
    case 'outstanding':
      return [ ['Resident','Room','Period','Total','Paid','Balance'],
        db()->query("SELECT u.name,rm.room_number,b.billing_period,b.total_amount,b.amount_paid,b.balance FROM bills b JOIN residents res ON res.residents_id=b.resident_id JOIN users u ON u.id=res.user_id JOIN rooms rm ON rm.id=b.room_id WHERE b.balance>0 ORDER BY b.balance DESC LIMIT 200")->fetchAll(PDO::FETCH_NUM) ];
    case 'inventory':
      return [ ['Item','Category','Available','Assigned','Damaged','Min','Location'],
        db()->query("SELECT i.name,c.name,i.available_qty,i.assigned_qty,i.damaged_qty,i.min_stock,i.location FROM inventory_items i JOIN inventory_categories c ON c.id=i.category_id ORDER BY i.name")->fetchAll(PDO::FETCH_NUM) ];
    case 'lowstock':
      return [ ['Item','Available','Min','Location'],
        db()->query("SELECT name,available_qty,min_stock,location FROM inventory_items WHERE available_qty<=min_stock ORDER BY available_qty")->fetchAll(PDO::FETCH_NUM) ];
    case 'repairs':
      return [ ['Report #','Resident','Room','Category','Priority','Status','Reported','Resolved'],
        db()->query("SELECT rr.report_id,u.name,rm.room_number,rr.category,rr.priority,rr.status,rr.date_reported,COALESCE(rr.date_resolved,'—') FROM repair_requests rr JOIN residents res ON res.residents_id=rr.resident_id JOIN users u ON u.id=res.user_id JOIN rooms rm ON rm.id=rr.room_id ORDER BY rr.id DESC LIMIT 200")->fetchAll(PDO::FETCH_NUM) ];
  }
  return [[], []];
}

// CSV export: layout already sent HTML, so attempt download only if headers still sendable;
// otherwise fall back to an on-page CSV preview with a JS download button.
if ($rep !== '' && ($_GET['export'] ?? '') === 'csv') {
  try {
    [$h, $rows] = report_data($rep, $period);
    $fh = fopen('php://temp', 'r+');
    fputcsv($fh, $h);
    foreach ($rows as $r) fputcsv($fh, $r);
    rewind($fh); $csv = stream_get_contents($fh); fclose($fh);
    if (!headers_sent()) {
      header('Content-Type: text/csv'); header('Content-Disposition: attachment; filename="' . $rep . '-' . $period . '.csv"');
      echo $csv; exit;
    }
    echo '<div class="card"><h3>CSV ready — ' . e($defs[$rep]) . '</h3><p>Automatic download was blocked (page already rendered). Use the button:</p>';
    echo '<button class="btn sm" onclick="dlCsv()">Download CSV</button> <a class="btn sm ghost" href="index.php?page=reports&report=' . e($rep) . '">Back</a>';
    echo '<textarea id="csvbox" rows="12" style="width:100%;margin-top:8px">' . e($csv) . '</textarea></div>';
    echo "<script>function dlCsv(){var t=document.getElementById('csvbox').value;var b=new Blob([t],{type:'text/csv'});var a=document.createElement('a');a.href=URL.createObjectURL(b);a.download='" . e($rep) . '-' . e($period) . ".csv';a.click();}</script>";
  } catch (Throwable $t) { echo '<div class="alert error">' . e($t->getMessage()) . '</div>'; }
  if ($rep !== '') { /* fall through to table view below */ }
}
?>
<div class="card"><h3>📊 Reports</h3>
  <p style="display:flex;gap:8px;flex-wrap:wrap">
  <?php foreach ($defs as $k => $label): ?>
    <a class="btn sm <?= $rep === $k ? '' : 'ghost' ?>" href="index.php?page=reports&report=<?= e($k) ?>"><?= e($label) ?></a>
  <?php endforeach; ?></p>
  <?php if (in_array($rep, ['billing', 'collection'], true)): ?>
    <form method="get" action="index.php" style="margin-top:8px">Period (YYYY-MM):
      <input type="hidden" name="page" value="reports"><input type="hidden" name="report" value="<?= e($rep) ?>">
      <input name="period" value="<?= e($period) ?>" pattern="\d{4}-\d{2}" style="width:100px"> <button class="btn sm">Go</button></form>
  <?php endif; ?>
</div>

<?php if ($rep !== '' && ($_GET['export'] ?? '') !== 'csv'): ?>
<?php try { [$headers, $rows] = report_data($rep, $period); } catch (Throwable $t) { $headers = []; $rows = []; echo '<div class="alert error">' . e($t->getMessage()) . '</div>'; } ?>
<div class="card"><h3><?= e($defs[$rep]) ?> (<?= count($rows) ?> rows)</h3>
  <p><button class="btn sm ghost" onclick="window.print()">🖨 Print</button>
  <a class="btn sm ghost" href="index.php?page=reports&report=<?= e($rep) ?>&period=<?= e($period) ?>&export=csv">⬇ CSV</a></p>
  <div style="overflow-x:auto"><table class="tbl"><tr><?php foreach ($headers as $h): ?><th><?= e($h) ?></th><?php endforeach; ?></tr>
  <?php foreach ($rows as $r): ?><tr><?php foreach ($r as $c): ?><td><?= e((string)$c) ?></td><?php endforeach; ?></tr><?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="<?= max(1, count($headers)) ?>">No records.</td></tr><?php endif; ?></table></div></div>
<?php endif; ?>
