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

/* Monthly expected vs received for the current year (Chart.js). */
$year  = (int)date('Y');
$chart = monthlyTrend($pdo, $year);
$byCompany = recoveryByCompany($pdo);

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
pageTitle(t('dashboard'), sprintf(t('dash_subtitle'), date('F Y')));
?>

<div class="row g-3 mb-4">
  <div class="col-md-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon gold"><i class="bi bi-receipt"></i></div>
      <div class="stat-label"><?= e(t('total_expected')) ?></div>
      <div class="stat-value" data-countup="<?= (int)$tot['expected'] ?>" data-prefix="<?= e(cfg('currency')) ?> "><?= money($tot['expected']) ?></div>
      <div class="stat-note"><?= e(t('note_billed_to_date')) ?></div>
    </div>
  </div>
  <div class="col-md-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon green"><i class="bi bi-cash-stack"></i></div>
      <div class="stat-label"><?= e(t('total_received')) ?></div>
      <div class="stat-value" data-countup="<?= (int)$tot['received'] ?>" data-prefix="<?= e(cfg('currency')) ?> "><?= money($tot['received']) ?></div>
      <div class="stat-note"><?= e(sprintf(t('recovery_so_far'), $tot['expected'] > 0 ? number_format($tot['received'] / $tot['expected'] * 100, 1) : '0.0')) ?></div>
    </div>
  </div>
  <div class="col-md-6 col-xl-3">
    <div class="stat-card danger">
      <div class="stat-icon red"><i class="bi bi-exclamation-octagon"></i></div>
      <div class="stat-label"><?= e(t('total_overdue')) ?></div>
      <div class="stat-value" data-countup="<?= (int)$tot['overdue'] ?>" data-prefix="<?= e(cfg('currency')) ?> "><?= money($tot['overdue']) ?></div>
      <div class="stat-note"><?= (int)$tot['overdue_bills'] ?> <?= e(t('bills_past_due_label')) ?> · <?= (int)$tot['companies_owing'] ?> <?= e(t('companies_owing_label')) ?></div>
    </div>
  </div>
  <div class="col-md-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon plum"><i class="bi bi-hourglass-split"></i></div>
      <div class="stat-label"><?= e(t('awaited_amounts')) ?></div>
      <div class="stat-value" data-countup="<?= (int)$tot['awaited_count'] ?>"><?= $tot['awaited_count'] ?></div>
      <div class="stat-note"><?= e(t('note_awaited_pct')) ?> · <?= money($tot['due']) ?> <?= e(t('still_due_not_late')) ?></div>
    </div>
  </div>
</div>

