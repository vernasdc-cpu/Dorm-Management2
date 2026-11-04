<?php
// pages/users.php — staff + tenant account management
$isSuper = ($user['role'] === 'super_admin');
$staffRoles = ['super_admin', 'admin_staff', 'housekeeping'];
$allRoles = ['super_admin', 'admin_staff', 'housekeeping', 'resident'];
$q = trim($_GET['q'] ?? '');
$fRole = $_GET['role'] ?? '';
$fStatus = $_GET['status'] ?? '';

$pdo = db();

try {
  if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
      $name = trim($_POST['name'] ?? ''); $email = trim($_POST['email'] ?? '');
      $pw = $_POST['password'] ?? ''; $phone = trim($_POST['phone'] ?? '');
      $role = $isSuper ? ($_POST['role'] ?? 'admin_staff') : 'admin_staff';
      if (!$isSuper) $role = in_array($role, $staffRoles, true) ? $role : 'admin_staff';
      else $role = in_array($role, $allRoles, true) ? $role : 'admin_staff';
      if ($name === '' || $email === '' || strlen($pw) < 8) throw new RuntimeException('Name, valid email and password (min 8 chars) required.');
      if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Invalid email address.');
      // Check duplicates
      $chk = $pdo->prepare('SELECT id FROM users WHERE LOWER(email)=LOWER(?) AND deleted_at IS NULL');
      $chk->execute([$email]);
      if ($chk->fetch()) throw new RuntimeException('Email already exists.');
      $st = $pdo->prepare('INSERT INTO users (name,email,password_hash,role,phone,status) VALUES (?,?,?,?,?,\'active\')');
      $st->execute([$name, $email, password_hash($pw, PASSWORD_DEFAULT), $role, $phone ?: null]);
      $uid = (int)$pdo->lastInsertId();
      // Try to link to tenant profile if resident
      if ($role === 'resident') {
        $tp = $pdo->prepare('SELECT id FROM tenant_profiles WHERE LOWER(email)=LOWER(?) LIMIT 1');
        $tp->execute([$email]);
        $tpid = $tp->fetchColumn();
        if ($tpid) {
          $pdo->prepare('UPDATE users SET tenant_profile_id = ? WHERE id = ?')->execute([(int)$tpid, $uid]);
        }
        $r = $pdo->prepare('SELECT residents_id FROM residents WHERE user_id = ? OR LOWER(email)=LOWER(?) LIMIT 1');
        $r->execute([$uid, $email]);
        $rid = $r->fetchColumn();
        if ($rid) {
          $pdo->prepare('UPDATE users SET resident_link_id = ? WHERE id = ?')->execute([(int)$rid, $uid]);
        }
      }
      audit_log((int)$user['id'], "Created user {$email} role {$role}", 'users', (string)$uid);
      flash('success', 'User created.');
      redirect('index.php?page=users');
    }

    if ($action === 'link') {
      if (!$isSuper) throw new RuntimeException('Not allowed.');
      $uid = (int)($_POST['user_id'] ?? 0);
      $tpid = (int)($_POST['tenant_profile_id'] ?? 0);
      $rid = (int)($_POST['resident_id'] ?? 0);
      if ($uid <= 0) throw new RuntimeException('Invalid user.');
      if ($tpid > 0) {
        $pdo->prepare('UPDATE users SET tenant_profile_id = ? WHERE id = ?')->execute([$tpid, $uid]);
      }
      if ($rid > 0) {
        $pdo->prepare('UPDATE users SET resident_link_id = ? WHERE id = ?')->execute([$rid, $uid]);
      }
      audit_log((int)$user['id'], "Linked account {$uid} to profile {$tpid}/resident {$rid}", 'users', (string)$uid);
      flash('success', 'Account linked.');
      redirect('index.php?page=users');
    }

    if ($action === 'edit') {
      $id = (int)($_POST['id'] ?? 0);
      $t = $pdo->prepare('SELECT * FROM users WHERE id=? AND deleted_at IS NULL'); $t->execute([$id]); $t = $t->fetch();
      if (!$t) throw new RuntimeException('User not found.');
      if (!$isSuper && $t['id'] !== (int)$user['id']) throw new RuntimeException('Not allowed.');
      $name = trim($_POST['name'] ?? $t['name']); $phone = trim($_POST['phone'] ?? '');
      $status = $_POST['status'] ?? $t['status'];
      if (!in_array($status, ['active', 'inactive', 'Pending Activation', 'Suspended', 'Disabled'], true)) $status = $t['status'];
      if ($id === (int)$user['id'] && $status !== $t['status']) throw new RuntimeException('You cannot change your own status.');
      $role = $t['role'];
      if ($isSuper) {
        $nr = $_POST['role'] ?? $t['role'];
        if (in_array($nr, $allRoles, true)) $role = $nr;
        if ($id === (int)$user['id'] && $role !== $t['role']) throw new RuntimeException('You cannot change your own role.');
      }
      $sql = 'UPDATE users SET name=?, phone=?, status=?, role=? WHERE id=?';
      $pdo->prepare($sql)->execute([$name, $phone ?: null, $status, $role, $id]);
      if (!empty($_POST['new_password'])) {
        if (strlen($_POST['new_password']) < 8) throw new RuntimeException('New password must be at least 8 characters.');
        $pdo->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($_POST['new_password'], PASSWORD_DEFAULT), $id]);
      }
      audit_log((int)$user['id'], "Updated user {$t['email']}", 'users', (string)$id);
      flash('success', 'User updated.');
      redirect('index.php?page=users');
    }

    if ($action === 'toggle') {
      $id = (int)($_POST['id'] ?? 0);
      if ($id === (int)$user['id']) throw new RuntimeException('You cannot deactivate yourself.');
      $t = db()->prepare('SELECT * FROM users WHERE id=? AND deleted_at IS NULL AND role <> ?'); $t->execute([$id, 'resident']); $t = $t->fetch();
      if (!$t) throw new RuntimeException('User not found.');
      if (!$isSuper) throw new RuntimeException('Not allowed.');
      $ns = $t['status'] === 'active' ? 'inactive' : 'active';
      db()->prepare('UPDATE users SET status=? WHERE id=?')->execute([$ns, $id]);
      audit_log((int)$user['id'], "Set user {$t['email']} {$ns}", 'users', (string)$id);
      flash('success', "User {$ns}.");
      redirect('index.php?page=users');
    }

    if ($action === 'delete') {
      if (!$isSuper) { http_response_code(403); die('Forbidden.'); }
      $id = (int)($_POST['id'] ?? 0);
      if ($id === (int)$user['id']) throw new RuntimeException('You cannot delete yourself.');
      $t = db()->prepare('SELECT * FROM users WHERE id=? AND deleted_at IS NULL AND role <> ?'); $t->execute([$id, 'resident']); $t = $t->fetch();
      if (!$t) throw new RuntimeException('User not found.');
      db()->prepare('UPDATE users SET deleted_at=NOW(), status=\'inactive\' WHERE id=?')->execute([$id]);
      audit_log((int)$user['id'], "Deleted user {$t['email']}", 'users', (string)$id);
      flash('success', 'User deleted.');
      redirect('index.php?page=users');
    }
  }
} catch (Throwable $t) { flash('error', $t->getMessage()); redirect('index.php?page=users'); }

