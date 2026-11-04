<?php
// pages/contract_monitoring.php — Contract Monitoring (super_admin, admin_staff). Inner content only.
$pdo = db();
$err = null;

// ---------- Renew / extend / email handler ----------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_check();
    try {
        $action = $_POST['action'] ?? '';

        if ($action === 'renew') {
            $id = (int)($_POST['id'] ?? 0);
            $newEnd = trim($_POST['new_end_date'] ?? '');
            if ($id <= 0 || $newEnd === '') throw new RuntimeException('Please choose a new end date.');
            $st = $pdo->prepare('SELECT * FROM contracts WHERE id=? LIMIT 1');
            $st->execute([$id]);
            $c = $st->fetch();
            if (!$c) throw new RuntimeException('Contract not found.');
            if ($newEnd <= substr((string)$c['end_date'], 0, 10)) throw new RuntimeException('New end date must be after the current end date.');
            $up = $pdo->prepare("UPDATE contracts SET end_date=?, status='Active' WHERE id=?");
            $up->execute([$newEnd, $id]);
            if (!empty($c['tenant_profile_id'])) {
                $pdo->prepare('UPDATE tenant_profiles SET contract_end=? WHERE id=?')
                    ->execute([$newEnd, (int)$c['tenant_profile_id']]);
            }
            audit_log((int)$user['id'], 'Renewed/extended contract #' . $id . ' to ' . $newEnd, 'contracts', (string)$id);
            flash('success', 'Contract extended to ' . $newEnd . (!empty($c['tenant_profile_id']) ? ' and the tenant profile was updated.' : '.'));
            redirect('index.php?page=contract_monitoring');
        }

        if ($action === 'email_reminder') {
            $id = (int)($_POST['id'] ?? 0);
            $st = $pdo->prepare("SELECT c.*,
                    COALESCE(tp.full_name, u.name) AS tenant_name,
                    COALESCE(tp.email, u.email) AS tenant_email,
                    u.id AS resident_user_id,
                    rm.room_number
                FROM contracts c
                LEFT JOIN tenant_profiles tp ON tp.id = c.tenant_profile_id
                LEFT JOIN residents r ON r.residents_id = c.resident_id
                LEFT JOIN users u ON u.id = r.user_id
                JOIN rooms rm ON rm.id = c.room_id
                WHERE c.id = ? LIMIT 1");
            $st->execute([$id]);
            $c = $st->fetch();
            if (!$c) throw new RuntimeException('Contract not found.');

            $email = trim((string)($c['tenant_email'] ?? ''));
            $tenantName = trim((string)($c['tenant_name'] ?? '')) ?: 'Tenant';
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('This tenant has no valid email address on file.');

            $start = substr((string)$c['start_date'], 0, 10);
            $end = substr((string)$c['end_date'], 0, 10);
            $days = days_remaining($end);
            if ($days > 0) {
                $timing = 'will end in ' . $days . ' day' . ($days === 1 ? '' : 's');
            } elseif ($days === 0) {
                $timing = 'ends today';
            } else {
                $timing = 'ended ' . abs($days) . ' day' . (abs($days) === 1 ? '' : 's') . ' ago';
            }

            $subject = 'Contract expiry reminder - ' . $c['contract_number'];
            $body = "Dear {$tenantName},\r\n\r\n"
                . "This is a reminder that your dormitory contract {$timing}.\r\n\r\n"
                . "Contract No: {$c['contract_number']}\r\n"
                . "Unit / Room: {$c['room_number']}\r\n"
                . "Contract start: {$start}\r\n"
                . "Contract end: {$end}\r\n\r\n"
                . "Please contact the dormitory administration office to renew or discuss your contract.\r\n\r\n"
                . "Thank you,\r\nHeart Ladies Dormitory";

            $mailErr = null;
            $sent = send_app_mail($email, $subject, $body, $mailErr);

            // Save an in-app notification when the contract has a portal account.
            $notifSaved = false;
            if (!empty($c['resident_user_id'])) {
                try {
                    $notif = $pdo->prepare('INSERT INTO notifications (title, message, sender_id, recipient_id, recipient_role, priority) VALUES (?,?,?,?,?,?)');
                    $notif->execute([
                        'Contract expiry reminder',
                        "Your contract {$c['contract_number']} for unit {$c['room_number']} {$timing}. Please contact the administration office.",
                        (int)$user['id'],
                        (int)$c['resident_user_id'],
                        'resident',
                        'Important',
                    ]);
                    $notifSaved = true;
                } catch (Throwable $t) { /* the email may still have been sent */ }
            }

            audit_log((int)$user['id'], 'Sent contract reminder for #' . $id . ' to ' . $email, 'contracts', (string)$id);
            if ($sent) {
                $note = mail_uses_local_catcher()
                    ? ' Test mode: the message was captured by Mailpit at http://localhost:8025 and was not delivered to a real inbox.'
                    : '';
                flash('email_success', 'Email reminder sent to ' . $email . '.' . ($notifSaved ? ' Saved to the tenant notification inbox.' : ' No portal account was linked, so no in-app notification was saved.') . $note);
            } else {
                flash('error', ($notifSaved ? 'In-app notification sent, but ' : '') . 'the email could not be delivered: ' . ($mailErr ?: 'unknown mail error'));
            }
            redirect('index.php?page=contract_monitoring');
        }
    } catch (Throwable $t) {
        $err = $t->getMessage() ?: 'Something went wrong. Please try again.';
    }
}

