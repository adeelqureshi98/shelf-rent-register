<?php
/**
 * Supplier Statement — an accounts-style statement for one
 * company over a chosen period: opening balance, every bill
 * raised and every payment received in the period (in date
 * order, with a running balance), and the closing balance.
 * Print / Save-as-PDF clean, in the same style as receipt.php.
 *
 * Balance maths: opening = (known bills raised before the
 * period) − (payments received before the period). Awaited
 * (percentage) bills are listed for completeness but carry no
 * amount — the register never invents a figure.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/billing.php';
require_once __DIR__ . '/auth.php';

requireLogin();
$pdo = db();
syncBills($pdo);

$all = $pdo->query('SELECT * FROM agreements ORDER BY supplier_name')->fetchAll();
$id = (int)($_GET['id'] ?? ($all[0]['id'] ?? 0));
$ag = null;
foreach ($all as $a) {
    if ((int)$a['id'] === $id) $ag = $a;
}
if (!$ag) {
    flash('error', 'Company not found.');
    header('Location: agreements.php');
    exit;
}

$from = $_GET['from'] ?? $ag['period_from'];
$to   = $_GET['to'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = $ag['period_from'];
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   $to = date('Y-m-d');
if ($to < $from) [$from, $to] = [$to, $from];

/* ---- Gather this company's bills + payments ---- */
$bills = loadBills($pdo, null, $id);
$paySt = $pdo->prepare(
    'SELECT p.*, b.bill_month FROM payments p JOIN bills b ON b.id = p.bill_id
      WHERE b.agreement_id = :ag ORDER BY p.payment_date, p.id'
);
$paySt->execute([':ag' => $id]);
$payments = $paySt->fetchAll();

$paidByBillBefore = [];
$paidBefore = 0.0;
foreach ($payments as $p) {
    if ($p['payment_date'] < $from) $paidBefore += (float)$p['amount'];
}
$expectedBefore = 0.0;
foreach ($bills as $b) {
    if ($b['bill_month'] < $from && $b['expected_amount'] !== null) {
        $expectedBefore += (float)$b['expected_amount'];
    }
}
$opening = $expectedBefore - $paidBefore;

/* ---- Period events in date order: bills are debits, payments credits ---- */
$events = [];
foreach ($bills as $b) {
    if ($b['bill_month'] < $from || $b['bill_month'] > $to) continue;
    $events[] = [
        'date' => $b['bill_month'], 'kind' => 'bill',
        'label' => t('shelf_rent') . ' — ' . date('F Y', strtotime($b['bill_month'])) . ' (' . t('due') . ' ' . date('d M Y', strtotime($b['due_date'])) . ')',
        'debit' => $b['expected_amount'] === null ? null : (float)$b['expected_amount'],
        'credit' => null,
        'note' => $b['expected_amount'] === null ? t('awaited_unknown') : '',
        '_ord' => 0,
    ];
}
foreach ($payments as $p) {
    if ($p['payment_date'] < $from || $p['payment_date'] > $to) continue;
    $events[] = [
        'date' => $p['payment_date'], 'kind' => 'payment',
        'label' => t('payment_received_label') . ' — ' . $p['method'] . ($p['reference'] ? ' (' . $p['reference'] . ')' : '') . ' · ' . date('F Y', strtotime($p['bill_month'])) . ' ' . t('bill_word'),
        'debit' => null, 'credit' => (float)$p['amount'], 'note' => '',
        '_ord' => 1,
    ];
}
usort($events, fn($a, $b) => [$a['date'], $a['_ord']] <=> [$b['date'], $b['_ord']]);

$totDebit = 0.0; $totCredit = 0.0;
$balance = $opening;
foreach ($events as &$ev) {
    if ($ev['debit'] !== null)  { $balance += $ev['debit'];  $totDebit += $ev['debit']; }
    if ($ev['credit'] !== null) { $balance -= $ev['credit']; $totCredit += $ev['credit']; }
    $ev['balance'] = $balance;
}
unset($ev);
$closing = $balance;

$incentives = loadIncentives($pdo, $id);
$lang = appLang();
?>
<!DOCTYPE html>
<html lang="<?= e($lang) ?>" dir="<?= $lang === 'ur' ? 'rtl' : 'ltr' ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(t('supplier_statement')) ?> — <?= e($ag['supplier_name']) ?> · <?= e(APP_NAME) ?></title>
<link href="assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
<link href="assets/vendor/bootstrap-icons/bootstrap-icons.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500;600;700&family=Inter:wght@400;500;600;700&family=Noto+Nastaliq+Urdu:wght@400;600;700&display=swap" rel="stylesheet">
<link href="assets/css/app.css" rel="stylesheet">
</head>
<body class="receipt-body">
<div class="receipt-actions no-print" style="max-width:900px">
  <a class="btn btn-light border" href="company.php?id=<?= $id ?>"><i class="bi bi-arrow-left me-1"></i> <?= e(t('back_to_ledger')) ?></a>
  <button class="btn btn-gold" onclick="window.print()"><i class="bi bi-printer me-1"></i> <?= e(t('print_save_pdf')) ?></button>
</div>

