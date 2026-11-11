<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/helpers.php';

function current_user(): ?array {
    if (empty($_SESSION['uid'])) return null;
    static $cache = null;
    if ($cache !== null) return $cache;
    $stmt = db()->prepare('SELECT u.*, r.residents_id AS resident_id, r.room_id AS resident_room_id FROM users u LEFT JOIN residents r ON r.user_id = u.id WHERE u.id = ? AND u.deleted_at IS NULL LIMIT 1');
    $stmt->execute([$_SESSION['uid']]);
    $cache = $stmt->fetch() ?: null;
    return $cache;
}

function require_login(): array {
    $u = current_user();
    if (!$u) { redirect('index.php?page=login'); }
    return $u;
}

function require_roles(array $roles): array {
    $u = require_login();
    if (!in_array($u['role'], $roles, true)) {
        http_response_code(403);
        die('Forbidden: your role (' . e($u['role']) . ') cannot access this page.');
    }
    return $u;
}

function login_rate_keys(string $identifier, string $ip): array {
    $accountKey = hash_hmac('sha256', "account\0" . strtolower(trim($identifier)) . "\0" . $ip, APP_KEY);
    $ipKey = hash_hmac('sha256', "ip\0" . $ip, APP_KEY);
    return [$accountKey, $ipKey];
}

// Limit to five failures per 10 minutes for both an IP/account pair and the IP.
function login_allowed(string $identifier, string $ip): bool {
    [$accountKey, $ipKey] = login_rate_keys($identifier, $ip);
    $pdo = db();
    $pdo->exec('DELETE FROM login_rate_limits WHERE window_started_at < DATE_SUB(NOW(), INTERVAL 1 DAY)');
    $pdo->beginTransaction();
    $insert = $pdo->prepare('INSERT IGNORE INTO login_rate_limits (rate_key, failed_attempts, window_started_at)
        VALUES (?, 0, NOW())');
    $insert->execute([$accountKey]);
    $insert->execute([$ipKey]);
    $reset = $pdo->prepare('UPDATE login_rate_limits SET failed_attempts=0, window_started_at=NOW()
        WHERE rate_key IN (?, ?) AND window_started_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)');
    $reset->execute([$accountKey, $ipKey]);
    $st = $pdo->prepare('SELECT rate_key, failed_attempts, window_started_at FROM login_rate_limits
        WHERE rate_key IN (?, ?) FOR UPDATE');
    $st->execute([$accountKey, $ipKey]);
    $rows = $st->fetchAll();
    foreach ($rows as $row) {
        if ((int)$row['failed_attempts'] >= 5) {
            $pdo->commit();
            return false;
        }
    }
    return true;
}

function login_register_attempt(string $identifier, string $ip, bool $success): void {
    [$accountKey, $ipKey] = login_rate_keys($identifier, $ip);
    $pdo = db();
    if ($success) {
        $st = $pdo->prepare('DELETE FROM login_rate_limits WHERE rate_key=?');
        $st->execute([$accountKey]);
    } else {
        $st = $pdo->prepare('UPDATE login_rate_limits SET failed_attempts=failed_attempts+1 WHERE rate_key IN (?, ?)');
        $st->execute([$accountKey, $ipKey]);
    }
    $pdo->commit();
}

function permission_matrix(): array {
    return [
        'super_admin'  => ['*'],
        'admin_staff'  => ['residents','rooms','allocations','contracts','billing','inventory','repairs','notifications','reports'],
        'housekeeping' => ['housekeeping_rooms','housekeeping','inventory','repairs_assigned','room_status','notifications'],
        'resident'     => ['my_room','my_contract','my_billing','my_repairs','notifications_own'],
    ];
}
