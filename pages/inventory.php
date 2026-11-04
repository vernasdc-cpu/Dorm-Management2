<?php
// pages/inventory.php — inner content only (layout_header already called). $user available.
$isAdmin = in_array($user['role'] ?? '', ['super_admin', 'admin_staff'], true);
$pdo = db();

// ---------- POST handlers ----------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    try {
        // ---- Add item (admin only) ----
        if ($action === 'add_item') {
            if (!$isAdmin) throw new RuntimeException('Forbidden: only admins can add items.');
            $name = trim($_POST['name'] ?? '');
            $cat  = (int)($_POST['category_id'] ?? 0);
            if ($name === '' || $cat <= 0) throw new RuntimeException('Name and category are required.');
            $unit = trim($_POST['unit'] ?? 'pcs') ?: 'pcs';
            $av = (int)($_POST['available_qty'] ?? 0);
            $as = (int)($_POST['assigned_qty'] ?? 0);
            $dm = (int)($_POST['damaged_qty'] ?? 0);
            $qtyIn = (int)($_POST['quantity'] ?? 0);
            if ($av === 0 && $as === 0 && $dm === 0 && $qtyIn > 0) $av = $qtyIn;
            if ($av < 0 || $as < 0 || $dm < 0) throw new RuntimeException('Quantities cannot be negative.');
            $qty = $av + $as + $dm;
            $min = max(0, (int)($_POST['min_stock'] ?? 5));
            $loc = trim($_POST['location'] ?? '') ?: null;
            $cond = trim($_POST['condition_status'] ?? 'Good') ?: 'Good';
            $dp = trim($_POST['date_purchased'] ?? '') ?: null;
            if ($dp !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dp)) throw new RuntimeException('Invalid date purchased.');
            $sup = trim($_POST['supplier'] ?? '') ?: null;
            $cost = (float)($_POST['cost'] ?? 0);
            if ($cost < 0) throw new RuntimeException('Cost cannot be negative.');
            $rem = trim($_POST['remarks'] ?? '') ?: null;
            $st = $pdo->prepare('INSERT INTO inventory_items (name,category_id,quantity,unit,available_qty,assigned_qty,damaged_qty,min_stock,location,condition_status,date_purchased,supplier,cost,remarks) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $st->execute([$name, $cat, $qty, $unit, $av, $as, $dm, $min, $loc, $cond, $dp, $sup, $cost, $rem]);
            audit_log((int)$user['id'], 'Added inventory item ' . $name, 'inventory', (string)$pdo->lastInsertId());
            flash('success', 'Item added.');
            redirect('index.php?page=inventory');
        }
        // ---- Edit item (admin only) ----
        if ($action === 'edit_item') {
            if (!$isAdmin) throw new RuntimeException('Forbidden: only admins can edit items.');
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('Invalid item.');
            $name = trim($_POST['name'] ?? '');
            $cat  = (int)($_POST['category_id'] ?? 0);
            if ($name === '' || $cat <= 0) throw new RuntimeException('Name and category are required.');
            $unit = trim($_POST['unit'] ?? 'pcs') ?: 'pcs';
            $av = (int)($_POST['available_qty'] ?? 0);
            $as = (int)($_POST['assigned_qty'] ?? 0);
            $dm = (int)($_POST['damaged_qty'] ?? 0);
            if ($av < 0 || $as < 0 || $dm < 0) throw new RuntimeException('Quantities cannot be negative.');
            $qty = $av + $as + $dm;
            $min = max(0, (int)($_POST['min_stock'] ?? 0));
            $loc = trim($_POST['location'] ?? '') ?: null;
            $cond = trim($_POST['condition_status'] ?? 'Good') ?: 'Good';
            $dp = trim($_POST['date_purchased'] ?? '') ?: null;
            if ($dp !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dp)) throw new RuntimeException('Invalid date purchased.');
            $sup = trim($_POST['supplier'] ?? '') ?: null;
            $cost = (float)($_POST['cost'] ?? 0);
            if ($cost < 0) throw new RuntimeException('Cost cannot be negative.');
            $rem = trim($_POST['remarks'] ?? '') ?: null;
            $st = $pdo->prepare('UPDATE inventory_items SET name=?,category_id=?,quantity=?,unit=?,available_qty=?,assigned_qty=?,damaged_qty=?,min_stock=?,location=?,condition_status=?,date_purchased=?,supplier=?,cost=?,remarks=? WHERE id=?');
            $st->execute([$name, $cat, $qty, $unit, $av, $as, $dm, $min, $loc, $cond, $dp, $sup, $cost, $rem, $id]);
            audit_log((int)$user['id'], 'Edited inventory item ' . $name, 'inventory', (string)$id);
            flash('success', 'Item updated.');
            redirect('index.php?page=inventory');
        }
        // ---- Delete item (admin only, blocked if transactions exist) ----
        if ($action === 'delete_item') {
            if (!$isAdmin) throw new RuntimeException('Forbidden: only admins can delete items.');
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('Invalid item.');
            $c = $pdo->prepare('SELECT COUNT(*) FROM inventory_transactions WHERE item_id=?');
            $c->execute([$id]);
            if ((int)$c->fetchColumn() > 0) throw new RuntimeException('Cannot delete: item has transaction history.');
            $d = $pdo->prepare('DELETE FROM inventory_items WHERE id=?');
            $d->execute([$id]);
            audit_log((int)$user['id'], 'Deleted inventory item #' . $id, 'inventory', (string)$id);
            flash('success', 'Item deleted.');
            redirect('index.php?page=inventory');
        }
        // ---- Stock transaction (all roles on this page) ----
        if ($action === 'transact') {
            $id = (int)($_POST['item_id'] ?? 0);
            $type = trim($_POST['type'] ?? '');
            $tqty = (int)($_POST['quantity'] ?? 0);
            $remarks = trim($_POST['remarks'] ?? '') ?: null;
            $valid = ['Stock In','Stock Out','Assigned','Returned','Damaged','Lost','Disposed'];
            if ($id <= 0 || !in_array($type, $valid, true)) throw new RuntimeException('Invalid transaction.');
            if ($tqty <= 0) throw new RuntimeException('Quantity must be greater than zero.');
            $pdo->beginTransaction();
            $s = $pdo->prepare('SELECT * FROM inventory_items WHERE id=? FOR UPDATE');
            $s->execute([$id]);
            $it = $s->fetch();
            if (!$it) { $pdo->rollBack(); throw new RuntimeException('Item not found.'); }
            $av = (int)$it['available_qty']; $as = (int)$it['assigned_qty']; $dm = (int)$it['damaged_qty'];
            switch ($type) {
                case 'Stock In':  $av += $tqty; break;
                case 'Stock Out':
                    if ($av < $tqty) { $pdo->rollBack(); throw new RuntimeException('Insufficient stock.'); }
                    $av -= $tqty; break;
                case 'Assigned':
                    if ($av < $tqty) { $pdo->rollBack(); throw new RuntimeException('Insufficient stock.'); }
                    $av -= $tqty; $as += $tqty; break;
                case 'Returned':
                    if ($as < $tqty) { $pdo->rollBack(); throw new RuntimeException('Insufficient assigned quantity to return.'); }
                    $as -= $tqty; $av += $tqty; break;
                case 'Damaged':
                    if ($av < $tqty) { $pdo->rollBack(); throw new RuntimeException('Insufficient stock.'); }
                    $av -= $tqty; $dm += $tqty; break;
                case 'Lost':
                    if ($av < $tqty) { $pdo->rollBack(); throw new RuntimeException('Insufficient stock.'); }
                    $av -= $tqty; break;
                case 'Disposed':
                    if ($av < $tqty) { $pdo->rollBack(); throw new RuntimeException('Insufficient stock.'); }
                    $av -= $tqty; break;
            }
            $qty = $av + $as + $dm;
            $u = $pdo->prepare('UPDATE inventory_items SET available_qty=?,assigned_qty=?,damaged_qty=?,quantity=? WHERE id=?');
            $u->execute([$av, $as, $dm, $qty, $id]);
            $t = $pdo->prepare('INSERT INTO inventory_transactions (item_id,type,quantity,performed_by,remarks) VALUES (?,?,?,?,?)');
            $t->execute([$id, $type, $tqty, (int)$user['id'], $remarks]);
            $pdo->commit();
            audit_log((int)$user['id'], $type . ' x' . $tqty . ' on ' . $it['name'], 'inventory', (string)$id);
            flash('success', $type . ' recorded.');
            redirect('index.php?page=inventory');
        }
        throw new RuntimeException('Unknown action.');
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('error', $ex->getMessage());
        redirect('index.php?page=inventory');
    }
}

