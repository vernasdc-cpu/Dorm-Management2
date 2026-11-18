<?php
if (!in_array($user['role'] ?? '', ['super_admin', 'admin_staff'], true)) {
    http_response_code(403);
    echo '<div class="card"><h3>Forbidden</h3></div>';
    return;
}

$error = null;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    if ($action === 'search') {
        $term = is_string($_POST['q'] ?? null) ? trim($_POST['q']) : '';
        if (mb_strlen($term) > 150) {
            $error = 'Search must be at most 150 characters.';
        } else {
            $_SESSION['reference_search'] = $term;
        }
    } elseif ($action === 'delete') {
        $ids = $_POST['ids'] ?? null;
        if (!is_array($ids) || count($ids) < 1 || count($ids) > 50) {
            $error = 'Select between 1 and 50 reference records to delete.';
        } else {
            foreach ($ids as $id) {
                if (!is_string($id) || !preg_match('/\A[1-9][0-9]{0,19}\z/', $id) || (strlen($id) === 20 && strcmp($id, '18446744073709551615') > 0)) {
                    $error = 'Invalid reference selection. No records were deleted.';
                    break;
                }
            }
            if ($error === null) {
                $ids = array_values(array_unique($ids));
                try {
                    $placeholders = implode(',', array_fill(0, count($ids), '?'));
                    $delete = db()->prepare("DELETE FROM bank_references WHERE id IN ($placeholders)");
                    $delete->execute($ids);
                    $deleted = $delete->rowCount();
                    if ($deleted > 0) {
                        audit_log((int)$user['id'], 'Deleted ' . $deleted . ' bank reference rows', 'bank_references');
                        flash('success', $deleted . ' reference record(s) permanently deleted.');
                    }
                    if ($deleted < count($ids)) flash('error', (count($ids) - $deleted) . ' selected reference record(s) were not found; they may already have been deleted.');
                    $returnPage = max(1, min(100000, (int)($_GET['p'] ?? 1)));
                    redirect('index.php?page=reference&p=' . $returnPage);
                } catch (PDOException $exception) {
                    error_log('Bank reference deletion failed: ' . $exception->getCode());
                    $error = 'Could not delete the selected reference records. Please contact the administrator.';
                }
            }
        }
    } elseif ($action === 'upload') {
    try {
        $file = $_FILES['workbook'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException('Upload failed. Select an XLSX file no larger than 2 MB.');
        if (($file['size'] ?? 0) <= 0 || $file['size'] > 2 * 1024 * 1024) throw new RuntimeException('The XLSX file must be no larger than 2 MB.');
        if (strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION)) !== 'xlsx' || !is_uploaded_file($file['tmp_name'])) throw new RuntimeException('Select a valid XLSX file.');
        $autoload = is_file('/opt/dorm-dependencies/vendor/autoload.php') ? '/opt/dorm-dependencies/vendor/autoload.php' : __DIR__ . '/../vendor/autoload.php';
        if (!is_file($autoload) || !extension_loaded('zip')) throw new RuntimeException('Spreadsheet support is not installed. Contact the administrator.');
        require_once $autoload;
        require_once __DIR__ . '/../includes/reference_import.php';
        $rows = reference_read_workbook($file['tmp_name']);
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $insert = $pdo->prepare('INSERT INTO bank_references (bank_name, account_name, account_number, reference_number, reference_date) VALUES (?,?,?,?,?)');
            foreach ($rows as $row) $insert->execute($row);
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $exception;
        }
        audit_log((int)$user['id'], 'Imported ' . count($rows) . ' bank reference rows', 'bank_references');
        flash('success', count($rows) . ' reference row(s) imported. Duplicate rows are also appended.');
        redirect('index.php?page=reference');
    } catch (\PhpOffice\PhpSpreadsheet\Exception $exception) {
        error_log('Bank reference workbook processing failed: ' . get_class($exception));
        $error = 'Cannot read this XLSX workbook. Check that it is not damaged or password-protected.';
    } catch (PDOException $exception) {
        error_log('Bank reference import database failure: ' . $exception->getCode());
        $error = 'The import failed. No rows were saved; contact the administrator.';
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    } catch (Throwable $exception) {
        error_log('Bank reference import failed: ' . get_class($exception));
        $error = 'The import failed. No rows were saved; contact the administrator.';
    }
    } else {
        $error = 'Unknown action.';
    }
}

