<?php
/**
 * Reports hub — every report reads through billing.php, so the
 * numbers always match the dashboard, the grid and each other.
 * Every report has a CSV download (reports_export.php) and the
 * whole page is print-friendly.
 *
 *   a. Monthly Recovery      b. Overdue by Company
 *   c. Company-wise Summary  d. Aging Report
 *   e. Payment Method Summary f. Incentive Report
 *   g. Expiring Agreements   h. Supplier Statements (links)
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/billing.php';
require_once __DIR__ . '/layout.php';

requireLogin();
$pdo = db();
syncBills($pdo);

$year  = (int)($_GET['year'] ?? date('Y'));
$year  = max(2020, min(2100, $year));
$today = date('Y-m-d');
$allBills = loadBills($pdo);

/* --- a. Monthly recovery --- */
$months = [];
for ($m = 1; $m <= 12; $m++) $months[$m] = ['expected' => 0.0, 'received' => 0.0, 'awaited' => 0];
foreach (loadBills($pdo, $year) as $b) {
    $m = (int)date('n', strtotime($b['bill_month']));
    if ($b['expected_amount'] === null) { $months[$m]['awaited']++; continue; }
    $months[$m]['expected'] += (float)$b['expected_amount'];
    $months[$m]['received'] += min($b['paid'], (float)$b['expected_amount']);
}

/* --- b. Overdue summary by company --- */
$overdue = [];
foreach ($allBills as $b) {
    if ($b['expected_amount'] === null || $b['due_date'] >= $today) continue;
    $balance = (float)$b['expected_amount'] - $b['paid'];
    if ($balance <= 0) continue;
    $id = $b['agreement_id'];
    $overdue[$id] ??= ['supplier_name' => $b['supplier_name'], 'ledger_code' => $b['ledger_code'], 'bills' => 0, 'amount' => 0.0, 'oldest' => $b['due_date']];
    $overdue[$id]['bills']++;
    $overdue[$id]['amount'] += $balance;
    if ($b['due_date'] < $overdue[$id]['oldest']) $overdue[$id]['oldest'] = $b['due_date'];
}
usort($overdue, fn($a, $b) => $b['amount'] <=> $a['amount']);
$totalOverdue = array_sum(array_column($overdue, 'amount'));

/* --- c. Company-wise summary (all bills, whole agreement periods) --- */
$companySum = [];
foreach ($allBills as $b) {
    $id = $b['agreement_id'];
    $companySum[$id] ??= ['supplier_name' => $b['supplier_name'], 'ledger_code' => $b['ledger_code'], 'expected' => 0.0, 'received' => 0.0, 'awaited' => 0];
    if ($b['expected_amount'] === null) { $companySum[$id]['awaited']++; continue; }
    $companySum[$id]['expected'] += (float)$b['expected_amount'];
    $companySum[$id]['received'] += min($b['paid'], (float)$b['expected_amount']);
}

/* --- d. Aging report (overdue buckets) --- */
$aging = ['0–30 days' => [0, 0.0], '31–60 days' => [0, 0.0], '61–90 days' => [0, 0.0], '90+ days' => [0, 0.0]];
foreach ($allBills as $b) {
    if ($b['expected_amount'] === null || $b['due_date'] >= $today) continue;
    $balance = (float)$b['expected_amount'] - $b['paid'];
    if ($balance <= 0) continue;
    $days = (int)floor((strtotime($today) - strtotime($b['due_date'])) / 86400);
    $key = $days <= 30 ? '0–30 days' : ($days <= 60 ? '31–60 days' : ($days <= 90 ? '61–90 days' : '90+ days'));
    $aging[$key][0]++;
    $aging[$key][1] += $balance;
}

/* --- e. Payment method summary --- */
$methods = $pdo->query('SELECT method, COUNT(*) AS n, SUM(amount) AS total FROM payments GROUP BY method ORDER BY total DESC')->fetchAll();
$methodTotal = array_sum(array_map(fn($r) => (float)$r['total'], $methods));

/* --- f. Incentive report (per company) --- */
$incPer = [];
foreach (loadIncentives($pdo) as $inc) {
    $id = $inc['agreement_id'];
    $incPer[$id] ??= ['supplier_name' => $inc['supplier_name'], 'ledger_code' => $inc['ledger_code'], 'expected' => 0.0, 'received' => 0.0, 'overdue' => 0.0, 'awaited' => 0];
    if ($inc['expected_amount'] === null) { $incPer[$id]['awaited']++; continue; }
    $incPer[$id]['expected'] += (float)$inc['expected_amount'];
    $incPer[$id]['received'] += min((float)$inc['received_amount'], (float)$inc['expected_amount']);
    if ($inc['expected_date'] < $today && $inc['balance'] !== null) $incPer[$id]['overdue'] += $inc['balance'];
}

