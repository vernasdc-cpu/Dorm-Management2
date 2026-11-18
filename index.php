<?php
ob_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/tenant_files.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

$page = $_GET['page'] ?? 'dashboard';
if ($page === 'audit') $page = 'audit-log';

// Guest-only: login
if ($page === 'login') {
    if (current_user()) redirect('index.php?page=dashboard');
    $err = null;
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $identifier = trim($_POST['email'] ?? '');
        $pw = $_POST['password'] ?? '';
        $pdo = null;
        try {
            $pdo = db();
            $ip = client_ip();
            if (!login_allowed($identifier, $ip)) {
                $err = 'Too many failed attempts. Please try again in 10 minutes.';
            } else {
                $st = $pdo->prepare('SELECT * FROM users WHERE LOWER(email)=LOWER(?) AND deleted_at IS NULL LIMIT 1');
                $st->execute([$identifier]);
                $u = $st->fetch();
                if (!$u) {
                    $st = $pdo->prepare('SELECT * FROM users WHERE LOWER(name)=LOWER(?) AND deleted_at IS NULL LIMIT 2');
                    $st->execute([$identifier]);
                    $matches = $st->fetchAll();
                    $u = count($matches) === 1 ? $matches[0] : null;
                }
                if ($u && $u['status'] === 'active' && password_verify($pw, $u['password_hash'])) {
                    session_regenerate_id(true);
                    $_SESSION['uid'] = (int)$u['id'];
                    login_register_attempt($identifier, $ip, true);
                    audit_log((int)$u['id'], 'Logged in', 'auth', (string)$u['id']);
                    if (!empty($_POST['remember'])) {
                        setcookie('remember_email', $u['email'], [
                            'expires' => time() + 86400 * 30,
                            'path' => '/',
                            'secure' => APP_ENV === 'production',
                            'httponly' => true,
                            'samesite' => 'Lax',
                        ]);
                    }
                    redirect('index.php?page=dashboard');
                }
                login_register_attempt($identifier, $ip, false);
                $err = 'Invalid username or password.';
            }
        } catch (Throwable $t) {
            if ($pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
            $err = 'Login service unavailable. Please try again later.';
        }
    }
    $remembered = $_COOKIE['remember_email'] ?? '';
    ?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login · Heart Ladies Dormitory</title><link rel="icon" href="assets/img/logo.jpg"><link rel="stylesheet" href="assets/css/style.css"></head>
<body><div class="login-wrap"><form class="login-card" method="post" action="index.php?page=login">
<img class="logo" src="assets/img/logo.jpg" alt="Heart Ladies Dormitory logo">
<h2>Heart Ladies Dormitory</h2><p class="sub">Management System · Ladies Dormitory</p>
<?php if ($err): ?><div class="alert error"><?=e($err)?></div><?php endif; ?>
<div class="field"><label>Email or Name</label><input name="email" type="text" required value="<?=e($_POST['email'] ?? $remembered)?>" autocomplete="username"></div>
<div class="field"><label>Password</label><input id="pw" name="password" type="password" required autocomplete="current-password">
<small><a href="#" onclick="togglePw('pw');return false;">Show/hide password</a></small></div>
<div class="row between" style="margin-bottom:12px"><label style="font-size:13px"><input type="checkbox" name="remember" value="1" <?=!empty($_POST['remember'])||$remembered?'checked':''?>> Remember me</label>
<a href="index.php?page=forgot" style="font-size:13px">Forgot password?</a></div>
<button class="btn" style="width:100%">Login</button>
</form></div><script src="assets/js/app.js"></script></body></html>
    <?php exit;
}

if ($page === 'forgot') {
    $msg = null;
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $msg = 'If the email exists in our system, a password reset link has been sent. Please contact the administrator to reset your password.';
    }
    ?><!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Forgot password · Heart Ladies Dormitory</title><link rel="stylesheet" href="assets/css/style.css"></head>
