<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/billing.php';
require_once __DIR__ . '/layout.php';

requireLogin();
$pdo = db();
syncBills($pdo);

$year   = (int)($_GET['year'] ?? date('Y'));
$year   = max(2020, min(2100, $year));
$q      = trim($_GET['q'] ?? '');
$status = $_GET['status'] ?? '';
$write  = canWrite();
$back   = 'grid.php?year=' . $year;

/* Agreements (rows), optionally filtered by company name. */
$sql = 'SELECT * FROM agreements';
$args = [];
if ($q !== '') {
    $sql .= ' WHERE supplier_name LIKE :q OR ledger_code LIKE :q';
    $args[':q'] = '%' . $q . '%';
}
$sql .= ' ORDER BY supplier_name';
$st = $pdo->prepare($sql);
$st->execute($args);
$agreements = $st->fetchAll();

/* Bills for the year, grouped [agreement_id][month] = bill. Payment history too. */
$bills  = loadBills($pdo, $year);
$byAg   = [];
$billJs = [];
$paySt  = $pdo->prepare(
    'SELECT p.*, u.name AS by_name FROM payments p JOIN users u ON u.id = p.received_by
      WHERE p.bill_id = :b ORDER BY p.payment_date, p.id'
);
foreach ($bills as $b) {
    $m = (int)date('n', strtotime($b['bill_month']));
    $byAg[$b['agreement_id']][$m] = $b;
    $paySt->execute([':b' => $b['id']]);
    $pays = $paySt->fetchAll();
    $billJs[$b['id']] = [
        'id'       => (int)$b['id'],
        'agreementId' => (int)$b['agreement_id'],
        'supplier' => $b['supplier_name'],
        'ledger'   => $b['ledger_code'],
        'month'    => date('F Y', strtotime($b['bill_month'])),
        'due'      => date('d M Y', strtotime($b['due_date'])),
        'expected' => $b['expected_amount'] === null ? null : (float)$b['expected_amount'],
        'paid'     => $b['paid'],
        'status'   => $b['status'],
        'statusLabel' => statusLabel($b['status']),
        'type'     => $b['rent_type'],
        'rate'     => $b['percentage_rate'] === null ? null : (float)$b['percentage_rate'],
        'payments' => array_map(fn($p) => [
            'id'     => (int)$p['id'],
            'date'   => date('d M Y', strtotime($p['payment_date'])),
            'amount' => (float)$p['amount'],
            'method' => $p['method'],
            'ref'    => $p['reference'],
            'cheque' => $p['cheque_status'],
            'by'     => $p['by_name'],
        ], $pays),
    ];
}

/* Status filter: keep only companies that have such a bill this year. */
if ($status !== '') {
    $agreements = array_values(array_filter($agreements, function ($ag) use ($byAg, $status) {
        foreach ($byAg[$ag['id']] ?? [] as $b) {
            if ($b['status'] === $status) return true;
        }
        return false;
    }));
}

layoutHead(t('payment_grid'), 'grid');
pageTitle(t('payment_grid'), t('grid_subtitle'));
?>

<form class="filter-bar" method="get">
  <div class="year-nav">
    <a class="btn btn-light border" href="grid.php?year=<?= $year - 1 ?>&q=<?= urlencode($q) ?>&status=<?= urlencode($status) ?>"><i class="bi bi-chevron-left"></i></a>
    <span class="year-label"><?= $year ?></span>
    <a class="btn btn-light border" href="grid.php?year=<?= $year + 1 ?>&q=<?= urlencode($q) ?>&status=<?= urlencode($status) ?>"><i class="bi bi-chevron-right"></i></a>
  </div>
  <input type="hidden" name="year" value="<?= $year ?>">
  <div class="input-group filter-search">
    <span class="input-group-text"><i class="bi bi-search"></i></span>
    <input class="form-control" name="q" value="<?= e($q) ?>" placeholder="<?= e(t('search_company_placeholder')) ?>">
  </div>
  <select class="form-select filter-status" name="status">
    <option value=""><?= e(t('all_statuses')) ?></option>
    <?php foreach (['received', 'partial', 'due', 'overdue', 'awaited'] as $s): ?>
      <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= statusLabel($s) ?></option>
    <?php endforeach; ?>
  </select>
  <button class="btn btn-gold" type="submit"><?= e(t('filter')) ?></button>
  <?php if ($q !== '' || $status !== ''): ?>
    <a class="btn btn-light border" href="grid.php?year=<?= $year ?>"><?= e(t('clear')) ?></a>
  <?php endif; ?>