/* --- g. Expiring agreements (next 90 days, windowed) + h. statement links --- */
$expiring = $pdo->prepare('SELECT * FROM agreements WHERE period_to BETWEEN :t AND :l ORDER BY period_to ASC');
$expiring->execute([':t' => $today, ':l' => date('Y-m-d', strtotime('+90 days'))]);
$expiring = $expiring->fetchAll();
$allAgreements = $pdo->query('SELECT id, supplier_name, ledger_code FROM agreements ORDER BY supplier_name')->fetchAll();

function csvBtn(string $type, array $params = []): void
{
    $qs = http_build_query(array_merge(['type' => $type], $params));
    echo '<a class="btn btn-sm btn-light border no-print" href="reports_export.php?' . e($qs) . '"><i class="bi bi-filetype-csv me-1"></i> ' . e(t('download_csv')) . '</a>';
}

layoutHead(t('reports'), 'reports');
?>

<div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
  <?php pageTitle(t('reports'), 'Print-friendly summaries. Figures agree with the Dashboard and Payment Grid.'); ?>
  <button class="btn btn-gold no-print" onclick="window.print()"><i class="bi bi-printer me-1"></i> <?= e(t('print')) ?></button>
</div>

<div class="no-print d-flex flex-wrap gap-2 mb-3">
  <?php foreach (['r-monthly' => 'Monthly Recovery', 'r-overdue' => 'Overdue by Company', 'r-company' => 'Company Summary', 'r-aging' => 'Aging', 'r-methods' => 'Payment Methods', 'r-incentives' => 'Incentives', 'r-expiring' => 'Expiring', 'r-statements' => 'Statements'] as $anchor => $label): ?>
    <a class="btn btn-sm btn-outline-gold" href="#<?= $anchor ?>"><?= $label ?></a>
  <?php endforeach; ?>
</div>

<div class="panel mb-3" id="r-monthly">
  <div class="panel-head">
    <h2><i class="bi bi-calendar-check me-2"></i>Monthly Recovery — <?= $year ?></h2>
    <div class="d-flex gap-2 align-items-center">
      <?php csvBtn('monthly', ['year' => $year]); ?>
      <form class="no-print d-flex gap-2" method="get">
        <a class="btn btn-sm btn-light border" href="reports.php?year=<?= $year - 1 ?>"><i class="bi bi-chevron-left"></i> <?= $year - 1 ?></a>
        <a class="btn btn-sm btn-light border" href="reports.php?year=<?= $year + 1 ?>"><?= $year + 1 ?> <i class="bi bi-chevron-right"></i></a>
      </form>
    </div>
  </div>
  <div class="table-responsive">
    <table class="table table-luxe align-middle mb-0">
      <thead><tr><th>Month</th><th class="text-end">Expected</th><th class="text-end">Received</th><th class="text-end">Outstanding</th><th class="text-end">Recovery %</th><th class="text-end">Awaited bills</th></tr></thead>
      <tbody>
      <?php $tE = 0; $tR = 0; foreach ($months as $m => $v): $tE += $v['expected']; $tR += $v['received']; ?>
        <tr>
          <td><?= date('F', mktime(0, 0, 0, $m, 1)) ?></td>
          <td class="text-end"><?= money($v['expected']) ?></td>
          <td class="text-end"><?= money($v['received']) ?></td>
          <td class="text-end"><?= money(max(0, $v['expected'] - $v['received'])) ?></td>
          <td class="text-end"><?= $v['expected'] > 0 ? number_format($v['received'] / $v['expected'] * 100, 1) . '%' : '—' ?></td>
          <td class="text-end"><?= $v['awaited'] ?: '—' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr><th>Total <?= $year ?></th><th class="text-end"><?= money($tE) ?></th><th class="text-end"><?= money($tR) ?></th>
            <th class="text-end"><?= money(max(0, $tE - $tR)) ?></th>
            <th class="text-end"><?= $tE > 0 ? number_format($tR / $tE * 100, 1) . '%' : '—' ?></th><th></th></tr>
      </tfoot>
    </table>
  </div>
  <p class="cell-sub mt-2 mb-0">Received means payments allocated to that month's bills, so a late payment still counts against its own bill month — that is what keeps this report in agreement with the grid.</p>
</div>