<form method="get" class="no-print mb-3 mx-auto d-flex gap-2 flex-wrap align-items-end" style="max-width:900px">
  <div>
    <label class="form-label"><?= e(t('company')) ?></label>
    <select class="form-select" name="id" onchange="this.form.submit()">
      <?php foreach ($all as $a): ?>
        <option value="<?= (int)$a['id'] ?>" <?= (int)$a['id'] === $id ? 'selected' : '' ?>><?= e($a['supplier_name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <label class="form-label"><?= e(t('from')) ?></label>
    <input type="date" class="form-control" name="from" value="<?= e($from) ?>">
  </div>
  <div>
    <label class="form-label"><?= e(t('to')) ?></label>
    <input type="date" class="form-control" name="to" value="<?= e($to) ?>">
  </div>
  <button class="btn btn-gold" type="submit"><?= e(t('generate')) ?></button>
</form>

<div class="receipt" style="max-width:900px">
  <div class="receipt-head">
    <div class="brand-mark big"><i class="bi bi-gem"></i></div>
    <h1><?= e(MART_NAME) ?></h1>
    <p><?= e(APP_NAME) ?></p>
    <h2><?= e(t('supplier_statement')) ?></h2>
  </div>

  <table class="receipt-table">
    <tr><th><?= e(t('supplier')) ?></th><td><?= e($ag['supplier_name']) ?> (<?= e($ag['ledger_code']) ?>)</td></tr>
    <tr><th><?= e(t('contact')) ?></th><td><?= e($ag['contact_person'] ?? '—') ?><?= $ag['contact_phone'] ? ' · ' . e($ag['contact_phone']) : '' ?><?= !empty($ag['contact_email']) ? ' · ' . e($ag['contact_email']) : '' ?></td></tr>
    <tr><th><?= e(t('agreement')) ?></th><td><?= $ag['rent_type'] === 'fixed' ? e(t('fixed_rent')) . ' ' . money($ag['rent_amount']) : e(t('percentage_rent')) . ' @ ' . e($ag['percentage_rate']) . '% ' . e(t('of_sales')) ?> · <?= e(freqLabel($ag['billing_frequency'])) ?> <?= e(t('billing_word')) ?> · <span dir="ltr"><?= date('d M Y', strtotime($ag['period_from'])) ?> → <?= date('d M Y', strtotime($ag['period_to'])) ?></span></td></tr>
    <tr><th><?= e(t('statement_period')) ?></th><td><span dir="ltr"><?= date('d F Y', strtotime($from)) ?> → <?= date('d F Y', strtotime($to)) ?></span></td></tr>
    <tr><th><?= e(t('generated')) ?></th><td><?= date('d F Y') ?> · <?= e(t('by_word')) ?> <?= e(currentUser()['name']) ?></td></tr>
  </table>

  <table class="table table-luxe align-middle mt-4">
    <thead><tr><th><?= e(t('date')) ?></th><th><?= e(t('particulars')) ?></th><th class="text-end"><?= e(t('debit')) ?></th><th class="text-end"><?= e(t('credit')) ?></th><th class="text-end"><?= e(t('balance')) ?></th></tr></thead>
    <tbody>
      <tr class="fw-semibold">
        <td class="text-nowrap"><?= date('d M Y', strtotime($from)) ?></td><td><?= e(t('opening_balance')) ?></td>
        <td class="text-end">—</td><td class="text-end">—</td><td class="text-end"><?= money($opening) ?></td>
      </tr>
      <?php if (!$events): ?>
        <tr><td colspan="5" class="text-center py-3 text-muted"><?= e(t('no_events_period')) ?></td></tr>
      <?php endif; ?>
      <?php foreach ($events as $ev): ?>
        <tr>
          <td class="text-nowrap"><?= date('d M Y', strtotime($ev['date'])) ?></td>
          <td><?= e($ev['label']) ?><?php if ($ev['note']): ?> <span class="cell-sub">(<?= e($ev['note']) ?>)</span><?php endif; ?></td>
          <td class="text-end"><?= $ev['debit'] === null ? '—' : money($ev['debit']) ?></td>
          <td class="text-end"><?= $ev['credit'] === null ? '—' : money($ev['credit']) ?></td>
          <td class="text-end"><?= money($ev['balance']) ?></td>
        </tr>
      <?php endforeach; ?>
      <tr class="fw-semibold">
        <td></td><td><?= e(t('closing_balance')) ?> (<?= date('d M Y', strtotime($to)) ?>)</td>
        <td class="text-end"><?= money($totDebit) ?></td><td class="text-end"><?= money($totCredit) ?></td>
        <td class="text-end"><?= money($closing) ?></td>
      </tr>
    </tbody>
  </table>

  <?php if ($incentives): ?>
    <h3 class="mt-4" style="font-size:18px"><?= e(t('incentives_info_note')) ?></h3>
    <table class="table table-luxe align-middle">
      <thead><tr><th><?= e(t('incentive')) ?></th><th><?= e(t('expected_date')) ?></th><th class="text-end"><?= e(t('expected')) ?></th><th class="text-end"><?= e(t('received')) ?></th><th class="text-end"><?= e(t('balance')) ?></th><th><?= e(t('status')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($incentives as $inc): ?>
        <tr>
          <td><?= e($inc['title']) ?></td>
          <td class="text-nowrap"><?= date('d M Y', strtotime($inc['expected_date'])) ?></td>
          <td class="text-end"><?= $inc['expected_amount'] === null ? e(t('awaited')) : money($inc['expected_amount']) ?></td>
          <td class="text-end"><?= money($inc['received_amount']) ?></td>
          <td class="text-end"><?= $inc['balance'] === null ? '—' : money($inc['balance']) ?></td>
          <td><?= statusLabel($inc['status']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <div class="receipt-foot">
    <div><?= e(t('prepared_by')) ?>: <strong><?= e(currentUser()['name']) ?></strong></div>
    <div class="receipt-sign"><?= e(t('authorised_signature')) ?> ______________________</div>
  </div>
  <p class="receipt-thanks"><?= e(sprintf(t('statement_thanks'), MART_NAME)) ?></p>
</div>
</body>
</html>