<div class="row g-3">
  <div class="col-xl-7">
    <div class="panel">
      <div class="panel-head">
        <h2><i class="bi bi-bar-chart-line me-2"></i><?= e(t('monthly_recovery_trend')) ?> — <?= $year ?></h2>
        <div class="legend">
          <span class="legend-dot exp"></span> <?= e(t('expected')) ?>
          <span class="legend-dot rec ms-3"></span> <?= e(t('received')) ?>
        </div>
      </div>
      <div class="chart-box"><canvas id="trendChart" aria-label="<?= e(t('monthly_recovery_trend')) ?>"></canvas></div>
    </div>

    <div class="panel mt-3">
      <div class="panel-head">
        <h2><i class="bi bi-telephone-outbound me-2"></i><?= e(t('followup_needed')) ?></h2>
        <a class="btn btn-sm btn-outline-gold" href="followups.php"><?= e(t('open_calling_list')) ?></a>
      </div>
      <?php if (!$follow): ?>
        <p class="empty-note"><?= e(t('nothing_outstanding')) ?></p>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table table-luxe align-middle mb-0">
            <thead><tr><th><?= e(t('company')) ?></th><th class="text-end"><?= e(t('overdue')) ?></th><th class="text-end"><?= e(t('due')) ?></th><th><?= e(t('oldest_due_date')) ?></th><th></th></tr></thead>
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
        <h2><i class="bi bi-alarm me-2"></i><?= e(sprintf(t('agreements_expiring_in'), EXPIRY_WARN_DAYS)) ?></h2>
      </div>
      <?php if (!$expiring): ?>
        <p class="empty-note"><?= e(sprintf(t('no_expiring'), EXPIRY_WARN_DAYS)) ?></p>
      <?php else: ?>
        <ul class="expiry-list">
        <?php foreach ($expiring as $ag): $days = (int)((strtotime($ag['period_to']) - strtotime(date('Y-m-d'))) / 86400); ?>
          <li>
            <div>
              <a href="company.php?id=<?= (int)$ag['id'] ?>"><?= e($ag['supplier_name']) ?></a>
              <div class="cell-sub"><?= e(t('ends')) ?> <?= date('d M Y', strtotime($ag['period_to'])) ?> · <?= e($ag['ledger_code']) ?></div>
            </div>
            <span class="pill pill-overdue"><?= $days === 0 ? e(t('ends_today')) : e(sprintf(t($days === 1 ? 'day_left' : 'days_left'), $days)) ?></span>
          </li>
        <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>

    <div class="panel mt-3">
      <div class="panel-head"><h2><i class="bi bi-clock-history me-2"></i><?= e(t('recent_payments')) ?></h2></div>
      <?php if (!$recent): ?>
        <p class="empty-note"><?= e(t('no_payments_yet')) ?></p>
      <?php else: ?>
        <ul class="activity-list">
        <?php foreach ($recent as $p): ?>
          <li>
            <div class="activity-amt"><?= money($p['amount']) ?></div>
            <div class="flex-grow-1">
              <a href="company.php?id=<?= (int)$p['agreement_id'] ?>"><?= e($p['supplier_name']) ?></a>
              <div class="cell-sub"><?= date('M Y', strtotime($p['bill_month'])) ?> <?= e(t('bill_word')) ?> · <?= e($p['method']) ?> · <?= e(t('by_word')) ?> <?= e($p['by_name']) ?></div>
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

    <div class="panel mt-3">
      <div class="panel-head">
        <h2><i class="bi bi-bank me-2"></i><?= e(t('financial')) ?></h2>
        <a class="btn btn-sm btn-outline-gold" href="financial.php"><?= e(t('open_financial_hub')) ?></a>
      </div>
      <p class="empty-note"><?= e(t('financial_hub_panel_sub')) ?></p>
      <div class="row g-2">
        <div class="col-6"><div class="mini-stat"><span><?= e(t('total_income')) ?> <?= (int)date('Y') ?></span><strong><?= money(incomeStatement($pdo, (int)date('Y'))['total']['received']) ?></strong></div></div>
        <div class="col-6"><div class="mini-stat"><span><?= e(t('total_receivables')) ?></span><strong><?= money(financialPosition($pdo)['total_outstanding']) ?></strong></div></div>
      </div>
    </div>

    <div class="panel mt-3">
      <div class="panel-head">
        <h2><i class="bi bi-pie-chart me-2"></i><?= e(t('recovery_by_company')) ?></h2>
      </div>
      <div class="chart-box-sm"><canvas id="companyChart" aria-label="<?= e(t('recovery_by_company')) ?>"></canvas></div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  if (!window.Chart) return;
  var cs = getComputedStyle(document.documentElement);
  Chart.defaults.color = cs.getPropertyValue('--muted').trim() || '#67766c';
  Chart.defaults.borderColor = cs.getPropertyValue('--line').trim() || '#d9e0d8';
  Chart.defaults.font.family = "'Inter', system-ui, sans-serif";
  var cur = <?= json_encode(cfg('currency')) ?>;
  var fmtRs = function (v) { return cur + ' ' + Number(v).toLocaleString('en-US'); };
  window.__srrCharts = [];
  var trend = new Chart(document.getElementById('trendChart'), {
    type: 'bar',
    data: {
      labels: <?= json_encode(array_map(fn($m) => date('M', mktime(0, 0, 0, $m, 1)), range(1, 12))) ?>,
      datasets: [
        { label: <?= json_encode(t('expected')) ?>, data: <?= json_encode(array_map(fn($c) => $c['expected'], array_values($chart))) ?>,
          backgroundColor: 'rgba(169,189,176,.75)', borderRadius: 5, maxBarThickness: 16 },
        { label: <?= json_encode(t('received')) ?>, data: <?= json_encode(array_map(fn($c) => $c['received'], array_values($chart))) ?>,
          backgroundColor: 'rgba(11,110,79,.88)', borderRadius: 5, maxBarThickness: 16 }
      ]
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: { display: false },
        tooltip: { callbacks: { label: function (ctx) { return ' ' + ctx.dataset.label + ': ' + fmtRs(ctx.parsed.y); } } }
      },
      scales: { y: { beginAtZero: true, ticks: { callback: function (v) { return fmtRs(v); }, maxTicksLimit: 6 }, grid: { drawBorder: false } },
                x: { grid: { display: false } } }
    }
  });
  window.__srrCharts.push(trend);
  <?php
  $top = array_slice($byCompany, 0, 5, true);
  $rest = array_sum(array_slice($byCompany, 5, null, true));
  if ($rest > 0) $top['Other'] = $rest;
  ?>
  var comp = new Chart(document.getElementById('companyChart'), {
    type: 'doughnut',
    data: {
      labels: <?= json_encode(array_keys($top)) ?>,
      datasets: [{ data: <?= json_encode(array_values($top)) ?>,
        backgroundColor: ['#0b6e4f', '#128a64', '#c9a227', '#41544a', '#7fae97', '#9db8a9'],
        borderWidth: 2, borderColor: 'rgba(127,127,127,.15)' }]
    },
    options: {
      responsive: true, maintainAspectRatio: false, cutout: '58%',
      plugins: {
        legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 11 } } },
        tooltip: { callbacks: { label: function (ctx) { return ' ' + ctx.label + ': ' + fmtRs(ctx.parsed); } } }
      }
    }
  });
  window.__srrCharts.push(comp);
});
</script>

<?php layoutFoot(); ?>
