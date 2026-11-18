<?php
if (!in_array($user['role'] ?? '', ['super_admin', 'admin_staff'], true)) {
    http_response_code(403);
    echo '<div class="card"><h3>Forbidden</h3><p>Your role cannot access this page.</p></div>';
    return;
}
$pdo = db();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'create') {
            $title = trim((string)($_POST['title'] ?? ''));
            $msg = trim((string)($_POST['message'] ?? ''));
            $audience = trim((string)($_POST['audience'] ?? 'all'));
            $pub = !empty($_POST['is_published']) ? 1 : 0;
            $deadline = trim((string)($_POST['important_deadline'] ?? '')) ?: null;
            if ($title === '' || $msg === '') throw new RuntimeException('Title and message required.');
            $ins = $pdo->prepare('INSERT INTO announcements (title,message,author_id,audience,is_published,important_deadline,published_at) VALUES (?,?,?,?,?,?,?)');
            $ins->execute([$title, $msg, (int)$user['id'], $audience, $pub, $deadline, $pub ? date('Y-m-d H:i:s') : null]);
            audit_log((int)$user['id'], "Created announcement", 'announcements', (string)$pdo->lastInsertId());
            flash('success', 'Announcement created.');
            redirect('index.php?page=manage_announcements');
        } elseif ($action === 'toggle') {
            $id = (int)($_POST['id'] ?? 0);
            $st = $pdo->prepare('SELECT is_published FROM announcements WHERE id=?');
            $st->execute([$id]);
            $cur = (int)$st->fetchColumn();
            $np = $cur ? 0 : 1;
            $pdo->prepare('UPDATE announcements SET is_published=?, published_at=? WHERE id=?')->execute([$np, $np ? date('Y-m-d H:i:s') : null, $id]);
            audit_log((int)$user['id'], ($np?'Published':'Unpublished')." announcement #$id", 'announcements', (string)$id);
            flash('success', $np ? 'Published.' : 'Unpublished.');
            redirect('index.php?page=manage_announcements');
        }
    } catch (Throwable $t) {
        error_log('Could not save announcement: ' . $t->getMessage());
        flash('error', $t instanceof PDOException ? 'Could not save the announcement. Please contact the administrator.' : $t->getMessage());
        redirect('index.php?page=manage_announcements');
    }
}

$anns = [];
try {
    $anns = $pdo->query("SELECT a.*, u.name as author FROM announcements a LEFT JOIN users u ON u.id = a.author_id ORDER BY a.id DESC")->fetchAll();
} catch (Throwable $t) {
    error_log('Could not load announcements: ' . $t->getMessage());
    $announcementsError = 'Could not load announcements. Please contact the administrator.';
}
?>
<div class="card" style="margin-bottom:14px">
  <h3>Create Announcement</h3>
  <form method="post">
    <?=csrf_field()?><input type="hidden" name="action" value="create">
    <div class="form-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
      <div class="field" style="grid-column:1/-1"><label>Title</label><input name="title" required></div>
      <div class="field"><label>Audience</label><select name="audience"><option value="all">All tenants</option><option value="residents">Residents</option></select></div>
      <div class="field"><label>Important Deadline</label><input type="date" name="important_deadline"></div>
      <div class="field" style="grid-column:1/-1"><label>Message</label><textarea name="message" rows="5" required></textarea></div>
      <div class="field"><label><input type="checkbox" name="is_published" value="1" checked> Publish now</label></div>
    </div>
    <div style="margin-top:10px"><button class="btn">Create</button></div>
  </form>
</div>

<div class="card">
  <h3>Announcements</h3>
  <div style="overflow-x:auto">
  <table class="tbl">
    <thead><tr><th>Title</th><th>Audience</th><th>Published</th><th>Deadline</th><th>Created</th><th>Action</th></tr></thead>
    <tbody>
    <?php if (!empty($announcementsError)): ?><tr><td colspan="6"><?=e($announcementsError)?></td></tr>
    <?php elseif (!$anns): ?><tr><td colspan="6">No announcements.</td></tr><?php endif; ?>
    <?php foreach ($anns as $a): ?>
      <tr>
        <td><?=e($a['title'])?></td>
        <td><?=e($a['audience'])?></td>
        <td><?= $a['is_published'] ? 'Yes' : 'No' ?></td>
        <td><?=e($a['important_deadline']??'—')?></td>
        <td><?=e(substr($a['created_at'],0,10))?></td>
        <td>
          <form method="post" style="display:inline">
            <?=csrf_field()?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?=(int)$a['id']?>">
            <button class="btn sm ghost"><?= $a['is_published'] ? 'Unpublish' : 'Publish' ?></button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