// ---------- Filters ----------
$q = trim($_GET['q'] ?? '');
$fcat = (int)($_GET['cat'] ?? 0);
$fstock = $_GET['stock'] ?? 'All';
$fcond = trim($_GET['cond'] ?? '');
$histItem = (int)($_GET['hist_item'] ?? 0);

$cats = $pdo->query('SELECT * FROM inventory_categories ORDER BY name')->fetchAll();
$conds = $pdo->query('SELECT DISTINCT condition_status FROM inventory_items ORDER BY condition_status')->fetchAll(PDO::FETCH_COLUMN);

$where = []; $params = [];
if ($q !== '') { $where[] = 'i.name LIKE ?'; $params[] = '%' . $q . '%'; }
if ($fcat > 0) { $where[] = 'i.category_id=?'; $params[] = $fcat; }
if ($fcond !== '') { $where[] = 'i.condition_status=?'; $params[] = $fcond; }
if ($fstock === 'Low') { $where[] = 'i.available_qty > 0 AND i.available_qty <= i.min_stock'; }
elseif ($fstock === 'Out') { $where[] = 'i.available_qty <= 0'; }
elseif ($fstock === 'OK') { $where[] = 'i.available_qty > i.min_stock'; }
$sql = 'SELECT i.*, c.name AS cat_name FROM inventory_items i LEFT JOIN inventory_categories c ON c.id=i.category_id'
    . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY i.name';
