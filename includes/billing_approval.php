<?php
require_once __DIR__ . '/tenant_files.php';

function billing_approval_cents(string $amount): int {
    if (!preg_match('/\A(\d{1,8})(?:\.(\d{1,2}))?\z/', $amount, $parts)) {
        throw new RuntimeException('Invalid payment amount.');
    }
    return (int)$parts[1] * 100 + (int)str_pad($parts[2] ?? '', 2, '0');
}

function billing_approval_write_documents(array $submission, int $billId, array $reviewer): array {
    $autoload = is_file('/opt/dorm-dependencies/vendor/autoload.php')
        ? '/opt/dorm-dependencies/vendor/autoload.php' : __DIR__ . '/../vendor/autoload.php';
    if (!is_file($autoload)) throw new RuntimeException('PDF generation is unavailable. Install the application dependencies.');
    require_once $autoload;
    if (!extension_loaded('gd') || !function_exists('imagejpeg')) throw new RuntimeException('Image conversion is unavailable. Enable GD with JPEG support.');

    $directory = __DIR__ . '/../uploads/billing';
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Could not create the billing document directory.');
    }
    $original = tenant_file_resolve((string)($submission['screenshot_path'] ?? ''), $directory);
    if (empty($submission['screenshot_path']) || !is_file($original)) throw new RuntimeException('The payment screenshot is missing.');
    $imageInfo = getimagesize($original);
    if (!$imageInfo || !in_array($imageInfo['mime'], ['image/jpeg', 'image/png', 'image/webp'], true)
        || $imageInfo[0] * $imageInfo[1] > 8000000) {
        throw new RuntimeException('The payment screenshot is invalid or too large to convert.');
    }
    $imageData = file_get_contents($original);
    if ($imageData === false || strlen($imageData) > 5 * 1024 * 1024) throw new RuntimeException('Could not read the payment screenshot.');
    $image = imagecreatefromstring($imageData);
    if ($image === false) throw new RuntimeException('Could not decode the payment screenshot.');
    $canvas = imagecreatetruecolor($imageInfo[0], $imageInfo[1]);
    if ($canvas === false) {
        imagedestroy($image);
        throw new RuntimeException('Could not convert the payment screenshot.');
    }
    imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
    imagecopy($canvas, $image, 0, 0, 0, 0, $imageInfo[0], $imageInfo[1]);
    imagedestroy($image);

    $profileId = (int)($submission['tenant_profile_id'] ?? 0);
    $invoiceName = $profileId ? tenant_file_new_path(db(), $profileId, 'Invoices', 'pdf') : bin2hex(random_bytes(16)) . '.pdf';
    $proofName = $profileId ? tenant_file_new_path(db(), $profileId, 'Payments', 'jpg') : bin2hex(random_bytes(16)) . '.jpg';
    $invoicePath = tenant_file_resolve($invoiceName, $directory);
    $proofPath = tenant_file_resolve($proofName, $directory);
    try {
        if (!imagejpeg($canvas, $proofPath, 90)) throw new RuntimeException('Could not save the payment screenshot.');
        $previousGet = $_GET;
        try {
            $_GET = ['id' => (string)$billId];
            $user = $reviewer;
            ob_start();
            try {
                include __DIR__ . '/../pages/invoice.php';
                $html = ob_get_clean();
            } catch (Throwable $t) {
                ob_end_clean();
                throw $t;
            }
        } finally {
            $_GET = $previousGet;
        }
        if (!str_contains($html, '<div class="inv">')) throw new RuntimeException('Could not render the invoice.');
        $logo = file_get_contents(__DIR__ . '/../assets/img/logo.jpg');
        if ($logo === false) throw new RuntimeException('Could not load the invoice logo.');
        $html = str_replace('src="assets/img/logo.jpg"', 'src="data:image/jpeg;base64,' . base64_encode($logo) . '"', $html);
        $options = new Dompdf\Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $pdf = new Dompdf\Dompdf($options);
        $pdf->loadHtml('<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>' . $html . '</body></html>', 'UTF-8');
        $pdf->setPaper('A4');
        $pdf->render();
        $bytes = $pdf->output();
        if (!str_starts_with($bytes, '%PDF-') || file_put_contents($invoicePath, $bytes, LOCK_EX) !== strlen($bytes)) {
            throw new RuntimeException('Could not save the invoice PDF.');
        }
        return [$invoiceName, $proofName];
    } catch (Throwable $t) {
        foreach ([$invoicePath, $proofPath] as $path) {
            if (is_file($path) && !unlink($path)) error_log('Could not remove failed billing document: ' . $path);
        }
        throw $t;
    } finally {
        imagedestroy($canvas);
    }
}

