<?php
// Billing & Payments — super_admin, admin_staff (inner content; layout already open; $user available)
if (!in_array($user['role'] ?? '', ['super_admin', 'admin_staff'], true)) {
    http_response_code(403);
    echo '<div class="card"><h3>Forbidden</h3><p>Your role cannot access this page.</p></div>';
    return;
}

$today = date('Y-m-d');
$curYm = date('Y-m');

// ---------- helpers ----------
if (!function_exists('billing_display_status')) {
    function billing_display_status(array $b): string {
        if (($b['payment_status'] ?? '') === 'Paid') return 'Paid';
        $due = substr((string)($b['due_date'] ?? ''), 0, 10);
        if ($due !== '' && $due < date('Y-m-d')) return 'Overdue';
        return (string)($b['payment_status'] ?? 'Unpaid');
    }
}
if (!function_exists('billing_unique_no')) {
    function billing_unique_no(string $prefix, string $ymCompact, string $table, string $col): string {
        for ($i = 0; $i < 30; $i++) {
            $no = $prefix . '-' . $ymCompact . '-' . str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);
            try {
                $st = db()->prepare("SELECT 1 FROM `$table` WHERE `$col` = ? LIMIT 1");
                $st->execute([$no]);
                if (!$st->fetchColumn()) return $no;
            } catch (Throwable $t) { return $no; }
        }
        return $prefix . '-' . $ymCompact . '-' . str_pad((string)random_int(0, 99999), 5, '0', STR_PAD_LEFT);
    }
}

