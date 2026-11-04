<?php
// One-time installer: creates schema, seed data and demo accounts.
// Run:  docker compose exec app php scripts/install.php
// Or via browser once: https://host/install.php (delete afterwards in production)
require_once __DIR__ . '/../config/app.php';

$host = DB_HOST; $port = DB_PORT; $user = DB_USER; $pass = DB_PASS; $name = DB_NAME;

$pdo = new PDO("mysql:host=$host;port=$port;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

function runSql(PDO $pdo, string $file): void {
    $sql = file_get_contents($file);
    // split on ";\n" boundaries is risky with procedures; our files have plain statements
    $stmts = array_filter(array_map('trim', explode(";\n", $sql)));
    foreach ($stmts as $s) { if ($s !== '') $pdo->exec($s); }
    echo "OK: $file\n";
}

runSql($pdo, __DIR__ . '/../database/01-schema.sql');

$pdo->exec("USE `$name`");

require __DIR__ . '/migrate.php';

// Seed only on a fresh database. Re-running 02-seed.sql would duplicate
// rows (inventory_items has no unique key), so guard it.
$roomCount = (int)$pdo->query('SELECT COUNT(*) FROM rooms')->fetchColumn();
if ($roomCount === 0) {
    runSql($pdo, __DIR__ . '/../database/02-seed.sql');
} else {
    echo "Seed already applied, skipping database/02-seed.sql\n";
}

// Backfill rooms inserted by the seed after the initial schema migrations.
runSql($pdo, __DIR__ . '/../database/14-units.sql');

// Demo users (passwords hashed at install time)
$demo = [
    ['Super Admin', 'superadmin@heartdorm.com', 'Super@123', 'super_admin', '09170000001'],
    ['Admin Staff John', 'admin@heartdorm.com', 'Admin@123', 'admin_staff', '09170000002'],
    ['Housekeeping Ana', 'housekeeping@heartdorm.com', 'House@123', 'housekeeping', '09170000003'],
    
];

$userIds = [];
foreach ($demo as [$nm, $em, $pw, $role, $ph]) {
    $chk = $pdo->prepare('SELECT id FROM users WHERE email=?');
    $chk->execute([$em]);
    $id = $chk->fetchColumn();
    if (!$id) {
        $st = $pdo->prepare('INSERT INTO users (name,email,password_hash,role,phone,status) VALUES (?,?,?,?,?,?)');
        $st->execute([$nm, $em, password_hash($pw, PASSWORD_DEFAULT), $role, $ph, 'active']);
        $id = $pdo->lastInsertId();
        echo "Created user $em\n";
    } else { echo "Exists user $em\n"; }
    $userIds[$em] = (int)$id;
}

// Residents for the 3 resident users
$resData = [
    'maria@heartdorm.com' => ['room'=>2,'bed'=>'B1','addr'=>'Quezon City','emg'=>'Santos Family','emgph'=>'09171111111','in'=>'2025-08-01'],
    'jenny@heartdorm.com' => ['room'=>2,'bed'=>'B2','addr'=>'Makati City','emg'=>'Cruz Family','emgph'=>'09172222222','in'=>'2025-08-05'],
    'rose@heartdorm.com'  => ['room'=>5,'bed'=>'B1','addr'=>'Pasig City','emg'=>'Villanueva Family','emgph'=>'09173333333','in'=>'2025-09-01'],
];
foreach ($resData as $em => $r) {
    $uid = $userIds[$em];
    $chk = $pdo->prepare('SELECT residents_id, room_id FROM residents WHERE user_id=?');
    $chk->execute([$uid]);
    $row = $chk->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        $st = $pdo->prepare("INSERT INTO residents (user_id,room_id,bed_no,address,emergency_contact,emergency_phone,move_in_date,status) VALUES (?,?,?,?,?,?,?,'active')");
        $st->execute([$uid, $r['room'], $r['bed'], $r['addr'], $r['emg'], $r['emgph'], $r['in']]);
        $rid = (int)$pdo->lastInsertId();
        echo "Created resident $em\n";
    } else { $rid = (int)$row['residents_id']; }
    // allocation + bed occupancy
    $chk2 = $pdo->prepare('SELECT id FROM room_allocations WHERE resident_id=? AND status=?');
    $chk2->execute([$rid, 'active']);
    if (!$chk2->fetchColumn()) {
        $pdo->prepare("INSERT INTO room_allocations (resident_id,room_id,bed_no,status,allocated_by,remarks) VALUES (?,?,?,'active',?,'Seed allocation')")
            ->execute([$rid, $r['room'], $r['bed'], $userIds['admin@heartdorm.com']]);
        $pdo->prepare('UPDATE beds SET status=?, resident_id=? WHERE room_id=? AND bed_number=?')->execute(['occupied', $rid, $r['room'], $r['bed']]);
        // refresh room status
        $occ = $pdo->prepare('SELECT COUNT(*) FROM beds WHERE room_id=? AND status=?');
        $occ->execute([$r['room'], 'occupied']); $o = (int)$occ->fetchColumn();
        $mx = $pdo->query('SELECT max_occupants FROM rooms WHERE id='.$r['room'])->fetchColumn();
        $st = $o == 0 ? 'Available' : ($o >= $mx ? 'Fully Occupied' : 'Partially Occupied');
        $pdo->prepare('UPDATE rooms SET status=? WHERE id=?')->execute([$st, $r['room']]);
    }
}

