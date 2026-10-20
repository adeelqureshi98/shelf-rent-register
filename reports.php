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
$aging = ['0_30' => [0, 0.0], '31_60' => [0, 0.0], '61_90' => [0, 0.0], '90p' => [0, 0.0]];
foreach ($allBills as $b) {
    if ($b['expected_amount'] === null || $b['due_date'] >= $today) continue;
    $balance = (float)$b['expected_amount'] - $b['paid'];
    if ($balance <= 0) continue;
    $days = (int)floor((strtotime($today) - strtotime($b['due_date'])) / 86400);
    $key = $days <= 30 ? '0_30' : ($days <= 60 ? '31_60' : ($days <= 90 ? '61_90' : '90p'));
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
  <?php pageTitle(t('reports'), t('reports_subtitle')); ?>
  <button class="btn btn-gold no-print" onclick="window.print()"><i class="bi bi-printer me-1"></i> <?= e(t('print')) ?></button>
</div>

<div class="no-print d-flex flex-wrap gap-2 mb-3">
  <?php foreach (['r-monthly' => t('monthly_recovery'), 'r-overdue' => t('overdue_by_company'), 'r-company' => t('company_summary'), 'r-aging' => t('aging_chip'), 'r-methods' => t('payment_methods'), 'r-incentives' => t('incentives'), 'r-expiring' => t('expiring_chip'), 'r-statements' => t('statements_chip')] as $anchor => $label): ?>
    <a class="btn btn-sm btn-outline-gold" href="#<?= $anchor ?>"><?= $label ?></a>
  <?php endforeach; ?>
</div>

<div class="panel mb-3" id="r-monthly">
  <div class="panel-head">
    <h2><i class="bi bi-calendar-check me-2"></i><?= e(t('monthly_recovery')) ?> — <?= $year ?></h2>
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
      <thead><tr><th><?= e(t('month')) ?></th><th class="text-end"><?= e(t('expected')) ?></th><th class="text-end"><?= e(t('received')) ?></th><th class="text-end"><?= e(t('outstanding')) ?></th><th class="text-end"><?= e(t('recovery_pct')) ?></th><th class="text-end"><?= e(t('awaited_bills')) ?></th></tr></thead>
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
        <tr><th><?= e(t('total')) ?> <?= $year ?></th><th class="text-end"><?= money($tE) ?></th><th class="text-end"><?= money($tR) ?></th>
            <th class="text-end"><?= money(max(0, $tE - $tR)) ?></th>
            <th class="text-end"><?= $tE > 0 ? number_format($tR / $tE * 100, 1) . '%' : '—' ?></th><th></th></tr>
      </tfoot>
    </table>
  </div>
  <p class="cell-sub mt-2 mb-0"><?= e(t('note_monthly_recovery')) ?></p>
</div>

<div class="panel mb-3" id="r-overdue">
  <div class="panel-head">
    <h2><i class="bi bi-exclamation-octagon me-2"></i><?= e(t('overdue_summary_by_company')) ?></h2>
    <?php csvBtn('overdue'); ?>
  </div>
  <div class="table-responsive">
    <table class="table table-luxe align-middle mb-0">
      <thead><tr><th><?= e(t('company')) ?></th><th class="text-end"><?= e(t('overdue_bills')) ?></th><th><?= e(t('oldest_due_date')) ?></th><th class="text-end"><?= e(t('overdue_amount')) ?></th><th class="no-print"></th></tr></thead>
      <tbody>
      <?php if (!$overdue): ?>
        <tr><td colspan="5" class="text-center py-4 text-muted"><?= e(t('no_overdue_excellent')) ?></td></tr>
      <?php endif; ?>
      <?php foreach ($overdue as $o): ?>
        <tr>
          <td><?= e($o['supplier_name']) ?><div class="cell-sub"><?= e($o['ledger_code']) ?></div></td>
          <td class="text-end"><?= $o['bills'] ?></td>
          <td><?= date('d M Y', strtotime($o['oldest'])) ?></td>
          <td class="text-end fw-semibold text-danger"><?= money($o['amount']) ?></td>
          <td class="text-end no-print"><a class="btn btn-sm btn-light border" href="reminders.php"><?= e(t('remind')) ?></a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <?php if ($overdue): ?>
      <tfoot><tr><th colspan="3"><?= e(t('total_overdue')) ?></th><th class="text-end text-danger"><?= money($totalOverdue) ?></th><th class="no-print"></th></tr></tfoot>
      <?php endif; ?>
    </table>
  </div>
</div>

<div class="panel mb-3" id="r-company">
  <div class="panel-head">
    <h2><i class="bi bi-building me-2"></i><?= e(t('companywise_summary')) ?></h2>
    <?php csvBtn('company'); ?>
  </div>
  <div class="table-responsive">
    <table class="table table-luxe align-middle mb-0">
      <thead><tr><th><?= e(t('company')) ?></th><th class="text-end"><?= e(t('expected')) ?></th><th class="text-end"><?= e(t('received')) ?></th><th class="text-end"><?= e(t('outstanding')) ?></th><th class="text-end"><?= e(t('recovery_pct')) ?></th><th class="text-end"><?= e(t('awaited_bills')) ?></th></tr></thead>
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
  <p class="cell-sub mt-2 mb-0"><?= e(t('note_company_summary')) ?></p>
</div>

<div class="row g-3 mb-3">
  <div class="col-xl-6">
    <div class="panel h-100" id="r-aging">
      <div class="panel-head">
        <h2><i class="bi bi-hourglass-bottom me-2"></i><?= e(t('aging_report')) ?></h2>
        <?php csvBtn('aging'); ?>
      </div>
      <div class="table-responsive">
        <table class="table table-luxe align-middle mb-0">
          <thead><tr><th><?= e(t('overdue_for')) ?></th><th class="text-end"><?= e(t('bills')) ?></th><th class="text-end"><?= e(t('amount')) ?></th></tr></thead>
          <tbody>
          <?php $aN = 0; $aT = 0; foreach ($aging as $k => [$n, $amt]): $aN += $n; $aT += $amt; ?>
            <tr><td><?= e(t('aging_' . $k)) ?></td><td class="text-end"><?= $n ?></td><td class="text-end <?= $amt > 0 ? 'fw-semibold' : '' ?>"><?= money($amt) ?></td></tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot><tr><th><?= e(t('total_overdue')) ?></th><th class="text-end"><?= $aN ?></th><th class="text-end text-danger"><?= money($aT) ?></th></tr></tfoot>
        </table>
      </div>
      <p class="cell-sub mt-2 mb-0"><?= e(t('note_aging')) ?></p>
    </div>
  </div>
  <div class="col-xl-6">
    <div class="panel h-100" id="r-methods">
      <div class="panel-head">
        <h2><i class="bi bi-wallet2 me-2"></i><?= e(t('payment_method_summary')) ?></h2>
        <?php csvBtn('methods'); ?>
      </div>
      <div class="table-responsive">
        <table class="table table-luxe align-middle mb-0">
          <thead><tr><th><?= e(t('method')) ?></th><th class="text-end"><?= e(t('payments')) ?></th><th class="text-end"><?= e(t('total_received')) ?></th><th class="text-end"><?= e(t('share')) ?></th></tr></thead>
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
          <tfoot><tr><th><?= e(t('all_methods')) ?></th><th class="text-end"><?= array_sum(array_map(fn($r) => (int)$r['n'], $methods)) ?></th><th class="text-end"><?= money($methodTotal) ?></th><th></th></tr></tfoot>
        </table>
      </div>
      <p class="cell-sub mt-2 mb-0"><?= e(t('note_methods')) ?></p>
    </div>
  </div>
</div>

<div class="panel mb-3" id="r-incentives">
  <div class="panel-head">
    <h2><i class="bi bi-gift me-2"></i><?= e(t('incentive_report')) ?></h2>
    <?php csvBtn('incentives'); ?>
  </div>
  <div class="table-responsive">
    <table class="table table-luxe align-middle mb-0">
      <thead><tr><th><?= e(t('company')) ?></th><th class="text-end"><?= e(t('expected')) ?></th><th class="text-end"><?= e(t('received')) ?></th><th class="text-end"><?= e(t('overdue_balance')) ?></th><th class="text-end"><?= e(t('awaited')) ?></th></tr></thead>
      <tbody>
      <?php if (!$incPer): ?>
        <tr><td colspan="5" class="text-center py-4 text-muted"><?= e(t('no_incentives_yet')) ?></td></tr>
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
      <tfoot><tr><th><?= e(t('total_incentives')) ?></th><th class="text-end"><?= money($iE) ?></th><th class="text-end"><?= money($iR) ?></th><th class="text-end text-danger"><?= money($iO) ?></th><th></th></tr></tfoot>
      <?php endif; ?>
    </table>
  </div>
</div>

<div class="panel mb-3" id="r-expiring">
  <div class="panel-head">
    <h2><i class="bi bi-calendar-x me-2"></i><?= e(t('expiring_next_90')) ?></h2>
    <?php csvBtn('expiring'); ?>
  </div>
  <div class="table-responsive">
    <table class="table table-luxe align-middle mb-0">
      <thead><tr><th><?= e(t('company')) ?></th><th><?= e(t('period_ends')) ?></th><th class="text-end"><?= e(t('days_left_label')) ?></th><th><?= e(t('window')) ?></th><th class="no-print"></th></tr></thead>
      <tbody>
      <?php if (!$expiring): ?>
        <tr><td colspan="5" class="text-center py-4 text-muted"><?= e(t('no_expiring_90')) ?></td></tr>
      <?php endif; ?>
      <?php foreach ($expiring as $ag): $days = (int)round((strtotime($ag['period_to']) - strtotime($today)) / 86400); ?>
        <tr>
          <td><a href="company.php?id=<?= (int)$ag['id'] ?>"><?= e($ag['supplier_name']) ?></a><div class="cell-sub"><?= e($ag['ledger_code']) ?></div></td>
          <td><?= date('d M Y', strtotime($ag['period_to'])) ?></td>
          <td class="text-end"><?= $days ?></td>
          <td><span class="pill <?= $days <= 30 ? 'pill-overdue' : ($days <= 60 ? 'pill-partial' : 'pill-due') ?>"><?= e(sprintf(t('within_days'), $days <= 30 ? 30 : ($days <= 60 ? 60 : 90))) ?></span></td>
          <td class="text-end no-print"><a class="btn btn-sm btn-light border" href="company.php?id=<?= (int)$ag['id'] ?>"><?= e(t('ledger')) ?></a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="panel" id="r-statements">
  <div class="panel-head"><h2><i class="bi bi-file-earmark-text me-2"></i><?= e(t('supplier_statements')) ?></h2></div>
  <p class="cell-sub"><?= e(t('note_statements')) ?></p>
  <div class="d-flex flex-wrap gap-2">
    <?php foreach ($allAgreements as $ag): ?>
      <a class="btn btn-light border" target="_blank" href="statement.php?id=<?= (int)$ag['id'] ?>"><i class="bi bi-printer me-1"></i> <?= e($ag['supplier_name']) ?></a>
    <?php endforeach; ?>
  </div>
</div>

<?php layoutFoot(); ?>
