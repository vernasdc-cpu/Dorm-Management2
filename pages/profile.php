<?php
// pages/profile.php — all roles: view/edit own name+phone, change password, resident extras.
try {
  if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    if ($action === 'info') {
      $name = trim($_POST['name'] ?? '');
      $phone = trim($_POST['phone'] ?? '');
      if ($name === '') throw new RuntimeException('Name cannot be empty.');
      db()->prepare('UPDATE users SET name=?, phone=? WHERE id=?')->execute([$name, $phone ?: null, (int)$user['id']]);
      $_SESSION['uid'] = (int)$user['id']; // keep session
      audit_log((int)$user['id'], 'Updated own profile', 'profile', (string)$user['id']);
      flash('success', 'Profile updated.');
      redirect('index.php?page=profile');
    }
    if ($action === 'password') {
      $cur = $_POST['current'] ?? ''; $nw = $_POST['new'] ?? ''; $cf = $_POST['confirm'] ?? '';
      $st = db()->prepare('SELECT password_hash FROM users WHERE id=?'); $st->execute([(int)$user['id']]);
      $hash = $st->fetchColumn();
      if (!password_verify($cur, (string)$hash)) throw new RuntimeException('Current password is incorrect.');
      if (strlen($nw) < 8) throw new RuntimeException('New password must be at least 8 characters.');
      if ($nw !== $cf) throw new RuntimeException('New passwords do not match.');
      db()->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($nw, PASSWORD_DEFAULT), (int)$user['id']]);
      audit_log((int)$user['id'], 'Changed own password', 'profile', (string)$user['id']);
      flash('success', 'Password changed.');
      redirect('index.php?page=profile');
    }
  }
} catch (Throwable $t) { flash('error', $t->getMessage()); redirect('index.php?page=profile'); }

try {
  $st = db()->prepare('SELECT u.*, rm.room_number, res.bed_no, res.address, res.emergency_contact, res.emergency_phone, res.move_in_date FROM users u LEFT JOIN residents res ON res.user_id=u.id LEFT JOIN rooms rm ON rm.id=res.room_id WHERE u.id=?');
  $st->execute([(int)$user['id']]);
  $me = $st->fetch() ?: $user;
} catch (Throwable $t) { $me = $user; flash('error', 'Could not load profile.'); }
?>
<div class="card"><h3>My Profile</h3>
  <p><b><?= e($me['name']) ?></b> · <?= e(str_replace('_', ' ', (string)$me['role'])) ?> · <?= e($me['email']) ?> · Status: <?= e($me['status']) ?></p>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="info">
    <div class="row" style="display:flex;gap:8px;flex-wrap:wrap">
      <div class="field"><label>Name</label><input name="name" value="<?= e($me['name']) ?>" required></div>
      <div class="field"><label>Phone</label><input name="phone" value="<?= e($me['phone'] ?? '') ?>"></div>
    </div><button class="btn sm">Save</button></form></div>
<div class="card"><h3>Change password</h3>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="password">
    <div class="field"><label>Current password</label><input name="current" type="password" required autocomplete="current-password"></div>
    <div class="field"><label>New password (min 8)</label><input name="new" type="password" required minlength="8" autocomplete="new-password"></div>
    <div class="field"><label>Confirm new password</label><input name="confirm" type="password" required autocomplete="new-password"></div>
    <button class="btn sm">Change password</button></form></div>
<?php if (($me['role'] ?? '') === 'resident'): ?>
<div class="card"><h3>My dorm details</h3>
  <p>Room: <b><?= e($me['room_number'] ?? '—') ?></b> · Bed: <b><?= e($me['bed_no'] ?? '—') ?></b> · Move-in: <b><?= e($me['move_in_date'] ?? '—') ?></b></p>
  <p>Address: <?= e($me['address'] ?? '—') ?> · Emergency: <?= e($me['emergency_contact'] ?? '—') ?> (<?= e($me['emergency_phone'] ?? '—') ?>)</p></div>
<?php endif; ?>