<div class="panel mb-3" id="r-overdue">
  <div class="panel-head">
    <h2><i class="bi bi-exclamation-octagon me-2"></i>Overdue Summary by Company</h2>
    <?php csvBtn('overdue'); ?>
  </div>
  <div class="table-responsive">
    <table class="table table-luxe align-middle mb-0">
      <thead><tr><th>Company</th><th class="text-end">Overdue bills</th><th>Oldest due date</th><th class="text-end">Overdue amount</th><th class="no-print"></th></tr></thead>
      <tbody>
      <?php if (!$overdue): ?>
        <tr><td colspan="5" class="text-center py-4 text-muted">No overdue amounts. Excellent.</td></tr>
      <?php endif; ?>
      <?php foreach ($overdue as $o): ?>
        <tr>
          <td><?= e($o['supplier_name']) ?><div class="cell-sub"><?= e($o['ledger_code']) ?></div></td>
          <td class="text-end"><?= $o['bills'] ?></td>
          <td><?= date('d M Y', strtotime($o['oldest'])) ?></td>
          <td class="text-end fw-semibold text-danger"><?= money($o['amount']) ?></td>
          <td class="text-end no-print"><a class="btn btn-sm btn-light border" href="reminders.php">Remind</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <?php if ($overdue): ?>
      <tfoot><tr><th colspan="3">Total overdue</th><th class="text-end text-danger"><?= money($totalOverdue) ?></th><th class="no-print"></th></tr></tfoot>
      <?php endif; ?>
    </table>
  </div>
</div>

<div class="panel mb-3" id="r-company">
  <div class="panel-head">
    <h2><i class="bi bi-building me-2"></i>Company-wise Summary</h2>
    <?php csvBtn('company'); ?>
  </div>
  <div class="table-responsive">
    <table class="table table-luxe align-middle mb-0">
      <thead><tr><th>Company</th><th class="text-end">Expected</th><th class="text-end">Received</th><th class="text-end">Outstanding</th><th class="text-end">Recovery %</th><th class="text-end">Awaited bills</th></tr></thead>
      <tbody>
      <?php foreach ($companySum as $id => $s): ?>
        <tr>
          <td><a href="company.php?id=<?= (int)$id ?>"><?= e($s['supplier_name']) ?></a><div class="cell-sub"><?= e($s['ledger_code']) ?></div></td>
          <td class="text-end"><?= money($s['expected']) ?></td>
          <td class="text-end"><?= money($s['received']) ?></td>
          <td class="text-end"><?= money(max(0, $s['expected'] - $s['received'])) ?></td>
          <td class="text-end"><?= $s['expected'] > 0 ? number_format($s['received'] / $s['expected'] * 100, 1) . '%' : '—' ?></td>
          <td class="text-end"><?= $s['awaited'] ?: '—' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="cell-sub mt-2 mb-0">Whole agreement periods (all bills raised), unlike the dashboard which counts bills raised to date.</p>
</div>

<div class="row g-3 mb-3">
  <div class="col-xl-6">
    <div class="panel h-100" id="r-aging">
      <div class="panel-head">
        <h2><i class="bi bi-hourglass-bottom me-2"></i>Aging Report</h2>
        <?php csvBtn('aging'); ?>
      </div>
      <div class="table-responsive">
        <table class="table table-luxe align-middle mb-0">
          <thead><tr><th>Overdue for</th><th class="text-end">Bills</th><th class="text-end">Amount</th></tr></thead>
          <tbody>
          <?php $aN = 0; $aT = 0; foreach ($aging as $k => [$n, $amt]): $aN += $n; $aT += $amt; ?>
            <tr><td><?= $k ?></td><td class="text-end"><?= $n ?></td><td class="text-end <?= $amt > 0 ? 'fw-semibold' : '' ?>"><?= money($amt) ?></td></tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot><tr><th>Total overdue</th><th class="text-end"><?= $aN ?></th><th class="text-end text-danger"><?= money($aT) ?></th></tr></tfoot>
        </table>
      </div>
      <p class="cell-sub mt-2 mb-0">How old the overdue money is, by each bill's due date.</p>
    </div>
  </div>
  <div class="col-xl-6">
    <div class="panel h-100" id="r-methods">
      <div class="panel-head">
        <h2><i class="bi bi-wallet2 me-2"></i>Payment Method Summary</h2>
        <?php csvBtn('methods'); ?>
      </div>
      <div class="table-responsive">
        <table class="table table-luxe align-middle mb-0">
          <thead><tr><th>Method</th><th class="text-end">Payments</th><th class="text-end">Total received</th><th class="text-end">Share</th></tr></thead>
          <tbody>
          <?php foreach ($methods as $r): ?>
            <tr>
              <td><?= e($r['method']) ?></td>
              <td class="text-end"><?= (int)$r['n'] ?></td>
              <td class="text-end"><?= money($r['total']) ?></td>
              <td class="text-end"><?= $methodTotal > 0 ? number_format($r['total'] / $methodTotal * 100, 1) . '%' : '—' ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot><tr><th>All methods</th><th class="text-end"><?= array_sum(array_map(fn($r) => (int)$r['n'], $methods)) ?></th><th class="text-end"><?= money($methodTotal) ?></th><th></th></tr></tfoot>
        </table>
      </div>
      <p class="cell-sub mt-2 mb-0">Every payment ever recorded, grouped by how the money arrived.</p>
    </div>
  </div>
