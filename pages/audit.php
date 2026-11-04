<?php
// pages/audit.php — super_admin only (also enforced in index.php). Included AFTER layout_header.
$module = trim($_GET['module'] ?? '');
$search = trim($_GET['q'] ?? '');
$date = trim($_GET['date'] ?? ''); // YYYY-MM-DD
$view = ($_GET['view'] ?? 'active') === 'trash' ? 'trash' : 'active';
$pdo = db();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  csrf_check();
  $id = (int)($_POST['id'] ?? 0);
  $action = (string)($_POST['action'] ?? '');
  try {
    if ($id <= 0) throw new RuntimeException('Invalid audit log.');

    if ($action === 'delete') {
      $st = $pdo->prepare('UPDATE audit_logs SET deleted_at=NOW() WHERE id=? AND deleted_at IS NULL');
      $st->execute([$id]);
      if ($st->rowCount() !== 1) throw new RuntimeException('This audit log is already deleted or no longer exists.');
      audit_log((int)$user['id'], 'Moved audit log #' . $id . ' to trash', 'audit', (string)$id);
      flash('success', 'Audit log moved to trash. It will be permanently deleted after 7 days unless restored.');
      redirect('index.php?page=audit&view=trash');
    }

    if ($action === 'restore') {
      $st = $pdo->prepare('SELECT deleted_at, deleted_at <= DATE_SUB(NOW(), INTERVAL 7 DAY) AS expired FROM audit_logs WHERE id=?');
      $st->execute([$id]);
      $deleted = $st->fetch();
      if (!$deleted) {
        $st = $pdo->prepare('SELECT deleted_at FROM audit_log_deletions WHERE audit_log_id=?');
        $st->execute([$id]);
        $deletedAt = $st->fetchColumn();
        if ($deletedAt) {
          flash('restore_expired', 'This audit log was deleted on ' . $deletedAt . '. The 7-day restoration period has ended; it cannot be restored.');
          redirect('index.php?page=audit&view=trash');
        }
        throw new RuntimeException('This audit log no longer exists.');
      }
      if (empty($deleted['deleted_at'])) throw new RuntimeException('This audit log is not in the trash.');
      if ((int)$deleted['expired'] === 1) {
        $pdo->beginTransaction();
        try {
          $st = $pdo->prepare('INSERT IGNORE INTO audit_log_deletions (audit_log_id, deleted_at) VALUES (?,?)');
          $st->execute([$id, $deleted['deleted_at']]);
          $pdo->prepare('DELETE FROM audit_logs WHERE id=? AND deleted_at=?')->execute([$id, $deleted['deleted_at']]);
          $pdo->commit();
        } catch (Throwable $t) {
          if ($pdo->inTransaction()) $pdo->rollBack();
          throw $t;
        }
        flash('restore_expired', 'This audit log was deleted on ' . $deleted['deleted_at'] . '. The 7-day restoration period has ended; it cannot be restored.');
        redirect('index.php?page=audit&view=trash');
      }
      $st = $pdo->prepare('UPDATE audit_logs SET deleted_at=NULL WHERE id=? AND deleted_at=?');
      $st->execute([$id, $deleted['deleted_at']]);
      if ($st->rowCount() !== 1) throw new RuntimeException('Could not restore this audit log.');
      audit_log((int)$user['id'], 'Restored audit log #' . $id, 'audit', (string)$id);
      flash('success', 'Audit log restored.');
      redirect('index.php?page=audit');
    }

    throw new RuntimeException('Invalid audit log action.');
  } catch (Throwable $t) {
    flash('error', $t->getMessage());
    redirect('index.php?page=audit&view=' . rawurlencode($view));
  }
}

try {
  $pdo->beginTransaction();
  $pdo->exec('INSERT IGNORE INTO audit_log_deletions (audit_log_id, deleted_at)
    SELECT id, deleted_at FROM audit_logs WHERE deleted_at <= DATE_SUB(NOW(), INTERVAL 7 DAY)');
  $pdo->exec('DELETE FROM audit_logs WHERE deleted_at <= DATE_SUB(NOW(), INTERVAL 7 DAY)');
  $pdo->commit();
} catch (Throwable $t) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  error_log('audit.php expired-log cleanup: ' . $t->getMessage());
  flash('error', 'Could not complete expired audit-log cleanup.');
}

try {
  $mods = $pdo->query('SELECT DISTINCT module FROM audit_logs ORDER BY module')->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $t) { $mods = []; }