<body><div class="login-wrap"><form class="login-card" method="post"><img class="logo" src="assets/img/logo.jpg" alt="logo">
<h2>Forgot Password</h2><?php if($msg):?><div class="alert success"><?=e($msg)?></div><?php endif; ?>
<div class="field"><label>Email</label><input name="email" type="email" required></div>
<button class="btn" style="width:100%">Send reset link</button>
<p style="text-align:center"><a href="index.php?page=login">Back to login</a></p></form></div></body></html><?php
    exit;
}

if ($page === 'logout') {
    $u = current_user();
    if ($u) audit_log((int)$u['id'], 'Logged out', 'auth', (string)$u['id']);
    session_destroy();
    redirect('index.php?page=login');
}

// All other pages require login
$user = require_login();
$titles = [
    'dashboard'=>'Dashboard','rooms'=>'Rooms','allocations'=>'Room Allocation','contracts'=>'Tenant Files','approve_billing_payment'=>'Approve Billing Payment',
    'contract_monitoring'=>'Contract Monitoring','billing'=>'Billing & Payments','reference'=>'Reference','billing_payments'=>'Billing Payments','payment_verification'=>'Payment Verification','billing_corrections'=>'Billing Corrections','violations'=>'Violations','admin_reports'=>'Reports/Maintenance','manage_announcements'=>'Manage Announcements','invoice'=>'Invoice','receipt'=>'Official Receipt',
    'inventory'=>'Inventory','repairs'=>'Repair & Maintenance','notifications'=>'Notifications','users'=>'Users & Staff',
    'tenants'=>'Tenant Profiles','tenant_folders'=>'Profile folders','reservations'=>'Reservations','acknowledgment_receipt'=>'Acknowledgment Receipt',
    'tenant_accounts'=>'Tenant Account Users',
    'housekeeping'=>'Housekeeping',
    'reports'=>'Reports','audit-log'=>'Audit Logs','settings'=>'System Settings','profile'=>'My Profile',
    'my_room'=>'My Room','my_contract'=>'My Contract','my_billing'=>'My Billing','my_billing_payment'=>'Billing Payment','my_billing_correction'=>'Billing Correction','my_repairs'=>'My Repairs',
    'my_violations'=>'My Violations','my_reports'=>'My Reports','announcements'=>'Announcements',
    'download'=>'Download',
];
$title = $titles[$page] ?? 'Dashboard';
if (!isset($titles[$page])) $page = 'dashboard';