// ---------- Listing ----------
try {
  $where = ['u.deleted_at IS NULL']; $args = [];
  if ($fRole !== '' && in_array($fRole, $allRoles, true)) { $where[] = 'u.role=?'; $args[] = $fRole; }
  if (in_array($fStatus, ['active', 'inactive', 'Pending Activation', 'Suspended', 'Disabled'], true)) { $where[] = 'u.status=?'; $args[] = $fStatus; }
  if ($q !== '') { $where[] = '(u.name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)'; array_push($args, "%$q%", "%$q%", "%$q%"); }
  $st = $pdo->prepare('SELECT u.id,u.name,u.email,u.role,u.phone,u.status,u.created_at,u.tenant_profile_id,u.resident_link_id FROM users u WHERE ' . implode(' AND ', $where) . ' ORDER BY u.id DESC LIMIT 300');
  $st->execute($args);
  $rows = $st->fetchAll();
  $tps = $pdo->query('SELECT id, full_name, email FROM tenant_profiles ORDER BY full_name')->fetchAll();
  $rss = $pdo->query('SELECT residents_id, user_id FROM residents ORDER BY residents_id')->fetchAll();
} catch (Throwable $t) { $rows = []; $tps=[]; $rss=[]; flash('error', 'Could not load users.'); }
?>
<div class="card"><h3>Users &amp; Accounts</h3>
  <form method="get" action="index.php" style="display:flex;gap:8px;flex-wrap:wrap">
    <input type="hidden" name="page" value="users">
    <input name="q" placeholder="Search name/email/phone" value="<?= e($q) ?>">
    <select name="role"><option value="">All roles</option>
      <?php foreach ($allRoles as $r): ?><option value="<?= $r ?>" <?= $fRole===$r?'selected':'' ?>><?= e($r) ?></option><?php endforeach; ?></select>
    <select name="status"><option value="">All statuses</option><option value="active" <?= $fStatus==='active'?'selected':'' ?>>active</option><option value="inactive" <?= $fStatus==='inactive'?'selected':'' ?>>inactive</option><option value="Pending Activation" <?= $fStatus==='Pending Activation'?'selected':'' ?>>Pending Activation</option><option value="Suspended" <?= $fStatus==='Suspended'?'selected':'' ?>>Suspended</option><option value="Disabled" <?= $fStatus==='Disabled'?'selected':'' ?>>Disabled</option></select>
    <button class="btn sm">Filter</button>
  </form></div>