</form>

<div class="legend mb-3">
  <?php foreach (['received', 'partial', 'due', 'overdue', 'awaited'] as $s): ?>
    <span class="pill pill-<?= $s ?> me-1"><?= statusLabel($s) ?></span>
  <?php endforeach; ?>
  <span class="pill pill-out me-1"><?= statusLabel('out') ?></span>
  <span class="pill pill-nobill"><?= statusLabel('nobill') ?> <?= e(t('not_a_billing_month')) ?></span>
</div>

<div class="panel p-0 overflow-hidden">
  <div class="table-responsive grid-wrap">
    <table class="table grid-table align-middle mb-0">
      <thead>
        <tr>
          <th class="sticky-col"><?= e(t('company')) ?></th>
          <?php for ($m = 1; $m <= 12; $m++): ?>
            <th class="<?= $year === (int)date('Y') && $m === (int)date('n') ? 'this-month' : '' ?>"><?= date('M', mktime(0, 0, 0, $m, 1)) ?></th>
          <?php endfor; ?>
        </tr>
      </thead>
      <tbody>
      <?php if (!$agreements): ?>
        <tr><td colspan="13" class="text-center py-4 text-muted"><?= e(t('no_companies_match')) ?></td></tr>
      <?php endif; ?>
      <?php foreach ($agreements as $ag):
        $startMon = strtotime(date('Y-m-01', strtotime($ag['period_from'])));
        $endMon   = strtotime(date('Y-m-01', strtotime($ag['period_to'])));
      ?>
        <tr>
          <th class="sticky-col">
            <a href="company.php?id=<?= (int)$ag['id'] ?>"><?= e($ag['supplier_name']) ?></a>
            <div class="cell-sub"><?= e($ag['ledger_code']) ?> · <?= e(freqLabel($ag['billing_frequency'])) ?> · <?= $ag['rent_type'] === 'fixed' ? money($ag['rent_amount']) : e($ag['percentage_rate']) . '%' ?></div>
          </th>
          <?php for ($m = 1; $m <= 12; $m++):
            $cellTime = mktime(0, 0, 0, $m, 1, $year);
            $bill = $byAg[$ag['id']][$m] ?? null;
            if ($cellTime < $startMon || $cellTime > $endMon): ?>
              <td class="cell-out" title="<?= e(t('outside_period')) ?>"></td>
            <?php elseif (!$bill): ?>
              <td class="cell-nobill" title="<?= e(t('not_billing_month_title')) ?>">·</td>
            <?php else:
              $dim = $status !== '' && $bill['status'] !== $status ? ' dim' : '';
            ?>
              <td class="cell-bill<?= $dim ?>">
                <button type="button" class="cell-btn" data-bill="<?= (int)$bill['id'] ?>" data-bs-toggle="modal" data-bs-target="#billModal">
                  <span class="pill pill-<?= $bill['status'] ?>"><?= statusLabel($bill['status']) ?></span>
                  <span class="cell-amt"><?= $bill['expected_amount'] === null ? '—' : money($bill['expected_amount']) ?></span>
                  <?php if ($bill['paid'] > 0 && $bill['status'] !== 'received'): ?>
                    <span class="cell-sub"><?= e(t('paid_lower')) ?> <?= money($bill['paid']) ?></span>
                  <?php endif; ?>
                </button>
              </td>
            <?php endif; ?>
          <?php endfor; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Bill detail modal -->
