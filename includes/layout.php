<?php
function layout_header(string $title, array $user): void {
    $role = $user['role'];
    $nav = [
        ['dashboard','Dashboard','🏠'],
        ['rooms','Rooms','🛏️'],
        ['allocations','Room Allocation','👥'],
        ['contracts','Contracts','📄'],
        ['contract_monitoring','Contract Monitoring','⏰'],
        ['billing','Billing & Payments','₱'],
        ['billing_payments','Billing Payments','📥'],
        ['payment_verification','Payment Verification','✅'],
        ['billing_corrections','Billing Corrections','🛠️'],
        ['tenant_applications','Tenant Applications','📋'],
        ['violations','Violations','⚠️'],
        ['admin_reports','Reports/Maintenance','📝'],
        ['manage_announcements','Manage Announcements','📢'],
        ['inventory','Inventory','📦'],
        ['repairs','Repairs','🔧'],
        ['notifications','Notifications','🔔'],
        ['reports','Reports','📊'],
    ];
    if ($role === 'super_admin') { $nav[] = ['housekeeping','Housekeeping','🧹']; $nav[] = ['tenants','Tenant Profiles','👤']; $nav[] = ['users','Users & Staff','👥']; $nav[] = ['audit','Audit Logs','📋']; $nav[] = ['settings','Settings','⚙']; }
    elseif ($role === 'admin_staff') { $nav[] = ['housekeeping','Housekeeping','🧹']; $nav[] = ['tenants','Tenant Profiles','👤']; }
    if ($role === 'resident') {
        $nav = [
            ['dashboard','Dashboard','🏠'],
            ['my_room','My Room','🛏️'],
            ['my_contract','My Contract','📄'],
            ['my_billing','My Billing','₱'],
            ['my_billing_payment','Billing Payment','₱'],
            ['my_billing_correction','Billing Correction','🛠️'],
            ['my_violations','My Violations','⚠️'],
            ['my_reports','My Reports','📝'],
            ['announcements','Announcements','📢'],
            ['my_repairs','My Repairs','🔧'],
            ['notifications','Notifications','🔔'],
        ];
    }
    if ($role === 'housekeeping') {
        $nav = [
            ['dashboard','Dashboard','🏠'],
            ['housekeeping','Housekeeping','🧹'],
            ['rooms','Rooms','🛏️'],
            ['inventory','Inventory','📦'],
            ['repairs','Repairs','🔧'],
            ['notifications','Notifications','🔔'],
        ];
    }
    $page = $_GET['page'] ?? 'dashboard';
    try { $unread = (int)db()->query('SELECT COUNT(*) FROM notifications WHERE recipient_id='.(int)$user['id'].' AND is_read=0')->fetchColumn(); }
    catch (Throwable $t) { $unread = 0; }
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?=e($title)?> — Heart Ladies Dormitory</title>
<link rel="icon" href="assets/img/logo.jpg">
<link rel="stylesheet" href="assets/css/style.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
</head>
<body>
<div class="app">
<aside class="sidebar" id="sidebar">
  <div class="brand"><img src="assets/img/logo.jpg" alt="logo"><div><strong>Heart Ladies</strong><span>Dormitory</span></div></div>
  <nav>
  <?php foreach ($nav as [$slug,$label,$icon]): ?>
    <a href="index.php?page=<?=e($slug)?>" class="<?=$page===$slug?'active':''?>"><span class="ico"><?=e($icon)?></span><?=e($label)?>
    <?php if ($slug==='notifications' && $unread>0): ?><em class="badge"><?=$unread?></em><?php endif; ?></a>
  <?php endforeach; ?>
  </nav>
  <div class="side-foot"><small><?=e($user['name'])?><br><?=e(str_replace('_',' ',ucfirst($user['role'])))?></small>
  <a class="btn ghost sm" href="index.php?page=logout">Logout</a></div>
</aside>
<div class="main">
<header class="topbar">
  <button class="hamb" onclick="document.getElementById('sidebar').classList.toggle('open')">☰</button>
  <h1><?=e($title)?></h1>
  <div class="top-actions">
    <a class="bell" href="index.php?page=notifications">🔔<?php if($unread>0):?><em><?=$unread?></em><?php endif;?></a>
    <a class="avatar" href="index.php?page=profile" title="Profile"><?=e(mb_substr($user['name'],0,1))?></a>
  </div>
</header>
<main class="content">
<?php foreach (['success','error'] as $k): if ($m = flash($k)): ?>
<div class="alert <?=$k?>"><?=e($m)?></div><?php endif; endforeach; ?>
    <?php
}
function layout_footer(): void { ?>
</main>
<footer class="footer">Heart Ladies Dormitory Management System © <?=date('Y')?></footer>
</div></div>
<script src="assets/js/app.js"></script>
</body></html>
<?php }