// ---------- POST handlers ----------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    // --- create individual new bill ---
    if ($action === 'create_bill') {
        try {
            $assignee = trim((string)($_POST['resident_id'] ?? ''));
            if ($assignee === '') throw new RuntimeException('Please select a resident or tenant.');

            $isTenant = strncmp($assignee, 'tp:', 3) === 0;
            $residentId = 0;
            $tenantProfileId = 0;
            if ($isTenant) {
                $tenantProfileId = (int)substr($assignee, 3);
            } else {
                $residentId = (int)preg_replace('/^r:/', '', $assignee);
            }

            $residentUserId = null;
            $residentName = '';

            if ($isTenant && $tenantProfileId > 0) {
                $tpSt = db()->prepare('SELECT * FROM tenant_profiles WHERE id = ?');
                $tpSt->execute([$tenantProfileId]);
                $tp = $tpSt->fetch();
                if (!$tp) throw new RuntimeException('Tenant profile not found.');
                $residentName = $tp['full_name'];

                // Check if user/resident already exists
                $uSt = db()->prepare(
                    'SELECT r.residents_id, u.id AS user_id
                     FROM users u
                     LEFT JOIN residents r ON r.user_id = u.id
                     WHERE u.tenant_profile_id = ? OR (u.email IS NOT NULL AND u.email <> \'\' AND LOWER(u.email) = LOWER(?)) OR LOWER(u.name) = LOWER(?)
                     ORDER BY r.residents_id DESC LIMIT 1'
                );
                $uSt->execute([$tenantProfileId, $tp['email'] ?? '', $tp['full_name']]);
                $existingUser = $uSt->fetch();

                if ($existingUser && !empty($existingUser['residents_id'])) {
                    $residentId = (int)$existingUser['residents_id'];
                    $residentUserId = (int)$existingUser['user_id'];
                } else {
                    $userId = $existingUser ? (int)$existingUser['user_id'] : 0;
                    if ($userId <= 0) {
                        $genEmail = !empty($tp['email']) ? $tp['email'] : ('tenant_' . $tp['id'] . '@heartdorm.local');
                        $pwdHash = password_hash('Resident@123', PASSWORD_BCRYPT);
                        $insU = db()->prepare(
                            'INSERT INTO users (name, email, password_hash, role, status, phone, tenant_profile_id)
                             VALUES (?,?,?,?,?,?,?)'
                        );
                        $insU->execute([$tp['full_name'], $genEmail, $pwdHash, 'resident', 'active', $tp['phone'] ?: null, $tenantProfileId]);
                        $userId = (int)db()->lastInsertId();
                    }
                    $insR = db()->prepare('INSERT INTO residents (user_id, room_id, status) VALUES (?,?,?)');
                    $insR->execute([$userId, !empty($tp['room_id']) ? (int)$tp['room_id'] : null, 'active']);
                    $residentId = (int)db()->lastInsertId();
                    $residentUserId = $userId;
                    try {
                        db()->prepare('UPDATE users SET resident_link_id = ?, tenant_profile_id = ? WHERE id = ?')
                            ->execute([$residentId, $tenantProfileId, $userId]);
                    } catch (Throwable $t) {}
                }
            } else {
                if ($residentId <= 0) throw new RuntimeException('Please select a valid resident.');
                $resSt = db()->prepare(
                    'SELECT r.residents_id, r.user_id, r.room_id, u.name, u.email
                     FROM residents r
                     JOIN users u ON u.id = r.user_id
                     WHERE r.residents_id = ? LIMIT 1'
                );
                $resSt->execute([$residentId]);
                $resRow = $resSt->fetch();
                if (!$resRow) throw new RuntimeException('Resident not found.');
                $residentName = $resRow['name'];
                $residentUserId = (int)$resRow['user_id'];
            }

            $roomId = (int)($_POST['room_id'] ?? 0);
            if ($roomId <= 0) throw new RuntimeException('Please select a valid room.');
            $rmCheck = db()->prepare('SELECT id, room_number, monthly_rate FROM rooms WHERE id = ? LIMIT 1');
            $rmCheck->execute([$roomId]);
            $roomInfo = $rmCheck->fetch();
            if (!$roomInfo) throw new RuntimeException('Selected room does not exist.');

            $period = trim((string)($_POST['period'] ?? ''));
            if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
                throw new RuntimeException('Invalid billing period. Use YYYY-MM format.');
            }

            $roomRental = max(0, round((float)($_POST['room_rental'] ?? 0), 2));
            $elec       = max(0, round((float)($_POST['electricity'] ?? 0), 2));
            $water      = max(0, round((float)($_POST['water'] ?? 0), 2));
            $utilities  = round($elec + $water, 2);
            if (isset($_POST['utilities']) && (float)$_POST['utilities'] > $utilities) {
                $utilities = max($utilities, round((float)$_POST['utilities'], 2));
            }
            $additional = max(0, round((float)($_POST['additional_charges'] ?? 0), 2));
            $discounts  = max(0, round((float)($_POST['discounts'] ?? 0), 2));
            $penalties  = max(0, round((float)($_POST['penalties'] ?? 0), 2));

            $dueDate = null;
            $dueRaw = trim((string)($_POST['due_date'] ?? ''));
            if ($dueRaw !== '') {
                $d = DateTime::createFromFormat('Y-m-d', $dueRaw);
                if (!$d || $d->format('Y-m-d') !== $dueRaw) throw new RuntimeException('Invalid due date.');
                $dueDate = $dueRaw;
            }

            $billType = trim((string)($_POST['bill_type'] ?? 'Monthly'));
            if (!in_array($billType, ['Monthly', 'Initial', 'Other'], true)) $billType = 'Monthly';

            $remarks = trim((string)($_POST['remarks'] ?? ''));

            $total = round($roomRental + $utilities + $additional + $penalties - $discounts, 2);
            if ($total < 0) $total = 0;

            // Check for duplicate bill for resident + billing_period
            $chk = db()->prepare('SELECT id, invoice_number FROM bills WHERE resident_id = ? AND billing_period = ? LIMIT 1');
            $chk->execute([$residentId, $period]);
            $existingBill = $chk->fetch();
            if ($existingBill) {
                throw new RuntimeException("A bill for {$residentName} already exists for period {$period} (Invoice: {$existingBill['invoice_number']}). Please select a different period or edit the existing bill.");
            }

            // Invoice number
            $ymCompact = str_replace('-', '', $period);
            $invCustom = trim((string)($_POST['invoice_number'] ?? ''));
            if ($invCustom !== '') {
                $invCheck = db()->prepare('SELECT id FROM bills WHERE invoice_number = ? LIMIT 1');
                $invCheck->execute([$invCustom]);
                if ($invCheck->fetchColumn()) {
                    throw new RuntimeException("Invoice number '{$invCustom}' is already in use. Please enter a unique invoice number or leave it blank to auto-generate.");
                }
                $invoiceNumber = $invCustom;
            } else {
                $invoiceNumber = billing_unique_no('INV', $ymCompact, 'bills', 'invoice_number');
            }

            $status = 'Unpaid';
            if ($dueDate && $dueDate < date('Y-m-d') && $total > 0) {
                $status = 'Overdue';
            }

            try {
                $ins = db()->prepare(
                    'INSERT INTO bills (resident_id, room_id, billing_period, room_rental, utilities, additional_charges, discounts, penalties, total_amount, amount_paid, balance, due_date, payment_status, invoice_number, created_by, bill_type)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
                );
                $ins->execute([
                    $residentId, $roomId, $period, $roomRental, $utilities,
                    $additional, $discounts, $penalties, $total, 0, $total,
                    $dueDate, $status, $invoiceNumber, (int)$user['id'], $billType
                ]);
            } catch (PDOException $pe) {
                if (str_contains($pe->getMessage(), 'Unknown column \'bill_type\'') || str_contains($pe->getMessage(), 'bill_type')) {
                    $ins = db()->prepare(
                        'INSERT INTO bills (resident_id, room_id, billing_period, room_rental, utilities, additional_charges, discounts, penalties, total_amount, amount_paid, balance, due_date, payment_status, invoice_number, created_by)
                         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
                    );
                    $ins->execute([
                        $residentId, $roomId, $period, $roomRental, $utilities,
                        $additional, $discounts, $penalties, $total, 0, $total,
                        $dueDate, $status, $invoiceNumber, (int)$user['id']
                    ]);
                } else {
                    throw $pe;
                }
            }

            $newBillId = (int)db()->lastInsertId();

            // Optional line items
            try {
                $bli = db()->prepare('INSERT INTO billing_line_items (bill_id, description, amount, line_type) VALUES (?,?,?,?)');
                if ($roomRental > 0) $bli->execute([$newBillId, 'Room rental (' . $roomInfo['room_number'] . ')', $roomRental, 'room_rental']);
                if ($elec > 0) $bli->execute([$newBillId, 'Electricity', $elec, 'electricity']);
                if ($water > 0) $bli->execute([$newBillId, 'Water', $water, 'water']);
                elseif ($utilities > 0 && ($elec <= 0 && $water <= 0)) $bli->execute([$newBillId, 'Utilities', $utilities, 'other']);
                if ($additional > 0) $bli->execute([$newBillId, $remarks ?: 'Additional charges', $additional, 'fee']);
                if ($penalties > 0) $bli->execute([$newBillId, 'Penalties', $penalties, 'penalty']);
            } catch (Throwable $t) {}

            // Send notification to resident
            try {
                if ($residentUserId) {
                    $nt = db()->prepare('INSERT INTO notifications (title, message, sender_id, recipient_id, recipient_role, priority) VALUES (?,?,?,?,?,?)');
                    $nt->execute([
                        'New Bill Issued (' . $period . ')',
                        "A new {$billType} bill ({$invoiceNumber}) has been issued for period {$period}. Total amount: " . money($total) . ". Due date: " . ($dueDate ?: 'N/A') . ".",
                        (int)$user['id'],
                        $residentUserId,
                        'resident',
                        'Important'
                    ]);
                }
            } catch (Throwable $t) {}

            audit_log((int)$user['id'], "Created bill {$invoiceNumber} for resident #{$residentId} ({$residentName}) - " . money($total), 'billing', (string)$newBillId);
            flash('success', "New bill {$invoiceNumber} created for {$residentName} (" . money($total) . ").");
        } catch (Throwable $t) {
            flash('error', 'Create bill failed: ' . $t->getMessage());
        }
        redirect('index.php?page=billing');
    }

    // --- edit existing bill ---
    if ($action === 'edit_bill') {
        try {
            $billId = (int)($_POST['bill_id'] ?? 0);
            if ($billId <= 0) throw new RuntimeException('Invalid bill.');
            $st = db()->prepare('SELECT * FROM bills WHERE id = ? LIMIT 1');
            $st->execute([$billId]);
            $bill = $st->fetch();
            if (!$bill) throw new RuntimeException('Bill not found.');

            $roomRental = max(0, round((float)($_POST['room_rental'] ?? $bill['room_rental']), 2));
            $utilities  = max(0, round((float)($_POST['utilities'] ?? $bill['utilities']), 2));
            $additional = max(0, round((float)($_POST['additional_charges'] ?? $bill['additional_charges']), 2));
            $discounts  = max(0, round((float)($_POST['discounts'] ?? $bill['discounts']), 2));
            $penalties  = max(0, round((float)($_POST['penalties'] ?? $bill['penalties']), 2));

            $dueRaw = trim((string)($_POST['due_date'] ?? ''));
            $dueDate = null;
            if ($dueRaw !== '') {
                $d = DateTime::createFromFormat('Y-m-d', $dueRaw);
                if (!$d || $d->format('Y-m-d') !== $dueRaw) throw new RuntimeException('Invalid due date.');
                $dueDate = $dueRaw;
            }

            $total = round($roomRental + $utilities + $additional + $penalties - $discounts, 2);
            if ($total < 0) $total = 0;
            $paid = (float)$bill['amount_paid'];
            $newBal = round($total - $paid, 2);
            if ($newBal < 0) $newBal = 0;

            if ($newBal <= 0) $status = 'Paid';
            elseif ($dueDate && $dueDate < date('Y-m-d')) $status = 'Overdue';
            elseif ($paid > 0) $status = 'Partially Paid';
            else $status = 'Unpaid';

            db()->prepare(
                'UPDATE bills SET room_rental = ?, utilities = ?, additional_charges = ?, discounts = ?, penalties = ?, total_amount = ?, balance = ?, due_date = ?, payment_status = ? WHERE id = ?'
            )->execute([$roomRental, $utilities, $additional, $discounts, $penalties, $total, $newBal, $dueDate, $status, $billId]);

            audit_log((int)$user['id'], "Updated bill #{$billId} ({$bill['invoice_number']})", 'billing', (string)$billId);
            flash('success', "Bill {$bill['invoice_number']} updated successfully.");
        } catch (Throwable $t) {
            flash('error', 'Update bill failed: ' . $t->getMessage());
        }
        redirect('index.php?page=billing');
    }

    // --- generate monthly billing ---
    if ($action === 'generate') {
        try {
            $period = trim((string)($_POST['period'] ?? ''));
            if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) throw new RuntimeException('Invalid billing period. Use YYYY-MM.');
            $utilities   = max(0, (float)($_POST['utilities'] ?? 0));
            $additional  = max(0, (float)($_POST['additional_charges'] ?? 0));
            $discounts   = max(0, (float)($_POST['discounts'] ?? 0));
            $penalties   = max(0, (float)($_POST['penalties'] ?? 0));
            $dueRaw = trim((string)($_POST['due_date'] ?? ''));
            $dueDate = null;
            if ($dueRaw !== '') {
                $d = DateTime::createFromFormat('Y-m-d', $dueRaw);
                if (!$d || $d->format('Y-m-d') !== $dueRaw) throw new RuntimeException('Invalid due date.');
                $dueDate = $dueRaw;
            }
            $rows = db()->query(
                "SELECT r.residents_id, r.room_id, rm.monthly_rate
                 FROM residents r JOIN rooms rm ON rm.id = r.room_id
                 WHERE r.status = 'active' AND r.room_id IS NOT NULL"
            )->fetchAll();
            if (!$rows) throw new RuntimeException('No active residents with rooms to bill.');
            $created = 0; $skipped = 0;
            $ymCompact = str_replace('-', '', $period);
            $chk = db()->prepare('SELECT id FROM bills WHERE resident_id = ? AND billing_period = ? LIMIT 1');
            $ins = db()->prepare(
                'INSERT INTO bills (resident_id, room_id, billing_period, room_rental, utilities, additional_charges, discounts, penalties, total_amount, amount_paid, balance, due_date, payment_status, invoice_number, created_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            );
            foreach ($rows as $r) {
                $chk->execute([(int)$r['residents_id'], $period]);
                if ($chk->fetchColumn()) { $skipped++; continue; }
                $rental = (float)$r['monthly_rate'];
                $total = round($rental + $utilities + $additional + $penalties - $discounts, 2);
                if ($total < 0) $total = 0;
                $inv = billing_unique_no('INV', $ymCompact, 'bills', 'invoice_number');
                $ins->execute([
                    (int)$r['residents_id'], (int)$r['room_id'], $period, $rental, $utilities,
                    $additional, $discounts, $penalties, $total, 0, $total, $dueDate, 'Unpaid', $inv, (int)$user['id'],
                ]);
                $created++;
            }
            audit_log((int)$user['id'], "Generated billing for $period: $created created, $skipped skipped", 'billing', $period);
            flash('success', "Billing generated for $period: $created created" . ($skipped ? ", $skipped skipped (already exist)." : '.'));
        } catch (Throwable $t) {
            flash('error', 'Generate failed: ' . $t->getMessage());
        }
        redirect('index.php?page=billing');
    }

    // --- record payment ---
    if ($action === 'pay') {
        try {
            $billId = (int)($_POST['bill_id'] ?? 0);
            $amount = round((float)($_POST['amount'] ?? 0), 2);
            $method = trim((string)($_POST['payment_method'] ?? ''));
            $pdate  = trim((string)($_POST['payment_date'] ?? ''));
            $ref    = trim((string)($_POST['reference_no'] ?? ''));
            $remarks = trim((string)($_POST['remarks'] ?? ''));
            if ($billId <= 0) throw new RuntimeException('Invalid bill.');
            if ($amount <= 0) throw new RuntimeException('Amount must be greater than zero.');
            if (!in_array($method, ['Cash', 'GCash', 'Bank Transfer'], true)) throw new RuntimeException('Invalid payment method.');
            $d = DateTime::createFromFormat('Y-m-d', $pdate);
            if (!$d || $d->format('Y-m-d') !== $pdate) throw new RuntimeException('Invalid payment date.');
            $st = db()->prepare('SELECT * FROM bills WHERE id = ? LIMIT 1');
            $st->execute([$billId]);
            $bill = $st->fetch();
            if (!$bill) throw new RuntimeException('Bill not found.');
            $bal = round((float)$bill['total_amount'] - (float)$bill['amount_paid'], 2);
            if ($amount - $bal > 0.009) throw new RuntimeException('Amount exceeds outstanding balance of ' . money($bal) . '.');
            $ymC = str_replace('-', '', substr($pdate, 0, 7));
            $orNo = billing_unique_no('OR', $ymC, 'payments', 'receipt_number');
            db()->prepare(
                'INSERT INTO payments (bill_id, resident_id, amount, payment_method, payment_date, reference_no, receipt_number, received_by, remarks)
                 VALUES (?,?,?,?,?,?,?,?,?)'
            )->execute([$billId, (int)$bill['resident_id'], $amount, $method, $pdate, ($ref !== '' ? $ref : null), $orNo, (int)$user['id'], ($remarks !== '' ? $remarks : null)]);
            $payId = (int)db()->lastInsertId();
            $newPaid = round((float)$bill['amount_paid'] + $amount, 2);
            $newBal = round((float)$bill['total_amount'] - $newPaid, 2);
            if ($newBal < 0.005 && $newBal > -0.01) { $newBal = 0; }
            if ($newBal <= 0) { $status = 'Paid'; $newBal = 0; }
            elseif (($bill['due_date'] && substr($bill['due_date'], 0, 10) < $today)) { $status = 'Overdue'; }
            elseif ($newPaid > 0) { $status = 'Partially Paid'; }
            else { $status = 'Unpaid'; }
            db()->prepare('UPDATE bills SET amount_paid = ?, balance = ?, payment_status = ? WHERE id = ?')
                ->execute([$newPaid, $newBal, $status, $billId]);
            audit_log((int)$user['id'], "Recorded payment $orNo of " . number_format($amount, 2) . " for bill #{$billId}", 'billing', (string)$payId);
            flash('success', 'Payment successfully recorded.');
        } catch (Throwable $t) {
            flash('error', 'Payment failed: ' . $t->getMessage());
        }
        redirect('index.php?page=billing');
    }

    // --- delete bill (super_admin only) ---
    if ($action === 'delete') {
        try {
            if (($user['role'] ?? '') !== 'super_admin') throw new RuntimeException('Only super admin can delete bills.');
            $billId = (int)($_POST['bill_id'] ?? 0);
            if ($billId <= 0) throw new RuntimeException('Invalid bill.');
            db()->prepare('DELETE FROM bills WHERE id = ?')->execute([$billId]);
            audit_log((int)$user['id'], "Deleted bill #$billId", 'billing', (string)$billId);
            flash('success', 'Bill deleted.');
        } catch (Throwable $t) {
            flash('error', 'Delete failed: ' . $t->getMessage());
        }
        redirect('index.php?page=billing');
    }
}

