<?php
// Shared helpers: escaping, csrf, flash, audit, upload validation
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => APP_ENV === 'production',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function e($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="csrf" value="'.e(csrf_token()).'">'; }
function csrf_check(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $t = $_POST['csrf'] ?? '';
        if (!hash_equals($_SESSION['csrf'] ?? '', (string)$t)) {
            http_response_code(419);
            die('CSRF token mismatch. Please go back and try again.');
        }
    }
}

function flash(string $key, ?string $msg = null) {
    if ($msg !== null) { $_SESSION['flash'][$key] = $msg; return; }
    $m = $_SESSION['flash'][$key] ?? null;
    unset($_SESSION['flash'][$key]);
    return $m;
}

function redirect(string $url): void { header('Location: ' . $url); exit; }

function client_ip(): string {
    $forwarded = trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''))[0]);
    if ($forwarded !== '' && filter_var($forwarded, FILTER_VALIDATE_IP)) return $forwarded;
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

function audit_log(?int $userId, string $action, string $module, ?string $recordId = null): void {
    try {
        $stmt = db()->prepare('INSERT INTO audit_logs (user_id, action, module, record_id, ip_address) VALUES (?,?,?,?,?)');
        $stmt->execute([$userId, $action, $module, $recordId, client_ip()]);
    } catch (Throwable $t) { /* never break app on audit failure */ }
}

function purge_expired_tenant_notifications(PDO $pdo, int $recipientId): void {
    $st = $pdo->prepare(
        'DELETE FROM notifications
         WHERE recipient_id = ?
           AND (
             (is_read = 1 AND COALESCE(read_at, created_at) <= DATE_SUB(NOW(), INTERVAL 3 DAY))
             OR (is_read = 0 AND created_at <= DATE_SUB(NOW(), INTERVAL 7 DAY))
           )'
    );
    $st->execute([$recipientId]);
}

// Contract auto-status: Active -> Expiring Soon (<=30d) -> Expired
function contract_computed_status(array $c): string {
    if (in_array($c['status'], ['Draft','Pending','Terminated'], true)) return $c['status'];
    $today = new DateTimeImmutable('today');
    $end = DateTimeImmutable::createFromFormat('Y-m-d', substr((string)$c['end_date'],0,10));
    if (!$end) return $c['status'];
    if ($end < $today) return 'Expired';
    $days = (int)$today->diff($end)->days;
    if ($days <= 30) return 'Expiring Soon';
    return 'Active';
}
function days_remaining(string $endDate): int {
    $today = new DateTimeImmutable('today');
    $end = DateTimeImmutable::createFromFormat('Y-m-d', substr($endDate,0,10));
    if (!$end) return 0;
    $diff = $today->diff($end);
    return $diff->invert ? -$diff->days : $diff->days;
}

function validate_upload(array $file, array $allowedExt, int $maxMb): ?string {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return $file['error'] === UPLOAD_ERR_NO_FILE ? 'No file was uploaded.' : 'Upload failed (error code '.$file['error'].').';
    }
    if (($file['size'] ?? 0) > $maxMb * 1024 * 1024) return 'File too large. Max '.$maxMb.'MB.';
    $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExt, true)) return 'Invalid file type. Allowed: '.implode(', ', $allowedExt).'.';
    // MIME sniff check
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    $okMimes = ['application/pdf','application/msword','application/vnd.openxmlformats-officedocument.wordprocessingml.document','image/jpeg','image/png','image/webp'];
    if (!in_array($mime, $okMimes, true)) return 'File content type not allowed.';
    return null;
}
function safe_store_upload(array $file, string $destDir): string {
    if (!is_dir($destDir)) mkdir($destDir, 0775, true);
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $name = bin2hex(random_bytes(12)) . '.' . $ext;
    $dest = rtrim($destDir,'/\\') . DIRECTORY_SEPARATOR . $name;
    if (!move_uploaded_file($file['tmp_name'], $dest)) throw new RuntimeException('Failed to store uploaded file.');
    return $name;
}

function tenant_account_default_password(string $name): string {
    return (preg_replace('/\s+/', '', trim($name)) ?? '') . '@123';
}

