<?php
// Dashboard — role-specific inner content (included after layout_header). Expects $user.
$role = $user['role'] ?? '';
$uid = (int)($user['id'] ?? 0);
$one = function (string $sql, array $p = []) { try { $s = db()->prepare($sql); $s->execute($p); $v = $s->fetchColumn(); return $v === false ? 0 : $v; } catch (Throwable $t) { return 0; } };
$all = function (string $sql, array $p = []) { try { $s = db()->prepare($sql); $s->execute($p); return $s->fetchAll() ?: []; } catch (Throwable $t) { return []; } };
$uno = function (string $sql, array $p = []) { try { $s = db()->prepare($sql); $s->execute($p); $r = $s->fetch(); return $r ?: []; } catch (Throwable $t) { return []; } };
if ($role === 'super_admin'):
$dRes = (int)$one('SELECT COUNT(*) FROM residents');
$dRooms = (int)$one('SELECT COUNT(*) FROM rooms');
$dVacRm = (int)$one("SELECT COUNT(*) FROM rooms WHERE status='Available'");
$dOccRm = (int)$one("SELECT COUNT(*) FROM rooms WHERE status IN ('Partially Occupied','Fully Occupied')");
$dVacBed = (int)$one("SELECT COUNT(*) FROM beds WHERE status='vacant'");
$dOccBed = (int)$one("SELECT COUNT(*) FROM beds WHERE status='occupied'");
$dActC = (int)$one("SELECT COUNT(*) FROM contracts WHERE status='Active'");
$dExpC = (int)$one("SELECT COUNT(*) FROM contracts WHERE end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 30 DAY) AND status NOT IN ('Expired','Terminated')");
$dOut = (float)$one("SELECT COALESCE(SUM(balance),0) FROM bills WHERE payment_status!='Paid'");
$dInv = (int)$one('SELECT COUNT(*) FROM inventory_items');
$dLow = (int)$one('SELECT COUNT(*) FROM inventory_items WHERE COALESCE(available_qty,quantity) < min_stock');
$dOpenR = (int)$one("SELECT COUNT(*) FROM repair_requests WHERE status NOT IN ('Completed','Cancelled')");
$occPct = $dRooms ? round($dOccRm / $dRooms * 100) : 0;
$incRows = $all("SELECT DATE_FORMAT(payment_date,'%Y-%m') m, COALESCE(SUM(amount),0) s FROM payments WHERE payment_date >= DATE_SUB(CURDATE(),INTERVAL 6 MONTH) GROUP BY m ORDER BY m");
$incMap = []; foreach ($incRows as $r) $incMap[$r['m']] = (float)$r['s'];
$incL = []; $incD = []; for ($i = 5; $i >= 0; $i--) { $k = date('Y-m', strtotime("-$i month")); $incL[] = $k; $incD[] = $incMap[$k] ?? 0; }
$payRows = $all('SELECT payment_status s, COUNT(*) c FROM bills GROUP BY payment_status');
$repRows = $all('SELECT status s, COUNT(*) c FROM repair_requests GROUP BY status');
$expList = $all("SELECT c.*, COALESCE(tp.full_name, u.name) AS tenant_name, r.room_number FROM contracts c LEFT JOIN tenant_profiles tp ON tp.id=c.tenant_profile_id LEFT JOIN residents rs ON rs.residents_id=c.resident_id LEFT JOIN users u ON u.id=rs.user_id JOIN rooms r ON r.id=c.room_id WHERE c.end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 30 DAY) AND c.status NOT IN ('Expired','Terminated') ORDER BY c.end_date LIMIT 8");
$lowList = $all('SELECT id,name,COALESCE(available_qty,quantity) q,min_stock FROM inventory_items WHERE COALESCE(available_qty,quantity) < min_stock ORDER BY q LIMIT 8');
$repList = $all('SELECT rr.*, u.name res_name, r.room_number FROM repair_requests rr LEFT JOIN residents rs ON rs.residents_id=rr.resident_id LEFT JOIN users u ON u.id=rs.user_id LEFT JOIN rooms r ON r.id=rr.room_id WHERE rr.status NOT IN (\'Completed\',\'Cancelled\') ORDER BY rr.date_reported DESC LIMIT 6');
?>
<h2>Super Admin Dashboard</h2>
<div class="grid g4">
<div class="card stat"><b><?=e($dRes)?></b><span>Total Residents</span></div>
<div class="card stat"><b><?=e($dRooms)?></b><span>Total Rooms</span></div>
<div class="card stat"><b><?=e($dVacRm)?></b><span>Vacant Rooms</span></div>
<div class="card stat"><b><?=e($dOccRm)?></b><span>Occupied Rooms</span></div>
<div class="card stat"><b><?=e($dVacBed)?></b><span>Available Beds</span></div>
<div class="card stat"><b><?=e($dActC)?></b><span>Active Contracts</span></div>
<div class="card stat"><b><?=e($dExpC)?></b><span>Expiring ≤30 days</span></div>
<div class="card stat"><b><?=e(money($dOut))?></b><span>Outstanding Payments</span></div>
<div class="card stat"><b><?=e($dInv)?></b><span>Inventory Items</span></div>
<div class="card stat"><b><?=e($dLow)?></b><span>Low-Stock Items</span></div>
<div class="card stat"><b><?=e($dOpenR)?></b><span>Open Repairs</span></div>
<div class="card stat"><b><?=e($occPct)?>%</b><span>Room Occupancy</span></div>
</div>
<div class="card" style="margin-top:14px"><h3>Room Occupancy — <?=e($dOccRm)?>/<?=e($dRooms)?> (<?=e($occPct)?>%)</h3>
<div style="background:#f3e8ff;border-radius:8px;height:14px;overflow:hidden"><div style="width:<?=e($occPct)?>%;height:100%;background:linear-gradient(90deg,#a855f7,#ec4899)"></div></div></div>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<div class="grid g2" style="margin-top:14px">
<div class="card"><h3>Beds: Occupied vs Vacant</h3><canvas id="chOcc" height="180"></canvas></div>
<div class="card"><h3>Monthly Income (6 mo)</h3><canvas id="chInc" height="180"></canvas></div>
<div class="card"><h3>Bills by Payment Status</h3><canvas id="chPay" height="180"></canvas></div>
<div class="card"><h3>Repairs by Status</h3><canvas id="chRep" height="180"></canvas></div>
</div>
<script>
try{
new Chart(document.getElementById('chOcc'),{type:'doughnut',data:{labels:['Occupied','Vacant'],datasets:[{data:<?=json_encode([$dOccBed,$dVacBed])?>}]}});
new Chart(document.getElementById('chInc'),{type:'bar',data:{labels:<?=json_encode($incL)?>,datasets:[{data:<?=json_encode($incD)?>}]},options:{plugins:{legend:{display:false}}}});
new Chart(document.getElementById('chPay'),{type:'pie',data:{labels:<?=json_encode(array_column($payRows,'s'))?>,datasets:[{data:<?=json_encode(array_map('intval',array_column($payRows,'c')))?>}]}});
new Chart(document.getElementById('chRep'),{type:'bar',data:{labels:<?=json_encode(array_column($repRows,'s'))?>,datasets:[{data:<?=json_encode(array_map('intval',array_column($repRows,'c')))?>}]},options:{plugins:{legend:{display:false}}}});
}catch(e){}
</script>
<div class="grid g2" style="margin-top:14px">
<div class="card"><h3>Contracts Expiring Soon</h3>
<table class="tbl"><tr><th>Tenant</th><th>Room</th><th>Days Left</th><th></th></tr>
<?php foreach ($expList as $c): $dr = (int)$one('SELECT DATEDIFF(?,CURDATE())', [$c['end_date']]); ?>
<tr><td><?=e($c['tenant_name'] ?? '—')?></td><td><?=e($c['room_number'])?></td><td><span class="pill warn"><?=e($dr)?>d</span></td><td><a href="index.php?page=notifications">Contact</a></td></tr>
<?php endforeach; if (!$expList) echo '<tr><td colspan="4">None expiring.</td></tr>'; ?></table></div>
<div class="card"><h3>Low-Stock Alerts</h3>
<?php foreach ($lowList as $it): ?><div class="card" style="margin-bottom:8px;border-left:5px solid #dc2626"><b><?=e($it['name'])?></b><br><span class="pill bad">BLEACH · <?=e($it['q'])?> left / min <?=e($it['min_stock'])?></span> <a href="index.php?page=inventory">Restock</a></div>
<?php endforeach; if (!$lowList) echo '<p>All stocks OK.</p>'; ?></div>
</div>
<div class="card" style="margin-top:14px"><h3>Recent Open Repairs</h3>
<table class="tbl"><tr><th>#</th><th>Resident</th><th>Room</th><th>Priority</th><th>Status</th></tr>
<?php foreach ($repList as $r): ?><tr><td><?=e($r['id'])?></td><td><?=e($r['res_name'] ?? '-')?></td><td><?=e($r['room_number'] ?? '-')?></td><td><span class="pill <?=e($r['priority']==='Emergency'?'bad':($r['priority']==='High'?'warn':'info'))?>"><?=e($r['priority'])?></span></td><td><?=e($r['status'])?></td></tr>
<?php endforeach; if (!$repList) echo '<tr><td colspan="5">No open repairs.</td></tr>'; ?></table></div>
<?php elseif ($role === 'admin_staff'):
$nRes = (int)$one('SELECT COUNT(*) FROM residents WHERE status=\'active\'');
$nRm = (int)$one('SELECT COUNT(*) FROM rooms'); $nOcc = (int)$one("SELECT COUNT(*) FROM rooms WHERE status IN ('Partially Occupied','Fully Occupied')");
$nPct = $nRm ? round($nOcc / $nRm * 100) : 0;
$nAct = (int)$one("SELECT COUNT(*) FROM contracts WHERE status='Active'");
$nOut = (float)$one("SELECT COALESCE(SUM(balance),0) FROM bills WHERE payment_status!='Paid'");
$nRep = (int)$one("SELECT COUNT(*) FROM repair_requests WHERE status NOT IN ('Completed','Cancelled')");
$nUnr = (int)$one('SELECT COUNT(*) FROM notifications WHERE recipient_id=? AND is_read=0', [$uid]);
$bills = $all('SELECT b.*, u.name rn FROM bills b LEFT JOIN residents rs ON rs.residents_id=b.resident_id LEFT JOIN users u ON u.id=rs.user_id ORDER BY b.id DESC LIMIT 8');
$exp = $all("SELECT c.*, COALESCE(tp.full_name, u.name) AS tenant_name, r.room_number FROM contracts c LEFT JOIN tenant_profiles tp ON tp.id=c.tenant_profile_id LEFT JOIN residents rs ON rs.residents_id=c.resident_id LEFT JOIN users u ON u.id=rs.user_id JOIN rooms r ON r.id=c.room_id WHERE c.end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 30 DAY) AND c.status NOT IN ('Expired','Terminated') ORDER BY c.end_date LIMIT 8");
$ops = $all('SELECT rr.*, u.name rn FROM repair_requests rr LEFT JOIN residents rs ON rs.residents_id=rr.resident_id LEFT JOIN users u ON u.id=rs.user_id WHERE rr.status NOT IN (\'Completed\',\'Cancelled\') ORDER BY rr.date_reported DESC LIMIT 8');
?>
<h2>Staff Dashboard</h2>
<div class="grid g3">
<div class="card stat"><b><?=e($nRes)?></b><span>Residents</span></div>
<div class="card stat"><b><?=e($nPct)?>%</b><span>Room Occupancy</span></div>
<div class="card stat"><b><?=e($nAct)?></b><span>Active Contracts</span></div>
<div class="card stat"><b><?=e(money($nOut))?></b><span>Outstanding Balances</span></div>
<div class="card stat"><b><?=e($nRep)?></b><span>Open Repairs</span></div>
<div class="card stat"><b><?=e($nUnr)?></b><span>Unread Notifications</span></div>
</div>
<div class="card" style="margin-top:14px"><h3>Recent Bills</h3>
<table class="tbl"><tr><th>#</th><th>Resident</th><th>Period</th><th>Total</th><th>Balance</th><th>Status</th></tr>
<?php foreach ($bills as $b): ?><tr><td><?=e($b['id'])?></td><td><?=e($b['rn'] ?? '-')?></td><td><?=e($b['billing_period'])?></td><td><?=e(money($b['total_amount']))?></td><td><?=e(money($b['balance']))?></td><td><span class="pill <?=e($b['payment_status']==='Paid'?'ok':($b['payment_status']==='Overdue'?'bad':'warn'))?>"><?=e($b['payment_status'])?></span></td></tr>
<?php endforeach; if (!$bills) echo '<tr><td colspan="6">No bills.</td></tr>'; ?></table></div>
<div class="grid g2" style="margin-top:14px">
<div class="card"><h3>Expiring Contracts</h3><table class="tbl"><tr><th>Tenant</th><th>Room</th><th>End</th></tr>
<?php foreach ($exp as $c): ?><tr><td><?=e($c['tenant_name'] ?? '—')?></td><td><?=e($c['room_number'])?></td><td><?=e($c['end_date'])?></td></tr>
<?php endforeach; if (!$exp) echo '<tr><td colspan="3">None.</td></tr>'; ?></table></div>
<div class="card"><h3>Open Repairs</h3><table class="tbl"><tr><th>#</th><th>Resident</th><th>Priority</th><th>Status</th></tr>
<?php foreach ($ops as $r): ?><tr><td><?=e($r['id'])?></td><td><?=e($r['rn'] ?? '-')?></td><td><?=e($r['priority'])?></td><td><?=e($r['status'])?></td></tr>
<?php endforeach; if (!$ops) echo '<tr><td colspan="4">None.</td></tr>'; ?></table></div>
</div>
<?php elseif ($role === 'housekeeping'):
$tRm = (int)$one('SELECT COUNT(*) FROM rooms');
$tClean = (int)$one("SELECT COUNT(*) FROM rooms WHERE status='Under Maintenance'");
$tLow = (int)$one('SELECT COUNT(*) FROM inventory_items WHERE COALESCE(available_qty,quantity) < min_stock');
$tMy = (int)$one("SELECT COUNT(*) FROM repair_requests WHERE assigned_staff_id=? AND status NOT IN ('Completed','Cancelled')", [$uid]);
$tAll = (int)$one("SELECT COUNT(*) FROM repair_requests WHERE status NOT IN ('Completed','Cancelled')");
$fs = $_GET['rstatus'] ?? '';
$rep = (!$fs || $fs === 'all') ? $all('SELECT rr.*, u.name rn, r.room_number FROM repair_requests rr LEFT JOIN residents rs ON rs.residents_id=rr.resident_id LEFT JOIN users u ON u.id=rs.user_id LEFT JOIN rooms r ON r.id=rr.room_id ORDER BY rr.date_reported DESC LIMIT 20') : $all('SELECT rr.*, u.name rn, r.room_number FROM repair_requests rr LEFT JOIN residents rs ON rs.residents_id=rr.resident_id LEFT JOIN users u ON u.id=rs.user_id LEFT JOIN rooms r ON r.id=rr.room_id WHERE rr.status=? ORDER BY rr.date_reported DESC LIMIT 20', [$fs]);
$low = $all('SELECT name, COALESCE(available_qty,quantity) q, min_stock FROM inventory_items WHERE COALESCE(available_qty,quantity) < min_stock ORDER BY q LIMIT 10');
$rmSt = $all('SELECT status s, COUNT(*) c FROM rooms GROUP BY status');
?>
<h2>Housekeeping Dashboard</h2>
<div class="grid g4">
<div class="card stat"><b><?=e($tRm)?></b><span>Total Rooms</span></div>
<div class="card stat"><b><?=e($tClean)?></b><span>Cleaning Tasks (Maintenance)</span></div>
<div class="card stat"><b><?=e($tLow)?></b><span>Low-Stock Items</span></div>
<div class="card stat"><b><?=e($tMy)?> / <?=e($tAll)?></b><span>My Open / All Open Repairs</span></div>
</div>
<div class="card" style="margin-top:14px"><h3>Repair Requests</h3>
<p><a href="index.php?page=dashboard&rstatus=all">All</a> | <a href="index.php?page=dashboard&rstatus=Reported">Reported</a> | <a href="index.php?page=dashboard&rstatus=Assigned">Assigned</a> | <a href="index.php?page=dashboard&rstatus=In+Progress">In Progress</a> | <a href="index.php?page=repairs">Manage</a></p>
<table class="tbl"><tr><th>#</th><th>Room</th><th>Resident</th><th>Priority</th><th>Status</th></tr>
<?php foreach ($rep as $r): ?><tr><td><?=e($r['id'])?></td><td><?=e($r['room_number'] ?? '-')?></td><td><?=e($r['rn'] ?? '-')?></td><td><span class="pill <?=e($r['priority']==='Emergency'?'bad':'info')?>"><?=e($r['priority'])?></span></td><td><?=e($r['status'])?></td></tr>
<?php endforeach; if (!$rep) echo '<tr><td colspan="5">No requests.</td></tr>'; ?></table></div>
<div class="grid g2" style="margin-top:14px">
<div class="card"><h3>Low Stock</h3><table class="tbl"><tr><th>Item</th><th>Qty</th><th>Min</th></tr>
<?php foreach ($low as $l): ?><tr><td><?=e($l['name'])?></td><td><span class="pill bad"><?=e($l['q'])?></span></td><td><?=e($l['min_stock'])?></td></tr>
<?php endforeach; if (!$low) echo '<tr><td colspan="3">Stocks OK.</td></tr>'; ?></table></div>
<div class="card"><h3>Room Status Overview</h3><table class="tbl"><tr><th>Status</th><th>Count</th></tr>
<?php foreach ($rmSt as $s): ?><tr><td><?=e($s['s'])?></td><td><?=e($s['c'])?></td></tr>
<?php endforeach; if (!$rmSt) echo '<tr><td colspan="2">No rooms.</td></tr>'; ?></table></div>
</div>
<?php else:
$res = $uno('SELECT * FROM residents WHERE user_id=?', [$uid]);
$rid = (int)($res['residents_id'] ?? 0);
$room = $rid ? $uno('SELECT r.room_number, t.name tname FROM residents rs LEFT JOIN rooms r ON r.id=rs.room_id LEFT JOIN room_types t ON t.id=r.room_type_id WHERE rs.residents_id=?', [$rid]) : [];
$ct = $rid ? $uno('SELECT * FROM contracts WHERE resident_id=? ORDER BY end_date DESC LIMIT 1', [$rid]) : [];
// Also check tenant profile contract
if (!$ct) {
    $tp = $uno('SELECT * FROM tenant_profiles WHERE email = ? OR full_name = ? ORDER BY id DESC LIMIT 1', [$user['email'] ?? '', $user['name'] ?? '']);
    if ($tp) {
        $ct = $uno('SELECT * FROM contracts WHERE tenant_profile_id = ? ORDER BY end_date DESC LIMIT 1', [(int)$tp['id']]);
    }
}
$cStat = $ct ? contract_computed_status($ct) : 'No contract';
$cDays = $ct ? (int)$one('SELECT DATEDIFF(?,CURDATE())', [$ct['end_date']]) : 0;
$bal = $rid ? (float)$one("SELECT COALESCE(SUM(balance),0) FROM bills WHERE resident_id=? AND payment_status!='Paid'", [$rid]) : 0;
if ($bal == 0) {
    // try via tenant profile
    try {
        $tp2 = $uno('SELECT * FROM tenant_profiles WHERE email = ? OR full_name = ? ORDER BY id DESC LIMIT 1', [$user['email'] ?? '', $user['name'] ?? '']);
        if ($tp2) {
            $bal = (float)$one("SELECT COALESCE(SUM(b.balance),0) FROM bills b JOIN residents r ON r.residents_id = b.resident_id JOIN users u ON u.id = r.user_id WHERE (LOWER(u.email)=LOWER(?) OR LOWER(u.name)=LOWER(?)) AND b.payment_status!='Paid'", [$tp2['email'] ?? '', $tp2['full_name'] ?? '']);
        }
    } catch (Throwable $t) {}
}
$myRep = $rid ? (int)$one("SELECT COUNT(*) FROM repair_requests WHERE resident_id=? AND status NOT IN ('Completed','Cancelled')", [$rid]) : 0;
$myUnr = (int)$one('SELECT COUNT(*) FROM notifications WHERE recipient_id=? AND is_read=0', [$uid]);
$myBills = $rid ? $all('SELECT * FROM bills WHERE resident_id=? ORDER BY id DESC LIMIT 5', [$rid]) : [];
if (!$myBills) {
    try {
        $tp3 = $uno('SELECT * FROM tenant_profiles WHERE email = ? OR full_name = ? ORDER BY id DESC LIMIT 1', [$user['email'] ?? '', $user['name'] ?? '']);
        if ($tp3) {
            $myBills = $all("SELECT b.* FROM bills b JOIN residents r ON r.residents_id = b.resident_id JOIN users u ON u.id = r.user_id WHERE (LOWER(u.email)=LOWER(?) OR LOWER(u.name)=LOWER(?)) ORDER BY b.id DESC LIMIT 5", [$tp3['email'] ?? '', $tp3['full_name'] ?? '']);
        }
    } catch (Throwable $t) {}
}
$myReps = $rid ? $all('SELECT * FROM repair_requests WHERE resident_id=? ORDER BY date_reported DESC LIMIT 5', [$rid]) : [];
$myNot = $all('SELECT * FROM notifications WHERE recipient_id=? ORDER BY id DESC LIMIT 5', [$uid]);
$annCount = 0;
try {
    $annCount = (int)$one("SELECT COUNT(*) FROM announcements WHERE is_published = 1 AND (published_at IS NULL OR published_at <= NOW())");
} catch (Throwable $t) {}
?>
<h2>My Dashboard</h2>
<div class="grid g6">
<div class="card stat"><b><?=e($room['room_number'] ?? '—')?> <?=e($room['tname'] ?? '')?></b><span>My Room + Type</span></div>
<div class="card stat"><b><?=e($cStat)?> <?=e($ct ? "({$cDays}d left)" : '')?></b><span>My Contract</span></div>
<div class="card stat"><b><?=e(money($bal))?></b><span>My Billing Balance</span></div>
<div class="card stat"><b><?=e($myRep)?></b><span>Open Repair Requests</span></div>
<div class="card stat"><b><?=e($myUnr)?></b><span>Unread Notifications</span></div>
</div>
<div class="card" style="margin-top:14px"><h3>Latest Bills</h3>
<table class="tbl"><tr><th>Period</th><th>Total</th><th>Balance</th><th>Status</th></tr>
<?php foreach ($myBills as $b): ?><tr><td><?=e($b['billing_period'])?></td><td><?=e(money($b['total_amount']))?></td><td><?=e(money($b['balance']))?></td><td><span class="pill <?=e($b['payment_status']==='Paid'?'ok':'warn')?>"><?=e($b['payment_status'])?></span></td></tr>
<?php endforeach; if (!$myBills) echo '<tr><td colspan="4">No bills.</td></tr>'; ?></table></div>
<div class="grid g2" style="margin-top:14px">
<div class="card"><h3>My Repairs</h3><table class="tbl"><tr><th>#</th><th>Status</th><th>Priority</th></tr>
<?php foreach ($myReps as $r): ?><tr><td><?=e($r['id'])?></td><td><?=e($r['status'])?></td><td><?=e($r['priority'])?></td></tr>
<?php endforeach; if (!$myReps) echo '<tr><td colspan="3">None.</td></tr>'; ?></table></div>
<div class="card"><h3>My Notifications</h3><table class="tbl"><tr><th>Title</th><th>Read</th></tr>
<?php foreach ($myNot as $n): ?><tr><td><?=e($n['title'])?></td><td><?=e($n['is_read'] ? 'Yes' : 'No')?></td></tr>
<?php endforeach; if (!$myNot) echo '<tr><td colspan="2">None.</td></tr>'; ?></table></div>
</div>
<?php endif; ?>