function billing_approve_submission(PDO $pdo, int $submissionId, array $reviewer, array $input): void {
    $billChoice = is_string($input['bill_id'] ?? null) ? $input['bill_id'] : '';
    $method = is_string($input['payment_method'] ?? null) ? $input['payment_method'] : '';
    $reference = is_string($input['reference_no'] ?? null) ? trim($input['reference_no']) : '';
    $note = is_string($input['review_note'] ?? null) ? trim($input['review_note']) : '';
    if (!in_array($method, ['Cash', 'GCash', 'Bank Transfer'], true)) throw new RuntimeException('Select a valid payment method.');
    if (mb_strlen($reference) > 60) throw new RuntimeException('Reference number must be at most 60 characters.');
    if ($billChoice !== 'new' && !preg_match('/\A[1-9][0-9]*\z/', $billChoice)) throw new RuntimeException('Select a bill or choose to create one.');

    $stored = [];
    try {
        $pdo->beginTransaction();
        $st = $pdo->prepare('SELECT * FROM tenant_billing_payments WHERE id=? FOR UPDATE');
        $st->execute([$submissionId]);
        $sub = $st->fetch();
        if (!$sub || !in_array($sub['status'], ['Pending', 'Edit Requested', 'Rejected'], true)) throw new RuntimeException('This submission is not available for approval.');
        $amount = billing_approval_cents((string)$sub['amount_paid']);
        if ($amount <= 0) throw new RuntimeException('The submitted payment amount must be greater than zero.');
        $residentSt = $pdo->prepare('SELECT r.residents_id, r.room_id, u.tenant_profile_id FROM users u JOIN residents r ON r.user_id=u.id WHERE u.id=? AND u.deleted_at IS NULL AND u.role=\'resident\' FOR UPDATE');
        $residentSt->execute([(int)$sub['user_id']]);
        $resident = $residentSt->fetch();
        if (!$resident || (!empty($sub['resident_id']) && (int)$sub['resident_id'] !== (int)$resident['residents_id'])
            || (!empty($sub['tenant_profile_id']) && (int)$sub['tenant_profile_id'] !== (int)$resident['tenant_profile_id'])) {
            throw new RuntimeException('This submission needs a correctly linked resident account before approval.');
        }
        $residentId = (int)$resident['residents_id'];
        if ($billChoice === 'new') {
            $period = is_string($input['billing_period'] ?? null) ? $input['billing_period'] : '';
            if (!preg_match('/\A\d{4}-(0[1-9]|1[0-2])\z/', $period)) throw new RuntimeException('Select a valid billing period for the new bill.');
            if (empty($sub['room_id']) || (int)$sub['room_id'] !== (int)$resident['room_id']) throw new RuntimeException('The submitted room no longer matches the resident account. Resolve the room assignment first.');
            $check = $pdo->prepare('SELECT id FROM bills WHERE resident_id=? AND billing_period=? FOR UPDATE');
            $check->execute([$residentId, $period]);
            if ($check->fetchColumn()) throw new RuntimeException('A bill already exists for that period. Select the existing bill instead.');
            $total = billing_approval_cents((string)$sub['total_price']);
            if ($amount > $total) throw new RuntimeException('The payment exceeds the new bill total.');
            $utilities = billing_approval_cents((string)$sub['electricity']) + billing_approval_cents((string)$sub['water']);
            if (billing_approval_cents((string)$sub['unit_room_price']) + $utilities + billing_approval_cents((string)$sub['others_fee_amount']) !== $total) {
                throw new RuntimeException('The submitted charges do not match the total. Resolve the submission before approval.');
            }
            $invoice = 'INV-SUB-' . $submissionId;
            $ins = $pdo->prepare('INSERT INTO bills (resident_id,room_id,billing_period,room_rental,utilities,additional_charges,total_amount,amount_paid,balance,payment_status,invoice_number,created_by) VALUES (?,?,?,?,?,?,?,0,?,\'Unpaid\',?,?)');
            $ins->execute([$residentId, (int)$sub['room_id'], $period, $sub['unit_room_price'], number_format($utilities / 100, 2, '.', ''), $sub['others_fee_amount'], $sub['total_price'], $sub['total_price'], $invoice, (int)$reviewer['id']]);
            $billId = (int)$pdo->lastInsertId();
        } else {
            $billId = (int)$billChoice;
        }
        $billSt = $pdo->prepare('SELECT * FROM bills WHERE id=? AND resident_id=? FOR UPDATE');
        $billSt->execute([$billId, $residentId]);
        $bill = $billSt->fetch();
        if (!$bill) throw new RuntimeException('The selected bill does not belong to this resident.');
        $total = billing_approval_cents((string)$bill['total_amount']);
        $paid = billing_approval_cents((string)$bill['amount_paid']);
        if ($paid > $total || $amount > $total - $paid) throw new RuntimeException('The payment exceeds the selected bill balance.');
        $newPaid = $paid + $amount;
        $balance = $total - $newPaid;
        $status = $balance === 0 ? 'Paid' : (!empty($bill['due_date']) && substr((string)$bill['due_date'], 0, 10) < date('Y-m-d') ? 'Overdue' : 'Partially Paid');
        $payment = $pdo->prepare('INSERT INTO payments (bill_id,resident_id,amount,payment_method,payment_date,reference_no,receipt_number,received_by) VALUES (?,?,?,?,?,?,?,?)');
        $payment->execute([$billId, $residentId, $sub['amount_paid'], $method, $sub['date_of_payment'], $reference !== '' ? $reference : null, 'OR-SUB-' . $submissionId, (int)$reviewer['id']]);
        $paymentId = (int)$pdo->lastInsertId();
        $pdo->prepare('UPDATE bills SET amount_paid=?, balance=?, payment_status=? WHERE id=?')->execute([number_format($newPaid / 100, 2, '.', ''), number_format($balance / 100, 2, '.', ''), $status, $billId]);
        $sub['tenant_profile_id'] = $resident['tenant_profile_id'];
        $stored = billing_approval_write_documents($sub, $billId, $reviewer);
        $pdo->prepare('INSERT INTO approved_billing_documents (submission_id,user_id,bill_id,payment_id,invoice_path,proof_path,created_at) VALUES (?,?,?,?,?,?,NOW())')
            ->execute([$submissionId, (int)$sub['user_id'], $billId, $paymentId, $stored[0], $stored[1]]);
        if (($input['source'] ?? '') === 'verification') {
            $pdo->prepare("UPDATE users SET status='active' WHERE id=? AND status='inactive'")->execute([(int)$sub['user_id']]);
        }
        $pdo->prepare("UPDATE tenant_billing_payments SET status='Approved',reviewed_by=?,reviewed_at=NOW(),review_note=? WHERE id=?")->execute([(int)$reviewer['id'], $note !== '' ? $note : null, $submissionId]);
        audit_log((int)$reviewer['id'], 'Approved billing payment #' . $submissionId . ' and recorded payment #' . $paymentId, 'billing', (string)$submissionId);
        $pdo->commit();
    } catch (Throwable $t) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        foreach ($stored as $name) {
            tenant_file_remove($name, __DIR__ . '/../uploads/billing');
        }
        throw $t;
    }
}