$st = $pdo->prepare($sql); $st->execute($params);
$items = $st->fetchAll();

// Low-stock alerts (global, unfiltered)
$low = $pdo->query('SELECT name, available_qty, min_stock FROM inventory_items WHERE available_qty <= min_stock ORDER BY available_qty ASC')->fetchAll();

// ---------- CSV export ----------
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="inventory_' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    $head = ['Name','Category','Quantity','Unit','Available','Assigned','Damaged','Min Stock','Location','Condition','Date Purchased'];
    if ($isAdmin) { $head[] = 'Supplier'; $head[] = 'Cost'; }
    $head[] = 'Remarks'; $head[] = 'Status';
    fputcsv($out, $head);
    foreach ($items as $r) {
        $status = ((int)$r['available_qty'] <= 0) ? 'OUT' : (((int)$r['available_qty'] <= (int)$r['min_stock']) ? 'LOW STOCK' : 'OK');
        $row = [$r['name'], $r['cat_name'], $r['quantity'], $r['unit'], $r['available_qty'], $r['assigned_qty'], $r['damaged_qty'], $r['min_stock'], $r['location'], $r['condition_status'], $r['date_purchased']];
        if ($isAdmin) { $row[] = $r['supplier']; $row[] = $r['cost']; }
        $row[] = $r['remarks']; $row[] = $status;
        fputcsv($out, $row);
    }
    fclose($out); exit;
}

// ---------- Transaction history (recent 50) ----------
$hsql = 'SELECT t.*, i.name AS item_name, u.name AS by_name FROM inventory_transactions t JOIN inventory_items i ON i.id=t.item_id LEFT JOIN users u ON u.id=t.performed_by'
    . ($histItem > 0 ? ' WHERE t.item_id=?' : '') . ' ORDER BY t.id DESC LIMIT 50';
$hst = $pdo->prepare($hsql); $hst->execute($histItem > 0 ? [$histItem] : []);
$history = $hst->fetchAll();

function stock_pill(array $r): string {
    $av = (int)$r['available_qty'];
    if ($av <= 0) return '<span class="pill bad">OUT</span>';
    if ($av <= (int)$r['min_stock']) return '<span class="pill warn">LOW STOCK</span>';
    return '<span class="pill ok">OK</span>';
}
$qs = fn($over = []) => http_build_query(array_merge(['page' => 'inventory','q' => $q,'cat' => $fcat,'stock' => $fstock,'cond' => $fcond,'hist_item' => $histItem], $over));
?>