// ---------- overdue auto-flag (cheap, best-effort) ----------
try {
    db()->prepare("UPDATE bills SET payment_status = 'Overdue' WHERE payment_status IN ('Unpaid','Partially Paid') AND due_date IS NOT NULL AND due_date < CURDATE()")->execute();
} catch (Throwable $t) { /* ignore */ }

// ---------- stat cards ----------
$stats = ['collected' => 0, 'outstanding' => 0, 'overdue' => 0, 'paid' => 0];
try {
    $q = db()->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE DATE_FORMAT(payment_date,'%Y-%m') = ?");
    $q->execute([$curYm]);
    $stats['collected'] = (float)($q->fetchColumn() ?: 0);
    $stats['outstanding'] = (float)(db()->query("SELECT COALESCE(SUM(balance),0) FROM bills WHERE payment_status IN ('Unpaid','Partially Paid','Overdue')")->fetchColumn() ?: 0);
    $stats['overdue'] = (int)(db()->query("SELECT COUNT(*) FROM bills WHERE payment_status = 'Overdue' OR (payment_status IN ('Unpaid','Partially Paid') AND due_date IS NOT NULL AND due_date < CURDATE())")->fetchColumn() ?: 0);
    $stats['paid'] = (int)(db()->query("SELECT COUNT(*) FROM bills WHERE payment_status = 'Paid'")->fetchColumn() ?: 0);
} catch (Throwable $t) {}

