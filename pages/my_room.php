<?php
// pages/my_room.php — Resident view of assigned room + roommates.
// Role: resident. Included AFTER layout_header. $user is available.

$resident = null; $alloc = null; $room = null; $mates = []; $loadError = null;
try {
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM residents WHERE user_id=?');
    $st->execute([(int)$user['id']]);
    $resident = $st->fetch() ?: null;

    if ($resident) {
        $a = $pdo->prepare("SELECT a.* FROM room_allocations a
            WHERE a.resident_id=? AND a.status IN ('active','reserved')
            ORDER BY FIELD(a.status,'active','reserved'), a.allocated_at DESC LIMIT 1");
        $a->execute([$resident['residents_id']]);
        $alloc = $a->fetch() ?: null;

        $roomId = $alloc ? (int)$alloc['room_id'] : (int)($resident['room_id'] ?? 0);
        if ($roomId > 0) {
            $r = $pdo->prepare('SELECT r.*, f.name AS floor_name, t.name AS type_name
                FROM rooms r JOIN floors f ON f.id=r.floor_id JOIN room_types t ON t.id=r.room_type_id
                WHERE r.id=?');
            $r->execute([$roomId]);
            $room = $r->fetch() ?: null;
        }
        if ($room) {
            $m = $pdo->prepare("SELECT u.name, a.bed_no FROM room_allocations a
                JOIN residents res ON res.residents_id=a.resident_id
                JOIN users u ON u.id=res.user_id
                WHERE a.room_id=? AND a.status IN ('active','reserved') AND a.resident_id<>?
                ORDER BY a.bed_no");
            $m->execute([$room['id'], $resident['residents_id']]);
            $mates = $m->fetchAll();
        }
    }
} catch (Throwable $e) {
    error_log('my_room.php load: ' . $e->getMessage());
    $loadError = 'Could not load your room details right now. Please try again later.';
}
?>

<?php if ($loadError): ?>
  <div class="alert error"><?=e($loadError)?></div>
<?php elseif (!$resident || (!$alloc && !$room)): ?>
  <div class="card"><p>No room assigned yet. Please contact administration.</p></div>
<?php else:
  $bedNo = $alloc['bed_no'] ?? $resident['bed_no'] ?? '—';
  $pill = ($alloc['status'] ?? '') === 'reserved' ? '<span class="pill info">Reserved</span>' : '<span class="pill ok">Active</span>';
?>
<div class="grid g2">
  <div class="card">
    <h3>My Room — <?=e($room['room_number'])?> <?=$pill?></h3>
    <table class="tbl"><tbody>
      <tr><th>Room number</th><td><?=e($room['room_number'])?></td></tr>
      <tr><th>Floor</th><td><?=e($room['floor_name'])?></td></tr>
      <tr><th>Room type</th><td><?=e($room['type_name'])?></td></tr>
      <tr><th>Aircon</th><td><?=e($room['aircon'])?></td></tr>
      <tr><th>My bed</th><td><strong><?=e($bedNo)?></strong></td></tr>
      <tr><th>Monthly rate</th><td><?=money($room['monthly_rate'])?></td></tr>
      <tr><th>Since</th><td><?=e($alloc ? substr((string)$alloc['allocated_at'], 0, 10) : '—')?></td></tr>
    </tbody></table>
    <?php if (!empty($room['description'])): ?><p style="margin-top:8px"><?=e($room['description'])?></p><?php endif; ?>
  </div>
  <div class="card">
    <h3>Room Details &amp; Bed Assignment</h3>
    <p>Room <strong><?=e($room['room_number'])?></strong> (<?=e($room['floor_name'])?>, <?=e($room['type_name'])?>, <?=e($room['aircon'])?>) at <strong><?=money($room['monthly_rate'])?>/month</strong>.</p>
    <p>Your assigned bed is <strong><?=e($bedNo)?></strong>. Please keep common areas clean and report any issue via the Repairs page.</p>
  </div>
</div>

<div class="card" style="margin-top:14px">
  <h3>Roommates (<?=count($mates)?>)</h3>
  <?php if (!$mates): ?><p style="color:#7c6f8a">No roommates assigned to this room right now.</p>
  <?php else: ?>
  <table class="tbl"><thead><tr><th>Name</th><th>Bed</th></tr></thead><tbody>
    <?php foreach ($mates as $mt): ?>
      <tr><td><?=e($mt['name'])?></td><td><?=e($mt['bed_no'] ?? '—')?></td></tr>
    <?php endforeach; ?>
  </tbody></table>
  <?php endif; ?>
</div>
<?php endif; ?>
