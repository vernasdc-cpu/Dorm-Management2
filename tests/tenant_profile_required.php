<?php
require __DIR__ . '/../includes/tenant_profile_helpers.php';

$photo = ['error' => UPLOAD_ERR_OK];
tenant_validate_new_profile_details('09123456789', '2026-01-01', '2027-01-01', $photo);
tenant_validate_contract_dates('', '');

$cases = [
    ['', '2026-01-01', '2027-01-01', $photo, 'phone'],
    ['   ', '2026-01-01', '2027-01-01', $photo, 'phone'],
    ['0912', '', '', $photo, 'Both contract dates'],
    ['0912', '2026-01-01', '', $photo, 'Both contract dates'],
    ['0912', '2027-01-01', '2026-01-01', $photo, 'on or before'],
    ['0912', '2026-01-01', '2027-01-01', null, 'picture'],
    ['0912', '2026-01-01', '2027-01-01', ['error' => UPLOAD_ERR_NO_FILE], 'picture'],
];
foreach ($cases as [$phone, $start, $end, $image, $message]) {
    try {
        tenant_validate_new_profile_details($phone, $start, $end, $image);
    } catch (RuntimeException $exception) {
        if (str_contains($exception->getMessage(), $message)) continue;
        throw $exception;
    }
    throw new RuntimeException('Missing or invalid profile detail was accepted: ' . $message);
}
echo "Tenant profile required-field tests passed.\n";