<?php if ($low): ?>
<div class="alert error" style="font-weight:700">⚠ <?=count($low)?> item(s) at or below minimum stock.</div>
<div class="grid g3" style="margin-bottom:14px">
  <?php foreach ($low as $l): ?>
  <div class="card" style="border-left:5px solid var(--bad)">
    <strong><?=e(strtoupper($l['name']))?></strong><br>
    <span>Current Stock: <?=e($l['available_qty'])?></span><br>
    <span>Minimum Stock: <?=e($l['min_stock'])?></span><br>
    Status: <span class="pill <?=((int)$l['available_qty']<=0)?'bad':'warn'?>"><?=((int)$l['available_qty']<=0)?'OUT OF STOCK':'LOW STOCK'?></span>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="card" style="margin-bottom:14px">
  <form method="get" action="index.php" class="toolbar">
    <input type="hidden" name="page" value="inventory">
    <input name="q" placeholder="Search name…" value="<?=e($q)?>" style="flex:1;min-width:160px">
    <select name="cat"><option value="0">All categories</option>
      <?php foreach ($cats as $c): ?><option value="<?=e($c['id'])?>" <?=$fcat===(int)$c['id']?'selected':''?>><?=e($c['name'])?></option><?php endforeach; ?>
    </select>
    <select name="stock">
      <?php foreach (['All','OK','Low','Out'] as $s): ?><option <?=$fstock===$s?'selected':''?>><?=e($s)?></option><?php endforeach; ?>
    </select>
    <select name="cond"><option value="">All conditions</option>
      <?php foreach ($conds as $c): ?><option <?=$fcond===$c?'selected':''?>><?=e($c)?></option><?php endforeach; ?>
    </select>
    <button class="btn sm">Filter</button>
    <a class="btn ghost sm" href="index.php?page=inventory">Reset</a>
    <span style="flex:1"></span>
    <?php if ($isAdmin): ?><button type="button" class="btn sm" onclick="openM('addM')">+ Add Item</button><?php endif; ?>
    <a class="btn ghost sm" href="index.php?<?=$qs(['export' => 'csv'])?>">CSV Export</a>
    <button type="button" class="btn ghost sm" onclick="window.print()">Print</button>
  </form>
</div>

<div class="card" style="margin-bottom:14px">
  <h3>Items (<?=count($items)?>)</h3>
  <div style="overflow-x:auto">
  <table class="tbl">
    <tr><th>Name</th><th>Category</th><th>Qty</th><th>Unit</th><th>Avail</th><th>Assigned</th><th>Damaged</th><th>Min</th><th>Location</th><th>Condition</th><th>Purchased</th><?php if ($isAdmin): ?><th>Supplier</th><th>Cost</th><?php endif; ?><th>Remarks</th><th>Status</th><th class="no-print">Actions</th></tr>
    <?php foreach ($items as $r): ?>
    <tr>
      <td><strong><?=e($r['name'])?></strong></td>
      <td><?=e($r['cat_name'] ?? '-')?></td>
      <td><?=e($r['quantity'])?></td><td><?=e($r['unit'])?></td>
      <td><?=e($r['available_qty'])?></td><td><?=e($r['assigned_qty'])?></td><td><?=e($r['damaged_qty'])?></td>
      <td><?=e($r['min_stock'])?></td><td><?=e($r['location'] ?? '-')?></td>
      <td><?=e($r['condition_status'])?></td><td><?=e($r['date_purchased'] ?? '-')?></td>
      <?php if ($isAdmin): ?><td><?=e($r['supplier'] ?? '-')?></td><td><?=e(money($r['cost'] ?? 0))?></td><?php endif; ?>
      <td><?=e($r['remarks'] ?? '-')?></td>
      <td><?=stock_pill($r)?></td>
      <td class="no-print" style="white-space:nowrap">
        <button class="btn sm" onclick='openTxn(<?= (int)$r['id'] ?>, <?= json_encode($r['name']) ?>)'>Stock</button>
        <?php if ($isAdmin): ?>
        <button class="btn ghost sm" onclick='openEdit(<?= json_encode($r, JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>Edit</button>
        <form method="post" style="display:inline" onsubmit="return confirm('Delete this item?')">
          <?=csrf_field()?><input type="hidden" name="action" value="delete_item"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
          <button class="btn danger sm">Del</button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    <?php if (!$items): ?><tr><td colspan="20">No items found.</td></tr><?php endif; ?>
  </table>
  </div>
