<?php
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
function db(): PDO { global $pdo; return $pdo; }
function e($value): string { return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8'); }
function money(float|int|string $value): string { return 'P' . number_format((float)$value, 2); }
function acknowledgment_number_words(int $amount): string { return (string)$amount; }

foreach ([
    'CREATE TABLE residents (residents_id INTEGER, user_id INTEGER, room_id INTEGER)',
    'CREATE TABLE rooms (id INTEGER, room_number TEXT)',
    'CREATE TABLE bills (id INTEGER, resident_id INTEGER, room_id INTEGER, application_id INTEGER, billing_period TEXT, invoice_number TEXT, total_amount TEXT, amount_paid TEXT, balance TEXT, due_date TEXT, payment_status TEXT)',
    'CREATE TABLE payments (id INTEGER, bill_id INTEGER, resident_id INTEGER, receipt_number TEXT, amount TEXT, payment_method TEXT, payment_date TEXT, reference_no TEXT)',
    'CREATE TABLE tenant_applications (id INTEGER, user_id INTEGER, full_name TEXT, email TEXT)',
    'CREATE TABLE tenant_acknowledgment_receipts (id INTEGER, tenant_profile_id INTEGER, tenant_name TEXT, address TEXT, unit_number TEXT, total_amount TEXT, bank_reference TEXT, received_at TEXT, received_by_name TEXT, monthly_rate TEXT)',
    'CREATE TABLE reservations (id INTEGER, tenant_profile_id INTEGER, full_name TEXT, address TEXT, unit_number TEXT, status TEXT, amount TEXT, bank_reference TEXT, received_at TEXT)',
    'CREATE TABLE reservation_additional_payments (id INTEGER, reservation_id INTEGER, amount TEXT, bank_reference TEXT, received_at TEXT, received_by_name TEXT, is_full_summary INTEGER)',
] as $sql) $pdo->exec($sql);

$pdo->exec("INSERT INTO residents VALUES (11,1,1),(22,2,1)");
$pdo->exec("INSERT INTO rooms VALUES (1,'301')");
$pdo->exec("INSERT INTO bills VALUES (1,11,1,NULL,'2026-01','INV-1','5000','200','4800','2026-01-30','Partially Paid'),(2,22,1,NULL,'2026-02','INV-2','5000','300','4700','2026-02-28','Partially Paid')");
$pdo->exec("INSERT INTO payments VALUES (1,1,11,'BILL-ONE','200','Cash','2026-01-15','BILLREF1'),(2,2,22,'BILL-TWO','300','Cash','2026-02-15','BILLREF2')");
$pdo->exec("INSERT INTO tenant_acknowledgment_receipts VALUES (3,101,'First Tenant','Dorm','301','10000.00','REF-ONE','2026-01-01 10:00:00','Staff','5000.00'),(4,202,'Second Tenant','Dorm','301','12000.00','REF-TWO','2026-02-01 10:00:00','Staff','6000.00')");
$pdo->exec("INSERT INTO reservations VALUES (5,101,'First Tenant','Dorm','301','Converted','1000','PARTIAL-ONE','2026-03-01 10:00:00'),(6,202,'Second Tenant','Dorm','301','Converted','2000','PARTIAL-TWO','2026-04-01 10:00:00'),(7,101,'First Tenant','Dorm','301','Reserved','900','NOT-CONVERTED','2026-05-01 10:00:00'),(8,101,'First Tenant','Dorm','301','Converted','700','DIRECT-ONE','2026-02-01 10:00:00')");
$pdo->exec("INSERT INTO reservation_additional_payments VALUES (8,5,'2500','BALANCE-ONE','2026-03-02 10:00:00','Staff',1),(9,6,'3000','BALANCE-TWO','2026-04-02 10:00:00','Staff',0)");

$render = static function (array $user, string $page, array $params = []): string {
    $_GET = $params;
    ob_start();
    require __DIR__ . '/../pages/' . $page . '.php';
    return ob_get_clean();
};
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$first = ['id' => 1, 'role' => 'resident', 'tenant_profile_id' => 101];
$second = ['id' => 2, 'role' => 'resident', 'tenant_profile_id' => 202];
$firstHistory = $render($first, 'my_billing');
foreach (['BILL-ONE', 'AR-000003', 'AR-RP000008', 'AR-R000008', 'P3,500.00', 'DIRECT-ONE', 'BALANCE-ONE'] as $value) {
    $assert(str_contains($firstHistory, $value), 'First tenant history missing ' . $value);
}
foreach (['BILL-TWO', 'AR-000004', 'AR-RP000009', 'BALANCE-TWO', 'NOT-CONVERTED'] as $value) {
    $assert(!str_contains($firstHistory, $value), 'First tenant history exposed ' . $value);
}
$assert(!str_contains($firstHistory, 'AR-R000005'), 'Completed reservation was counted twice.');
$secondHistory = $render($second, 'my_billing');
foreach (['BILL-TWO', 'AR-000004', 'AR-RP000009', 'P5,000.00', 'legacy balance receipt'] as $value) {
    $assert(str_contains($secondHistory, $value), 'Second tenant history missing ' . $value);
}
foreach (['BILL-ONE', 'AR-000003', 'AR-RP000008', 'AR-R000008', 'BALANCE-ONE'] as $value) {
    $assert(!str_contains($secondHistory, $value), 'Second tenant history exposed ' . $value);
}
$unlinkedHistory = $render(['id' => 3, 'role' => 'resident'], 'my_billing');
$assert(str_contains($unlinkedHistory, 'No payments yet.'), 'Unlinked tenant history should be empty.');
$assert(!str_contains($unlinkedHistory, 'AR-000003'), 'Unlinked tenant saw another receipt.');
$otherReceipt = $render($first, 'acknowledgment_receipt', ['id' => 4]);
$assert(str_contains($otherReceipt, 'You may only view your own acknowledgment receipt.'), 'Another tenant receipt was accessible.');
$otherReservationReceipt = $render($first, 'acknowledgment_receipt', ['type' => 'reservation_payment', 'id' => 9]);
$assert(str_contains($otherReservationReceipt, 'You may only view your own acknowledgment receipt.'), 'Another tenant reservation receipt was accessible.');
$ownReceipt = $render($first, 'acknowledgment_receipt', ['id' => 3]);
$assert(str_contains($ownReceipt, 'AR-000003'), 'Own acknowledgment receipt was not accessible.');
$pdo->exec('DROP TABLE tenant_acknowledgment_receipts');
$errorHistory = $render(['id' => 3, 'role' => 'resident', 'tenant_profile_id' => 303], 'my_billing');
$assert(str_contains($errorHistory, 'Could not load your advance and deposit receipts.'), 'Receipt loading error was hidden.');
$assert(!str_contains($errorHistory, 'No payments yet.'), 'Receipt loading failure was reported as an empty history.');
echo "Tenant payment history tests passed.\n";