</div>

<div class="panel mb-3" id="r-incentives">
  <div class="panel-head">
    <h2><i class="bi bi-gift me-2"></i>Incentive Report</h2>
    <?php csvBtn('incentives'); ?>
  </div>
  <div class="table-responsive">
    <table class="table table-luxe align-middle mb-0">
      <thead><tr><th>Company</th><th class="text-end">Expected</th><th class="text-end">Received</th><th class="text-end">Overdue balance</th><th class="text-end">Awaited</th></tr></thead>
      <tbody>
      <?php if (!$incPer): ?>
        <tr><td colspan="5" class="text-center py-4 text-muted">No incentives recorded yet.</td></tr>
      <?php endif; ?>
      <?php $iE = 0; $iR = 0; $iO = 0; foreach ($incPer as $id => $s): $iE += $s['expected']; $iR += $s['received']; $iO += $s['overdue']; ?>
        <tr>
          <td><a href="company.php?id=<?= (int)$id ?>"><?= e($s['supplier_name']) ?></a><div class="cell-sub"><?= e($s['ledger_code']) ?></div></td>
          <td class="text-end"><?= money($s['expected']) ?></td>
          <td class="text-end"><?= money($s['received']) ?></td>
          <td class="text-end <?= $s['overdue'] > 0 ? 'fw-semibold text-danger' : '' ?>"><?= money($s['overdue']) ?></td>
          <td class="text-end"><?= $s['awaited'] ?: '—' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <?php if ($incPer): ?>
      <tfoot><tr><th>Total incentives</th><th class="text-end"><?= money($iE) ?></th><th class="text-end"><?= money($iR) ?></th><th class="text-end text-danger"><?= money($iO) ?></th><th></th></tr></tfoot>
      <?php endif; ?>
    </table>
  </div>
</div>

<div class="panel mb-3" id="r-expiring">
  <div class="panel-head">
    <h2><i class="bi bi-calendar-x me-2"></i>Expiring Agreements — Next 90 Days</h2>
    <?php csvBtn('expiring'); ?>
  </div>
  <div class="table-responsive">
    <table class="table table-luxe align-middle mb-0">
      <thead><tr><th>Company</th><th>Period ends</th><th class="text-end">Days left</th><th>Window</th><th class="no-print"></th></tr></thead>
      <tbody>
      <?php if (!$expiring): ?>
        <tr><td colspan="5" class="text-center py-4 text-muted">No agreements end in the next 90 days.</td></tr>
      <?php endif; ?>
      <?php foreach ($expiring as $ag): $days = (int)round((strtotime($ag['period_to']) - strtotime($today)) / 86400); ?>
        <tr>
          <td><a href="company.php?id=<?= (int)$ag['id'] ?>"><?= e($ag['supplier_name']) ?></a><div class="cell-sub"><?= e($ag['ledger_code']) ?></div></td>
          <td><?= date('d M Y', strtotime($ag['period_to'])) ?></td>
          <td class="text-end"><?= $days ?></td>
          <td><span class="pill <?= $days <= 30 ? 'pill-overdue' : ($days <= 60 ? 'pill-partial' : 'pill-due') ?>"><?= $days <= 30 ? 'Within 30 days' : ($days <= 60 ? 'Within 60 days' : 'Within 90 days') ?></span></td>
          <td class="text-end no-print"><a class="btn btn-sm btn-light border" href="company.php?id=<?= (int)$ag['id'] ?>">Ledger</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="panel" id="r-statements">
  <div class="panel-head"><h2><i class="bi bi-file-earmark-text me-2"></i><?= e(t('supplier_statement')) ?>s</h2></div>
  <p class="cell-sub">An accounts-style statement per supplier — opening balance, every bill and payment in the period, closing balance. Opens print-ready.</p>
  <div class="d-flex flex-wrap gap-2">
    <?php foreach ($allAgreements as $ag): ?>
      <a class="btn btn-light border" target="_blank" href="statement.php?id=<?= (int)$ag['id'] ?>"><i class="bi bi-printer me-1"></i> <?= e($ag['supplier_name']) ?></a>
    <?php endforeach; ?>
  </div>
</div>

<?php layoutFoot(); ?>
