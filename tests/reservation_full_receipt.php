<?php
define('APP_ENV', 'testing');
require __DIR__ . '/../includes/helpers.php';

$reservation = [
    'id' => 12, 'amount' => '999999999.99', 'received_at' => '2026-03-01 10:30:00',
    'full_name' => 'Tenant', 'payment_classification' => 'Partial',
];
$payment = [
    'id' => 34, 'reservation_id' => 12, 'amount' => '999999999.99',
    'received_at' => '2026-04-02 09:00:00', 'is_full_summary' => 1,
];
$full = reservation_full_receipt_data($reservation, $payment);
if ($full['id'] !== 34 || $full['amount'] !== '1999999999.98' || $full['partial_receipt_id'] !== 12 ||
    $full['partial_amount'] !== '999999999.99' || $full['partial_received_at'] !== '2026-03-01 10:30:00' ||
    $full['balance_amount'] !== '999999999.99' || $full['payment_classification'] !== 'Full') {
    throw new RuntimeException('Full receipt did not preserve the partial payment and exact total.');
}
$reservation['amount'] = '1234.56';
$payment['amount'] = '8765.45';
if (reservation_full_receipt_data($reservation, $payment)['amount'] !== '10000.01') {
    throw new RuntimeException('Full receipt did not add centavos correctly.');
}
$payment['is_full_summary'] = 0;
$legacy = reservation_full_receipt_data($reservation, $payment);
if ($legacy['amount'] !== '8765.45' || isset($legacy['partial_amount'])) {
    throw new RuntimeException('Previously issued balance receipt was changed.');
}
echo "Reservation full receipt tests passed.\n";
