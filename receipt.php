<?php
/**
 * Printable payment receipt. Opened right after a payment is recorded
 * (and from any payment history) — designed to print cleanly on A4.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

requireLogin();
$pdo = db();

$pid = (int)($_GET['payment_id'] ?? 0);
$st = $pdo->prepare(
    'SELECT p.*, a.supplier_name, a.ledger_code, a.contact_person, b.bill_month, b.due_date,
            b.expected_amount, u.name AS by_name,
            (SELECT COALESCE(SUM(p2.amount),0) FROM payments p2 WHERE p2.bill_id = b.id AND p2.id <= p.id) AS paid_after,
            (SELECT COALESCE(SUM(p3.amount),0) FROM payments p3 WHERE p3.bill_id = b.id) AS paid_total
       FROM payments p
       JOIN bills b      ON b.id = p.bill_id
       JOIN agreements a ON a.id = b.agreement_id
       JOIN users u      ON u.id = p.received_by
      WHERE p.id = :id'
);
$st->execute([':id' => $pid]);
$r = $st->fetch();
if (!$r) {
    flash('error', 'Receipt not found.');
    header('Location: index.php');
    exit;
}
$back = $_GET['back'] ?? 'index.php';
if (!preg_match('#^[a-z0-9_\-]+\.php(\?[a-z0-9_\-=&%]*)?$#i', $back)) $back = 'index.php';
$balanceAfter = (float)$r['expected_amount'] - (float)$r['paid_after'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Receipt #<?= $pid ?> · <?= e(APP_NAME) ?></title>
<link href="assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
<link href="assets/vendor/bootstrap-icons/bootstrap-icons.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500;600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link href="assets/css/app.css" rel="stylesheet">
</head>
<body class="receipt-body">
<div class="receipt-actions no-print">
  <a class="btn btn-light border" href="<?= e($back) ?>"><i class="bi bi-arrow-left me-1"></i> Back</a>
  <button class="btn btn-gold" onclick="window.print()"><i class="bi bi-printer me-1"></i> Print Receipt</button>
</div>

<div class="receipt">
  <div class="receipt-head">
    <div class="brand-mark big"><i class="bi bi-gem"></i></div>
    <h1><?= e(MART_NAME) ?></h1>
    <p><?= e(APP_NAME) ?></p>
    <h2>Payment Receipt</h2>
  </div>
  <table class="receipt-table">
    <tr><th>Receipt No.</th><td>SR-<?= str_pad((string)$pid, 5, '0', STR_PAD_LEFT) ?></td></tr>
    <tr><th>Received from</th><td><?= e($r['supplier_name']) ?> (<?= e($r['ledger_code']) ?>)</td></tr>
    <tr><th>Bill month</th><td><?= date('F Y', strtotime($r['bill_month'])) ?> (due <?= date('d M Y', strtotime($r['due_date'])) ?>)</td></tr>
    <tr><th>Payment date</th><td><?= date('d F Y', strtotime($r['payment_date'])) ?></td></tr>
    <tr><th>Method</th><td><?= e($r['method']) ?><?= $r['cheque_status'] ? ' — cheque ' . e($r['cheque_status']) : '' ?></td></tr>
    <?php if ($r['reference']): ?><tr><th>Reference</th><td><?= e($r['reference']) ?></td></tr><?php endif; ?>
    <?php if ($r['notes']): ?><tr><th>Notes</th><td><?= e($r['notes']) ?></td></tr><?php endif; ?>
    <tr><th>Bill amount</th><td><?= moneyFull($r['expected_amount']) ?></td></tr>
    <tr><th>Paid so far (incl. this payment)</th><td><?= moneyFull($r['paid_after']) ?></td></tr>
    <tr><th>Balance remaining</th><td><?= moneyFull(max(0, $balanceAfter)) ?></td></tr>
    <tr class="receipt-amount"><th>Amount received</th><td><?= moneyFull($r['amount']) ?></td></tr>
  </table>
  <div class="receipt-foot">
    <div>Received by: <strong><?= e($r['by_name']) ?></strong></div>
    <div class="receipt-sign">Authorised signature ______________________</div>
  </div>
  <p class="receipt-thanks">Thank you. This is a computer-generated receipt from <?= e(MART_NAME) ?>.</p>
</div>
</body>
</html>
