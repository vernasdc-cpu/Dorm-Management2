<?php
$pdo = db();
$err = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_check();
    try {
        if (($_POST['action'] ?? '') !== 'create_account') {
            throw new RuntimeException('Invalid tenant account action.');
        }
        $profileId = (int)($_POST['tenant_profile_id'] ?? 0);
        if ($profileId <= 0) throw new RuntimeException('Select a valid tenant profile.');

        try {
            $pdo->beginTransaction();
            $st = $pdo->prepare('SELECT id, full_name, email, phone, profile_status, room_id FROM tenant_profiles WHERE id=? FOR UPDATE');
            $st->execute([$profileId]);
            $profile = $st->fetch();
            if (!$profile) throw new RuntimeException('Tenant profile not found.');
            $account = tenant_provision_account($pdo, $profile);
            $pdo->commit();
        } catch (Throwable $t) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $t;
        }

        audit_log((int)$user['id'], ($account['created'] ? 'Created' : 'Linked') . ' tenant portal account for ' . $profile['email'], 'tenant_accounts', (string)$profileId);
        $profile['email'] = $account['email'];
        $profile['account_status'] = $account['status'];
        $mailError = null;
        if (tenant_send_account_email($profile, $account['password'], $mailError)) {
            flash('success', ($account['created'] ? 'Tenant account created.' : 'Existing tenant account linked.') . ' Welcome email sent to ' . $profile['email'] . '.');
        } else {
            flash('error', 'Tenant account is ready, but the welcome email could not be sent: ' . ($mailError ?: 'Unknown mail error.'));
        }
        redirect('index.php?page=tenant_accounts');
    } catch (Throwable $t) {
        $err = $t->getMessage() ?: 'Could not create the tenant account.';
    }
}

try {
    $rows = $pdo->query("SELECT tp.id, tp.full_name, tp.email, tp.phone, tp.profile_status, tp.room_id,
            u.id AS account_id, u.email AS account_email, u.role AS account_role, u.status AS account_status,
            u.deleted_at AS account_deleted_at, u.tenant_profile_id AS account_profile_id
        FROM tenant_profiles tp
        LEFT JOIN users u ON u.id = (
            SELECT u2.id
            FROM users u2
            WHERE u2.deleted_at IS NULL AND (u2.tenant_profile_id = tp.id
               OR (tp.email IS NOT NULL AND LOWER(u2.email) = LOWER(tp.email)
                   AND NOT EXISTS (SELECT 1 FROM users old WHERE old.deleted_at IS NOT NULL AND LOWER(old.email) = LOWER(u2.email))))
            ORDER BY (u2.tenant_profile_id = tp.id) DESC, u2.id DESC
            LIMIT 1
        )
        ORDER BY tp.full_name, tp.id")->fetchAll();
} catch (Throwable $t) {
    $rows = [];
    $err = $err ?? 'Could not load tenant accounts.';
}
?>
<?php if ($err): ?><div class="alert error"><?=e($err)?></div><?php endif; ?>

<div class="card">
  <h3>Tenant Account Users</h3>
  <p>Accounts are created automatically when a tenant profile with an email address is added. New accounts use the tenant name followed by <code>@123</code> as the initial password. Tenants can change it from My Profile after logging in.</p>
</div>

<div class="card" style="padding:0;overflow:auto">
<table class="tbl">
  <thead><tr><th>Tenant</th><th>Email / User account</th><th>Profile status</th><th>Account status</th><th class="no-print">Action</th></tr></thead>
  <tbody>
  <?php if (!$rows): ?><tr><td colspan="5">No tenant profiles found.</td></tr><?php endif; ?>
  <?php foreach ($rows as $r):
      $accountStatus = 'Not created';
      if ($r['account_id'] !== null) {
          if ($r['account_deleted_at'] !== null) $accountStatus = 'Deleted';
          elseif ($r['account_role'] !== 'resident') $accountStatus = 'Email already used';
          elseif ($r['account_profile_id'] !== null && (int)$r['account_profile_id'] !== (int)$r['id']) $accountStatus = 'Linked to another profile';
          else $accountStatus = $r['account_status'] === 'active' ? 'Active' : 'Inactive';
      }
      $canCreate = $r['account_id'] === null && filter_var((string)$r['email'], FILTER_VALIDATE_EMAIL);
  ?>
    <tr>
      <td><strong><?=e($r['full_name'])?></strong></td>
      <td><?=e($r['account_email'] ?? $r['email'] ?? '—')?></td>
      <td><?=e($r['profile_status'])?></td>
      <td><span class="pill <?=$accountStatus === 'Active' ? 'ok' : ($accountStatus === 'Not created' ? 'warn' : 'bad')?>"><?=e($accountStatus)?></span></td>
      <td class="no-print">
        <?php if ($canCreate): ?>
          <form method="post" style="display:inline">
            <?=csrf_field()?><input type="hidden" name="action" value="create_account"><input type="hidden" name="tenant_profile_id" value="<?=(int)$r['id']?>">
            <button class="btn sm">Create &amp; email account</button>
          </form>
        <?php elseif (!$r['email']): ?>
          <span style="color:var(--muted)">Add an email to the profile first.</span>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
