<?php

require_once __DIR__ . '/tenant_files.php';

function tenant_store_photo(?array $file, ?string $existing = null, ?int $profileId = null): ?string {
    if (!$file || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return $existing;
    $msg = validate_upload($file, ['jpg', 'jpeg', 'png', 'webp'], 2);
    if ($msg) throw new RuntimeException('2x2 picture upload failed: ' . $msg);
    try {
        return $profileId ? tenant_file_store_upload(db(), $profileId, 'Profile', $file) : safe_store_upload($file, __DIR__ . '/../uploads/tenants');
    } catch (Throwable $t) {
        error_log('Could not save tenant photo: ' . $t->getMessage());
        throw new RuntimeException('2x2 picture could not be saved. Check private storage permissions.', 0, $t);
    }
}

function tenant_remove_photo(?string $photo): void {
    if (!$photo) return;
    tenant_file_remove($photo, __DIR__ . '/../uploads/tenants');
}

function tenant_validate_contract_dates(string $start, string $end): void {
    foreach ([$start, $end] as $date) {
        if ($date === '') continue;
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$dt || $dt->format('Y-m-d') !== $date) throw new RuntimeException('Contract dates must be valid dates.');
    }

    if (($start === '') !== ($end === '')) throw new RuntimeException('Please enter both contract dates, or leave both blank.');
    if ($start !== '' && $start > $end) throw new RuntimeException('Contract start date must be on or before the end date.');
}

function tenant_validate_new_profile_details(string $phone, string $start, string $end, ?array $photo): void {
    if (trim($phone) === '' || mb_strlen(trim($phone)) > 40) throw new RuntimeException('Enter a phone number of at most 40 characters.');
    if ($start === '' || $end === '') throw new RuntimeException('Both contract dates are required for a new tenant profile.');
    tenant_validate_contract_dates($start, $end);
    if (!$photo || ($photo['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) throw new RuntimeException('A 2x2 picture is required for a new tenant profile.');
}