<div class="card"><h3>Create user / Link tenant</h3>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="create">
    <div class="row" style="display:flex;gap:8px;flex-wrap:wrap">
      <div class="field"><label>Name</label><input name="name" required></div>
      <div class="field"><label>Email</label><input name="email" type="email" required></div>
      <div class="field"><label>Password (min 8)</label><input name="password" type="password" required minlength="8"></div>
      <div class="field"><label>Phone</label><input name="phone"></div>
      <div class="field"><label>Role</label><select name="role"><?php foreach ($allRoles as $r): if ($r==='super_admin' && !$isSuper) continue; ?><option value="<?=e($r)?>"><?=e($r)?></option><?php endforeach; ?></select></div>
    </div><button class="btn sm">Create</button></form></div>

<div class="card"><h3>List (<?= count($rows) ?>)</h3>
<table class="tbl"><tr><th>Name</th><th>Email</th><th>Role</th><th>Phone</th><th>Status</th><th>Links</th><th></th></tr>
<?php foreach ($rows as $r): ?><tr>
  <td><?= e($r['name']) ?></td><td><?= e($r['email']) ?></td><td><?= e($r['role']) ?></td><td><?= e($r['phone'] ?? '—') ?></td><td><?= e($r['status']) ?></td>
  <td>TP: <?= $r['tenant_profile_id'] ?: '—' ?> | Res: <?= $r['resident_link_id'] ?: '—' ?></td>
  <td><details><summary class="btn sm ghost" style="cursor:pointer">Manage</summary>
    <form method="post" style="margin-top:8px"><?= csrf_field() ?><input type="hidden" name="action" value="edit"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
      <div class="field"><label>Name</label><input name="name" value="<?= e($r['name']) ?>" required></div>
      <div class="field"><label>Phone</label><input name="phone" value="<?= e($r['phone'] ?? '') ?>"></div>
      <div class="field"><label>Status</label><select name="status"><option value="active" <?= $r['status']==='active'?'selected':'' ?>>active</option><option value="inactive" <?= $r['status']==='inactive'?'selected':'' ?>>inactive</option><option value="Pending Activation" <?= $r['status']==='Pending Activation'?'selected':'' ?>>Pending Activation</option><option value="Suspended" <?= $r['status']==='Suspended'?'selected':'' ?>>Suspended</option><option value="Disabled" <?= $r['status']==='Disabled'?'selected':'' ?>>Disabled</option></select></div>
      <?php if ($isSuper): ?><div class="field"><label>Role</label><select name="role"><?php foreach ($allRoles as $rr): ?><option value="<?=e($rr)?>" <?= $r['role']===$rr?'selected':'' ?>><?=e($rr)?></option><?php endforeach; ?></select></div><?php endif; ?>
      <div class="field"><label>Reset password (optional)</label><input name="new_password" type="password" minlength="8" placeholder="Leave blank to keep"></div>
      <button class="btn sm">Save</button></form>
    <?php if ($isSuper && ($r['role']==='resident' || $r['role']==='admin_staff' || $r['role']==='housekeeping')): ?>
    <form method="post" style="margin-top:8px"><?= csrf_field() ?><input type="hidden" name="action" value="link"><input type="hidden" name="user_id" value="<?= (int)$r['id'] ?>">
      <div class="field"><label>Link to Tenant Profile</label><select name="tenant_profile_id"><option value="">—</option><?php foreach ($tps as $tp): ?><option value="<?= (int)$tp['id'] ?>" <?= $r['tenant_profile_id']==$tp['id']?'selected':'' ?>><?= e($tp['full_name']) ?> (<?= e($tp['email']??'') ?>)</option><?php endforeach; ?></select></div>
      <div class="field"><label>Link to Resident</label><select name="resident_id"><option value="">—</option><?php foreach ($rss as $rs): ?><option value="<?= (int)$rs['residents_id'] ?>" <?= $r['resident_link_id']==$rs['residents_id']?'selected':'' ?>>Resident #<?= (int)$rs['residents_id'] ?></option><?php endforeach; ?></select></div>
      <button class="btn sm ghost">Link</button>
    </form>
    <?php endif; ?>
    <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn sm ghost"><?= $r['status']==='active'?'Deactivate':'Activate' ?></button></form>
    <?php if ($isSuper): ?><form method="post" style="display:inline" onsubmit="return confirm('Delete this user?')"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn sm ghost">Delete</button></form><?php endif; ?>
  </details></td></tr>
<?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="7">No users found.</td></tr><?php endif; ?></table>
<p style="font-size:13px;color:#7c6f8a">Residents/tenants can be managed on <a href="index.php?page=tenants">Tenant Profiles</a>. Link accounts to profiles/residents for proper isolation.</p></div>