if (isset($_GET['reset'])) unset($_SESSION['reference_search']);
$search = $_SESSION['reference_search'] ?? '';
$pageNumber = max(1, min(100000, (int)($_GET['p'] ?? 1)));
$where = '';
$params = [];
if ($search !== '') {
    $where = " WHERE bank_name LIKE ? ESCAPE '!' OR account_name LIKE ? ESCAPE '!' OR account_number LIKE ? ESCAPE '!' OR reference_number LIKE ? ESCAPE '!' OR CAST(reference_date AS CHAR) LIKE ? ESCAPE '!'";
    $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search) . '%';
    $params = array_fill(0, 5, $like);
}
$countQuery = db()->prepare('SELECT COUNT(*) FROM bank_references' . $where);
$countQuery->execute($params);
$total = (int)$countQuery->fetchColumn();
$pageCount = max(1, (int)ceil($total / 50));
$pageNumber = min($pageNumber, $pageCount);
$query = db()->prepare('SELECT id, bank_name, account_name, account_number, reference_number, reference_date FROM bank_references' . $where . ' ORDER BY id DESC LIMIT 50 OFFSET ' . (($pageNumber - 1) * 50));
$query->execute($params);
$results = $query->fetchAll();
?>
<div class="card">
  <h3>Upload bank references</h3>
  <p>Upload an XLSX file (maximum 2 MB). The first worksheet must have the headings Bank Name, Account Name, Account Number, Reference Number, and Date. Store account and reference numbers as text. Dates must be Excel dates or YYYY-MM-DD text.</p>
  <p>Imports append rows, including duplicates. Invalid files are not imported.</p>
  <?php if ($error): ?><div class="alert error"><?=e($error)?></div><?php endif; ?>
  <form method="post" enctype="multipart/form-data">
    <?=csrf_field()?>
    <input type="hidden" name="action" value="upload">
    <div class="field"><label for="workbook">Excel file (.xlsx)</label><input id="workbook" type="file" name="workbook" accept=".xlsx" required></div>
    <button class="btn">Upload references</button>
    <a class="btn ghost" href="index.php?page=billing">Back to Billing &amp; Payments</a>
  </form>
</div>
<div class="card">
  <h3>Reference</h3>
  <form method="post" action="index.php?page=reference" class="row" style="gap:8px;align-items:end;flex-wrap:wrap">
    <?=csrf_field()?>
    <input type="hidden" name="action" value="search">
    <div class="field"><label for="reference-search">Search all columns</label><input id="reference-search" name="q" value="<?=e($search)?>" maxlength="150"></div>
    <button class="btn">Search</button>
    <a class="btn ghost" href="index.php?page=reference&amp;reset=1">Reset</a>
  </form>
  <p><?=e($total)?> result(s) &middot; Page <?=e($pageNumber)?> of <?=e($pageCount)?></p>
  <?php if ($results): ?>
    <form id="delete-selected-references" method="post" action="index.php?page=reference&amp;p=<?=e($pageNumber)?>" onsubmit="if (!document.querySelectorAll('.reference-selection:checked').length) { alert('Select at least one reference record.'); return false; } return confirm('Permanently delete the selected reference records?');">
      <?=csrf_field()?>
      <input type="hidden" name="action" value="delete">
      <button class="btn danger sm" type="submit">Delete selected</button>
    </form>
  <?php endif; ?>
  <div class="tbl-wrap"><table class="tbl">
    <thead><tr><th><input type="checkbox" aria-label="Select all records on this page" onchange="document.querySelectorAll('.reference-selection').forEach(box => box.checked = this.checked)"></th><th>Bank Name</th><th>Account Name</th><th>Account Number</th><th>Reference Number</th><th>Date</th><th>Action</th></tr></thead>
    <tbody>
      <?php if (!$results): ?><tr><td colspan="7">No references found.</td></tr><?php endif; ?>
      <?php foreach ($results as $result): ?><tr>
        <td><input type="checkbox" class="reference-selection" name="ids[]" value="<?=e($result['id'])?>" form="delete-selected-references" aria-label="Select reference record"></td>
        <td><?=e($result['bank_name'])?></td><td><?=e($result['account_name'])?></td><td><?=e($result['account_number'])?></td><td><?=e($result['reference_number'])?></td><td><?=e($result['reference_date'])?></td>
        <td><form method="post" action="index.php?page=reference&amp;p=<?=e($pageNumber)?>" onsubmit="return confirm('Permanently delete this reference record?');">
          <?=csrf_field()?><input type="hidden" name="action" value="delete"><input type="hidden" name="ids[]" value="<?=e($result['id'])?>">
          <button type="submit" class="btn danger sm">Delete</button>
        </form></td>
      </tr><?php endforeach; ?>
    </tbody>
  </table></div>
  <?php if ($pageNumber > 1): ?><a class="btn ghost sm" href="index.php?page=reference&amp;p=<?=e($pageNumber-1)?>">Previous</a><?php endif; ?>
  <?php if ($pageNumber < $pageCount): ?><a class="btn ghost sm" href="index.php?page=reference&amp;p=<?=e($pageNumber+1)?>">Next</a><?php endif; ?>
</div>