// ---------- Load all contracts ----------
try {
    $rows = $pdo->query("SELECT c.*,
        COALESCE(tp.full_name, u.name) AS tenant_name,
        COALESCE(tp.email, u.email) AS tenant_email,
        COALESCE(tp.phone, u.phone) AS tenant_phone,
        u.id AS resident_user_id,
        rm.room_number,
        (SELECT cd.id FROM contract_documents cd WHERE cd.contract_id=c.id ORDER BY cd.id DESC LIMIT 1) AS latest_doc_id,
        (SELECT cd.file_name FROM contract_documents cd WHERE cd.contract_id=c.id ORDER BY cd.id DESC LIMIT 1) AS latest_doc_name
        FROM contracts c
        LEFT JOIN tenant_profiles tp ON tp.id=c.tenant_profile_id
        LEFT JOIN residents r ON r.residents_id=c.resident_id
        LEFT JOIN users u ON u.id=r.user_id
        JOIN rooms rm ON rm.id=c.room_id
        ORDER BY c.end_date ASC")->fetchAll();
} catch (Throwable $t) { $rows = []; $err = $err ?? 'Could not load contracts.'; }

foreach ($rows as &$c) {
    $c['_computed'] = contract_computed_status($c);
    $c['_days'] = days_remaining(substr((string)$c['end_date'], 0, 10));
}
unset($c);

// Nearest expiry first: upcoming contracts by soonest end date, then recently expired, terminated last.
usort($rows, static function (array $a, array $b): int {
    $aTerm = $a['_computed'] === 'Terminated' ? 1 : 0;
    $bTerm = $b['_computed'] === 'Terminated' ? 1 : 0;
    if ($aTerm !== $bTerm) return $aTerm <=> $bTerm;
    $aUp = ((int)$a['_days']) >= 0;
    $bUp = ((int)$b['_days']) >= 0;
    if ($aUp !== $bUp) return $aUp ? -1 : 1;
    return $aUp
        ? ((int)$a['_days']) <=> ((int)$b['_days'])
        : ((int)$b['_days']) <=> ((int)$a['_days']);
});

$tab = $_GET['tab'] ?? 'All';
$allowed = ['All','Active','Expiring Soon','Expired','Terminated'];
if (!in_array($tab, $allowed, true)) $tab = 'All';

$filtered = array_values(array_filter($rows, fn($c) => $tab === 'All' || $c['_computed'] === $tab));
$expiring = array_values(array_filter($rows, fn($c) => $c['_computed'] === 'Expiring Soon'));

// ---------- CSV export ----------
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="contract_monitoring_' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Tenant','Email','Room','Contract No','Start','End','Days Remaining','Status','Document']);
    foreach ($filtered as $c) {
        fputcsv($out, [$c['tenant_name'] ?? '', $c['tenant_email'] ?? '', $c['room_number'], $c['contract_number'], substr((string)$c['start_date'],0,10), substr((string)$c['end_date'],0,10), $c['_days'], $c['_computed'], $c['latest_doc_name'] ?? '']);
    }
    fclose($out);
    audit_log((int)$user['id'], 'Exported contract monitoring CSV', 'contracts', null);
    exit;
}