// File download: contracts & repair photos (auth-checked)
if ($page === 'download') {
    require_login();
    $type = $_GET['type'] ?? ''; $id = (int)($_GET['id'] ?? 0);
    if ($type === 'tenant_file') {
        if (!in_array($user['role'], ['super_admin', 'admin_staff'], true)) { http_response_code(403); die('Forbidden.'); }
        $st = db()->prepare('SELECT file_name, file_path FROM tenant_file_uploads WHERE id=?');
        $st->execute([$id]);
        $document = $st->fetch();
        if (!$document) { http_response_code(404); die('Document not found.'); }
        $path = tenant_file_resolve((string)$document['file_path'], '');
        if (!is_file($path)) { http_response_code(404); die('Document file missing.'); }
        header('Content-Type: application/octet-stream');
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: attachment; filename="tenant-document-' . $id . '.' . strtolower(pathinfo($path, PATHINFO_EXTENSION)) . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path); exit;
    }
    if ($type === 'report_attachment' || $type === 'repair_photo') {
        $sql = $type === 'report_attachment'
            ? 'SELECT attachment_path AS file_path, user_id FROM tenant_reports WHERE id=?'
            : 'SELECT rr.photo_path AS file_path, r.user_id FROM repair_requests rr JOIN residents r ON r.residents_id=rr.resident_id WHERE rr.id=?';
        $st = db()->prepare($sql);
        $st->execute([$id]);
        $document = $st->fetch();
        if (!$document || empty($document['file_path'])) { http_response_code(404); die('File not found.'); }
        $roles = $type === 'repair_photo' ? ['super_admin', 'admin_staff', 'housekeeping'] : ['super_admin', 'admin_staff'];
        if (!in_array($user['role'], $roles, true) && !($user['role'] === 'resident' && (int)$document['user_id'] === (int)$user['id'])) {
            http_response_code(403); die('Forbidden.');
        }
        $legacy = __DIR__ . ($type === 'report_attachment' ? '/uploads/reports' : '/uploads/repairs');
        $path = tenant_file_resolve((string)$document['file_path'], $legacy);
        if (!is_file($path)) { http_response_code(404); die('File missing.'); }
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mimes = ['jpg'=>'image/jpeg', 'jpeg'=>'image/jpeg', 'png'=>'image/png', 'webp'=>'image/webp'];
        header('Content-Type: ' . ($mimes[$ext] ?? 'application/octet-stream'));
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: inline; filename="attachment-' . $id . '.' . $ext . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path); exit;
    }
    if (in_array($type, ['approved_invoice', 'approved_proof'], true)) {
        if (!in_array($user['role'], ['super_admin', 'admin_staff', 'resident'], true)) { http_response_code(403); die('Forbidden.'); }
        try {
            $st = db()->prepare('SELECT user_id, invoice_path, proof_path FROM approved_billing_documents WHERE id=?');
            $st->execute([$id]);
            $document = $st->fetch();
            if (!$document) { http_response_code(404); die('Document not found.'); }
            if ($user['role'] === 'resident' && (int)($document['user_id'] ?? 0) !== (int)$user['id']) { http_response_code(403); die('Forbidden.'); }
            $file = $type === 'approved_invoice' ? $document['invoice_path'] : $document['proof_path'];
            $path = tenant_file_resolve((string)$file, __DIR__ . '/uploads/billing');
            if (!is_file($path)) { http_response_code(404); die('Document file missing.'); }
            $ext = $type === 'approved_invoice' ? 'pdf' : 'jpg';
            header('Content-Type: ' . ($ext === 'pdf' ? 'application/pdf' : 'image/jpeg'));
            header('Content-Disposition: inline; filename="billing-' . $id . '-' . ($ext === 'pdf' ? 'invoice' : 'proof') . '.' . $ext . '"');
            header('Content-Length: ' . filesize($path));
            readfile($path);
            exit;
        } catch (Throwable $t) {
            error_log('Could not download approved billing document: ' . $t->getMessage());
            http_response_code(500);
            die('Could not load the document.');
        }
    }
    if ($type === 'contract') {
        $st = db()->prepare('SELECT cd.*, c.resident_id, c.tenant_profile_id FROM contract_documents cd JOIN contracts c ON c.id=cd.contract_id WHERE cd.id=?');
        $st->execute([$id]); $d = $st->fetch();
        if (!$d) die('File not found.');
        if ($user['role'] === 'resident') {
            $ok = false;
            $r = db()->prepare('SELECT residents_id FROM residents WHERE user_id=?');
            $r->execute([$user['id']]); $rid = $r->fetchColumn();
            if ($rid && (int)$d['resident_id'] === (int)$rid) $ok = true;
            if (!$ok) {
                try {
                    $ctx = get_tenant_context($user);
                    if ($ctx['tenant_profile'] && !empty($d['tenant_profile_id']) && (int)$ctx['tenant_profile']['id'] === (int)$d['tenant_profile_id']) $ok = true;
                } catch (Throwable $t) {}
            }
            if (!$ok) { http_response_code(403); die('Forbidden.'); }
        }
        $path = tenant_file_resolve((string)$d['file_path'], __DIR__ . '/uploads/contracts');
        if (!is_file($path)) die('File missing on server.');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($d['file_name']) . '"');
        readfile($path); exit;
    }
    if ($type === 'tenant_photo') {
        if (!in_array($user['role'], ['super_admin', 'admin_staff', 'housekeeping'], true)) { http_response_code(403); die('Forbidden.'); }
        $st = db()->prepare('SELECT photo_path FROM tenant_profiles WHERE id=?');
        $st->execute([$id]); $photo = $st->fetchColumn();
        if (!$photo) die('Image not found.');
        $path = tenant_file_resolve((string)$photo, __DIR__ . '/uploads/tenants');
        if (!is_file($path)) die('Image missing on server.');
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mimes = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
        header('Content-Type: ' . ($mimes[$ext] ?? 'application/octet-stream'));
        header('Content-Disposition: inline; filename="tenant-' . $id . '.' . $ext . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path); exit;
    }
    if ($type === 'billing_proof') {
        require_login();
        $pid = (int)($_GET['pid'] ?? 0);
        if ($pid <= 0) die('Invalid.');
        $st = db()->prepare('SELECT screenshot_path, user_id FROM tenant_billing_payments WHERE id=?');
        $st->execute([$pid]);
        $row = $st->fetch();
        if (!$row || empty($row['screenshot_path'])) die('Not found.');
        if (($user['role'] ?? '') === 'resident' && (int)$row['user_id'] !== (int)$user['id']) {
            http_response_code(403); die('Forbidden.');
        }
        if (!in_array($user['role'] ?? '', ['super_admin','admin_staff','resident'], true)) { http_response_code(403); die('Forbidden.'); }
        $path = tenant_file_resolve((string)$row['screenshot_path'], __DIR__ . '/uploads/billing');
        if (!is_file($path)) die('File missing.');
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mimes = ['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp'];
        header('Content-Type: ' . ($mimes[$ext] ?? 'application/octet-stream'));
        header('Content-Disposition: inline; filename="billing-proof-'.$pid.'.'.$ext.'"');
        header('Content-Length: ' . filesize($path));
        readfile($path); exit;
    }
    die('Invalid download.');
}

