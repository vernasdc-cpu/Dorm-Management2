<?php
require __DIR__ . '/../includes/billing_approval.php';

function e($value): string { return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8'); }
function money(float|int|string $value): string { return 'P' . number_format((float)$value, 2); }

class BillingDocumentTestStatement {
    public function __construct(private bool $payments) {}
    public function execute(array $args): bool { return true; }
    public function fetch(): array {
        return [
            'resident_name' => 'Test Tenant', 'resident_email' => 'tenant@example.test', 'resident_phone' => '',
            'room_number' => '301', 'billing_period' => '2026-01', 'invoice_number' => 'INV-TEST',
            'created_at' => '2026-01-01', 'due_date' => null, 'payment_status' => 'Partially Paid',
            'room_rental' => '1000.00', 'utilities' => '0.00', 'additional_charges' => '0.00',
            'discounts' => '0.00', 'penalties' => '0.00', 'total_amount' => '1000.00',
            'amount_paid' => '250.00', 'balance' => '750.00',
        ];
    }
    public function fetchAll(): array {
        return $this->payments ? [['payment_date' => '2026-01-02', 'receipt_number' => 'OR-TEST', 'payment_method' => 'Cash', 'amount' => '250.00']] : [];
    }
}
class BillingDocumentTestDatabase {
    public function prepare(string $sql): BillingDocumentTestStatement {
        return new BillingDocumentTestStatement(str_contains($sql, 'FROM payments'));
    }
}
function db(): BillingDocumentTestDatabase { return new BillingDocumentTestDatabase(); }

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
foreach (['0.01' => 1, '12.3' => 1230, '99999999.99' => 9999999999] as $input => $expected) {
    $assert(billing_approval_cents($input) === $expected, 'Incorrect cents conversion.');
}
foreach (['-1', '1.001', '100000000.00', 'abc'] as $input) {
    try {
        billing_approval_cents($input);
        throw new LogicException('Invalid amount was accepted.');
    } catch (RuntimeException $expected) {}
}

$directory = __DIR__ . '/../uploads/billing';
if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) throw new RuntimeException('Cannot create test directory.');
$files = [];
try {
    foreach (['png', 'jpeg', 'webp'] as $format) {
        $input = 'test-' . bin2hex(random_bytes(12)) . '.' . $format;
        $files[] = $input;
        $image = imagecreatetruecolor(2, 2);
        $writeImage = 'image' . $format;
        if (!$image || !$writeImage($image, $directory . '/' . $input)) throw new RuntimeException('Cannot create test screenshot.');
        imagedestroy($image);
        $documents = billing_approval_write_documents(['screenshot_path' => $input], 1, ['id' => 1, 'role' => 'super_admin']);
        $files = array_merge($files, $documents);
        $assert(str_starts_with((string)file_get_contents($directory . '/' . $documents[0]), '%PDF-'), 'Invoice was not a PDF.');
        $assert(substr((string)file_get_contents($directory . '/' . $documents[1]), 0, 3) === "\xff\xd8\xff", 'Proof was not a JPEG.');
    }
    try {
        billing_approval_write_documents(['screenshot_path' => 'missing-test-screenshot'], 1, ['id' => 1, 'role' => 'super_admin']);
        throw new LogicException('Missing screenshot was accepted.');
    } catch (RuntimeException $expected) {}
} finally {
    foreach ($files as $name) {
        $path = $directory . '/' . $name;
        if (is_file($path) && !unlink($path)) throw new RuntimeException('Could not remove test file.');
    }
}
echo "Billing approval document tests passed.\n";
