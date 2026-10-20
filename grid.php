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
pageTitle(t('payment_grid'), 'Companies in rows, months in columns. Click any bill cell to see details or record a payment.');
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
    <input class="form-control" name="q" value="<?= e($q) ?>" placeholder="Search company or ledger code">
  </div>
  <select class="form-select filter-status" name="status">
    <option value="">All statuses</option>
    <?php foreach (['received', 'partial', 'due', 'overdue', 'awaited'] as $s): ?>
      <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= statusLabel($s) ?></option>
    <?php endforeach; ?>
  </select>
  <button class="btn btn-gold" type="submit"><?= e(t('filter')) ?></button>
  <?php if ($q !== '' || $status !== ''): ?>
    <a class="btn btn-light border" href="grid.php?year=<?= $year ?>">Clear</a>
  <?php endif; ?>
</form>

<div class="legend mb-3">
  <?php foreach (['received', 'partial', 'due', 'overdue', 'awaited'] as $s): ?>
    <span class="pill pill-<?= $s ?> me-1"><?= statusLabel($s) ?></span>
  <?php endforeach; ?>
  <span class="pill pill-out me-1"><?= statusLabel('out') ?></span>
  <span class="pill pill-nobill"><?= statusLabel('nobill') ?> (not a billing month)</span>
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
        <tr><td colspan="13" class="text-center py-4 text-muted">No companies match this filter.</td></tr>
      <?php endif; ?>
      <?php foreach ($agreements as $ag):
        $startMon = strtotime(date('Y-m-01', strtotime($ag['period_from'])));
        $endMon   = strtotime(date('Y-m-01', strtotime($ag['period_to'])));
      ?>
        <tr>
          <th class="sticky-col">
            <a href="company.php?id=<?= (int)$ag['id'] ?>"><?= e($ag['supplier_name']) ?></a>
            <div class="cell-sub"><?= e($ag['ledger_code']) ?> · <?= ucfirst(str_replace('_', ' ', $ag['billing_frequency'])) ?> · <?= $ag['rent_type'] === 'fixed' ? money($ag['rent_amount']) : e($ag['percentage_rate']) . '%' ?></div>
          </th>
          <?php for ($m = 1; $m <= 12; $m++):
            $cellTime = mktime(0, 0, 0, $m, 1, $year);
            $bill = $byAg[$ag['id']][$m] ?? null;
            if ($cellTime < $startMon || $cellTime > $endMon): ?>
              <td class="cell-out" title="Outside the agreement period"></td>
            <?php elseif (!$bill): ?>
              <td class="cell-nobill" title="Not a billing month for this agreement">·</td>
            <?php else:
              $dim = $status !== '' && $bill['status'] !== $status ? ' dim' : '';
            ?>
              <td class="cell-bill<?= $dim ?>">
                <button type="button" class="cell-btn" data-bill="<?= (int)$bill['id'] ?>" data-bs-toggle="modal" data-bs-target="#billModal">
                  <span class="pill pill-<?= $bill['status'] ?>"><?= statusLabel($bill['status']) ?></span>
                  <span class="cell-amt"><?= $bill['expected_amount'] === null ? '—' : money($bill['expected_amount']) ?></span>
                  <?php if ($bill['paid'] > 0 && $bill['status'] !== 'received'): ?>
                    <span class="cell-sub">paid <?= money($bill['paid']) ?></span>
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
          <h5 class="modal-title" id="bmTitle">Bill</h5>
          <div class="cell-sub" id="bmSub"></div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3 mb-3">
          <div class="col-6 col-md-3"><div class="mini-stat"><span>Status</span><span class="pill" id="bmStatus"></span></div></div>
          <div class="col-6 col-md-3"><div class="mini-stat"><span>Expected</span><strong id="bmExpected">—</strong></div></div>
          <div class="col-6 col-md-3"><div class="mini-stat"><span>Paid</span><strong id="bmPaid">—</strong></div></div>
          <div class="col-6 col-md-3"><div class="mini-stat"><span>Balance</span><strong id="bmBalance">—</strong></div></div>
        </div>
        <p class="cell-sub mb-3">Due date: <strong id="bmDue"></strong></p>

        <h6 class="modal-section">Payments on this bill</h6>
        <div id="bmPayments"></div>

        <?php if ($write): ?>
        <div id="bmSetAmount" class="action-box d-none">
          <h6><i class="bi bi-percent me-1"></i>Percentage agreement — enter the actual billed amount</h6>
          <form method="post" action="payment_action.php" class="row g-2 align-items-end">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="set_amount">
            <input type="hidden" name="bill_id" class="bm-bill-id">
            <input type="hidden" name="back" value="<?= e($back) ?>">
            <div class="col-sm-6">
              <label class="form-label">Actual billed amount (<?= e(CURRENCY) ?>)</label>
              <input type="number" step="0.01" min="0.01" class="form-control" name="actual_amount" required>
            </div>
            <div class="col-sm-6"><button class="btn btn-gold" type="submit">Save Amount</button></div>
          </form>
        </div>

        <div id="bmPayForm" class="action-box d-none">
          <h6><i class="bi bi-cash-coin me-1"></i>Record a payment</h6>
          <form method="post" action="payment_action.php" class="row g-2">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="record_payment">
            <input type="hidden" name="bill_id" class="bm-bill-id">
            <input type="hidden" name="back" value="<?= e($back) ?>">
            <div class="col-sm-4">
              <label class="form-label">Amount (<?= e(CURRENCY) ?>)</label>
              <input type="number" step="0.01" min="0.01" class="form-control" id="bmPayAmount" name="amount" required>
            </div>
            <div class="col-sm-4">
              <label class="form-label">Date</label>
              <input type="date" class="form-control" name="payment_date" value="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="col-sm-4">
              <label class="form-label">Method</label>
              <select class="form-select" name="method" id="bmMethod">
                <option>Cash</option><option>Bank Transfer</option><option>Cheque</option><option>Online</option>
              </select>
            </div>
            <div class="col-sm-4">
              <label class="form-label">Reference / Cheque No.</label>
              <input class="form-control" name="reference" placeholder="Optional">
            </div>
            <div class="col-sm-4 d-none" id="bmChequeWrap">
              <label class="form-label">Cheque status</label>
              <select class="form-select" name="cheque_status">
                <option>Pending</option><option>Cleared</option><option>Bounced</option>
              </select>
            </div>
            <div class="col-sm-4">
              <label class="form-label">Notes</label>
              <input class="form-control" name="notes" placeholder="Optional">
            </div>
            <div class="col-12">
              <button class="btn btn-gold" type="submit"><i class="bi bi-check2-circle me-1"></i>Save Payment</button>
              <span class="cell-sub ms-2">Part payments are fine — the balance stays open.</span>
            </div>
          </form>
        </div>
        <?php else: ?>
          <p class="cell-sub">Your role is read-only, so payments cannot be recorded from this account.</p>
        <?php endif; ?>
      </div>
      <div class="modal-footer">
        <a class="btn btn-light border" id="bmLedger" href="#">Open company ledger</a>
        <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<script>
