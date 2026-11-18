<?php
require_once __DIR__ . '/../includes/allocation_room_status.php';

$cases = [
    ['Available', 0, 0, 3, 'Available'],
    ['Available', 0, 1, 3, 'Reserved'],
    ['Available', 0, 3, 3, 'Reserved'],
    ['Available', 1, 0, 3, 'Partially Occupied'],
    ['Available', 1, 1, 3, 'Partially Occupied'],
    ['Available', 3, 0, 3, 'Fully Occupied'],
    ['Reserved', 0, 0, 3, 'Reserved'],
    ['Under Maintenance', 1, 1, 3, 'Under Maintenance'],
    ['Unavailable', 3, 0, 3, 'Unavailable'],
];
foreach ($cases as [$stored, $occupied, $reserved, $capacity, $expected]) {
    $actual = allocation_room_status($stored, $occupied, $reserved, $capacity);
    if ($actual !== $expected) throw new RuntimeException("Expected $expected, got $actual.");
}
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE floors (id INTEGER PRIMARY KEY, name TEXT);
    CREATE TABLE room_types (id INTEGER PRIMARY KEY, name TEXT);
    CREATE TABLE rooms (id INTEGER PRIMARY KEY, room_number TEXT, floor_id INTEGER, room_type_id INTEGER, aircon TEXT, max_occupants INTEGER, monthly_rate REAL, status TEXT);
    CREATE TABLE beds (id INTEGER PRIMARY KEY, room_id INTEGER, bed_number TEXT, status TEXT);
    CREATE TABLE tenant_profiles (id INTEGER, full_name TEXT, email TEXT, unit_number TEXT, room_id INTEGER, bed_id INTEGER, contract_start TEXT, contract_end TEXT, profile_status TEXT);
    CREATE TABLE residents (residents_id INTEGER, user_id INTEGER);
    CREATE TABLE users (id INTEGER, name TEXT);
    CREATE TABLE room_allocations (id INTEGER, resident_id INTEGER, room_id INTEGER, bed_no TEXT, status TEXT, allocated_at TEXT, vacated_at TEXT, remarks TEXT);
    CREATE TABLE notifications (recipient_id INTEGER, is_read INTEGER);
    INSERT INTO floors VALUES (1, "3rd");
    INSERT INTO room_types VALUES (1, "Shared");
    INSERT INTO rooms VALUES (1,"301",1,1,"Aircon",3,100,"Available"),(2,"302",1,1,"Aircon",3,100,"Available"),(3,"303",1,1,"Aircon",3,100,"Available"),(4,"304",1,1,"Aircon",3,100,"Under Maintenance");
    INSERT INTO beds VALUES (1,1,"B1","reserved"),(2,1,"B2","vacant"),(3,1,"B3","vacant"),
        (4,2,"B1","occupied"),(5,2,"B2","vacant"),(6,2,"B3","vacant"),
        (7,3,"B1","occupied"),(8,3,"B2","reserved"),(9,3,"B3","vacant"),
        (10,4,"B1","reserved"),(11,4,"B2","vacant"),(12,4,"B3","vacant");
    INSERT INTO users VALUES (1,"Test Resident");
    INSERT INTO residents VALUES (1,1);
    INSERT INTO room_allocations VALUES (1,1,2,"B1","active","2026-01-01",NULL,NULL),
        (2,1,1,"B2","vacated","2025-01-01","2025-02-01","Previous room")');
function db(): PDO { global $pdo; return $pdo; }
function e($value): string { return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8'); }
function money($value): string { return number_format((float)$value, 2); }
function csrf_field(): string { return ''; }
function csrf_check(): void { throw new RuntimeException('Unexpected POST reached allocation handling.'); }
function flash($type): ?string { return null; }
$_GET = [];
$_SERVER['REQUEST_METHOD'] = getenv('ALLOCATION_TEST_METHOD') ?: 'GET';
$_POST = ['action' => getenv('ALLOCATION_TEST_ACTION') ?: '', 'csrf_token' => 'test-token', 'allocation_id' => 1];
$user = ['id' => 1, 'name' => 'Test Staff', 'role' => getenv('ALLOCATION_TEST_ROLE') ?: 'admin_staff'];
$before = $pdo->query('SELECT * FROM room_allocations ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
ob_start();
require __DIR__ . '/../pages/allocations.php';
$html = ob_get_clean();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($user['role'] !== 'housekeeping' || http_response_code() !== 403 || !str_contains($html, 'read-only')) {
        throw new RuntimeException('Housekeeping POST was not rejected.');
    }
    if ($before !== $pdo->query('SELECT * FROM room_allocations ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)) {
        throw new RuntimeException('Rejected POST changed allocations.');
    }
    echo "Housekeeping allocation POST denied.\n";
    exit;
}
$document = new DOMDocument();
libxml_use_internal_errors(true);
$document->loadHTML('<?xml encoding="UTF-8">' . $html);
$xpath = new DOMXPath($document);
$table = $xpath->query('//table')->item(0);
$headers = [];
foreach ($xpath->query('.//thead/tr/th', $table) as $header) $headers[] = trim($header->textContent);
if ($headers !== ['Room', 'Floor', 'Type', 'Aircon', 'Occupancy', 'Rate', 'Status']) throw new RuntimeException('Unexpected room table columns.');
$rows = $xpath->query('.//tbody/tr', $table);
$expected = ['Reserved / 1', 'Partially Occupied', 'Partially Occupied', 'Under Maintenance'];
foreach ($expected as $index => $status) {
    $cells = $xpath->query('./td', $rows->item($index));
    if ($cells->length !== 7 || !str_contains($cells->item(6)->textContent, $status)) throw new RuntimeException('Room status was not rendered correctly.');
}
if (!str_contains($xpath->query('./td', $rows->item(2))->item(6)->textContent, 'Reserved / 1')) throw new RuntimeException('Mixed occupancy must show the reserved bed count.');
if ($xpath->query('//input[@name="action" and (@value="assign" or @value="reserve")]')->length !== 0) throw new RuntimeException('Removed room actions are still present.');
$actions = $xpath->query('//input[@name="action" and (@value="vacate" or @value="transfer")]')->length;
if ($user['role'] === 'housekeeping') {
    if ($actions !== 0 || !str_contains($html, 'read-only') || $xpath->query('//input[@name="action" and @value="clear_history"]')->length !== 0) throw new RuntimeException('Housekeeping must have a read-only view.');
} elseif ($actions !== 2) {
    throw new RuntimeException('Staff allocation controls are missing.');
}
if (!str_contains($html, 'Test Resident') || !str_contains($html, 'Previous room')) throw new RuntimeException('Allocation or history data is missing.');
$clearHistory = $xpath->query('//input[@name="action" and @value="clear_history"]')->length;
if ($clearHistory !== ($user['role'] === 'super_admin' ? 1 : 0)) throw new RuntimeException('Clear History visibility is incorrect.');
if ($user['role'] === 'housekeeping') {
    require __DIR__ . '/../includes/layout.php';
    ob_start();
    layout_header('Room Allocation', $user);
    $navigation = ob_get_clean();
    if (!str_contains($navigation, 'index.php?page=allocations')) throw new RuntimeException('Housekeeping navigation is missing Room Allocation.');
}
echo "Room allocation status tests passed.\n";