</div>

<div class="card">
  <h3>Transactions — recent 50</h3>
  <form method="get" action="index.php" class="toolbar no-print">
    <input type="hidden" name="page" value="inventory">
    <input type="hidden" name="q" value="<?=e($q)?>"><input type="hidden" name="cat" value="<?=e($fcat)?>">
    <input type="hidden" name="stock" value="<?=e($fstock)?>"><input type="hidden" name="cond" value="<?=e($fcond)?>">
    <select name="hist_item" onchange="this.form.submit()">
      <option value="0">All items</option>
      <?php foreach ($items as $r): ?><option value="<?= (int)$r['id'] ?>" <?=$histItem===(int)$r['id']?'selected':''?>><?=e($r['name'])?></option><?php endforeach; ?>
    </select>
    <?php if ($histItem): ?><a class="btn ghost sm" href="index.php?<?=$qs(['hist_item' => 0])?>">Clear</a><?php endif; ?>
  </form>
  <div style="overflow-x:auto">
  <table class="tbl">
    <tr><th>Date</th><th>Item</th><th>Type</th><th>Qty</th><th>By</th><th>Remarks</th></tr>
    <?php foreach ($history as $h): ?>
    <tr><td><?=e($h['created_at'])?></td><td><?=e($h['item_name'])?></td><td><?=e($h['type'])?></td><td><?=e($h['quantity'])?></td><td><?=e($h['by_name'] ?? '-')?></td><td><?=e($h['remarks'] ?? '-')?></td></tr>
    <?php endforeach; ?>
    <?php if (!$history): ?><tr><td colspan="6">No transactions yet.</td></tr><?php endif; ?>
  </table>
  </div>
</div>

<?php if ($isAdmin): ?>
<div class="modal" id="addM"><div class="box">
  <h3>Add Item</h3>
  <form method="post"><?=csrf_field()?><input type="hidden" name="action" value="add_item">
    <div class="form-grid">
      <div class="field"><label>Name *</label><input name="name" required maxlength="120"></div>
      <div class="field"><label>Category *</label><select name="category_id" required><option value="">—</option><?php foreach ($cats as $c): ?><option value="<?=e($c['id'])?>"><?=e($c['name'])?></option><?php endforeach; ?></select></div>
      <div class="field"><label>Total Qty</label><input name="quantity" type="number" min="0" value="0"></div>
      <div class="field"><label>Unit</label><input name="unit" value="pcs" maxlength="20"></div>
      <div class="field"><label>Available</label><input name="available_qty" type="number" min="0" value="0"></div>
      <div class="field"><label>Assigned</label><input name="assigned_qty" type="number" min="0" value="0"></div>
      <div class="field"><label>Damaged</label><input name="damaged_qty" type="number" min="0" value="0"></div>
      <div class="field"><label>Min Stock</label><input name="min_stock" type="number" min="0" value="5"></div>
      <div class="field"><label>Location</label><input name="location" maxlength="120"></div>
      <div class="field"><label>Condition</label><select name="condition_status"><option>Good</option><option>Fair</option><option>Poor</option><option>Damaged</option><option>New</option></select></div>
      <div class="field"><label>Date Purchased</label><input name="date_purchased" type="date"></div>
      <div class="field"><label>Supplier</label><input name="supplier" maxlength="120"></div>
      <div class="field"><label>Cost (₱)</label><input name="cost" type="number" step="0.01" min="0" value="0"></div>
      <div class="field"><label>Remarks</label><input name="remarks" maxlength="255"></div>
    </div>
    <div class="row"><button class="btn sm">Save</button><button type="button" class="btn ghost sm" onclick="closeM('addM')">Cancel</button></div>
  </form>
</div></div>

