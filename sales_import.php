<?php
/**
 * Percentage Sales Import — the practical stand-in for a live
 * POS integration. Percentage-of-sales agreements cannot be
 * billed until the mart knows the month's sales; the supplier
 * (or the mart's POS export) provides them as a simple CSV:
 *
 *   company, month, sales_amount
 *   Fresh Dairy, 2026-10, 1250000
 *
 * Each row fills that company's awaited bill for the month at
 * the agreement's percentage rate, through the same audit and
 * validation rules as entering the amount by hand. Rows that
 * cannot be applied are reported, never silently dropped.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/billing.php';
require_once __DIR__ . '/layout.php';

requireRole(['admin', 'accountant']);
$pdo = db();
syncBills($pdo);

if (($_GET['template'] ?? '') === '1') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="sales-import-template.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['company', 'month', 'sales_amount']);
    fputcsv($out, ['Fresh Dairy', '2026-10', '1250000']);
    fputcsv($out, ['Herbal Care Co', '2026-10', '860000']);
    fclose($out);
    exit;
}

$results = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $f = $_FILES['csv'] ?? null;
    if (!$f || $f['error'] !== UPLOAD_ERR_OK) {
        flash('error', 'Please choose a CSV file to import.');
        header('Location: sales_import.php');
        exit;
    }
    $agreements = $pdo->query('SELECT * FROM agreements')->fetchAll();
    $byKey = [];
    foreach ($agreements as $ag) {
        $byKey[strtolower(trim($ag['supplier_name']))] = $ag;
        $byKey[strtolower(trim($ag['ledger_code']))] = $ag;
    }
    $results = [];
    $applied = 0;
    $fh = fopen($f['tmp_name'], 'r');
    $line = 0;
    while (($row = fgetcsv($fh)) !== false) {
        $line++;
        if ($line === 1 && isset($row[0]) && stripos((string)$row[0], 'company') !== false) continue; // header
        if (count($row) < 3 || trim((string)$row[0]) === '') continue;
        [$company, $month, $sales] = [trim((string)$row[0]), trim((string)$row[1]), (float)str_replace(',', '', (string)$row[2])];
        $key = strtolower($company);
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])(-\d{2})?$/', $month)) {
            $results[] = [$company, $month, 'Skipped — month must look like 2026-10.'];
            continue;
        }
        $billMonth = substr($month, 0, 7) . '-01';
        $ag = $byKey[$key] ?? null;
        if (!$ag) {
            $results[] = [$company, $month, 'Skipped — company not found.'];
            continue;
        }
        if ($ag['rent_type'] !== 'percentage') {
            $results[] = [$company, $month, 'Skipped — this agreement is fixed-rent, not percentage.'];
            continue;
        }
        $bst = $pdo->prepare('SELECT id, expected_amount FROM bills WHERE agreement_id = :a AND bill_month = :m');
        $bst->execute([':a' => $ag['id'], ':m' => $billMonth]);
        $bill = $bst->fetch();
        if (!$bill) {
            $results[] = [$company, $month, 'Skipped — no bill exists for that month (outside the agreement period?).'];
            continue;
        }
        if ($bill['expected_amount'] !== null) {
            $results[] = [$company, $month, 'Skipped — the bill already has an amount (' . money($bill['expected_amount']) . ').'];
            continue;
        }
        $amount = round($sales * (float)$ag['percentage_rate'] / 100, 2);
        $pdo->prepare('UPDATE bills SET expected_amount = :amt WHERE id = :id')
            ->execute([':amt' => $amount, ':id' => $bill['id']]);
        audit($pdo, 'sales_import', 'bill', (int)$bill['id'],
            'Percentage amount set from sales import: sales ' . money($sales) . ' × ' . $ag['percentage_rate'] . '% = ' . money($amount) . ' (' . $company . ', ' . $billMonth . ').');
        $results[] = [$company, $month, 'Applied — rent ' . money($amount) . ' from sales ' . money($sales) . '.'];
        $applied++;
    }
    fclose($fh);
    flash($applied > 0 ? 'success' : 'error', sprintf(t('sales_imported'), $applied));
}

layoutHead(t('sales_import'), 'grid');
pageTitle(t('sales_import'), t('sales_import_subtitle'));
?>

<div class="panel" style="max-width:760px">
  <h2 class="panel-title"><i class="bi bi-upload me-2"></i><?= e(t('sales_import')) ?></h2>
  <p><?= e(t('sales_import_note')) ?></p>
  <p><a class="btn btn-light border" href="sales_import.php?template=1"><i class="bi bi-filetype-csv me-1"></i> <?= e(t('download_template')) ?></a></p>
  <form method="post" enctype="multipart/form-data" class="row g-2">
    <?= csrfField() ?>
    <div class="col-md-8">
      <input type="file" class="form-control" name="csv" accept=".csv,text/csv" required>
    </div>
    <div class="col-md-4">
      <button class="btn btn-gold w-100" type="submit"><i class="bi bi-cloud-arrow-up me-1"></i> <?= e(t('import')) ?></button>
    </div>
  </form>
</div>

<?php if ($results !== null): ?>
<div class="panel mt-3" style="max-width:760px">
  <h2 class="panel-title"><?= e(t('import_results')) ?></h2>
  <div class="table-responsive">
    <table class="table table-luxe align-middle mb-0">
      <thead><tr><th><?= e(t('company')) ?></th><th><?= e(t('month')) ?></th><th><?= e(t('result')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($results as [$c, $m, $msg]): ?>
        <tr><td><?= e($c) ?></td><td class="text-nowrap"><?= e($m) ?></td><td><?= e($msg) ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$results): ?><tr><td colspan="3" class="text-center py-3 text-muted">The file had no data rows.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php layoutFoot(); ?>