function m_pill(string $s): string {
    return match($s) {
        'Active' => 'ok',
        'Expiring Soon' => 'warn',
        'Expired' => 'bad',
        'Terminated' => 'info',
        default => '',
    };
}
$emailSuccess = flash('email_success');
?>

<?php if ($emailSuccess): ?>
<div class="modal open" id="email-success" role="dialog" aria-modal="true" aria-labelledby="email-success-title" onclick="if(event.target===this)closeModal('email-success')">
  <div class="box" style="text-align:center">
    <div aria-hidden="true" style="width:48px;height:48px;margin:0 auto 12px;border-radius:50%;background:#dcfce7;color:#166534;display:grid;place-items:center;font-size:28px">✓</div>
    <h3 id="email-success-title" style="margin-top:0">Email sent successfully</h3>
    <p><?=e($emailSuccess)?></p>
    <button type="button" class="btn" onclick="closeModal('email-success')">OK</button>
  </div>
</div>
<?php endif; ?>

<?php if ($err): ?><div class="alert error"><?=e($err)?></div><?php endif; ?>

<!-- Expiring soon widget -->
<div class="card" style="margin-bottom:14px">
  <div class="row between"><h3 style="margin:0">Contracts Expiring Soon (<?=count($expiring)?>)</h3>
    <div class="row no-print">
      <button class="btn sm ghost" onclick="window.print()">🖨 Print</button>
      <a class="btn sm ghost" href="index.php?page=contract_monitoring&amp;tab=<?=e($tab)?>&amp;export=csv">⬇ CSV</a>
    </div>
  </div>
  <?php if (!$expiring): ?><p style="color:#7c6f8a">No contracts expiring soon. 🎉</p><?php else: ?>
  <div class="grid g3" style="margin-top:10px">
    <?php foreach (array_slice($expiring, 0, 9) as $c): ?>
      <div class="card" style="border-left:5px solid <?= (int)$c['_days'] <= 7 ? '#dc2626' : '#d97706' ?>">
        <strong><?=e($c['tenant_name'] ?? '—')?></strong><br>
        <?php if (!empty($c['tenant_email'])): ?><span style="font-size:12px;color:#7c6f8a"><?=e($c['tenant_email'])?></span><br><?php endif; ?>
        <span style="font-size:13px;color:#7c6f8a">Room <?=e($c['room_number'])?> · <?=e($c['contract_number'])?></span><br>
        <span class="pill <?= (int)$c['_days'] <= 7 ? 'bad' : 'warn' ?>">Expires in <?=(int)$c['_days']?> day<?= (int)$c['_days'] === 1 ? '' : 's' ?></span>
        <div class="row" style="margin-top:8px">
          <?php if (!empty($c['resident_user_id'])): ?>
            <a class="btn sm ghost" href="index.php?page=notifications&amp;to=<?=(int)$c['resident_user_id']?>">Contact</a>
          <?php endif; ?>
          <?php if (!empty($c['tenant_email'])): ?>
          <form method="post" style="display:inline" onsubmit="return confirmDel('Send a contract expiry email reminder to this tenant?')">
            <?=csrf_field()?><input type="hidden" name="action" value="email_reminder"><input type="hidden" name="id" value="<?=(int)$c['id']?>">
            <button class="btn sm">✉ Email</button>
          </form>
          <?php endif; ?>
          <button class="btn sm" onclick="openModal('renew-<?=(int)$c['id']?>')">Renew</button>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<!-- Filter tabs -->
<div class="toolbar no-print">
  <?php foreach ($allowed as $t): ?>
    <a class="btn sm <?= $tab===$t ? '' : 'ghost' ?>" href="index.php?page=contract_monitoring&amp;tab=<?=e($t)?>"><?=e($t)?></a>
  <?php endforeach; ?>
</div>

