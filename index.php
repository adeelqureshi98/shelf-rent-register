<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/billing.php';
require_once __DIR__ . '/layout.php';

requireLogin();
$pdo = db();
syncBills($pdo);

$tot      = headlineTotals($pdo);
$follow   = followUps($pdo);
$expiring = expiringSoon($pdo);
$incTot   = incentiveTotals($pdo);

/* Monthly expected vs received for the current year (chart). */
$year  = (int)date('Y');
$bills = loadBills($pdo, $year);
$chart = [];
for ($m = 1; $m <= 12; $m++) {
    $chart[$m] = ['expected' => 0.0, 'received' => 0.0];
}
foreach ($bills as $b) {
    $m = (int)date('n', strtotime($b['bill_month']));
    if ($b['expected_amount'] !== null) {
        $chart[$m]['expected'] += (float)$b['expected_amount'];
        $chart[$m]['received'] += min($b['paid'], (float)$b['expected_amount']);
    }
}
$chartMax = 1.0;
foreach ($chart as $c) {
    $chartMax = max($chartMax, $c['expected'], $c['received']);
}

/* Recent payment activity (latest 8). */
$recent = $pdo->query(
    'SELECT p.*, a.supplier_name, b.bill_month, b.agreement_id, u.name AS by_name
       FROM payments p
       JOIN bills b      ON b.id = p.bill_id
       JOIN agreements a ON a.id = b.agreement_id
       JOIN users u      ON u.id = p.received_by
      ORDER BY p.payment_date DESC, p.id DESC
      LIMIT 8'
)->fetchAll();

layoutHead(t('dashboard'), 'index');
pageTitle(t('dashboard'), 'The whole shelf-rent picture at a glance — billed to date, ' . date('F Y') . '.');
?>

<div class="row g-3 mb-4">
  <div class="col-md-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon gold"><i class="bi bi-receipt"></i></div>
      <div class="stat-label"><?= e(t('total_expected')) ?></div>
      <div class="stat-value"><?= money($tot['expected']) ?></div>
      <div class="stat-note">Billed to date (known amounts)</div>
    </div>
  </div>
  <div class="col-md-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon green"><i class="bi bi-cash-stack"></i></div>
      <div class="stat-label"><?= e(t('total_received')) ?></div>
      <div class="stat-value"><?= money($tot['received']) ?></div>
      <div class="stat-note"><?= $tot['expected'] > 0 ? number_format($tot['received'] / $tot['expected'] * 100, 1) : '0.0' ?>% recovery so far</div>
    </div>
  </div>
  <div class="col-md-6 col-xl-3">
    <div class="stat-card danger">
      <div class="stat-icon red"><i class="bi bi-exclamation-octagon"></i></div>
      <div class="stat-label"><?= e(t('total_overdue')) ?></div>
      <div class="stat-value"><?= money($tot['overdue']) ?></div>
      <div class="stat-note"><?= $tot['overdue_bills'] ?> bill(s) past due · <?= $tot['companies_owing'] ?> company(ies) owing</div>
    </div>
  </div>
  <div class="col-md-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon plum"><i class="bi bi-hourglass-split"></i></div>
      <div class="stat-label"><?= e(t('awaited_amounts')) ?></div>
      <div class="stat-value"><?= $tot['awaited_count'] ?></div>
      <div class="stat-note">Percentage bills waiting for the actual amount · <?= money($tot['due']) ?> still due (not yet late)</div>
    </div>
  </div>
</div>

