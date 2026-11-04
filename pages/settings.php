<?php
// pages/settings.php — super_admin only. Key-value system_settings editor. Included AFTER layout_header.
$keys = ['dorm_name' => 'Dorm name', 'dorm_address' => 'Dorm address', 'dorm_phone' => 'Dorm phone', 'low_stock_default' => 'Low-stock default', 'contract_warning_days' => 'Contract warning days (comma-separated)'];

try {
  if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_check();
    $up = db()->prepare('INSERT INTO system_settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
    foreach ($keys as $k => $label) {
      $v = trim($_POST[$k] ?? '');
      if ($k === 'low_stock_default' && $v !== '' && !ctype_digit($v)) throw new RuntimeException('Low-stock default must be a number.');
      $up->execute([$k, $v]);
    }
    audit_log((int)$user['id'], 'Updated system settings', 'settings');
    flash('success', 'Settings saved.');
    redirect('index.php?page=settings');
  }
  $rows = db()->query('SELECT setting_key,setting_value FROM system_settings')->fetchAll(PDO::FETCH_KEY_PAIR);
  try { $dbVer = db()->query('SELECT VERSION()')->fetchColumn(); } catch (Throwable $t) { $dbVer = 'unknown'; }
} catch (Throwable $t) { $rows = $rows ?? []; $dbVer = $dbVer ?? 'unknown'; if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') { flash('error', $t->getMessage()); redirect('index.php?page=settings'); } }
$dbName = defined('DB_NAME') ? DB_NAME : '(see config/app.php)';
$dbHost = defined('DB_HOST') ? DB_HOST : 'localhost';
?>
<div class="card"><h3>⚙ System Settings</h3>
  <form method="post"><?= csrf_field() ?>
    <?php foreach ($keys as $k => $label): ?>
      <div class="field"><label><?= e($label) ?> <small>(<?= e($k) ?>)</small></label>
      <input name="<?= e($k) ?>" value="<?= e($rows[$k] ?? '') ?>"></div>
    <?php endforeach; ?>
    <button class="btn">Save settings</button></form></div>
<div class="card"><h3>Database info & backup</h3>
  <p>Host: <b><?= e($dbHost) ?></b> · Database: <b><?= e($dbName) ?></b> · Server version: <b><?= e((string)$dbVer) ?></b></p>
  <p>Back up with <code>mysqldump</code> (run on the DB host):</p>
  <pre>mysqldump -h <?= e($dbHost) ?> -u YOUR_USER -p <?= e($dbName) ?> &gt; heart_dorm_backup_<?= date('Ymd') ?>.sql</pre>
  <p><small>Restore with: <code>mysql -h <?= e($dbHost) ?> -u YOUR_USER -p <?= e($dbName) ?> &lt; heart_dorm_backup_YYYYMMDD.sql</code></small></p></div>