// Sample contracts (one expiring soon: 7 days from today)
$residents = $pdo->query('SELECT residents_id, room_id FROM residents')->fetchAll(PDO::FETCH_ASSOC);
$adminId = $userIds['admin@heartdorm.com'];
foreach ($residents as $i => $res) {
    $chk = $pdo->prepare('SELECT id FROM contracts WHERE resident_id=?');
    $chk->execute([$res['residents_id']]);
    if ($chk->fetchColumn()) continue;
    $rate = $pdo->query('SELECT monthly_rate FROM rooms WHERE id='.(int)$res['room_id'])->fetchColumn() ?: 3500;
    $start = $i === 0 ? date('Y-m-d', strtotime('-11 months')) : date('Y-m-d', strtotime('-2 months'));
    $end   = $i === 0 ? date('Y-m-d', strtotime('+7 days'))     : date('Y-m-d', strtotime('+10 months'));
    $status = $i === 0 ? 'Active' : 'Active';
    $cno = 'CTR-' . date('Y') . '-' . str_pad((string)(1001 + $i), 4, '0', STR_PAD_LEFT);
    $pdo->prepare('INSERT INTO contracts (contract_number,resident_id,room_id,start_date,end_date,monthly_rate,status,terms,created_by) VALUES (?,?,?,?,?,?,?,?,?)')
        ->execute([$cno, $res['residents_id'], $res['room_id'], $start, $end, $rate, $status, 'Standard 12-month dormitory contract.', $adminId]);
    echo "Created contract $cno\n";
}

// Sample bills + payments for current month
$period = date('Y-m');
foreach ($residents as $i => $res) {
    $chk = $pdo->prepare('SELECT id,total_amount,amount_paid FROM bills WHERE resident_id=? AND billing_period=?');
    $chk->execute([$res['residents_id'], $period]);
    $bill = $chk->fetch(PDO::FETCH_ASSOC);
    if (!$bill) {
        $rate = $pdo->query('SELECT monthly_rate FROM rooms WHERE id='.(int)$res['room_id'])->fetchColumn() ?: 3500;
        $util = 500; $add = 0; $disc = 0; $pen = 0;
        $total = $rate + $util + $add - $disc + $pen;
        $paid = $i === 0 ? $total : ($i === 1 ? 1000 : 0);
        $bal = $total - $paid;
        $pstat = $bal <= 0 ? 'Paid' : ($paid > 0 ? 'Partially Paid' : 'Unpaid');
        $inv = 'INV-' . str_replace('-','', $period) . '-' . str_pad((string)(2001+$i),4,'0',STR_PAD_LEFT);
        $pdo->prepare("INSERT INTO bills (resident_id,room_id,billing_period,room_rental,utilities,additional_charges,discounts,penalties,total_amount,amount_paid,balance,due_date,payment_status,invoice_number,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([$res['residents_id'], $res['room_id'], $period, $rate, $util, $add, $disc, $pen, $total, $paid, $bal, date('Y-m-15'), $pstat, $inv, $adminId]);
        $bid = (int)$pdo->lastInsertId();
        echo "Created bill $inv\n";
        if ($paid > 0) {
            $rcp = 'OR-' . str_replace('-','', $period) . '-' . str_pad((string)(3001+$i),4,'0',STR_PAD_LEFT);
            $pdo->prepare('INSERT INTO payments (bill_id,resident_id,amount,payment_method,payment_date,reference_no,receipt_number,received_by) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([$bid, $res['residents_id'], $paid, 'Cash', date('Y-m-d'), 'REF'.$bid, $rcp, $adminId]);
            echo "Created payment $rcp\n";
        }
    }
}

// Sample repair requests
$firstRes = $residents[0]['residents_id'] ?? null;
if ($firstRes) {
    $c = $pdo->query('SELECT COUNT(*) FROM repair_requests')->fetchColumn();
    if ((int)$c === 0) {
        $hk = $userIds['housekeeping@heartdorm.com'];
        $pdo->prepare("INSERT INTO repair_requests (report_id,resident_id,room_id,category,description,priority,status,assigned_staff_id) VALUES ('REP-0001',?,?, 'Aircon problem','Aircon not cooling properly in Room 302','High','In Progress',?)")
            ->execute([$firstRes, 2, $hk]);
        $pdo->prepare("INSERT INTO repair_requests (report_id,resident_id,room_id,category,description,priority,status) VALUES ('REP-0002',?,?, 'Plumbing problem','Leaking faucet in common bathroom','Medium','Reported')")
            ->execute([$firstRes, 2]);
        echo "Created sample repairs\n";
    }
}

// Sample notifications
if ((int)$pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn() === 0) {
    $pdo->prepare("INSERT INTO notifications (title,message,sender_id,recipient_role,priority) VALUES (?,?,?,NULL,?)")
        ->execute(['Welcome to Heart Ladies Dormitory','Please keep common areas clean. Curfew is 10:00 PM.', $adminId, 'Normal']);
    foreach ($userIds as $em => $uid) {
        if (strpos($em, '@heartdorm.com') !== false && !in_array($em, ['superadmin@heartdorm.com','admin@heartdorm.com'], true)) {
            $pdo->prepare('INSERT INTO notifications (title,message,sender_id,recipient_id,priority) VALUES (?,?,?,?,?)')
                ->execute(['Payment Reminder','Your bill for '.$period.' is now available. Please pay on or before the 15th.', $adminId, $uid, 'Important']);
        }
    }
    echo "Created sample notifications\n";
}

// Inventory transaction samples
if ((int)$pdo->query('SELECT COUNT(*) FROM inventory_transactions')->fetchColumn() === 0) {
    $pdo->prepare("INSERT INTO inventory_transactions (item_id,type,quantity,performed_by,remarks) VALUES (11,'Stock Out',10,?, 'Issued to utility room')")->execute([$adminId]);
    echo "Created sample inventory transactions\n";
}

echo "DONE. Demo logins: superadmin@heartdorm.com / Super@123, admin@heartdorm.com / Admin@123, housekeeping@heartdorm.com / House@123, maria@heartdorm.com / Resident@123\n";