<div class="modal" id="editM"><div class="box">
  <h3>Edit Item</h3>
  <form method="post"><?=csrf_field()?><input type="hidden" name="action" value="edit_item"><input type="hidden" name="id" id="e_id">
    <div class="form-grid">
      <div class="field"><label>Name *</label><input name="name" id="e_name" required maxlength="120"></div>
      <div class="field"><label>Category *</label><select name="category_id" id="e_cat" required><?php foreach ($cats as $c): ?><option value="<?=e($c['id'])?>"><?=e($c['name'])?></option><?php endforeach; ?></select></div>
      <div class="field"><label>Unit</label><input name="unit" id="e_unit" maxlength="20"></div>
      <div class="field"><label>Available</label><input name="available_qty" id="e_av" type="number" min="0"></div>
      <div class="field"><label>Assigned</label><input name="assigned_qty" id="e_as" type="number" min="0"></div>
      <div class="field"><label>Damaged</label><input name="damaged_qty" id="e_dm" type="number" min="0"></div>
      <div class="field"><label>Min Stock</label><input name="min_stock" id="e_min" type="number" min="0"></div>
      <div class="field"><label>Location</label><input name="location" id="e_loc" maxlength="120"></div>
      <div class="field"><label>Condition</label><select name="condition_status" id="e_cond"><option>Good</option><option>Fair</option><option>Poor</option><option>Damaged</option><option>New</option></select></div>
      <div class="field"><label>Date Purchased</label><input name="date_purchased" id="e_dp" type="date"></div>
      <div class="field"><label>Supplier</label><input name="supplier" id="e_sup" maxlength="120"></div>
      <div class="field"><label>Cost (₱)</label><input name="cost" id="e_cost" type="number" step="0.01" min="0"></div>
      <div class="field" style="grid-column:1/-1"><label>Remarks</label><input name="remarks" id="e_rem" maxlength="255"></div>
    </div>
    <div class="row"><button class="btn sm">Update</button><button type="button" class="btn ghost sm" onclick="closeM('editM')">Cancel</button></div>
  </form>
</div></div>
<?php endif; ?>

<div class="modal" id="txnM"><div class="box">
  <h3>Stock Transaction — <span id="t_name"></span></h3>
  <form method="post"><?=csrf_field()?><input type="hidden" name="action" value="transact"><input type="hidden" name="item_id" id="t_id">
    <div class="field"><label>Type</label><select name="type" required>
      <option>Stock In</option><option>Stock Out</option><option>Assigned</option><option>Returned</option><option>Damaged</option><option>Lost</option><option>Disposed</option>
    </select></div>
    <div class="field"><label>Quantity</label><input name="quantity" type="number" min="1" value="1" required></div>
    <div class="field"><label>Remarks</label><input name="remarks" maxlength="255" placeholder="e.g. issued to Room 101"></div>
    <div class="row"><button class="btn sm">Submit</button><button type="button" class="btn ghost sm" onclick="closeM('txnM')">Cancel</button></div>
  </form>
</div></div>

<script>
function openM(id){document.getElementById(id).classList.add('open');}
function closeM(id){document.getElementById(id).classList.remove('open');}
function openTxn(id,name){document.getElementById('t_id').value=id;document.getElementById('t_name').textContent=name;openM('txnM');}
<?php if ($isAdmin): ?>
function openEdit(r){
  document.getElementById('e_id').value=r.id; document.getElementById('e_name').value=r.name||'';
  document.getElementById('e_cat').value=r.category_id; document.getElementById('e_unit').value=r.unit||'pcs';
  document.getElementById('e_av').value=r.available_qty; document.getElementById('e_as').value=r.assigned_qty;
  document.getElementById('e_dm').value=r.damaged_qty; document.getElementById('e_min').value=r.min_stock;
  document.getElementById('e_loc').value=r.location||''; document.getElementById('e_cond').value=r.condition_status||'Good';
  document.getElementById('e_dp').value=(r.date_purchased||'').substring(0,10); document.getElementById('e_sup').value=r.supplier||'';
  document.getElementById('e_cost').value=r.cost||0; document.getElementById('e_rem').value=r.remarks||'';
  openM('editM');
}
<?php endif; ?>
document.querySelectorAll('.modal').forEach(m=>m.addEventListener('click',ev=>{if(ev.target===m)m.classList.remove('open');}));
</script>
