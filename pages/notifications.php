<?php
// pages/notifications.php — notification center (all roles). Included AFTER layout_header.
$isAdmin = in_array($user['role'], ['super_admin', 'admin_staff'], true);
$tab = $_GET['tab'] ?? 'inbox';
if (!$isAdmin) $tab = 'inbox';

try {
  // ---------- POST actions ----------
  if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    // Compose (admins only)
    if ($action === 'send') {
      if (!$isAdmin) { http_response_code(403); die('Forbidden.'); }
      $title = trim($_POST['title'] ?? '');
      $message = trim($_POST['message'] ?? '');
      $scope = $_POST['scope'] ?? 'all_residents';
      $roleSel = $_POST['role_sel'] ?? 'resident';
      $oneUser = (int)($_POST['user_id'] ?? 0);
      $priority = $_POST['priority'] ?? 'Normal';
      if (!in_array($priority, ['Normal', 'Important', 'Urgent'], true)) $priority = 'Normal';
      if ($title === '' || $message === '') throw new RuntimeException('Title and message are required.');
      $recips = [];
      $roleTag = null;
      if ($scope === 'user') {
        if ($oneUser <= 0) throw new RuntimeException('Please choose a specific user.');
        $st = db()->prepare("SELECT id, role FROM users WHERE id=? AND status='active' AND deleted_at IS NULL");
        $st->execute([$oneUser]);
        if ($r = $st->fetch()) { $recips[] = (int)$r['id']; $roleTag = $r['role']; }
        else throw new RuntimeException('Selected user not found or inactive.');
      } elseif ($scope === 'role') {
        if (!in_array($roleSel, ['resident', 'housekeeping', 'all'], true)) throw new RuntimeException('Invalid audience.');
        if ($roleSel === 'all') {
          $recips = db()->query("SELECT id FROM users WHERE status='active' AND deleted_at IS NULL AND id!=" . (int)$user['id'])->fetchAll(PDO::FETCH_COLUMN);
          $roleTag = 'all';
        } else {
          $st = db()->prepare("SELECT id FROM users WHERE role=? AND status='active' AND deleted_at IS NULL");
          $st->execute([$roleSel]);
          $recips = $st->fetchAll(PDO::FETCH_COLUMN);
          $roleTag = $roleSel;
        }
      } else { // all_residents
        $recips = db()->query("SELECT id FROM users WHERE role='resident' AND status='active' AND deleted_at IS NULL")->fetchAll(PDO::FETCH_COLUMN);
        $roleTag = 'resident';
      }
      if (!$recips) throw new RuntimeException('No recipients found for that audience.');
      $ins = db()->prepare('INSERT INTO notifications (title,message,sender_id,recipient_id,recipient_role,priority) VALUES (?,?,?,?,?,?)');
      $n = 0;
      foreach ($recips as $rid) { $ins->execute([$title, $message, (int)$user['id'], (int)$rid, $roleTag, $priority]); $n++; }
      audit_log((int)$user['id'], "Sent notification to {$n} user(s)", 'notifications');
      flash('success', "Notification sent to {$n} user(s).");
      redirect('index.php?page=notifications&tab=sent');
    }

    if ($action === 'read') {
      $id = (int)($_POST['id'] ?? 0);
      db()->prepare('UPDATE notifications SET is_read=1, read_at=COALESCE(read_at,NOW()) WHERE id=? AND recipient_id=?')->execute([$id, (int)$user['id']]);
      redirect('index.php?page=notifications&tab=' . urlencode($tab));
    }
    if ($action === 'read_all') {
      db()->prepare('UPDATE notifications SET is_read=1, read_at=NOW() WHERE recipient_id=? AND is_read=0')->execute([(int)$user['id']]);
      flash('success', 'All notifications marked as read.');
      redirect('index.php?page=notifications&tab=' . urlencode($tab));
    }
    if ($action === 'delete') {
      $id = (int)($_POST['id'] ?? 0);
      db()->prepare('DELETE FROM notifications WHERE id=? AND recipient_id=?')->execute([$id, (int)$user['id']]);
      flash('success', 'Notification deleted.');
      redirect('index.php?page=notifications&tab=' . urlencode($tab));
    }
  }
} catch (Throwable $t) { flash('error', $t->getMessage()); redirect('index.php?page=notifications'); }

// ---------- Data ----------
try {
  $uStmt = db()->prepare('SELECT COUNT(*) FROM notifications WHERE recipient_id=? AND is_read=0');
  $uStmt->execute([(int)$user['id']]);
  $unreadN = (int)$uStmt->fetchColumn();
  $st = db()->prepare('SELECT n.*, u.name AS sender FROM notifications n LEFT JOIN users u ON u.id=n.sender_id WHERE n.recipient_id=? ORDER BY n.id DESC LIMIT 100');
  $st->execute([(int)$user['id']]);
  $inbox = $st->fetchAll();
  $sent = [];
  if ($isAdmin) {
    $s = db()->prepare('SELECT n.*, u.name AS recip FROM notifications n LEFT JOIN users u ON u.id=n.recipient_id WHERE n.sender_id=? ORDER BY n.id DESC LIMIT 100');
    $s->execute([(int)$user['id']]);
    $sent = $s->fetchAll();
  }
  $allUsers = db()->query("SELECT id,name,email,role FROM users WHERE status='active' AND deleted_at IS NULL ORDER BY role,name LIMIT 300")->fetchAll();
} catch (Throwable $t) { $inbox = []; $sent = []; $allUsers = []; $unreadN = 0; flash('error', 'Could not load notifications.'); }
?>

