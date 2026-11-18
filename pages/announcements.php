<?php
if (($user['role'] ?? '') !== 'resident') {
    http_response_code(403);
    echo '<div class="card"><h3>Forbidden</h3><p>Residents only.</p></div>';
    return;
}
$pdo = db();
$ctx = get_tenant_context($user);
$anns = [];
try {
    $st = $pdo->query("SELECT a.* FROM announcements a WHERE a.is_published = 1 AND (a.published_at IS NULL OR a.published_at <= NOW()) ORDER BY a.id DESC");
    $all = $st->fetchAll();
    foreach ($all as $a) {
        // Basic audience check
        $ok = true;
        if ($a['audience'] === 'floor' && $a['target_floor']) {
            $ok = false;
            if ($ctx['tenant_profile'] && $ctx['tenant_profile']['floor_level'] === $a['target_floor']) $ok = true;
        }
        if ($ok) $anns[] = $a;
    }
} catch (Throwable $t) {
    error_log('Could not load tenant announcements: ' . $t->getMessage());
    $announcementsError = 'Could not load announcements. Please contact the administrator.';
}
?>
<div class="card">
  <h3>Announcements</h3>
  <?php if (!empty($announcementsError)): ?><div class="alert error"><?=e($announcementsError)?></div>
  <?php elseif (!$anns): ?><p style="color:#7c6f8a">No announcements.</p><?php endif; ?>
  <?php foreach ($anns as $a): ?>
    <div class="card" style="margin-top:10px">
      <h4 style="margin:0"><?=e($a['title'])?></h4>
      <small style="color:#7c6f8a"><?=e($a['published_at'] ?: $a['created_at'])?><?= $a['important_deadline'] ? ' · Deadline: '.e($a['important_deadline']) : '' ?></small>
      <div style="margin-top:8px;white-space:pre-wrap"><?=nl2br(e($a['message']))?></div>
      <?php if(!empty($a['attachment_path'])): ?><p><a class="btn ghost sm" href="uploads/announcements/<?=e(basename($a['attachment_path']))?>" target="_blank">Attachment</a></p><?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>