try {
  $where = [$view === 'trash' ? 'a.deleted_at IS NOT NULL' : 'a.deleted_at IS NULL']; $args = [];
  if ($module !== '') { $where[] = 'a.module=?'; $args[] = $module; }
  if ($search !== '') { $where[] = '(a.action LIKE ? OR u.name LIKE ? OR u.email LIKE ?)'; array_push($args, "%$search%", "%$search%", "%$search%"); }
  if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { $where[] = 'DATE(a.created_at)=?'; $args[] = $date; }
  $sql = 'SELECT a.*, DATE_ADD(a.deleted_at, INTERVAL 7 DAY) AS purge_at, u.name AS uname, u.email AS uemail FROM audit_logs a LEFT JOIN users u ON u.id=a.user_id'
    . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY a.id DESC LIMIT 200';
  $st = $pdo->prepare($sql); $st->execute($args);
  $rows = $st->fetchAll();

  if (($_GET['export'] ?? '') === 'csv' && $view === 'active') {
    $fh = fopen('php://temp', 'r+');
    fputcsv($fh, ['Time', 'User', 'Email', 'Action', 'Module', 'Record', 'IP']);
    foreach ($rows as $r) fputcsv($fh, [$r['created_at'], $r['uname'] ?? ('#' . $r['user_id']), $r['uemail'] ?? '', $r['action'], $r['module'], $r['record_id'], $r['ip_address']]);
    rewind($fh); $csv = stream_get_contents($fh); fclose($fh);
    if (!headers_sent()) { header('Content-Type: text/csv'); header('Content-Disposition: attachment; filename="audit-logs.csv"'); echo $csv; exit; }
    echo '<div class="card"><h3>CSV ready</h3><button class="btn sm" onclick="dlA()">Download CSV</button> <a class="btn sm ghost" href="index.php?page=audit">Back</a><textarea id="abox" rows="12" style="width:100%;margin-top:8px">' . e($csv) . '</textarea></div>';
    echo "<script>function dlA(){var b=new Blob([document.getElementById('abox').value],{type:'text/csv'});var a=document.createElement('a');a.href=URL.createObjectURL(b);a.download='audit-logs.csv';a.click();}</script>";
  }
} catch (Throwable $t) { $rows = []; flash('error', 'Could not load audit logs.'); }
$restoreExpired = flash('restore_expired');
?>
<?php if ($restoreExpired): ?>
<div class="modal open" role="dialog" aria-modal="true" aria-labelledby="audit-restore-expired-title">
  <div class="box" style="text-align:center">
    <h3 id="audit-restore-expired-title">Cannot restore audit log</h3>
    <p><?= e($restoreExpired) ?></p>
    <a class="btn" href="index.php?page=audit&view=trash">OK</a>
  </div>
</div>
<?php endif; ?>
<div class="card"><h3>📜 Audit Logs (latest <?= count($rows) ?> / max 200)</h3>
  <div class="row" style="margin:10px 0">
    <a class="btn sm <?= $view === 'active' ? '' : 'ghost' ?>" href="index.php?page=audit">Active logs</a>
    <a class="btn sm <?= $view === 'trash' ? '' : 'ghost' ?>" href="index.php?page=audit&amp;view=trash">Trash</a>
  </div>
  <form method="get" action="index.php" style="display:flex;gap:8px;flex-wrap:wrap">
    <input type="hidden" name="page" value="audit">
    <?php if ($view === 'trash'): ?><input type="hidden" name="view" value="trash"><?php endif; ?>
    <select name="module"><option value="">All modules</option>
      <?php foreach ($mods as $m): ?><option value="<?= e($m) ?>" <?= $module === $m ? 'selected' : '' ?>><?= e($m) ?></option><?php endforeach; ?></select>
    <input name="q" placeholder="Search action/user" value="<?= e($search) ?>">
    <input name="date" type="date" value="<?= e($date) ?>">
    <button class="btn sm">Filter</button>
    <?php if ($view === 'active'): ?><a class="btn sm ghost" href="index.php?page=audit&module=<?= e(urlencode($module)) ?>&q=<?= e(urlencode($search)) ?>&date=<?= e($date) ?>&export=csv">⬇ CSV</a><?php endif; ?>
  </form></div>
<div class="card"><div style="overflow-x:auto"><table class="tbl">
  <tr><th>User</th><th>Action</th><th>Module</th><th>Record</th><th>IP</th><th>Time</th><?php if ($view === 'trash'): ?><th>Permanent delete</th><?php endif; ?><th>Manage</th></tr>
  <?php foreach ($rows as $r): ?><tr>
    <td><?= e($r['uname'] ?? ('#' . ($r['user_id'] ?? '?'))) ?></td>
    <td><?= e($r['action']) ?></td><td><?= e($r['module']) ?></td>
    <td><?= e((string)($r['record_id'] ?? '—')) ?></td><td><?= e((string)($r['ip_address'] ?? '')) ?></td>
    <td><?= e($r['created_at']) ?></td>
    <?php if ($view === 'trash'): ?><td><?= e($r['purge_at']) ?></td><?php endif; ?>
    <td>
      <?php if ($view === 'trash'): ?>
        <form method="post" style="display:inline">
          <?= csrf_field() ?><input type="hidden" name="action" value="restore"><input type="hidden" name="id" value="<?=(int)$r['id']?>">
          <button class="btn sm">Restore</button>
        </form>
      <?php else: ?>
        <form method="post" style="display:inline" onsubmit="return confirm('Move this audit log to trash? It will be permanently deleted after 7 days.')">
          <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=(int)$r['id']?>">
          <button class="btn sm ghost">Delete</button>
        </form>
      <?php endif; ?>
    </td></tr><?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="<?= $view === 'trash' ? 8 : 7 ?>">No logs found.</td></tr><?php endif; ?>
</table></div></div>
