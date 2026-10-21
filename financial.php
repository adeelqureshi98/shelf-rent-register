<?php
/**
 * Financial Hub (v3) — Income Statement, Financial Position and
 * Cash Received for the shelf-rent register. Every figure comes
 * from the shared functions in billing.php, so this page always
 * agrees with the dashboard, the grid and the reports.
 *
 * Honest scope (also printed on the page): the Income Statement
 * is shelf rent + incentive income only (no expense data exists
 * in the register, so it is not a full trading P&L), and the
 * Financial Position is a receivables position, not a full
 * balance sheet. HR and Payroll are deliberately NOT part of
 * this register.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/billing.php';
require_once __DIR__ . '/layout.php';

requireLogin();
$pdo = db();
syncBills($pdo);

$year  = (int)($_GET['year'] ?? date('Y'));
$year  = max(2020, min(2100, $year));
$month = isset($_GET['month']) && $_GET['month'] !== '' ? (int)$_GET['month'] : null;
if ($month !== null && ($month < 1 || $month > 12)) $month = null;

$inc  = incomeStatement($pdo, $year, $month);
$pos  = financialPosition($pdo);
$cash = cashReceived($pdo, $year);

function csvBtnF(string $type, array $params = []): void
{
    $qs = http_build_query(array_merge(['type' => $type], $params));
    echo '<a class="btn btn-sm btn-light border no-print" href="reports_export.php?' . e($qs) . '"><i class="bi bi-filetype-csv me-1"></i> ' . e(t('download_csv')) . '</a>';
}
function pctCell($pct): string
{
    return $pct === null ? '—' : number_format($pct, 1) . '%';
}

layoutHead(t('financial'), 'financial');
?>

<div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
  <?php pageTitle(t('financial'), t('financial_subtitle')); ?>
  <button class="btn btn-gold no-print" onclick="window.print()"><i class="bi bi-printer me-1"></i> <?= e(t('print')) ?></button>
</div>

<form class="filter-bar no-print" method="get">
  <label class="form-label mb-0" for="f-year"><?= e(t('year_label')) ?></label>
  <select class="form-select filter-status" id="f-year" name="year" onchange="this.form.submit()">
    <?php for ($y = $year - 2; $y <= $year + 1; $y++): ?>
      <option value="<?= $y ?>" <?= $y === $year ? 'selected' : '' ?>><?= $y ?></option>
    <?php endfor; ?>
  </select>
  <label class="form-label mb-0" for="f-month"><?= e(t('month_label')) ?></label>
  <select class="form-select filter-status" id="f-month" name="month" onchange="this.form.submit()">
    <option value=""><?= e(t('all_year')) ?></option>
    <?php for ($m = 1; $m <= 12; $m++): ?>
      <option value="<?= $m ?>" <?= $month === $m ? 'selected' : '' ?>><?= date('F', mktime(0, 0, 0, $m, 1)) ?></option>
    <?php endfor; ?>
  </select>
</form>

<!-- ============ 1. Income Statement ============ -->
<div class="panel mb-3" id="fin-income">
  <div class="panel-head">
    <h2><i class="bi bi-file-earmark-bar-graph me-2"></i><?= e(t('income_statement')) ?> — <?= $month !== null ? date('F', mktime(0, 0, 0, $month, 1)) . ' ' : '' ?><?= $year ?></h2>
    <?php csvBtnF('income', ['year' => $year] + ($month !== null ? ['month' => $month] : [])); ?>
  </div>
  <p class="cell-sub mt-0"><?= e(t('income_statement_sub')) ?></p>
  <div class="table-responsive">
    <table class="table table-luxe align-middle mb-0">
      <thead><tr><th></th><th class="text-end"><?= e(t('expected')) ?></th><th class="text-end"><?= e(t('received')) ?></th><th class="text-end"><?= e(t('outstanding')) ?></th><th class="text-end"><?= e(t('recovery_pct')) ?></th></tr></thead>
      <tbody>
        <tr>
          <td><?= e(t('rent_income')) ?></td>
          <td class="text-end"><?= money($inc['rent']['expected']) ?></td>
          <td class="text-end"><?= money($inc['rent']['received']) ?></td>
          <td class="text-end"><?= money($inc['rent']['outstanding']) ?></td>
          <td class="text-end"><?= pctCell($inc['rent']['recovery_pct']) ?></td>
        </tr>
        <tr>
          <td><?= e(t('incentive_income')) ?></td>
          <td class="text-end"><?= money($inc['incentive']['expected']) ?></td>
          <td class="text-end"><?= money($inc['incentive']['received']) ?></td>
          <td class="text-end"><?= money($inc['incentive']['outstanding']) ?></td>
          <td class="text-end"><?= pctCell($inc['incentive']['recovery_pct']) ?></td>
        </tr>
      </tbody>
      <tfoot>
        <tr><th><?= e(t('total_income')) ?></th>
            <th class="text-end"><?= money($inc['total']['expected']) ?></th>
            <th class="text-end"><?= money($inc['total']['received']) ?></th>
            <th class="text-end"><?= money($inc['total']['outstanding']) ?></th>
            <th class="text-end"><?= pctCell($inc['total']['recovery_pct']) ?></th></tr>
      </tfoot>
    </table>
  </div>
  <p class="cell-sub mt-2 mb-0"><?= e(t('awaited_items_note')) ?> <?= e(t('awaited')) ?>: <?= (int)$inc['awaited_bills'] ?> <?= e(t('bill_word')) ?> · <?= (int)$inc['awaited_incentives'] ?> <?= e(t('incentives_label')) ?></p>
  <p class="cell-sub mb-0"><?= e(t('note_income_statement')) ?></p>
</div>

<!-- ============ 2. Financial Position ============ -->
<div class="panel mb-3" id="fin-position">
  <div class="panel-head">
    <h2><i class="bi bi-bank me-2"></i><?= e(t('financial_position')) ?> — <?= e(t('as_of')) ?> <?= date('d M Y', strtotime($pos['as_of'])) ?></h2>
    <?php csvBtnF('position'); ?>
  </div>
  <p class="cell-sub mt-0"><?= e(t('financial_position_sub')) ?></p>

  <div class="row g-2 mb-3">
    <div class="col-md-6 col-xl-3"><div class="mini-stat"><span><?= e(t('total_receivables')) ?></span><strong><?= money($pos['total_outstanding']) ?></strong></div></div>
    <div class="col-md-6 col-xl-3"><div class="mini-stat"><span><?= e(t('received_to_date')) ?></span><strong><?= money($pos['total_received']) ?></strong></div></div>
    <div class="col-md-6 col-xl-3"><div class="mini-stat"><span><?= e(t('overdue_portion')) ?></span><strong class="<?= $pos['overdue'] > 0 ? 'text-danger' : '' ?>"><?= money($pos['overdue']) ?></strong></div></div>
    <div class="col-md-6 col-xl-3"><div class="mini-stat"><span><?= e(t('not_yet_due_portion')) ?></span><strong><?= money($pos['not_yet_due']) ?></strong></div></div>
  </div>

  <div class="table-responsive">
    <table class="table table-luxe align-middle mb-0">
      <thead><tr><th></th><th class="text-end"><?= e(t('position_expected')) ?></th><th class="text-end"><?= e(t('received')) ?></th><th class="text-end"><?= e(t('outstanding')) ?></th><th class="text-end"><?= e(t('overdue')) ?></th></tr></thead>
      <tbody>
        <tr>
          <td><?= e(t('rent_label')) ?></td>
          <td class="text-end"><?= money($pos['rent_expected']) ?></td>
          <td class="text-end"><?= money($pos['rent_received']) ?></td>
          <td class="text-end"><?= money($pos['rent_outstanding']) ?></td>
          <td class="text-end"><?= money($pos['rent_overdue']) ?></td>
        </tr>
        <tr>
          <td><?= e(t('incentives_label')) ?></td>
          <td class="text-end"><?= money($pos['inc_expected']) ?></td>
          <td class="text-end"><?= money($pos['inc_received']) ?></td>
          <td class="text-end"><?= money($pos['inc_outstanding']) ?></td>
          <td class="text-end"><?= money($pos['inc_overdue']) ?></td>
        </tr>
      </tbody>
      <tfoot>
        <tr><th><?= e(t('total')) ?></th>
            <th class="text-end"><?= money($pos['total_expected']) ?></th>
            <th class="text-end"><?= money($pos['total_received']) ?></th>
            <th class="text-end"><?= money($pos['total_outstanding']) ?></th>
            <th class="text-end text-danger"><?= money($pos['overdue']) ?></th></tr>
      </tfoot>
    </table>
  </div>

  <div class="table-responsive mt-3">
    <table class="table table-luxe align-middle mb-0">
      <thead><tr><th><?= e(t('aging_report')) ?> — <?= e(t('rent_label')) ?></th><th class="text-end"><?= e(t('bills')) ?></th><th class="text-end"><?= e(t('amount')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($pos['aging'] as $k => [$n, $amt]): ?>
        <tr><td><?= e(t('aging_' . $k)) ?></td><td class="text-end"><?= $n ?></td><td class="text-end <?= $amt > 0 ? 'fw-semibold' : '' ?>"><?= money($amt) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot><tr><th><?= e(t('total_overdue')) ?> — <?= e(t('rent_label')) ?></th><th class="text-end"><?= array_sum(array_column($pos['aging'], 0)) ?></th><th class="text-end text-danger"><?= money(array_sum(array_column($pos['aging'], 1))) ?></th></tr></tfoot>
    </table>
  </div>
  <p class="cell-sub mt-2 mb-0"><?= e(t('awaited_items_note')) ?> <?= e(t('awaited')) ?>: <?= (int)$pos['awaited_bills'] ?> <?= e(t('bill_word')) ?> · <?= (int)$pos['awaited_incentives'] ?> <?= e(t('incentives_label')) ?></p>
  <p class="cell-sub mb-0"><?= e(t('note_financial_position')) ?></p>
</div>

<!-- ============ 3. Cash Received ============ -->
<div class="panel" id="fin-cash">
  <div class="panel-head">
    <h2><i class="bi bi-cash-coin me-2"></i><?= e(t('cash_received')) ?> — <?= $year ?></h2>
    <?php csvBtnF('cash', ['year' => $year]); ?>
  </div>
  <p class="cell-sub mt-0"><?= e(t('cash_received_sub')) ?></p>
  <div class="table-responsive">
    <table class="table table-luxe align-middle mb-0">
      <thead><tr><th><?= e(t('month')) ?></th><th class="text-end"><?= e(t('payments_count')) ?></th><th class="text-end"><?= e(t('cash_in')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($cash['months'] as $m => $v): ?>
        <tr>
          <td><?= date('F', mktime(0, 0, 0, $m, 1)) ?></td>
          <td class="text-end"><?= $v['count'] ?: '—' ?></td>
          <td class="text-end"><?= $v['amount'] > 0 ? money($v['amount']) : '—' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr><th><?= e(t('total_cash_year')) ?> <?= $year ?></th><th class="text-end"><?= (int)$cash['total_count'] ?></th><th class="text-end"><?= money($cash['total_amount']) ?></th></tr>
        <tr><th><?= e(t('incentive_receipts_total')) ?></th><th></th><th class="text-end"><?= money($cash['incentive_received_total']) ?></th></tr>
      </tfoot>
    </table>
  </div>
  <p class="cell-sub mt-2 mb-0"><?= e(t('note_cash_received')) ?></p>
</div>

<?php layoutFoot(); ?>
