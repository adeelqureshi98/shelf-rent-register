<?php
/**
 * Company ledger view: one company's agreement, every bill and every
 * payment in a single place — the page staff open before calling a supplier.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/billing.php';
require_once __DIR__ . '/layout.php';

requireLogin();
$pdo = db();
syncBills($pdo);

$id = (int)($_GET['id'] ?? 0);
$st = $pdo->prepare('SELECT * FROM agreements WHERE id = :id');
$st->execute([':id' => $id]);
$ag = $st->fetch();
if (!$ag) {
    flash('error', 'Company not found.');
    header('Location: agreements.php');
    exit;
}

$bills = loadBills($pdo, null, $id);
$paySt = $pdo->prepare(
    'SELECT p.*, u.name AS by_name FROM payments p JOIN users u ON u.id = p.received_by
      WHERE p.bill_id = :b ORDER BY p.payment_date, p.id'
);
foreach ($bills as &$b) {
    $paySt->execute([':b' => $b['id']]);
    $b['payments'] = $paySt->fetchAll();
}
unset($b);

$sumExpected = 0.0; $sumPaid = 0.0; $sumOverdue = 0.0; $today = date('Y-m-d');
foreach ($bills as $b) {
    if ($b['expected_amount'] === null) continue;
    $sumExpected += (float)$b['expected_amount'];
    $sumPaid     += min($b['paid'], (float)$b['expected_amount']);
    if ($b['status'] === 'overdue' || ($b['status'] === 'partial' && $b['due_date'] < $today)) {
        $sumOverdue += (float)$b['expected_amount'] - $b['paid'];
    }
}

$incs  = loadIncentives($pdo, $id);
$back  = 'company.php?id=' . $id;
$write = canWrite();

layoutHead($ag['supplier_name'], 'agreements');
?>

<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
  <div class="page-head m-0">
    <h1><?= e($ag['supplier_name']) ?></h1>
    <p>
      <?= e($ag['ledger_code']) ?> ·
      <?= $ag['rent_type'] === 'fixed' ? e(t('fixed_rent')) . ' ' . money($ag['rent_amount']) : e(t('percentage_rent')) . ' @ ' . e($ag['percentage_rate']) . '% ' . e(t('of_sales')) ?> ·
      <?= e(freqLabel($ag['billing_frequency'])) ?> <?= e(t('billing_word')) ?> ·
      <span dir="ltr"><?= date('d M Y', strtotime($ag['period_from'])) ?> → <?= date('d M Y', strtotime($ag['period_to'])) ?></span>
      <?php if ($ag['contact_person']): ?> · <?= e(t('contact')) ?>: <?= e($ag['contact_person']) ?><?= $ag['contact_phone'] ? ' (' . e($ag['contact_phone']) . ')' : '' ?><?php endif; ?>
      <?php if ($ag['scan_file']): ?> · <a href="<?= e(UPLOAD_URL . '/' . $ag['scan_file']) ?>" target="_blank"><?= e(t('scanned_agreement')) ?></a><?php endif; ?>
    </p>
  </div>
  <div class="d-flex gap-2">
    <a class="btn btn-light border" target="_blank" href="statement.php?id=<?= $id ?>"><i class="bi bi-printer me-1"></i> <?= e(t('supplier_statement')) ?></a>
    <a class="btn btn-light border" href="grid.php?year=<?= date('Y') ?>&q=<?= urlencode($ag['ledger_code']) ?>"><?= e(t('view_in_grid')) ?></a>
    <?php if ($write): ?>
      <a class="btn btn-gold" href="agreement_form.php?id=<?= $id ?>"><?= e(t('edit_agreement')) ?></a>
    <?php endif; ?>
  </div>
</div>

<?php if ($ag['notes']): ?>
  <div class="alert alert-light border shadow-sm"><i class="bi bi-sticky me-1"></i> <?= e($ag['notes']) ?></div>
<?php endif; ?>

<div class="row g-3 mb-3">
  <div class="col-md-4"><div class="stat-card"><div class="stat-label"><?= e(t('total_expected')) ?></div><div class="stat-value"><?= money($sumExpected) ?></div></div></div>
  <div class="col-md-4"><div class="stat-card"><div class="stat-label"><?= e(t('total_received')) ?></div><div class="stat-value"><?= money($sumPaid) ?></div></div></div>
  <div class="col-md-4"><div class="stat-card danger"><div class="stat-label"><?= e(t('overdue_balance')) ?></div><div class="stat-value"><?= money($sumOverdue) ?></div></div></div>
</div>

<div class="panel p-0 overflow-hidden">
  <div class="table-responsive">
    <table class="table table-luxe align-middle mb-0">
      <thead>
        <tr><th><?= e(t('bill_month')) ?></th><th><?= e(t('due_date')) ?></th><th class="text-end"><?= e(t('expected')) ?></th><th class="text-end"><?= e(t('paid')) ?></th><th class="text-end"><?= e(t('balance')) ?></th><th><?= e(t('status')) ?></th><th class="text-end"><?= e(t('actions')) ?></th></tr>
      </thead>
      <tbody>
      <?php foreach ($bills as $b): $bal = $b['expected_amount'] === null ? null : (float)$b['expected_amount'] - $b['paid']; ?>
        <tr>
          <td><?= date('F Y', strtotime($b['bill_month'])) ?></td>
          <td><?= date('d M Y', strtotime($b['due_date'])) ?></td>
          <td class="text-end"><?= $b['expected_amount'] === null ? '<span class="text-muted">' . e(t('awaited')) . '</span>' : money($b['expected_amount']) ?></td>
          <td class="text-end"><?= money($b['paid']) ?></td>
          <td class="text-end"><?= $bal === null ? '—' : money(max(0, $bal)) ?></td>
          <td><span class="pill pill-<?= $b['status'] ?>"><?= statusLabel($b['status']) ?></span></td>
          <td class="text-end text-nowrap">
            <button class="btn btn-sm btn-light border" data-bs-toggle="collapse" data-bs-target="#bill-<?= (int)$b['id'] ?>"><?= e(t('history')) ?></button>
            <?php if ($write && $b['expected_amount'] === null): ?>
              <button class="btn btn-sm btn-gold" data-bs-toggle="collapse" data-bs-target="#bill-<?= (int)$b['id'] ?>"><?= e(t('enter_amount')) ?></button>
            <?php elseif ($write && $bal !== null && $bal > 0): ?>
              <button class="btn btn-sm btn-gold" data-bs-toggle="collapse" data-bs-target="#bill-<?= (int)$b['id'] ?>"><?= e(t('record_payment')) ?></button>
            <?php endif; ?>
          </td>
        </tr>
        <tr class="collapse-row">
          <td colspan="7" class="p-0 border-0">
            <div class="collapse" id="bill-<?= (int)$b['id'] ?>">
              <div class="bill-detail">
                <?php if ($b['payments']): ?>
                  <table class="table table-sm mb-3">
                    <thead><tr><th><?= e(t('date')) ?></th><th><?= e(t('method')) ?></th><th><?= e(t('reference')) ?></th><th><?= e(t('cheque')) ?></th><th><?= e(t('received_by')) ?></th><th class="text-end"><?= e(t('amount')) ?></th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($b['payments'] as $p): ?>
                      <tr>
                        <td><?= date('d M Y', strtotime($p['payment_date'])) ?></td>
                        <td><?= e($p['method']) ?></td>
                        <td><?= e($p['reference'] ?? '—') ?></td>
                        <td><?= e($p['cheque_status'] ?? '—') ?></td>
                        <td><?= e($p['by_name']) ?></td>
                        <td class="text-end"><?= money($p['amount']) ?></td>
                        <td class="text-end text-nowrap">
                          <a class="btn btn-sm btn-light border" target="_blank" href="receipt.php?payment_id=<?= (int)$p['id'] ?>&back=<?= urlencode($back) ?>"><i class="bi bi-printer"></i> <?= e(t('receipt')) ?></a>
                          <?php if (isAdmin()): ?>
                            <form method="post" action="payment_action.php" class="d-inline" onsubmit="return confirm('Delete this payment?');">
                              <?= csrfField() ?>
                              <input type="hidden" name="action" value="delete_payment">
                              <input type="hidden" name="payment_id" value="<?= (int)$p['id'] ?>">
                              <input type="hidden" name="back" value="<?= e($back) ?>">
                              <button class="btn btn-sm btn-outline-danger" type="submit"><?= e(t('delete')) ?></button>
                            </form>
                          <?php endif; ?>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                    </tbody>
                  </table>
                <?php else: ?>
                  <p class="cell-sub"><?= e(t('no_payments_bill')) ?></p>
                <?php endif; ?>

                <?php if ($write && $b['expected_amount'] === null): ?>
                  <form method="post" action="payment_action.php" class="row g-2 align-items-end">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="set_amount">
                    <input type="hidden" name="bill_id" value="<?= (int)$b['id'] ?>">
                    <input type="hidden" name="back" value="<?= e($back) ?>">
                    <div class="col-sm-4">
                      <label class="form-label"><?= e(t('actual_billed_amount')) ?> (<?= e(CURRENCY) ?>)</label>
                      <input type="number" step="0.01" min="0.01" class="form-control" name="actual_amount" required>
                    </div>
                    <div class="col-sm-4"><button class="btn btn-gold" type="submit"><?= e(t('save_amount')) ?></button></div>
                  </form>
                <?php elseif ($write && $bal !== null && $bal > 0): ?>
                  <form method="post" action="payment_action.php" class="row g-2 align-items-end">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="record_payment">
                    <input type="hidden" name="bill_id" value="<?= (int)$b['id'] ?>">
                    <input type="hidden" name="back" value="<?= e($back) ?>">
                    <div class="col-sm-3">
                      <label class="form-label"><?= e(t('amount')) ?> (<?= e(CURRENCY) ?>)</label>
                      <input type="number" step="0.01" min="0.01" max="<?= e($bal) ?>" class="form-control" name="amount" value="<?= e($bal) ?>" required>
                    </div>
                    <div class="col-sm-3">
                      <label class="form-label"><?= e(t('date')) ?></label>
                      <input type="date" class="form-control" name="payment_date" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="col-sm-2">
                      <label class="form-label"><?= e(t('method')) ?></label>
                      <select class="form-select" name="method"><option>Cash</option><option>Bank Transfer</option><option>Cheque</option><option>Online</option></select>
                    </div>
                    <div class="col-sm-2">
                      <label class="form-label"><?= e(t('reference')) ?></label>
                      <input class="form-control" name="reference" placeholder="<?= e(t('optional')) ?>">
                    </div>
                    <div class="col-sm-2"><button class="btn btn-gold w-100" type="submit"><?= e(t('save_payment')) ?></button></div>
                  </form>
                <?php endif; ?>
              </div>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="panel mt-3 p-0 overflow-hidden">
  <div class="panel-head px-4 pt-3">
    <h2><i class="bi bi-gift me-2"></i><?= e(t('incentives')) ?></h2>
    <?php if ($write): ?>
      <a class="btn btn-sm btn-gold" href="incentive_form.php"><i class="bi bi-plus-lg me-1"></i> <?= e(t('add_incentive')) ?></a>
    <?php endif; ?>
  </div>
  <div class="table-responsive">
    <table class="table table-luxe align-middle mb-0">
      <thead><tr><th><?= e(t('incentive')) ?></th><th class="text-end"><?= e(t('expected')) ?></th><th class="text-end"><?= e(t('received')) ?></th><th class="text-end"><?= e(t('balance')) ?></th><th><?= e(t('expected_date')) ?></th><th><?= e(t('status')) ?></th></tr></thead>
      <tbody>
      <?php if (!$incs): ?>
        <tr><td colspan="6" class="text-center py-3 text-muted"><?= e(t('no_incentives_company')) ?></td></tr>
      <?php endif; ?>
      <?php foreach ($incs as $inc): ?>
        <tr>
          <td><?= e($inc['title']) ?><div class="cell-sub"><?= e($inc['type']) ?> · <?= $inc['basis'] === 'Percentage' ? e(t('percentage')) . ' @ ' . e($inc['rate']) . '%' : e(t('fixed')) ?></div></td>
          <td class="text-end"><?= $inc['expected_amount'] === null ? '<span class="text-muted">' . e(t('awaited')) . '</span>' : money($inc['expected_amount']) ?></td>
          <td class="text-end"><?= money($inc['received_amount']) ?></td>
          <td class="text-end"><?= $inc['balance'] === null ? '—' : money($inc['balance']) ?></td>
          <td><?= date('d M Y', strtotime($inc['expected_date'])) ?></td>
          <td><span class="pill pill-<?= $inc['status'] ?>"><?= statusLabel($inc['status']) ?></span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php layoutFoot(); ?>