<div class="row g-3">
  <div class="col-xl-7">
    <div class="panel">
      <div class="panel-head">
        <h2><i class="bi bi-bar-chart-line me-2"></i>Expected vs Received — <?= $year ?></h2>
        <div class="legend">
          <span class="legend-dot exp"></span> Expected
          <span class="legend-dot rec ms-3"></span> Received
        </div>
      </div>
      <div class="chart">
        <?php for ($m = 1; $m <= 12; $m++): $c = $chart[$m]; ?>
          <div class="chart-col" title="<?= date('F', mktime(0, 0, 0, $m, 1)) ?> — Expected <?= money($c['expected']) ?> · Received <?= money($c['received']) ?>">
            <div class="chart-bars">
              <div class="bar exp" style="height: <?= round($c['expected'] / $chartMax * 100) ?>%"></div>
              <div class="bar rec" style="height: <?= round($c['received'] / $chartMax * 100) ?>%"></div>
            </div>
            <div class="chart-month"><?= date('M', mktime(0, 0, 0, $m, 1)) ?></div>
          </div>
        <?php endfor; ?>
      </div>
    </div>

    <div class="panel mt-3">
      <div class="panel-head">
        <h2><i class="bi bi-telephone-outbound me-2"></i><?= e(t('followup_needed')) ?></h2>
        <a class="btn btn-sm btn-outline-gold" href="followups.php"><?= e(t('open_calling_list')) ?></a>
      </div>
      <?php if (!$follow): ?>
        <p class="empty-note">Nothing outstanding. Every billed company is up to date.</p>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table table-luxe align-middle mb-0">
            <thead><tr><th>Company</th><th class="text-end">Overdue</th><th class="text-end">Due</th><th>Oldest due date</th><th></th></tr></thead>
            <tbody>
            <?php foreach (array_slice($follow, 0, 6) as $f): ?>
              <tr>
                <td><a href="company.php?id=<?= (int)$f['agreement_id'] ?>"><?= e($f['supplier_name']) ?></a>
                    <div class="cell-sub"><?= e($f['ledger_code']) ?></div></td>
                <td class="text-end fw-semibold text-danger"><?= $f['overdue'] > 0 ? money($f['overdue']) : '—' ?></td>
                <td class="text-end"><?= $f['due'] > 0 ? money($f['due']) : '—' ?></td>
                <td><?= $f['oldest_due'] ? date('d M Y', strtotime($f['oldest_due'])) : '—' ?></td>
                <td class="text-end"><a class="btn btn-sm btn-light border" href="company.php?id=<?= (int)$f['agreement_id'] ?>"><?= e(t('ledger')) ?></a></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="col-xl-5">
    <div class="panel alert-panel">
      <div class="panel-head">
        <h2><i class="bi bi-alarm me-2"></i>Agreements Expiring in <?= EXPIRY_WARN_DAYS ?> Days</h2>
      </div>
      <?php if (!$expiring): ?>
        <p class="empty-note">No agreements are ending in the next <?= EXPIRY_WARN_DAYS ?> days.</p>
      <?php else: ?>
        <ul class="expiry-list">
        <?php foreach ($expiring as $ag): $days = (int)((strtotime($ag['period_to']) - strtotime(date('Y-m-d'))) / 86400); ?>
          <li>
            <div>
              <a href="company.php?id=<?= (int)$ag['id'] ?>"><?= e($ag['supplier_name']) ?></a>
              <div class="cell-sub">Ends <?= date('d M Y', strtotime($ag['period_to'])) ?> · <?= e($ag['ledger_code']) ?></div>
            </div>
            <span class="pill pill-overdue"><?= $days === 0 ? 'Ends today' : $days . ' day' . ($days === 1 ? '' : 's') . ' left' ?></span>
          </li>
        <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>

    <div class="panel mt-3">
      <div class="panel-head"><h2><i class="bi bi-clock-history me-2"></i>Recent Payments</h2></div>
      <?php if (!$recent): ?>
        <p class="empty-note">No payments recorded yet.</p>
      <?php else: ?>
        <ul class="activity-list">
        <?php foreach ($recent as $p): ?>
          <li>
            <div class="activity-amt"><?= money($p['amount']) ?></div>
            <div class="flex-grow-1">
              <a href="company.php?id=<?= (int)$p['agreement_id'] ?>"><?= e($p['supplier_name']) ?></a>
              <div class="cell-sub"><?= date('M Y', strtotime($p['bill_month'])) ?> bill · <?= e($p['method']) ?> · by <?= e($p['by_name']) ?></div>
            </div>
            <div class="cell-sub"><?= date('d M', strtotime($p['payment_date'])) ?></div>
          </li>
        <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>

    <div class="panel mt-3">
      <div class="panel-head">
        <h2><i class="bi bi-gift me-2"></i><?= e(t('incentives')) ?></h2>
        <a class="btn btn-sm btn-outline-gold" href="incentives.php"><?= e(t('view_all')) ?></a>
      </div>
      <div class="row g-2">
        <div class="col-6"><div class="mini-stat"><span><?= e(t('total_expected')) ?></span><strong><?= money($incTot['expected']) ?></strong></div></div>
        <div class="col-6"><div class="mini-stat"><span><?= e(t('total_received')) ?></span><strong><?= money($incTot['received']) ?></strong></div></div>
        <div class="col-6"><div class="mini-stat"><span><?= e(t('total_overdue')) ?></span><strong class="<?= $incTot['overdue'] > 0 ? 'text-danger' : '' ?>"><?= money($incTot['overdue']) ?></strong></div></div>
        <div class="col-6"><div class="mini-stat"><span><?= e(t('awaited_amounts')) ?></span><strong><?= $incTot['awaited_count'] ?></strong></div></div>
      </div>
    </div>
  </div>
</div>

<?php layoutFoot(); ?>
