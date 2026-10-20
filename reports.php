<?php
/**
 * Reports — both read through billing.php, so the numbers always match
 * the dashboard and the payment grid.
 *   1. Monthly recovery: expected vs received per month of a chosen year.
 *   2. Overdue summary by company.
 * Print-friendly: use the Print button (sidebar and controls are hidden).
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/billing.php';
require_once __DIR__ . '/layout.php';

requireLogin();
$pdo = db();
syncBills($pdo);

$year  = (int)($_GET['year'] ?? date('Y'));
$year  = max(2020, min(2100, $year));
$bills = loadBills($pdo, $year);

/* --- Monthly recovery (received = payments allocated to that month's bills) --- */
$months = [];
for ($m = 1; $m <= 12; $m++) $months[$m] = ['expected' => 0.0, 'received' => 0.0, 'awaited' => 0];
foreach ($bills as $b) {
    $m = (int)date('n', strtotime($b['bill_month']));
    if ($b['expected_amount'] === null) {
        $months[$m]['awaited']++;
        continue;
    }
    $months[$m]['expected'] += (float)$b['expected_amount'];
    $months[$m]['received'] += min($b['paid'], (float)$b['expected_amount']);
}

/* --- Overdue summary by company (all years) --- */
$today   = date('Y-m-d');
$overdue = [];
foreach (loadBills($pdo) as $b) {
    if ($b['expected_amount'] === null || $b['due_date'] >= $today) continue;
    $balance = (float)$b['expected_amount'] - $b['paid'];
    if ($balance <= 0) continue;
    $id = $b['agreement_id'];
    if (!isset($overdue[$id])) {
        $overdue[$id] = ['supplier_name' => $b['supplier_name'], 'ledger_code' => $b['ledger_code'],
                         'bills' => 0, 'amount' => 0.0, 'oldest' => $b['due_date']];
    }
    $overdue[$id]['bills']++;
    $overdue[$id]['amount'] += $balance;
    if ($b['due_date'] < $overdue[$id]['oldest']) $overdue[$id]['oldest'] = $b['due_date'];
}
usort($overdue, fn($a, $b) => $b['amount'] <=> $a['amount']);
$totalOverdue = array_sum(array_column($overdue, 'amount'));

layoutHead('Reports', 'reports');
?>

<div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
  <?php pageTitle('Reports', 'Print-friendly summaries. Figures agree with the Dashboard and Payment Grid.'); ?>
  <button class="btn btn-gold no-print" onclick="window.print()"><i class="bi bi-printer me-1"></i> Print</button>
</div>

<div class="panel mb-3">
  <div class="panel-head">
    <h2><i class="bi bi-calendar-check me-2"></i>Monthly Recovery — <?= $year ?></h2>
    <form class="no-print d-flex gap-2" method="get">
      <a class="btn btn-sm btn-light border" href="reports.php?year=<?= $year - 1 ?>"><i class="bi bi-chevron-left"></i> <?= $year - 1 ?></a>
      <a class="btn btn-sm btn-light border" href="reports.php?year=<?= $year + 1 ?>"><?= $year + 1 ?> <i class="bi bi-chevron-right"></i></a>
    </form>
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

<div class="panel">
  <div class="panel-head"><h2><i class="bi bi-exclamation-octagon me-2"></i>Overdue Summary by Company</h2></div>
  <div class="table-responsive">
    <table class="table table-luxe align-middle mb-0">
      <thead><tr><th>Company</th><th class="text-end">Overdue bills</th><th>Oldest due date</th><th class="text-end">Overdue amount</th><th class="no-print"></th></tr></thead>
      <tbody>
      <?php if (!$overdue): ?>
        <tr><td colspan="5" class="text-center py-4 text-muted">No overdue amounts. Excellent.</td></tr>
      <?php endif; ?>
      <?php foreach ($overdue as $id => $o): ?>
        <tr>
          <td><?= e($o['supplier_name']) ?><div class="cell-sub"><?= e($o['ledger_code']) ?></div></td>
          <td class="text-end"><?= $o['bills'] ?></td>
          <td><?= date('d M Y', strtotime($o['oldest'])) ?></td>
          <td class="text-end fw-semibold text-danger"><?= money($o['amount']) ?></td>
          <td class="text-end no-print"><a class="btn btn-sm btn-light border" href="followups.php">Follow up</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <?php if ($overdue): ?>
      <tfoot><tr><th colspan="3">Total overdue</th><th class="text-end text-danger"><?= money($totalOverdue) ?></th><th class="no-print"></th></tr></tfoot>
      <?php endif; ?>
    </table>
  </div>
</div>

<?php layoutFoot(); ?>
