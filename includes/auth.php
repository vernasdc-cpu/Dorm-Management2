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

// Rate limiting for login: max 5 attempts per 10 min per IP+email
function login_allowed(string $key): bool {
    $k = 'login_' . md5($key);
    $a = $_SESSION[$k] ?? ['count'=>0,'first'=>time()];
    if (time() - $a['first'] > 600) return true;
    return $a['count'] < 5;
}
function login_register_attempt(string $key, bool $success): void {
    $k = 'login_' . md5($key);
    $a = $_SESSION[$k] ?? ['count'=>0,'first'=>time()];
    if (time() - $a['first'] > 600) $a = ['count'=>0,'first'=>time()];
    if ($success) { unset($_SESSION[$k]); return; }
    $a['count']++;
    $_SESSION[$k] = $a;
}

function permission_matrix(): array {
    return [
        'super_admin'  => ['*'],
        'admin_staff'  => ['residents','rooms','allocations','contracts','billing','inventory','repairs','notifications','reports'],
        'housekeeping' => ['housekeeping_rooms','housekeeping','inventory','repairs_assigned','room_status','notifications'],
        'resident'     => ['my_room','my_contract','my_billing','my_repairs','notifications_own'],
    ];
}