<div class="modal fade" id="billModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content luxe-modal">
      <div class="modal-header">
        <div>
          <h5 class="modal-title" id="bmTitle"><?= e(t('bill_title')) ?></h5>
          <div class="cell-sub" id="bmSub"></div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3 mb-3">
          <div class="col-6 col-md-3"><div class="mini-stat"><span><?= e(t('status')) ?></span><span class="pill" id="bmStatus"></span></div></div>
          <div class="col-6 col-md-3"><div class="mini-stat"><span><?= e(t('expected')) ?></span><strong id="bmExpected">—</strong></div></div>
          <div class="col-6 col-md-3"><div class="mini-stat"><span><?= e(t('paid')) ?></span><strong id="bmPaid">—</strong></div></div>
          <div class="col-6 col-md-3"><div class="mini-stat"><span><?= e(t('balance')) ?></span><strong id="bmBalance">—</strong></div></div>
        </div>
        <p class="cell-sub mb-3"><?= e(t('due_date')) ?>: <strong id="bmDue" dir="ltr"></strong></p>

        <h6 class="modal-section"><?= e(t('payments_on_bill')) ?></h6>
        <div id="bmPayments"></div>

        <?php if ($write): ?>
        <div id="bmSetAmount" class="action-box d-none">
          <h6><i class="bi bi-percent me-1"></i><?= e(t('pct_enter_amount')) ?></h6>
          <form method="post" action="payment_action.php" class="row g-2 align-items-end">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="set_amount">
            <input type="hidden" name="bill_id" class="bm-bill-id">
            <input type="hidden" name="back" value="<?= e($back) ?>">
            <div class="col-sm-6">
              <label class="form-label"><?= e(t('actual_billed_amount')) ?> (<?= e(CURRENCY) ?>)</label>
              <input type="number" step="0.01" min="0.01" class="form-control" name="actual_amount" required>
            </div>
            <div class="col-sm-6"><button class="btn btn-gold" type="submit"><?= e(t('save_amount')) ?></button></div>
          </form>
        </div>

        <div id="bmPayForm" class="action-box d-none">
          <h6><i class="bi bi-cash-coin me-1"></i><?= e(t('record_a_payment')) ?></h6>
          <form method="post" action="payment_action.php" class="row g-2">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="record_payment">
            <input type="hidden" name="bill_id" class="bm-bill-id">
            <input type="hidden" name="back" value="<?= e($back) ?>">
            <div class="col-sm-4">
              <label class="form-label"><?= e(t('amount')) ?> (<?= e(CURRENCY) ?>)</label>
              <input type="number" step="0.01" min="0.01" class="form-control" id="bmPayAmount" name="amount" required>
            </div>
            <div class="col-sm-4">
              <label class="form-label"><?= e(t('date')) ?></label>
              <input type="date" class="form-control" name="payment_date" value="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="col-sm-4">
              <label class="form-label"><?= e(t('method')) ?></label>
              <select class="form-select" name="method" id="bmMethod">
                <option>Cash</option><option>Bank Transfer</option><option>Cheque</option><option>Online</option>
              </select>
            </div>
            <div class="col-sm-4">
              <label class="form-label"><?= e(t('reference_cheque')) ?></label>
              <input class="form-control" name="reference" placeholder="<?= e(t('optional')) ?>">
            </div>
            <div class="col-sm-4 d-none" id="bmChequeWrap">
              <label class="form-label"><?= e(t('cheque_status')) ?></label>
              <select class="form-select" name="cheque_status">
                <option>Pending</option><option>Cleared</option><option>Bounced</option>
              </select>
            </div>
            <div class="col-sm-4">
              <label class="form-label"><?= e(t('notes')) ?></label>
              <input class="form-control" name="notes" placeholder="<?= e(t('optional')) ?>">
            </div>
            <div class="col-12">
              <button class="btn btn-gold" type="submit"><i class="bi bi-check2-circle me-1"></i><?= e(t('save_payment')) ?></button>
              <span class="cell-sub ms-2"><?= e(t('part_payments_note')) ?></span>
            </div>
          </form>
        </div>
        <?php else: ?>
          <p class="cell-sub"><?= e(t('readonly_note')) ?></p>
        <?php endif; ?>
      </div>
      <div class="modal-footer">
        <a class="btn btn-light border" id="bmLedger" href="#"><?= e(t('open_company_ledger')) ?></a>
        <button type="button" class="btn btn-dark" data-bs-dismiss="modal"><?= e(t('close')) ?></button>
      </div>
    </div>
  </div>
