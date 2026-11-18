<?php
if (($argv[1] ?? '') === 'render') {
    $scenario = $argv[2] ?? '';
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SESSION = [];
    $_GET = $scenario === 'invalid' ? ['reservation_id' => ['7']] : ['reservation_id' => $scenario === 'missing' ? '8' : '7'];
    $user = ['id' => 1, 'role' => 'admin_staff'];

    function e($value): string { return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8'); }
    function csrf_field(): string { return ''; }
    function tenant_floor_from_unit(string $unit): string { return $unit === '301' ? '3rd' : ''; }
    function acknowledgment_number_words(int $amount): string { return (string)$amount; }
    function money(float|int|string $amount): string { return number_format((float)$amount, 2); }

    class TestStatement {
        public function __construct(private array $rows) {}
        public function execute(array $args = []): bool { return true; }
        public function fetchAll(): array { return $this->rows; }
        public function fetch(): array|false { return $this->rows[0] ?? false; }
    }
    class TestDatabase {
        public function query(string $sql): TestStatement {
            if (str_contains($sql, 'FROM rooms ORDER BY')) {
                return new TestStatement([['id' => 1, 'room_number' => '301', 'monthly_rate' => '5000.00']]);
            }
            if (str_contains($sql, 'FROM reservations r JOIN rooms')) {
                return new TestStatement([[
                    'id' => 7, 'full_name' => 'Test Tenant', 'email' => 'tenant@example.test',
                    'phone' => '09123456789', 'bank_reference' => '00123', 'unit_number' => '301',
                    'amount' => '1000.00', 'received_at' => '2026-01-02 12:00:00',
                ]]);
            }
            return new TestStatement([['total' => 0, 'resident_count' => 0, 'commercial_count' => 0]]);
        }
        public function prepare(string $sql): TestStatement {
            if (str_contains($sql, 'FROM reservations r WHERE r.id=')) {
                return new TestStatement([[
                    'id' => 7, 'tenant_name' => 'Test Tenant', 'address' => 'Dormitory',
                    'unit_number' => '301', 'status' => 'Reserved', 'tenant_profile_id' => null,
                    'total_amount' => '1000.00', 'received_at' => '2026-01-02 12:00:00',
                    'bank_reference' => '00123', 'payment_classification' => 'Partial',
                    'received_by_name' => 'Staff',
                ]]);
            }
            return new TestStatement([]);
        }
    }
    function db(): TestDatabase { return new TestDatabase(); }

    if ($scenario === 'receipt') {
        $_GET = ['type' => 'reservation', 'id' => '7'];
        require __DIR__ . '/../pages/acknowledgment_receipt.php';
    } else {
        require __DIR__ . '/../pages/tenants.php';
    }
    exit;
}

$render = static function (string $scenario): string {
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' render ' . escapeshellarg($scenario);
    exec($command, $output, $status);
    if ($status !== 0) throw new RuntimeException('Page rendering failed for ' . $scenario);
    return implode("\n", $output);
};
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$receipt = $render('receipt');
$assert(str_contains($receipt, 'href="index.php?page=tenants&amp;reservation_id=7">Create Profile</a>'), 'Partial receipt does not link to profile creation.');
$assert(!str_contains($receipt, 'completeReservation'), 'Old balance modal is still present.');
$valid = $render('valid');
$assert((bool)preg_match('/<option value="7"[^>]* selected>/', $valid), 'Reservation was not preselected.');
$assert(str_contains($valid, "document.getElementById('mTenantCreate').classList.add('open')"), 'Profile form did not open.');
$assert(!str_contains($valid, '<div class="alert error"></div>'), 'Profile form showed an empty error.');
foreach (['missing', 'invalid'] as $scenario) {
    $html = $render($scenario);
    $assert(str_contains($html, 'This reservation is no longer available for tenant profile creation.'), 'Invalid reservation was not reported.');
    $assert(!str_contains($html, "document.getElementById('mTenantCreate').classList.add('open')"), 'Invalid reservation opened a profile form.');
}
echo "Reservation create-profile tests passed.\n";