function tenant_provision_account(PDO $pdo, array $profile): array {
    $profileId = (int)($profile['id'] ?? 0);
    $name = trim((string)($profile['full_name'] ?? ''));
    $email = trim((string)($profile['email'] ?? ''));
    if ($profileId <= 0 || $name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('A valid tenant name and email are required to create an account.');
    }

    $st = $pdo->prepare('SELECT id, email, role, status, deleted_at, tenant_profile_id FROM users
        WHERE tenant_profile_id=? OR LOWER(email)=LOWER(?)
        ORDER BY (tenant_profile_id=?) DESC
        LIMIT 1 FOR UPDATE');
    $st->execute([$profileId, $email, $profileId]);
    $existing = $st->fetch();
    if ($existing) {
        if ($existing['deleted_at'] !== null) {
            throw new RuntimeException('This email belongs to a deleted account. Use a different email address or restore the account first.');
        }
        if ($existing['role'] !== 'resident') {
            throw new RuntimeException('This email is already used by a non-tenant account.');
        }
        if (!empty($existing['tenant_profile_id']) && (int)$existing['tenant_profile_id'] !== $profileId) {
            throw new RuntimeException('This email is already linked to another tenant profile.');
        }
        if (empty($existing['tenant_profile_id'])) {
            $pdo->prepare('UPDATE users SET tenant_profile_id=? WHERE id=?')->execute([$profileId, (int)$existing['id']]);
        }
        $userId = (int)$existing['id'];
        $accountStatus = $existing['status'];
    } else {
        $password = tenant_account_default_password($name);
        $accountStatus = ($profile['profile_status'] ?? 'Active') === 'Active' ? 'active' : 'inactive';
        $ins = $pdo->prepare('INSERT INTO users (name, email, password_hash, role, phone, status, tenant_profile_id) VALUES (?,?,?,?,?,?,?)');
        $ins->execute([
            $name,
            $email,
            password_hash($password, PASSWORD_DEFAULT),
            'resident',
            trim((string)($profile['phone'] ?? '')) ?: null,
            $accountStatus,
            $profileId,
        ]);
        $userId = (int)$pdo->lastInsertId();
    }

    $resident = $pdo->prepare('SELECT residents_id FROM residents WHERE user_id=? LIMIT 1 FOR UPDATE');
    $resident->execute([$userId]);
    if (!$resident->fetchColumn()) {
        $pdo->prepare('INSERT INTO residents (user_id, room_id, status) VALUES (?,?,?)')
            ->execute([
                $userId,
                (int)($profile['room_id'] ?? 0) ?: null,
                ($profile['profile_status'] ?? 'Active') === 'Active' ? 'active' : 'inactive',
            ]);
    }

    if ($existing) {
        return [
            'created' => false,
            'user_id' => (int)$existing['id'],
            'email' => $existing['email'],
            'status' => $existing['status'],
            'password' => null,
        ];
    }
    return ['created' => true, 'user_id' => $userId, 'email' => $email, 'status' => $accountStatus, 'password' => $password];
}

function tenant_send_account_email(array $profile, ?string $password, ?string &$error = null): bool {
    $name = trim((string)($profile['full_name'] ?? 'Tenant'));
    $email = trim((string)($profile['email'] ?? ''));
    $body = "Dear {$name},\r\n\r\nThank you for choosing Heart Ladies Dormitory.\r\n\r\n";
    $body .= "Your tenant portal account is ready.\r\n";
    $body .= "User account: {$email}\r\n";
    if ($password !== null) {
        $body .= "Password: {$password}\r\n";
    } else {
        $body .= "Use your existing password to log in. If you have forgotten it, please contact the dormitory administrator.\r\n";
    }
    if (($profile['profile_status'] ?? 'Active') !== 'Active') {
        $body .= "\r\nYour account is inactive until your tenant profile is activated. Please contact the dormitory administrator.\r\n";
    } elseif (($profile['account_status'] ?? 'active') !== 'active') {
        $body .= "\r\nYour account is currently inactive. Please contact the dormitory administrator for assistance.\r\n";
    }
    $body .= "\r\nYou can change your password after logging in from My Profile.\r\n\r\nThank you,\r\nHeart Ladies Dormitory";

    return send_app_mail($email, 'Welcome to Heart Ladies Dormitory', $body, $error);
}

function money(float|int|string $n): string { return '₱' . number_format((float)$n, 2); }

// Tenant context helpers
function get_tenant_context(array $user): ?array {
    $pdo = db();
    $ctx = ['user' => $user, 'resident' => null, 'tenant_profile' => null];
    try {
        // Try residents table
        $st = $pdo->prepare('SELECT * FROM residents WHERE user_id = ? LIMIT 1');
        $st->execute([(int)$user['id']]);
        $ctx['resident'] = $st->fetch() ?: null;
        if ($ctx['resident']) {
            // Also try to link resident_link_id
            if (empty($user['resident_link_id'])) {
                try { $pdo->prepare('UPDATE users SET resident_link_id = ? WHERE id = ?')->execute([(int)$ctx['resident']['residents_id'], (int)$user['id']]); } catch (Throwable $t) {}
            }
        }
        // Resolve tenant profiles by explicit link, or by a unique email match.
        $tpId = $user['tenant_profile_id'] ?? null;
        if ($tpId) {
            $st2 = $pdo->prepare('SELECT * FROM tenant_profiles WHERE id = ? LIMIT 1');
            $st2->execute([(int)$tpId]);
            $ctx['tenant_profile'] = $st2->fetch() ?: null;
        } elseif (!empty($user['email'])) {
            $st2 = $pdo->prepare('SELECT * FROM tenant_profiles WHERE LOWER(email)=LOWER(?) LIMIT 2');
            $st2->execute([$user['email']]);
            $matches = $st2->fetchAll();
            $ctx['tenant_profile'] = count($matches) === 1 ? $matches[0] : null;
        }
        if ($ctx['tenant_profile'] && empty($user['tenant_profile_id'])) {
            try { $pdo->prepare('UPDATE users SET tenant_profile_id = ? WHERE id = ?')->execute([(int)$ctx['tenant_profile']['id'], (int)$user['id']]); } catch (Throwable $t) {}
        }
    } catch (Throwable $t) {}
    return $ctx;
}

function is_tenant_owner_of_bill(array $user, int $billId): bool {
    if (!in_array($user['role'] ?? '', ['resident'], true)) return false;
    try {
        $pdo = db();
        $ctx = get_tenant_context($user);
        // Check via residents
        if ($ctx['resident']) {
            $st = $pdo->prepare('SELECT COUNT(*) FROM bills WHERE id = ? AND resident_id = ?');
            $st->execute([$billId, (int)$ctx['resident']['residents_id']]);
            if ((int)$st->fetchColumn() > 0) return true;
        }
        $st = $pdo->prepare('SELECT COUNT(*) FROM bills b JOIN tenant_applications ta ON ta.id = b.application_id WHERE b.id = ? AND (b.resident_id IS NULL OR b.resident_id = 0) AND ta.user_id = ?');
        $st->execute([$billId, (int)$user['id']]);
        if ((int)$st->fetchColumn() > 0) return true;
    } catch (Throwable $t) {}
    return false;
}

// ---------- Room occupancy / tenant bed assignment ----------
// Recompute a room's status from occupied beds. Manually blocked statuses are preserved.
function refresh_room_status(int $roomId): void {
    try {
        $pdo = db();
        $st = $pdo->prepare('SELECT status, max_occupants FROM rooms WHERE id=?');
        $st->execute([$roomId]);
        $r = $st->fetch();
        if (!$r) return;
        if (in_array($r['status'], ['Reserved', 'Under Maintenance', 'Unavailable'], true)) return;
        $c = $pdo->prepare("SELECT COUNT(*) FROM beds WHERE room_id=? AND status='occupied'");
        $c->execute([$roomId]);
        $occ = (int)$c->fetchColumn();
        $max = max(1, (int)$r['max_occupants']);
        $new = $occ <= 0 ? 'Available' : ($occ >= $max ? 'Fully Occupied' : 'Partially Occupied');
        if ($new !== $r['status']) {
            $u = $pdo->prepare('UPDATE rooms SET status=? WHERE id=?');
            $u->execute([$new, $roomId]);
        }
    } catch (Throwable $t) { error_log('refresh_room_status: ' . $t->getMessage()); }
}

// Make sure a room has at least max_occupants bed rows before assigning a tenant.
function ensure_room_beds(PDO $pdo, int $roomId): void {
    $st = $pdo->prepare('SELECT max_occupants FROM rooms WHERE id=?');
    $st->execute([$roomId]);
    $max = (int)$st->fetchColumn();
    if ($max < 1) return;
    $cnt = $pdo->prepare('SELECT COUNT(*) FROM beds WHERE room_id=?');
    $cnt->execute([$roomId]);
    $need = $max - (int)$cnt->fetchColumn();
    if ($need <= 0) return;
    $ins = $pdo->prepare("INSERT IGNORE INTO beds (room_id, bed_number, status) VALUES (?,?,'vacant')");
    // Insert only the missing number of beds. The attempt cap avoids an endless
    // loop when a legacy room uses custom labels that collide with B1..B12.
    for ($i = 1; $need > 0 && $i <= 12; $i++) {
        $ins->execute([$roomId, 'B' . $i]);
        if ($ins->rowCount() > 0) $need--;
    }
}

// Reserve the first vacant bed for a tenant profile. Call inside a transaction.
// $keepBedId keeps the current bed when a profile is edited without moving rooms.
function assign_tenant_bed(PDO $pdo, int $roomId, ?int $keepBedId = null): int {
    ensure_room_beds($pdo, $roomId);

    if ($keepBedId !== null && $keepBedId > 0) {
        $st = $pdo->prepare('SELECT status, resident_id FROM beds WHERE id=? AND room_id=? FOR UPDATE');
        $st->execute([$keepBedId, $roomId]);
        $bed = $st->fetch();
        if ($bed && $bed['resident_id'] === null && !in_array($bed['status'], ['reserved', 'maintenance'], true)) {
            if ($bed['status'] !== 'occupied') {
                $pdo->prepare("UPDATE beds SET status='occupied' WHERE id=?")->execute([$keepBedId]);
            }
            return (int)$keepBedId;
        }
    }

    $st = $pdo->prepare("SELECT id FROM beds WHERE room_id=? AND status='vacant' AND resident_id IS NULL
                         ORDER BY CAST(SUBSTRING(bed_number,2) AS UNSIGNED), id LIMIT 1 FOR UPDATE");
    $st->execute([$roomId]);
    $bedId = (int)$st->fetchColumn();
    if ($bedId <= 0) throw new RuntimeException('This room is fully occupied. No available bed for this tenant.');

    $upd = $pdo->prepare("UPDATE beds SET status='occupied' WHERE id=? AND status='vacant'");
    $upd->execute([$bedId]);
    if ($upd->rowCount() < 1) throw new RuntimeException('That bed was just taken. Please try again.');
    return $bedId;
}

// Release a tenant-profile bed back to "vacant" unless a resident or another profile uses it.
function release_tenant_bed(PDO $pdo, ?int $bedId): void {
    if (!$bedId) return;
    $st = $pdo->prepare('SELECT resident_id FROM beds WHERE id=? FOR UPDATE');
    $st->execute([$bedId]);
    $bed = $st->fetch();
    if (!$bed || $bed['resident_id'] !== null) return;
    $chk = $pdo->prepare('SELECT COUNT(*) FROM tenant_profiles WHERE bed_id=?');
    $chk->execute([$bedId]);
    if ((int)$chk->fetchColumn() > 0) return;
    $pdo->prepare("UPDATE beds SET status='vacant' WHERE id=?")->execute([$bedId]);
}

// Keep a tenant profile's contract row in sync with its room and contract dates.
// Returns true when a contract was created or updated. Call inside a transaction.
function sync_tenant_contract(PDO $pdo, int $profileId, ?int $roomId, ?string $start, ?string $end, ?int $createdBy = null): bool {
    $profileId = (int)$profileId;
    if ($profileId <= 0) return false;

    $start = trim((string)$start);
    $end = trim((string)$end);

    // Both dates cleared means the tenant no longer has a contract.
    if ($start === '' && $end === '') {
        $pdo->prepare('DELETE FROM contracts WHERE tenant_profile_id=?')->execute([$profileId]);
        return false;
    }
    if ($roomId === null || $roomId <= 0 || $start === '' || $end === '' || $start > $end) return false;

    $existingSt = $pdo->prepare('SELECT id, status FROM contracts WHERE tenant_profile_id=? LIMIT 1');
    $existingSt->execute([$profileId]);
    $existing = $existingSt->fetch();

    $rateSt = $pdo->prepare('SELECT monthly_rate FROM rooms WHERE id=?');
    $rateSt->execute([$roomId]);
    $rate = (float)$rateSt->fetchColumn();

    if ($existing) {
        $status = $existing['status'] === 'Terminated' ? 'Terminated' : 'Active';
        $upd = $pdo->prepare('UPDATE contracts SET room_id=?, start_date=?, end_date=?, monthly_rate=?, status=? WHERE id=?');
        $upd->execute([$roomId, $start, $end, $rate, $status, (int)$existing['id']]);
        return true;
    }

    $ins = $pdo->prepare("INSERT INTO contracts
        (contract_number, tenant_profile_id, room_id, start_date, end_date, monthly_rate, status, terms, created_by)
        VALUES (?,?,?,?,?,?,?,?,?)");
    $ins->execute([
        'CTR-T' . $profileId,
        $profileId,
        $roomId,
        $start,
        $end,
        $rate,
        'Active',
        'Created automatically from tenant profile.',
        $createdBy ?: null,
    ]);
    return true;
}

// ---------- Email delivery (contract reminders) ----------
// Uses SMTP when MAIL_HOST is set, otherwise PHP mail(). Returns true on success.
function smtp_format_name(string $name): string {
    $name = str_replace(["\r", "\n", '"'], '', $name);
    return preg_match('/[^\x20-\x7E]/', $name) ? '=?UTF-8?B?' . base64_encode($name) . '?=' : '"' . $name . '"';
}
function smtp_read_response($fp): string {
    $out = '';
    while (!feof($fp)) {
        $line = fgets($fp, 1024);
        if ($line === false) break;
        $out .= $line;
        if (strlen($line) >= 4 && $line[3] === ' ') break;
    }
    return $out;
}
function smtp_expect($fp, array $codes, ?string &$error = null): bool {
    $resp = smtp_read_response($fp);
    $code = (int)substr($resp, 0, 3);
    if (!in_array($code, $codes, true)) {
        $error = trim($resp) ?: 'Unexpected SMTP response.';
        return false;
    }
    return true;
}
function smtp_cmd($fp, string $command, array $codes, ?string &$error = null): bool {
    if (@fwrite($fp, $command . "\r\n") === false) {
        $error = 'Could not write to the SMTP server.';
        return false;
    }
    return smtp_expect($fp, $codes, $error);
}
function smtp_send_mail(string $to, string $subject, string $body, string $from, string $fromName, string $replyTo, ?string &$error = null): bool {
    $host = trim((string)envv('MAIL_HOST', ''));
    if ($host === '') return false;
    $enc = strtolower(trim((string)envv('MAIL_ENCRYPTION', 'tls')));
    if (!in_array($enc, ['tls', 'ssl', 'none', ''], true)) $enc = 'tls';
    $port = (int)envv('MAIL_PORT', $enc === 'ssl' ? 465 : 587);
    if ($port <= 0) $port = $enc === 'ssl' ? 465 : 587;
    $user = (string)envv('MAIL_USER', '');
    $pass = (string)envv('MAIL_PASS', '');
    $remote = ($enc === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false]]);
    $fp = @stream_socket_client($remote, $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) {
        $error = 'SMTP connection failed: ' . ($errstr ?: 'unknown error');
        return false;
    }
    stream_set_timeout($fp, 20);
    $ok = false;
    try {
        if (!smtp_expect($fp, [220], $error)) throw new RuntimeException($error ?: 'SMTP greeting failed.');
        $hostname = gethostname() ?: 'localhost';
        if (!smtp_cmd($fp, 'EHLO ' . $hostname, [250], $error)) throw new RuntimeException($error ?: 'EHLO failed.');
        if ($enc === 'tls') {
            if (!smtp_cmd($fp, 'STARTTLS', [220], $error)) throw new RuntimeException($error ?: 'STARTTLS failed.');
            if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new RuntimeException('Could not enable TLS.');
            if (!smtp_cmd($fp, 'EHLO ' . $hostname, [250], $error)) throw new RuntimeException($error ?: 'EHLO after TLS failed.');
        }
        if ($user !== '') {
            if (!smtp_cmd($fp, 'AUTH LOGIN', [334], $error)) throw new RuntimeException($error ?: 'SMTP authentication not accepted.');
            if (!smtp_cmd($fp, base64_encode($user), [334], $error)) throw new RuntimeException($error ?: 'SMTP username rejected.');
            if (!smtp_cmd($fp, base64_encode($pass), [235], $error)) throw new RuntimeException('SMTP authentication failed: ' . ($error ?: 'check MAIL_USER / MAIL_PASS.'));
        }
        if (!smtp_cmd($fp, 'MAIL FROM:<' . $from . '>', [250], $error)) throw new RuntimeException($error ?: 'MAIL FROM rejected.');
        if (!smtp_cmd($fp, 'RCPT TO:<' . $to . '>', [250, 251], $error)) throw new RuntimeException($error ?: 'RCPT TO rejected.');
        if (!smtp_cmd($fp, 'DATA', [354], $error)) throw new RuntimeException($error ?: 'DATA rejected.');

        $headers = [
            'Date: ' . date('r'),
            'From: ' . smtp_format_name($fromName) . ' <' . $from . '>',
            'To: <' . $to . '>',
            'Reply-To: ' . ($replyTo ?: $from),
            'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ];
        $data = implode("\r\n", $headers) . "\r\n\r\n" . preg_replace('/\r\n|\r|\n/', "\r\n", $body);
        $data = preg_replace('/^\./m', '..', $data);
        fwrite($fp, rtrim($data, "\r\n") . "\r\n.\r\n");
        if (!smtp_expect($fp, [250], $error)) throw new RuntimeException($error ?: 'Message was not accepted.');
        @smtp_cmd($fp, 'QUIT', [221], $error);
        $ok = true;
    } catch (Throwable $t) {
        if (!$error) $error = $t->getMessage();
    }
    @fclose($fp);
    return $ok;
}
/** True when mail goes to the bundled Mailpit catcher instead of a real inbox. */
function mail_uses_local_catcher(): bool {
    $host = strtolower(trim((string)envv('MAIL_HOST', '')));
    $port = trim((string)envv('MAIL_PORT', ''));
    return in_array($host, ['mailpit', 'localhost', '127.0.0.1', '::1'], true) && $port === '1025';
}
function send_app_mail(string $to, string $subject, string $body, ?string &$error = null): bool {
    $to = trim($to);
    $error = null;
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) { $error = 'Invalid recipient email address.'; return false; }

    $from = trim((string)envv('MAIL_FROM', 'no-reply@heartdorm.local'));
    if (!filter_var($from, FILTER_VALIDATE_EMAIL)) $from = 'no-reply@heartdorm.local';
    $fromName = trim((string)envv('MAIL_FROM_NAME', 'Heart Ladies Dormitory')) ?: 'Heart Ladies Dormitory';
    $replyTo = trim((string)envv('MAIL_REPLY_TO', ''));
    if ($replyTo !== '' && !filter_var($replyTo, FILTER_VALIDATE_EMAIL)) $replyTo = '';
    $subject = str_replace(["\r", "\n"], ' ', $subject);

    if (trim((string)envv('MAIL_HOST', '')) !== '') {
        $ok = smtp_send_mail($to, $subject, $body, $from, $fromName, $replyTo, $error);
    } else {
        $headers = [
            'From: ' . smtp_format_name($fromName) . ' <' . $from . '>',
            'Reply-To: ' . ($replyTo ?: $from),
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ];
        $ok = @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, implode("\r\n", $headers));
        if (!$ok) $error = 'PHP mail() could not send the message. Install sendmail or set MAIL_HOST / SMTP settings in .env.';
    }

    try {
        $log = __DIR__ . '/../uploads/mail.log';
        @file_put_contents($log, date('c') . ' | ' . ($ok ? 'SENT' : 'FAILED') . ' | to=' . $to . ' | subject=' . $subject . ($error ? ' | error=' . $error : '') . PHP_EOL, FILE_APPEND | LOCK_EX);
    } catch (Throwable $t) { /* logging must never break the app */ }
    return (bool)$ok;
}