<div class="card" style="padding:0;overflow:auto">
<table class="tbl">
  <thead><tr><th>Tenant</th><th>Email</th><th>Room</th><th>Contract No</th><th>Start</th><th>End</th><th>Days Left</th><th>Status</th><th>Document</th><th class="no-print">Actions</th></tr></thead>
  <tbody>
  <?php if (!$filtered): ?><tr><td colspan="10">No contracts in this view.</td></tr><?php endif; ?>
  <?php foreach ($filtered as $c): ?>
    <tr>
      <td><?=e($c['tenant_name'] ?? '—')?><?php if (!empty($c['tenant_profile_id'])): ?><br><small style="color:#7c6f8a">Tenant profile</small><?php endif; ?><?php if (!empty($c['tenant_phone'])): ?><br><small style="color:#7c6f8a"><?=e($c['tenant_phone'])?></small><?php endif; ?></td>
      <td><?=e($c['tenant_email'] ?? '—')?></td>
      <td><?=e($c['room_number'])?></td>
      <td><strong><?=e($c['contract_number'])?></strong></td>
      <td><?=e(substr((string)$c['start_date'],0,10))?></td>
      <td><?=e(substr((string)$c['end_date'],0,10))?></td>
      <td><?=(int)$c['_days']?> days</td>
      <td><span class="pill <?=m_pill($c['_computed'])?>"><?=e($c['_computed'])?></span></td>
      <td><?php if ($c['latest_doc_id']): ?><a href="index.php?page=download&amp;type=contract&amp;id=<?=(int)$c['latest_doc_id']?>">📎 <?=e($c['latest_doc_name'] ?? 'Download')?></a><?php else: ?><span style="color:#999">—</span><?php endif; ?></td>
      <td class="no-print" style="white-space:nowrap">
        <button class="btn sm ghost" onclick="openModal('mview-<?=(int)$c['id']?>')">View</button>
        <?php if (!empty($c['resident_user_id'])): ?>
          <a class="btn sm ghost" href="index.php?page=notifications&amp;to=<?=(int)$c['resident_user_id']?>">Contact tenant</a>
        <?php endif; ?>
        <?php if (!empty($c['tenant_email'])): ?>
          <form method="post" style="display:inline" onsubmit="return confirmDel('Send a contract expiry email reminder to this tenant?')">
            <?=csrf_field()?><input type="hidden" name="action" value="email_reminder"><input type="hidden" name="id" value="<?=(int)$c['id']?>">
            <button class="btn sm">✉ Email</button>
          </form>
        <?php else: ?>
          <button class="btn sm ghost" disabled title="No email address on file">✉ Email</button>
        <?php endif; ?>
        <button class="btn sm" onclick="openModal('renew-<?=(int)$c['id']?>')">Renew / Extend</button>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<?php foreach ($filtered as $c): ?>
<div class="modal" id="mview-<?=(int)$c['id']?>"><div class="box">
  <h3><?=e($c['contract_number'])?> — <?=e($c['tenant_name'] ?? '—')?></h3>
  <p>Room <?=e($c['room_number'])?> · <?=e(substr((string)$c['start_date'],0,10))?> → <?=e(substr((string)$c['end_date'],0,10))?><br>
  Tenant email: <?=e($c['tenant_email'] ?? '—')?><br>
  Days remaining: <strong><?=(int)$c['_days']?></strong> · Status: <span class="pill <?=m_pill($c['_computed'])?>"><?=e($c['_computed'])?></span><br>
  Rate: ₱<?=e(number_format((float)$c['monthly_rate'],2))?></p>
  <p><strong>Terms:</strong><br><?=nl2br(e($c['terms'] ?? '—'))?></p>
  <button class="btn sm ghost" onclick="closeModal('mview-<?=(int)$c['id']?>')">Close</button>
</div></div>
<div class="modal" id="renew-<?=(int)$c['id']?>"><div class="box">
  <h3>Renew / Extend — <?=e($c['contract_number'])?></h3>
  <p style="font-size:13px">Current end: <strong><?=e(substr((string)$c['end_date'],0,10))?></strong>. New end date must be later.</p>
  <form method="post">
    <?=csrf_field()?><input type="hidden" name="action" value="renew"><input type="hidden" name="id" value="<?=(int)$c['id']?>">
    <div class="field"><label>New end date</label><input type="date" name="new_end_date" min="<?=e(date('Y-m-d', strtotime(substr((string)$c['end_date'],0,10) . ' +1 day')))?>" required></div>
    <div class="row"><button class="btn sm">Extend</button><button type="button" class="btn sm ghost" onclick="closeModal('renew-<?=(int)$c['id']?>')">Cancel</button></div>
  </form>
</div></div>
<?php endforeach; ?>

<script>
function openModal(id){document.getElementById(id)?.classList.add('open');}
function closeModal(id){document.getElementById(id)?.classList.remove('open');}
document.querySelectorAll('.modal').forEach(m=>m.addEventListener('click',e=>{if(e.target===m)m.classList.remove('open');}));
</script>