$allowed = [
    'rooms'=>['super_admin','admin_staff','housekeeping'],
    'allocations'=>['super_admin','admin_staff','housekeeping'],
    'contracts'=>['super_admin','admin_staff'],
    'tenant_folders'=>['super_admin','admin_staff'],
    'contract_monitoring'=>['super_admin','admin_staff'],
    'billing'=>['super_admin','admin_staff'],
    'billing_payments'=>['super_admin','admin_staff'],
    'approve_billing_payment'=>['super_admin','admin_staff'],
    'payment_verification'=>['super_admin','admin_staff'],
    'billing_corrections'=>['super_admin','admin_staff'],
    'violations'=>['super_admin','admin_staff'],
    'admin_reports'=>['super_admin','admin_staff'],
    'manage_announcements'=>['super_admin','admin_staff'],
    'invoice'=>['super_admin','admin_staff','resident'],
    'receipt'=>['super_admin','admin_staff','resident'],
    'acknowledgment_receipt'=>['super_admin','admin_staff','resident'],
    'inventory'=>['super_admin','admin_staff','housekeeping'],
    'repairs'=>['super_admin','admin_staff','housekeeping'],
    'my_room'=>['resident'],'my_contract'=>['resident'],'my_billing'=>['resident'],'my_billing_payment'=>['resident'],'my_billing_correction'=>['resident'],'my_repairs'=>[],
    'my_violations'=>['resident'],'my_reports'=>['resident'],'announcements'=>['resident'],
    'tenants'=>['super_admin','admin_staff'],
    'reservations'=>['super_admin','admin_staff'],
    'reference'=>['super_admin','admin_staff'],
    'tenant_accounts'=>['super_admin','admin_staff'],
    'housekeeping'=>['super_admin','admin_staff','housekeeping'],
    'users'=>['super_admin'],
    'audit-log'=>['super_admin'],
    'settings'=>['super_admin'],
    'reports'=>['super_admin','admin_staff'],
];
if (isset($allowed[$page]) && !in_array($user['role'], $allowed[$page], true)) {
    http_response_code(403);
    layout_header('Forbidden', $user);
    echo '<div class="card"><h3>Forbidden</h3><p>Your role cannot access this page.</p></div>';
    layout_footer(); exit;
}

layout_header($title, $user);
$file = __DIR__ . '/pages/' . $page . '.php';
if (is_file($file)) include $file;
else echo '<div class="card"><p>Page not found.</p></div>';
layout_footer();
