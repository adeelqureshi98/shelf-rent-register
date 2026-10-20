<?php
/**
 * Incentives — the register's second half: promotional, display,
 * launch and target money suppliers owe the mart on top of shelf
 * rent. Reads through billing.php (loadIncentives), so this page,
 * the dashboard panel, the company ledger and the reports agree.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/billing.php';
require_once __DIR__ . '/layout.php';

requireLogin();
$pdo = db();

/* Delete — Admin only, enforced here on the server. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    requireRole(['admin']);
    checkCsrf();
    $id = (int)($_POST['id'] ?? 0);
    $st = $pdo->prepare('SELECT title FROM incentives WHERE id = :id');
    $st->execute([':id' => $id]);
    $row = $st->fetch();
    if ($row) {
        $pdo->prepare('DELETE FROM incentives WHERE id = :id')->execute([':id' => $id]);
        audit($pdo, 'delete', 'incentive', $id, 'Deleted incentive "' . $row['title'] . '".');
        flash('success', 'Incentive deleted.');
    }
    header('Location: incentives.php');
    exit;
}

$tot   = incentiveTotals($pdo);
$rows  = loadIncentives($pdo);
$q     = trim($_GET['q'] ?? '');
$statF = $_GET['status'] ?? '';
if ($q !== '') {
    $rows = array_values(array_filter($rows, fn($r) =>
        stripos($r['supplier_name'], $q) !== false || stripos($r['title'], $q) !== false || stripos($r['ledger_code'], $q) !== false));
}
if ($statF !== '') {
    $rows = array_values(array_filter($rows, fn($r) => $r['status'] === $statF));
}

layoutHead(t('incentives'), 'incentives');
pageTitle(t('incentives'), 'Promotion, display and launch money owed by suppliers, on top of shelf rent.');
?>

<div class="row g-3 mb-4">
  <div class="col-md-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon gold"><i class="bi bi-gift"></i></div>
      <div class="stat-label"><?= e(t('total_expected')) ?></div>
      <div class="stat-value"><?= money($tot['expected']) ?></div>
      <div class="stat-note">Known incentive amounts (awaited excluded)</div>
    </div>
  </div>
  <div class="col-md-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon green"><i class="bi bi-cash-stack"></i></div>
      <div class="stat-label"><?= e(t('total_received')) ?></div>
      <div class="stat-value"><?= money($tot['received']) ?></div>
      <div class="stat-note"><?= $tot['expected'] > 0 ? number_format($tot['received'] / $tot['expected'] * 100, 1) : '0.0' ?>% of known incentives collected</div>
    </div>
  </div>
  <div class="col-md-6 col-xl-3">
    <div class="stat-card danger">
      <div class="stat-icon red"><i class="bi bi-exclamation-octagon"></i></div>
      <div class="stat-label"><?= e(t('total_overdue')) ?></div>
      <div class="stat-value"><?= money($tot['overdue']) ?></div>
      <div class="stat-note">Unpaid balance past its expected date</div>
    </div>
  </div>
  <div class="col-md-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon plum"><i class="bi bi-hourglass-split"></i></div>
      <div class="stat-label"><?= e(t('awaited_amounts')) ?></div>
      <div class="stat-value"><?= $tot['awaited_count'] ?></div>
      <div class="stat-note">Percentage incentives waiting for the actual amount</div>
    </div>
  </div>
</div>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
  <form class="filter-bar m-0 flex-grow-1" method="get">
    <div class="input-group filter-search">
      <span class="input-group-text"><i class="bi bi-search"></i></span>
      <input class="form-control" name="q" value="<?= e($q) ?>" placeholder="<?= e(t('search')) ?> company or title">
    </div>
    <select class="form-select filter-status" name="status">
      <option value="">All statuses</option>
      <?php foreach (['received', 'partial', 'overdue', 'pending', 'awaited'] as $s): ?>
        <option value="<?= $s ?>" <?= $statF === $s ? 'selected' : '' ?>><?= statusLabel($s) ?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn btn-gold" type="submit"><?= e(t('filter')) ?></button>
  </form>
  <?php if (canWrite()): ?>
    <a class="btn btn-gold" href="incentive_form.php"><i class="bi bi-plus-lg me-1"></i> <?= e(t('add_incentive')) ?></a>
  <?php endif; ?>
</div>

<div class="panel p-0 overflow-hidden">
  <div class="table-responsive">
    <table class="table table-luxe align-middle mb-0">
      <thead>
        <tr><th><?= e(t('company')) ?></th><th>Incentive</th><th class="text-end"><?= e(t('expected')) ?></th><th class="text-end"><?= e(t('received')) ?></th><th class="text-end"><?= e(t('balance')) ?></th><th>Expected date</th><th><?= e(t('status')) ?></th><th class="text-end"><?= e(t('actions')) ?></th></tr>
      </thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="8" class="text-center py-4 text-muted">No incentives match this filter.</td></tr>
      <?php endif; ?>
      <?php foreach ($rows as $inc): ?>
        <tr>
          <td>
            <a href="company.php?id=<?= (int)$inc['agreement_id'] ?>"><?= e($inc['supplier_name']) ?></a>
            <div class="cell-sub"><?= e($inc['ledger_code']) ?></div>
          </td>
          <td><?= e($inc['title']) ?>
            <div class="cell-sub"><?= e($inc['type']) ?> · <?= $inc['basis'] === 'Percentage' ? 'Percentage @ ' . e($inc['rate']) . '%' : 'Fixed' ?></div>
          </td>
          <td class="text-end"><?= $inc['expected_amount'] === null ? '<span class="text-muted">Awaited</span>' : money($inc['expected_amount']) ?></td>
          <td class="text-end"><?= money($inc['received_amount']) ?></td>
          <td class="text-end"><?= $inc['balance'] === null ? '—' : money($inc['balance']) ?></td>
          <td><?= date('d M Y', strtotime($inc['expected_date'])) ?></td>
          <td><span class="pill pill-<?= $inc['status'] ?>"><?= statusLabel($inc['status']) ?></span></td>
          <td class="text-end text-nowrap">
            <?php if (canWrite()): ?>
              <a class="btn btn-sm btn-light border" href="incentive_form.php?id=<?= (int)$inc['id'] ?>"><?= e(t('edit')) ?></a>
            <?php endif; ?>
            <?php if (isAdmin()): ?>
              <form method="post" class="d-inline" onsubmit="return confirm('Delete this incentive? This cannot be undone.');">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int)$inc['id'] ?>">
                <button class="btn btn-sm btn-outline-danger" type="submit"><?= e(t('delete')) ?></button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php layoutFoot(); ?>