const BILLS = <?= json_encode($billJs, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const CAN_WRITE = <?= $write ? 'true' : 'false' ?>;
const BACK = <?= json_encode($back) ?>;
function fmt(n){ return '<?= e(CURRENCY) ?> ' + Number(n).toLocaleString('en-US', {maximumFractionDigits: 2}); }
document.querySelectorAll('[data-bill]').forEach(btn => {
  btn.addEventListener('click', () => {
    const b = BILLS[btn.dataset.bill];
    if (!b) return;
    document.getElementById('bmTitle').textContent = b.supplier + ' — ' + b.month;
    document.getElementById('bmSub').textContent = b.ledger + (b.type === 'percentage' ? ' · Percentage agreement @ ' + b.rate + '%' : ' · Fixed rent');
    const pill = document.getElementById('bmStatus');
    pill.textContent = b.statusLabel;
    pill.className = 'pill pill-' + b.status;
    document.getElementById('bmExpected').textContent = b.expected === null ? 'Awaited' : fmt(b.expected);
    document.getElementById('bmPaid').textContent = fmt(b.paid);
    document.getElementById('bmBalance').textContent = b.expected === null ? '—' : fmt(Math.max(0, b.expected - b.paid));
    document.getElementById('bmDue').textContent = b.due;
    document.getElementById('bmLedger').href = 'company.php?id=' + b.agreementId;
    const list = document.getElementById('bmPayments');
    if (!b.payments.length) {
      list.innerHTML = '<p class="cell-sub">No payments recorded yet.</p>';
    } else {
      list.innerHTML = '<table class="table table-sm table-luxe"><thead><tr><th>Date</th><th>Method</th><th>Reference</th><th>Received by</th><th class="text-end">Amount</th><th></th></tr></thead><tbody>' +
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