<div class="card">
  <h3>🔔 Notifications <?= $unreadN > 0 ? '<span class="badge">' . $unreadN . ' unread</span>' : '' ?></h3>
  <p>
    <a class="btn sm <?= $tab === 'inbox' ? '' : 'ghost' ?>" href="index.php?page=notifications&tab=inbox">Inbox</a>
    <?php if ($isAdmin): ?>
      <a class="btn sm <?= $tab === 'sent' ? '' : 'ghost' ?>" href="index.php?page=notifications&tab=sent">Sent</a>
      <a class="btn sm <?= $tab === 'compose' ? '' : 'ghost' ?>" href="index.php?page=notifications&tab=compose">Compose</a>
    <?php endif; ?>
    <?php if ($unreadN > 0): ?>
      <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="read_all"><button class="btn ghost sm">Mark all read</button></form>
    <?php endif; ?>
  </p>
</div>

<?php if ($isAdmin && $tab === 'compose'): ?>
<div class="card">
  <h3>Compose notification</h3>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="send">
    <div class="field"><label>Title</label><input name="title" required maxlength="160"></div>
    <div class="field"><label>Message</label><textarea name="message" rows="4" required></textarea></div>
    <div class="row" style="display:flex;gap:10px;flex-wrap:wrap">
      <div class="field"><label>Audience</label>
        <select name="scope" id="nscope">
          <option value="all_residents">All residents</option>
          <option value="role">By role</option>
          <option value="user">Specific user</option>
        </select></div>
      <div class="field"><label>Role (if by role)</label>
        <select name="role_sel"><option value="resident">Resident</option><option value="housekeeping">Housekeeping</option><option value="all">Everyone</option></select></div>
      <div class="field"><label>User (if specific)</label>
        <select name="user_id"><option value="0">— choose —</option>
        <?php $preTo = (int)($_GET['to'] ?? 0); foreach ($allUsers as $a): ?><option value="<?= (int)$a['id'] ?>"<?= $preTo===(int)$a['id']?' selected':'' ?>><?= e($a['name']) ?> (<?= e($a['role']) ?>)</option><?php endforeach; ?>
        </select></div>
      <div class="field"><label>Priority</label>
        <select name="priority"><option>Normal</option><option>Important</option><option>Urgent</option></select></div>
    </div>
    <button class="btn">Send</button>
  </form>
</div>
<?php endif; ?>

<?php if ($tab === 'sent' && $isAdmin): ?>
<div class="card"><h3>Sent messages</h3>
  <table class="tbl"><tr><th>To</th><th>Title</th><th>Priority</th><th>Date</th></tr>
  <?php foreach ($sent as $n): ?><tr>
    <td><?= e($n['recip'] ?? ('#' . $n['recipient_id'])) ?></td>
    <td><?= e($n['title']) ?><br><small><?= e(mb_substr($n['message'], 0, 120)) ?></small></td>
    <td><?= e($n['priority']) ?></td><td><?= e($n['created_at']) ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$sent): ?><tr><td colspan="4">Nothing sent yet.</td></tr><?php endif; ?></table></div>
<?php else: ?>
<div class="card"><h3>My notifications</h3>
  <table class="tbl"><tr><th>Status</th><th>Title</th><th>From</th><th>Priority</th><th>Date</th><th></th></tr>
  <?php foreach ($inbox as $n): ?><tr style="<?= $n['is_read'] ? '' : 'font-weight:bold' ?>">
    <td><?= $n['is_read'] ? 'Read' : 'Unread' ?></td>
    <td><?= e($n['title']) ?><br><small><?= e($n['message']) ?></small></td>
    <td><?= e($n['sender'] ?? '—') ?></td><td><?= e($n['priority']) ?></td><td><?= e($n['created_at']) ?></td>
    <td style="white-space:nowrap">
      <?php if (!$n['is_read']): ?><form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="read"><input type="hidden" name="id" value="<?= (int)$n['id'] ?>"><button class="btn sm">Mark read</button></form><?php endif; ?>
      <form method="post" style="display:inline" onsubmit="return confirm('Delete this notification?')"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$n['id'] ?>"><button class="btn sm ghost">Delete</button></form>
    </td></tr>
  <?php endforeach; ?>
  <?php if (!$inbox): ?><tr><td colspan="6">No notifications.</td></tr><?php endif; ?></table></div>
<?php endif; ?>