</div>

<script>
const BILLS = <?= json_encode($billJs, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const CAN_WRITE = <?= $write ? 'true' : 'false' ?>;
const BACK = <?= json_encode($back) ?>;
const L = {
  awaited: <?= json_encode(t('awaited')) ?>,
  noPayments: <?= json_encode(t('no_payments_yet')) ?>,
  date: <?= json_encode(t('date')) ?>,
  method: <?= json_encode(t('method')) ?>,
  reference: <?= json_encode(t('reference')) ?>,
  receivedBy: <?= json_encode(t('received_by')) ?>,
  amount: <?= json_encode(t('amount')) ?>,
  pctAgreement: <?= json_encode(t('pct_agreement')) ?>,
  fixedRent: <?= json_encode(t('fixed_rent')) ?>,
};
function fmt(n){ return '<?= e(CURRENCY) ?> ' + Number(n).toLocaleString('en-US', {maximumFractionDigits: 2}); }
document.querySelectorAll('[data-bill]').forEach(btn => {
  btn.addEventListener('click', () => {
    const b = BILLS[btn.dataset.bill];
    if (!b) return;
    document.getElementById('bmTitle').textContent = b.supplier + ' — ' + b.month;
    document.getElementById('bmSub').textContent = b.ledger + (b.type === 'percentage' ? ' · ' + L.pctAgreement + ' @ ' + b.rate + '%' : ' · ' + L.fixedRent);
    const pill = document.getElementById('bmStatus');
    pill.textContent = b.statusLabel;
    pill.className = 'pill pill-' + b.status;
    document.getElementById('bmExpected').textContent = b.expected === null ? L.awaited : fmt(b.expected);
    document.getElementById('bmPaid').textContent = fmt(b.paid);
    document.getElementById('bmBalance').textContent = b.expected === null ? '—' : fmt(Math.max(0, b.expected - b.paid));
    document.getElementById('bmDue').textContent = b.due;
    document.getElementById('bmLedger').href = 'company.php?id=' + b.agreementId;
    const list = document.getElementById('bmPayments');
    if (!b.payments.length) {
      list.innerHTML = '<p class="cell-sub">' + L.noPayments + '</p>';
    } else {
      list.innerHTML = '<table class="table table-sm table-luxe"><thead><tr><th>' + L.date + '</th><th>' + L.method + '</th><th>' + L.reference + '</th><th>' + L.receivedBy + '</th><th class="text-end">' + L.amount + '</th><th></th></tr></thead><tbody>' +
        b.payments.map(p => '<tr><td>' + p.date + '</td><td>' + p.method + (p.cheque ? ' <span class="cell-sub">(' + p.cheque + ')</span>' : '') + '</td><td>' + (p.ref || '—') + '</td><td>' + p.by + '</td><td class="text-end">' + fmt(p.amount) + '</td><td class="text-end"><a class="btn btn-sm btn-light border" target="_blank" href="receipt.php?payment_id=' + p.id + '&back=' + encodeURIComponent(BACK) + '"><i class="bi bi-printer"></i></a></td></tr>').join('') +
        '</tbody></table>';
    }
    if (CAN_WRITE) {
      document.querySelectorAll('.bm-bill-id').forEach(i => i.value = b.id);
      const awaited = b.expected === null;
      document.getElementById('bmSetAmount').classList.toggle('d-none', !awaited);
      const balance = awaited ? 0 : Math.max(0, b.expected - b.paid);
      document.getElementById('bmPayForm').classList.toggle('d-none', awaited || balance <= 0);
      if (!awaited && balance > 0) document.getElementById('bmPayAmount').value = balance;
    }
  });
});
const methodSel = document.getElementById('bmMethod');
if (methodSel) methodSel.addEventListener('change', () => {
  document.getElementById('bmChequeWrap').classList.toggle('d-none', methodSel.value !== 'Cheque');
});
</script>

<?php layoutFoot(); ?>