// ---------- fetch tenant profiles & rooms for Create Bill modal ----------
$billableList = [];
try {
    $tpRows = db()->query(
        "SELECT tp.id, tp.full_name, tp.email, tp.unit_number, tp.room_id, rm.room_number, rm.monthly_rate
         FROM tenant_profiles tp
         LEFT JOIN rooms rm ON rm.id = tp.room_id
         ORDER BY tp.full_name ASC"
    )->fetchAll();
    foreach ($tpRows as $tp) {
        $billableList[] = [
            'value' => 'tp:' . $tp['id'],
            'name' => $tp['full_name'],
            'room_id' => (int)($tp['room_id'] ?? 0),
            'room_number' => (string)($tp['room_number'] ?? $tp['unit_number'] ?? ''),
            'rate' => (float)($tp['monthly_rate'] ?? 0)
        ];
    }
} catch (Throwable $t) {}

$allRooms = [];
try {
    $allRooms = db()->query("SELECT id, room_number, monthly_rate FROM rooms ORDER BY room_number ASC")->fetchAll();
} catch (Throwable $t) {}

// ---------- filters ----------
$fPeriod = trim((string)($_GET['f_period'] ?? ''));
$fStatus = trim((string)($_GET['f_status'] ?? ''));
$fSearch = trim((string)($_GET['q'] ?? ''));
$where = [];
$params = [];
if ($fPeriod !== '' && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $fPeriod)) { $where[] = 'b.billing_period = ?'; $params[] = $fPeriod; }
if (in_array($fStatus, ['Unpaid', 'Partially Paid', 'Paid', 'Overdue'], true)) { $where[] = 'b.payment_status = ?'; $params[] = $fStatus; }
if ($fSearch !== '') {
    $where[] = '(COALESCE(u.name, ta.full_name, \'\') LIKE ? OR COALESCE(rm.room_number, ta.intended_unit_number, \'\') LIKE ? OR b.invoice_number LIKE ?)';
    $params[] = "%$fSearch%"; $params[] = "%$fSearch%"; $params[] = "%$fSearch%";
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$bills = [];
$payBills = [];
try {
    $st = db()->prepare(
        "SELECT b.*,
                COALESCE(u.name, ta.full_name, 'Unknown Resident') AS resident_name,
                COALESCE(rm.room_number, ta.intended_unit_number, '—') AS room_number
         FROM bills b
         LEFT JOIN residents r ON r.residents_id = b.resident_id
         LEFT JOIN users u ON u.id = r.user_id
         LEFT JOIN rooms rm ON rm.id = b.room_id
         LEFT JOIN tenant_applications ta ON ta.id = b.application_id
         $whereSql ORDER BY b.billing_period DESC, b.id DESC LIMIT 200"
    );
    $st->execute($params);
    $bills = $st->fetchAll();
} catch (Throwable $t) {
    echo '<div class="alert error">' . e('Failed to load bills: ' . $t->getMessage()) . '</div>';
}

// receipts map: bill_id => payments (for "receipt link after payments")
$receiptMap = [];
if ($bills) {
    try {
        $ids = array_map(fn($b) => (int)$b['id'], $bills);
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = db()->prepare("SELECT id, bill_id, receipt_number, amount FROM payments WHERE bill_id IN ($in) ORDER BY id ASC");
        $st->execute($ids);
        foreach ($st->fetchAll() as $p) $receiptMap[(int)$p['bill_id']][] = $p;
    } catch (Throwable $t) {}
}

// ---------- CSV export ----------
if (($_GET['export'] ?? '') === 'csv') {
    try {
        while (ob_get_level() > 0) ob_end_clean(); // discard layout output already echoed
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="bills_' . ($fPeriod !== '' ? $fPeriod : 'all') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Invoice', 'Resident', 'Room', 'Period', 'Rental', 'Utilities', 'Additional', 'Discounts', 'Penalties', 'Total', 'Paid', 'Balance', 'Due Date', 'Status']);
        foreach ($bills as $b) {
            fputcsv($out, [$b['invoice_number'], $b['resident_name'], $b['room_number'], $b['billing_period'], $b['room_rental'], $b['utilities'], $b['additional_charges'], $b['discounts'], $b['penalties'], $b['total_amount'], $b['amount_paid'], $b['balance'], substr((string)$b['due_date'], 0, 10), billing_display_status($b)]);
        }
        fclose($out);
        audit_log((int)$user['id'], 'Exported bills CSV', 'billing', null);
        exit;
    } catch (Throwable $t) {
        flash('error', 'Export failed: ' . $t->getMessage());
        redirect('index.php?page=billing');
    }
}

// ---------- recent payments ----------
$payments = [];
try {
    $payments = db()->query(
        "SELECT p.*,
                COALESCE(u.name, ta.full_name, 'Resident') AS resident_name,
                COALESCE(rm.room_number, ta.intended_unit_number, '—') AS room_number,
                b.billing_period, b.invoice_number, rv.name AS received_by_name
         FROM payments p
         JOIN bills b ON b.id = p.bill_id
         LEFT JOIN residents r ON r.residents_id = p.resident_id
         LEFT JOIN users u ON u.id = r.user_id
         LEFT JOIN rooms rm ON rm.id = b.room_id
         LEFT JOIN tenant_applications ta ON ta.id = b.application_id
         LEFT JOIN users rv ON rv.id = p.received_by
         ORDER BY p.id DESC LIMIT 50"
    )->fetchAll();
} catch (Throwable $t) {}

$approvedSubmissions = [];
$approvedSubmissionsError = null;
try {
    $approvedSubmissions = db()->query(
        "SELECT id, full_name, email, unit_number, total_price, payment_type, amount_paid,
                date_of_payment, screenshot_path, reviewed_at, review_note
         FROM tenant_billing_payments
         WHERE status='Approved'
         ORDER BY reviewed_at DESC, id DESC
         LIMIT 100"
    )->fetchAll();
} catch (Throwable $t) {
    $approvedSubmissionsError = 'Could not load approved payment submissions: ' . $t->getMessage();
}

$expQuery = http_build_query(array_filter(['page' => 'billing', 'export' => 'csv', 'f_period' => $fPeriod, 'f_status' => $fStatus, 'q' => $fSearch]));
?>

<style>
.stat-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-bottom:14px}
.stat{background:#fff;border:1px solid #eadcf5;border-radius:10px;padding:12px 14px}
.stat small{color:#7c6f8a}.stat strong{font-size:20px;display:block}
.pill{display:inline-block;padding:2px 10px;border-radius:999px;font-size:12px;font-weight:600}
.pill.Unpaid{background:#fde8e8;color:#b42318}.pill.Partially.Paid,.pill.Partially{background:#fef3c7;color:#92400e}
.pill.Paid{background:#dcfce7;color:#166534}.pill.Overdue{background:#fee2e2;color:#991b1b;border:1px solid #fca5a5}
.tbl-wrap{overflow-x:auto}
.modal{display:none;position:fixed;inset:0;background:rgba(20,10,35,.5);z-index:60;align-items:center;justify-content:center;padding:16px}
.modal.open{display:flex!important}
.modal .box{background:#fff;border-radius:14px;max-width:540px;width:100%;padding:22px;max-height:92vh;overflow-y:auto;box-shadow:0 12px 35px rgba(0,0,0,.25)}
.calc-callout{background:#f8f4ff;border:1px solid #e9d5ff;border-radius:10px;padding:12px 16px;margin:12px 0}
@media print{.no-print{display:none!important}.modal{display:none!important}body{background:#fff}}
</style>

<div class="stat-grid">
  <div class="stat"><small>Collected this month (<?=e($curYm)?>)</small><strong><?=money($stats['collected'])?></strong></div>
  <div class="stat"><small>Outstanding balance</small><strong><?=money($stats['outstanding'])?></strong></div>
  <div class="stat"><small>Overdue bills</small><strong><?=e((string)$stats['overdue'])?></strong></div>
  <div class="stat"><small>Paid bills</small><strong><?=e((string)$stats['paid'])?></strong></div>
</div>

<div class="card no-print">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:12px">
    <h3 style="margin:0">Billing Actions</h3>
    <button type="button" class="btn" onclick="openModal('mCreateBill')">+ Create New Bill</button>
  </div>
  <details style="margin-top:6px">
    <summary style="cursor:pointer;font-weight:600;color:#6b21a8;padding:6px 0">⚡ Batch Generate Monthly Billing (for all active residents)</summary>
    <form method="post" action="index.php?page=billing" class="row" style="display:flex;gap:10px;flex-wrap:wrap;align-items:end;margin-top:10px">
      <?=csrf_field()?>
      <input type="hidden" name="action" value="generate">
      <div class="field"><label>Period (YYYY-MM)</label><input type="month" name="period" value="<?=e($curYm)?>" required></div>
      <div class="field"><label>Utilities (each)</label><input type="number" step="0.01" min="0" name="utilities" value="0"></div>
      <div class="field"><label>Additional (each)</label><input type="number" step="0.01" min="0" name="additional_charges" value="0"></div>
      <div class="field"><label>Discounts (each)</label><input type="number" step="0.01" min="0" name="discounts" value="0"></div>
      <div class="field"><label>Penalties (each)</label><input type="number" step="0.01" min="0" name="penalties" value="0"></div>
      <div class="field"><label>Due date</label><input type="date" name="due_date"></div>
      <button class="btn ghost">Generate All</button>
    </form>
    <small style="color:#7c6f8a;display:block;margin-top:6px">Loops active residents with rooms · room rental from room monthly rate · total = rental + utilities + additional + penalties − discounts · existing resident+period rows are skipped.</small>
  </details>
</div>

<div class="card">
  <div class="row between no-print" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;justify-content:space-between;margin-bottom:14px">
    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
      <h3 style="margin:0">Bills</h3>
      <button type="button" class="btn sm" onclick="openModal('mCreateBill')">+ Create New Bill</button>
    </div>
    <form method="get" action="index.php" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end">
      <input type="hidden" name="page" value="billing">
      <div class="field"><label>Period</label><input type="month" name="f_period" value="<?=e($fPeriod)?>"></div>
      <div class="field"><label>Status</label>
        <select name="f_status">
          <option value="">All</option>
          <?php foreach (['Unpaid','Partially Paid','Paid','Overdue'] as $s): ?>
          <option value="<?=e($s)?>" <?=$fStatus===$s?'selected':''?>><?=e($s)?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field"><label>Search resident / room / invoice</label><input name="q" value="<?=e($fSearch)?>" placeholder="name, room, INV-…"></div>
      <button class="btn sm">Filter</button>
      <a class="btn ghost sm" href="index.php?page=billing">Reset</a>
      <a class="btn ghost sm" href="index.php?<?=e($expQuery)?>">CSV export</a>
      <button type="button" class="btn ghost sm" onclick="window.print()">Print</button>
    </form>
  </div>
  <div class="tbl-wrap">
  <table class="tbl">
    <thead><tr>
      <th>Resident</th><th>Room</th><th>Period</th><th>Rental</th><th>Utilities</th><th>Addl</th><th>Disc</th><th>Pen</th>
      <th>Total</th><th>Paid</th><th>Balance</th><th>Due</th><th>Status</th><th class="no-print">Actions</th>
    </tr></thead>
    <tbody>
    <?php if (!$bills): ?><tr><td colspan="14">No bills found.</td></tr><?php endif; ?>
    <?php foreach ($bills as $b):
        $disp = billing_display_status($b);
        $pillClass = $disp === 'Partially Paid' ? 'Partially Paid' : $disp;
        if ($disp !== 'Paid') $payBills[] = $b;
    ?>
      <tr>
        <td>
          <?=e($b['resident_name'])?><br>
          <small style="color:#7c6f8a"><?=e((string)$b['invoice_number'])?><?=!empty($b['bill_type']) && $b['bill_type'] !== 'Monthly' ? ' · ' . e($b['bill_type']) : ''?></small>
        </td>
        <td><?=e($b['room_number'])?></td>
        <td><?=e($b['billing_period'])?></td>
        <td><?=money($b['room_rental'])?></td>
        <td><?=money($b['utilities'])?></td>
        <td><?=money($b['additional_charges'])?></td>
        <td><?=money($b['discounts'])?></td>
        <td><?=money($b['penalties'])?></td>
        <td><strong><?=money($b['total_amount'])?></strong></td>
        <td><?=money($b['amount_paid'])?></td>
        <td><strong><?=money($b['balance'])?></strong></td>
        <td><?=e(substr((string)$b['due_date'], 0, 10))?></td>
        <td><span class="pill <?=e($pillClass)?>"><?=e($disp)?></span></td>
        <td class="no-print" style="white-space:nowrap">
          <?php if ($disp !== 'Paid'): ?>
          <button type="button" class="btn sm" onclick="document.getElementById('pay-<?=(int)$b['id']?>').style.display='flex'">Pay</button>
          <button type="button" class="btn ghost sm" onclick="openModal('edit-bill-<?=(int)$b['id']?>')">Edit</button>
          <?php endif; ?>
          <a class="btn ghost sm" href="index.php?page=invoice&id=<?=(int)$b['id']?>">Invoice</a>
          <?php foreach (($receiptMap[(int)$b['id']] ?? []) as $rc): ?>
            <a class="btn ghost sm" href="index.php?page=receipt&id=<?=(int)$rc['id']?>" title="<?=money($rc['amount'])?>"><?=e((string)$rc['receipt_number'])?></a>
          <?php endforeach; ?>
          <?php if (($user['role'] ?? '') === 'super_admin'): ?>
          <form method="post" action="index.php?page=billing" style="display:inline" onsubmit="return confirm('Delete this bill and its payments?')">
            <?=csrf_field()?><input type="hidden" name="action" value="delete"><input type="hidden" name="bill_id" value="<?=(int)$b['id']?>">
            <button class="btn danger sm">Delete</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>

<!-- Modal: Create New Bill -->
<div class="modal no-print" id="mCreateBill">
  <div class="box" style="max-width:620px">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;border-bottom:1px solid #eadcf5;padding-bottom:10px">
      <h3 style="margin:0">Create New Bill</h3>
      <button type="button" class="btn ghost sm" onclick="closeModal('mCreateBill')">✕</button>
    </div>
    <p style="font-size:13px;color:#7c6f8a;margin-top:0">Issue an individual bill or invoice for a tenant profile.</p>

    <form method="post" action="index.php?page=billing" id="formCreateBill">
      <?=csrf_field()?>
      <input type="hidden" name="action" value="create_bill">

      <div class="form-grid">
        <div class="field" style="grid-column:1/-1">
          <label>Tenant Profile <span style="color:#b42318">*</span></label>
          <select name="resident_id" id="newBillResident" required onchange="onBillResidentChange()">
            <option value="">— Select tenant profile —</option>
            <?php foreach ($billableList as $bItem): ?>
              <option value="<?=e($bItem['value'])?>"
                      data-room="<?=(int)$bItem['room_id']?>"
                      data-rate="<?=e((string)$bItem['rate'])?>"
                      data-room-no="<?=e($bItem['room_number'])?>">
                <?=e($bItem['name'])?> <?=!empty($bItem['room_number']) ? '(Room ' . e($bItem['room_number']) . ')' : '(No room)'?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="field">
          <label>Room <span style="color:#b42318">*</span></label>
          <select name="room_id" id="newBillRoom" required onchange="onBillRoomChange()">
            <option value="">— Select room —</option>
            <?php foreach ($allRooms as $rm): ?>
              <option value="<?=(int)$rm['id']?>" data-rate="<?=e((string)$rm['monthly_rate'])?>">
                Room <?=e($rm['room_number'])?> (<?=money($rm['monthly_rate'])?>/mo)
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="field">
          <label>Billing Period <span style="color:#b42318">*</span></label>
          <input type="month" name="period" id="newBillPeriod" value="<?=e($curYm)?>" required>
        </div>

        <div class="field">
          <label>Due Date</label>
          <input type="date" name="due_date" id="newBillDueDate" value="<?=date('Y-m-d', strtotime('+15 days'))?>">
        </div>

        <div class="field">
          <label>Bill Type</label>
          <select name="bill_type">
            <option value="Monthly" selected>Monthly Billing</option>
            <option value="Initial">Initial Deposit / Move-in</option>
            <option value="Other">Other / Utility / Surcharge</option>
          </select>
        </div>

        <div class="field" style="grid-column:1/-1">
          <label>Invoice Number (optional)</label>
          <input type="text" name="invoice_number" placeholder="Leave blank to auto-generate (e.g. INV-<?=str_replace('-','',$curYm)?>-0001)">
        </div>
      </div>

      <div style="background:#fcfaff;border:1px solid #eadcf5;border-radius:10px;padding:14px;margin:12px 0">
        <strong style="display:block;margin-bottom:10px;color:#4c1d95">Charges Breakdown</strong>
        <div class="form-grid">
          <div class="field">
            <label>Room Rental (₱)</label>
            <input type="number" step="0.01" min="0" name="room_rental" id="newBillRental" value="0.00" oninput="calcBillTotal()">
          </div>
          <div class="field">
            <label>Electricity (₱)</label>
            <input type="number" step="0.01" min="0" name="electricity" id="newBillElec" value="0.00" oninput="calcBillTotal()">
          </div>
          <div class="field">
            <label>Water (₱)</label>
            <input type="number" step="0.01" min="0" name="water" id="newBillWater" value="0.00" oninput="calcBillTotal()">
          </div>
          <div class="field">
            <label>Additional Charges (₱)</label>
            <input type="number" step="0.01" min="0" name="additional_charges" id="newBillAddl" value="0.00" oninput="calcBillTotal()">
          </div>
          <div class="field">
            <label>Discounts (₱)</label>
            <input type="number" step="0.01" min="0" name="discounts" id="newBillDisc" value="0.00" oninput="calcBillTotal()">
          </div>
          <div class="field">
            <label>Penalties / Late Fee (₱)</label>
            <input type="number" step="0.01" min="0" name="penalties" id="newBillPen" value="0.00" oninput="calcBillTotal()">
          </div>
        </div>

        <div class="calc-callout" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
          <div>
            <span style="font-size:13px;color:#6b21a8">Utilities Subtotal (Elec + Water): <strong id="newBillUtilDisplay">₱0.00</strong></span>
          </div>
          <div style="text-align:right">
            <span style="font-size:13px;color:#555">Total Bill Amount:</span>
            <strong style="font-size:22px;color:#7c3aed;display:block" id="newBillTotalDisplay">₱0.00</strong>
          </div>
        </div>
      </div>

      <div class="field">
        <label>Notes / Remarks (optional)</label>
        <input name="remarks" placeholder="Optional notes for invoice / line items (e.g. Submeter reading, key deposit)">
      </div>

      <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:16px">
        <button type="button" class="btn ghost" onclick="closeModal('mCreateBill')">Cancel</button>
        <button type="submit" class="btn">Create Bill</button>
      </div>
    </form>
  </div>
</div>

<!-- Modals: Record Payment -->
<?php foreach (($payBills ?? []) as $b): ?>
<div class="modal no-print" id="pay-<?=(int)$b['id']?>">
  <div class="box">
    <h3 style="margin-top:0">Record payment — <?=e($b['resident_name'])?> (<?=e($b['billing_period'])?>)</h3>
    <p>Balance: <strong><?=money($b['balance'])?></strong> · Total <?=money($b['total_amount'])?></p>
    <form method="post" action="index.php?page=billing">
      <?=csrf_field()?>
      <input type="hidden" name="action" value="pay">
      <input type="hidden" name="bill_id" value="<?=(int)$b['id']?>">
      <div class="field"><label>Amount</label><input type="number" step="0.01" min="0.01" max="<?=e((string)$b['balance'])?>" name="amount" value="<?=e((string)$b['balance'])?>" required></div>
      <div class="field"><label>Method</label>
        <select name="payment_method" required>
          <option>Cash</option><option>GCash</option><option>Bank Transfer</option>
        </select>
      </div>
      <div class="field"><label>Payment date</label><input type="date" name="payment_date" value="<?=e($today)?>" required></div>
      <div class="field"><label>Reference no. (optional)</label><input name="reference_no" maxlength="60"></div>
      <div class="field"><label>Remarks (optional)</label><input name="remarks" maxlength="255"></div>
      <small style="color:#7c6f8a">Receipt number OR-YYYYMM-XXXX is generated automatically.</small>
      <div style="display:flex;gap:8px;margin-top:10px">
        <button class="btn">Save payment</button>
        <button type="button" class="btn ghost" onclick="document.getElementById('pay-<?=(int)$b['id']?>').style.display='none'">Cancel</button>
      </div>
    </form>
  </div>
</div>
<?php endforeach; ?>

<!-- Modals: Edit Bill -->
<?php foreach ($bills as $b):
    $disp = billing_display_status($b);
    if ($disp === 'Paid') continue;
?>
<div class="modal no-print" id="edit-bill-<?=(int)$b['id']?>">
  <div class="box" style="max-width:560px">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;border-bottom:1px solid #eadcf5;padding-bottom:10px">
      <h3 style="margin:0">Edit Bill — <?=e($b['invoice_number'])?></h3>
      <button type="button" class="btn ghost sm" onclick="closeModal('edit-bill-<?=(int)$b['id']?>')">✕</button>
    </div>
    <p style="font-size:13px;color:#7c6f8a;margin-top:0">
      Resident: <strong><?=e($b['resident_name'])?></strong> · Room <strong><?=e($b['room_number'])?></strong> · Period <strong><?=e($b['billing_period'])?></strong>
    </p>
    <form method="post" action="index.php?page=billing">
      <?=csrf_field()?>
      <input type="hidden" name="action" value="edit_bill">
      <input type="hidden" name="bill_id" value="<?=(int)$b['id']?>">
      <div class="form-grid">
        <div class="field">
          <label>Room Rental (₱)</label>
          <input type="number" step="0.01" min="0" name="room_rental" id="eb_rental_<?=(int)$b['id']?>" value="<?=e((string)$b['room_rental'])?>" oninput="calcEditBillTotal(<?=(int)$b['id']?>, <?=(float)$b['amount_paid']?>)" required>
        </div>
        <div class="field">
          <label>Utilities (₱)</label>
          <input type="number" step="0.01" min="0" name="utilities" id="eb_util_<?=(int)$b['id']?>" value="<?=e((string)$b['utilities'])?>" oninput="calcEditBillTotal(<?=(int)$b['id']?>, <?=(float)$b['amount_paid']?>)">
        </div>
        <div class="field">
          <label>Additional Charges (₱)</label>
          <input type="number" step="0.01" min="0" name="additional_charges" id="eb_addl_<?=(int)$b['id']?>" value="<?=e((string)$b['additional_charges'])?>" oninput="calcEditBillTotal(<?=(int)$b['id']?>, <?=(float)$b['amount_paid']?>)">
        </div>
        <div class="field">
          <label>Discounts (₱)</label>
          <input type="number" step="0.01" min="0" name="discounts" id="eb_disc_<?=(int)$b['id']?>" value="<?=e((string)$b['discounts'])?>" oninput="calcEditBillTotal(<?=(int)$b['id']?>, <?=(float)$b['amount_paid']?>)">
        </div>
        <div class="field">
          <label>Penalties (₱)</label>
          <input type="number" step="0.01" min="0" name="penalties" id="eb_pen_<?=(int)$b['id']?>" value="<?=e((string)$b['penalties'])?>" oninput="calcEditBillTotal(<?=(int)$b['id']?>, <?=(float)$b['amount_paid']?>)">
        </div>
        <div class="field">
          <label>Due Date</label>
          <input type="date" name="due_date" value="<?=e(substr((string)$b['due_date'], 0, 10))?>">
        </div>
      </div>
      <div class="calc-callout" style="display:flex;justify-content:space-between;align-items:center">
        <div>
          <span style="font-size:12px;color:#6b21a8">Already Paid: <strong><?=money($b['amount_paid'])?></strong></span>
        </div>
        <div style="text-align:right">
          <span style="font-size:12px;color:#555">New Balance:</span>
          <strong style="font-size:18px;color:#7c3aed;display:block" id="eb_bal_<?=(int)$b['id']?>"><?=money($b['balance'])?></strong>
        </div>
      </div>
      <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:14px">
        <button type="button" class="btn ghost sm" onclick="closeModal('edit-bill-<?=(int)$b['id']?>')">Cancel</button>
        <button type="submit" class="btn sm">Save Changes</button>
      </div>
    </form>
  </div>
</div>
<?php endforeach; ?>

<div class="card">
  <h3>Payments history (recent 50)</h3>
  <div class="tbl-wrap">
  <table class="tbl">
    <thead><tr><th>Receipt</th><th>Resident</th><th>Room</th><th>Period</th><th>Amount</th><th>Method</th><th>Date</th><th>Reference</th><th>Received by</th><th class="no-print">Receipt</th></tr></thead>
    <tbody>
    <?php if (!$payments): ?><tr><td colspan="10">No payments yet.</td></tr><?php endif; ?>
    <?php foreach ($payments as $p): ?>
      <tr>
        <td><?=e((string)$p['receipt_number'])?></td>
        <td><?=e($p['resident_name'])?></td>
        <td><?=e($p['room_number'])?></td>
        <td><?=e($p['billing_period'])?></td>
        <td><?=money($p['amount'])?></td>
        <td><?=e((string)$p['payment_method'])?></td>
        <td><?=e(substr((string)$p['payment_date'], 0, 10))?></td>
        <td><?=e((string)$p['reference_no'])?></td>
        <td><?=e((string)($p['received_by_name'] ?? ''))?></td>
        <td class="no-print"><a class="btn ghost sm" href="index.php?page=receipt&id=<?=(int)$p['id']?>">View</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>

<div class="card">
  <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap">
    <h3 style="margin:0">Approved Billing Payment Submissions</h3>
    <a class="btn ghost sm" href="index.php?page=billing_payments">Manage submissions</a>
  </div>
  <p style="color:#7c6f8a">Tenant payment proofs approved by an administrator. These are submissions, not receipts recorded against a bill.</p>
  <?php if ($approvedSubmissionsError): ?>
    <div class="alert error"><?=e($approvedSubmissionsError)?></div>
  <?php else: ?>
    <div class="tbl-wrap">
    <table class="tbl">
      <thead><tr><th>Ref</th><th>Tenant</th><th>Unit</th><th>Submitted total</th><th>Payment type</th><th>Amount paid</th><th>Payment date</th><th>Approved</th><th class="no-print">Proof</th></tr></thead>
      <tbody>
      <?php if (!$approvedSubmissions): ?><tr><td colspan="9">No approved payment submissions.</td></tr><?php endif; ?>
      <?php foreach ($approvedSubmissions as $submission): ?>
        <tr>
          <td>#<?=e((string)$submission['id'])?></td>
          <td><strong><?=e($submission['full_name'])?></strong><br><small><?=e($submission['email'] ?? '')?></small></td>
          <td><?=e($submission['unit_number'] ?? '—')?></td>
          <td><?=money($submission['total_price'])?></td>
          <td><?=e($submission['payment_type'])?></td>
          <td><?=money($submission['amount_paid'])?></td>
          <td><?=e(substr((string)$submission['date_of_payment'], 0, 10))?></td>
          <td><?=e($submission['reviewed_at'] ? substr((string)$submission['reviewed_at'], 0, 16) : '—')?></td>
          <td class="no-print">
            <?php if (!empty($submission['screenshot_path'])): ?>
              <a class="btn ghost sm" href="index.php?page=download&amp;type=billing_proof&amp;pid=<?=(int)$submission['id']?>" target="_blank">View proof</a>
            <?php else: ?>—<?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
</div>

<script>
function onBillResidentChange() {
  var sel = document.getElementById('newBillResident');
  if (!sel) return;
  var opt = sel.options[sel.selectedIndex];
  if (!opt || !opt.value) return;
  var roomId = opt.getAttribute('data-room');
  var rate = opt.getAttribute('data-rate');
  var roomSel = document.getElementById('newBillRoom');
  if (roomSel && roomId && roomId !== '0') {
    roomSel.value = roomId;
  }
  var rentalInput = document.getElementById('newBillRental');
  if (rentalInput && rate !== null && rate !== '') {
    rentalInput.value = parseFloat(rate).toFixed(2);
  }
  calcBillTotal();
}

function onBillRoomChange() {
  var sel = document.getElementById('newBillRoom');
  if (!sel) return;
  var opt = sel.options[sel.selectedIndex];
  if (!opt || !opt.value) return;
  var rate = opt.getAttribute('data-rate');
  var rentalInput = document.getElementById('newBillRental');
  if (rentalInput && rate !== null && rate !== '') {
    rentalInput.value = parseFloat(rate).toFixed(2);
  }
  calcBillTotal();
}

function calcBillTotal() {
  var rental = parseFloat(document.getElementById('newBillRental')?.value || 0) || 0;
  var elec   = parseFloat(document.getElementById('newBillElec')?.value || 0) || 0;
  var water  = parseFloat(document.getElementById('newBillWater')?.value || 0) || 0;
  var addl   = parseFloat(document.getElementById('newBillAddl')?.value || 0) || 0;
  var disc   = parseFloat(document.getElementById('newBillDisc')?.value || 0) || 0;
  var pen    = parseFloat(document.getElementById('newBillPen')?.value || 0) || 0;

  var util = elec + water;
  var total = rental + util + addl + pen - disc;
  if (total < 0) total = 0;

  var disp = document.getElementById('newBillTotalDisplay');
  if (disp) {
    disp.textContent = '₱' + total.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }
  var utilDisp = document.getElementById('newBillUtilDisplay');
  if (utilDisp) {
    utilDisp.textContent = '₱' + util.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }
}

function calcEditBillTotal(id, paid) {
  var rental = parseFloat(document.getElementById('eb_rental_' + id)?.value || 0) || 0;
  var util   = parseFloat(document.getElementById('eb_util_' + id)?.value || 0) || 0;
  var addl   = parseFloat(document.getElementById('eb_addl_' + id)?.value || 0) || 0;
  var disc   = parseFloat(document.getElementById('eb_disc_' + id)?.value || 0) || 0;
  var pen    = parseFloat(document.getElementById('eb_pen_' + id)?.value || 0) || 0;

  var total = rental + util + addl + pen - disc;
  if (total < 0) total = 0;
  var bal = total - paid;
  if (bal < 0) bal = 0;

  var balEl = document.getElementById('eb_bal_' + id);
  if (balEl) {
    balEl.textContent = '₱' + bal.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }
}

window.addEventListener('click', function(e) {
  if (e.target && e.target.classList && e.target.classList.contains('modal')) {
    e.target.classList.remove('open');
    e.target.style.display = 'none';
  }
});
</script>
