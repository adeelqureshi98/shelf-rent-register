<?php
/**
 * Formal Tax Invoice — one invoice per bill: the mart's
 * identity (name from config; NTN / STRN / address / phone
 * from Settings), the supplier, the billing period, the bill
 * amount, GST charged, WHT deducted and the net payable —
 * all from the shared tax helpers in billing.php, so the
 * invoice can never disagree with the receipt or statement.
 * The QR code carries the same summary as text. Printing the
 * page produces the paper/PDF invoice.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/billing.php';
require_once __DIR__ . '/auth.php';

requireLogin();
$pdo = db();
syncBills($pdo);

$billId = (int)($_GET['bill'] ?? 0);
$bill = null;
foreach (loadBills($pdo) as $b) {
    if ((int)$b['id'] === $billId) { $bill = $b; break; }
}
if (!$bill) {
    http_response_code(404);
    exit('Invoice not found.');
}

$u = currentUser();
if (($u['role'] ?? '') === 'supplier'
    && (empty($u['company_id']) || (int)$bill['agreement_id'] !== (int)$u['company_id'])) {
    http_response_code(403);
    exit('This invoice belongs to another supplier.');
}

$agSt = $pdo->prepare('SELECT supplier_name, ledger_code, contact_person, contact_phone FROM agreements WHERE id = :id');
$agSt->execute([':id' => (int)$bill['agreement_id']]);
$ag = $agSt->fetch();

$settings = getSettings($pdo);
$tax = taxForBill($bill);
$invoiceNo = 'INV-' . str_pad((string)$billId, 5, '0', STR_PAD_LEFT);
$lang = appLang();

$pays = $pdo->prepare('SELECT * FROM payments WHERE bill_id = :b ORDER BY payment_date, id');
$pays->execute([':b' => $billId]);
$payments = $pays->fetchAll();

/* Kept short + ASCII on purpose: the vendored QR library has a
 * modest capacity, and a scannable code beats a long one. */
$qrText = implode(' | ', array_filter([
    cfg('mart_name'),
    $invoiceNo,
    $settings['mart_ntn'] !== '' ? 'NTN ' . $settings['mart_ntn'] : '',
    $ag['ledger_code'],
    date('M Y', strtotime($bill['bill_month'])),
    'Net ' . number_format($tax['net_payable'], 0, '.', ''),
]));

$theme = ($_COOKIE['srr_theme'] ?? 'light') === 'dark' ? 'dark' : 'light';
?>
<!DOCTYPE html>
<html lang="<?= e($lang) ?>" dir="<?= $lang === 'ur' ? 'rtl' : 'ltr' ?>" data-theme="<?= e($theme) ?>" data-bs-theme="<?= e($theme) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($invoiceNo) ?> · <?= e(t('tax_invoice')) ?></title>
<script>(function(){try{var t=localStorage.getItem('srr-theme');if(t==='dark'||t==='light'){document.documentElement.setAttribute('data-theme',t);document.documentElement.setAttribute('data-bs-theme',t);}}catch(e){}})();</script>
<link href="assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
<link href="assets/vendor/bootstrap-icons/bootstrap-icons.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500;600;700&family=Inter:wght@400;500;600;700&family=Noto+Nastaliq+Urdu:wght@400;600;700&display=swap" rel="stylesheet">
<link href="assets/css/app.css" rel="stylesheet">
</head>
<body class="receipt-body">
<div class="receipt-actions no-print">
  <a class="btn btn-light border" href="<?= ($u['role'] ?? '') === 'supplier' ? 'portal.php' : 'company.php?id=' . (int)$bill['agreement_id'] ?>"><i class="bi bi-arrow-left"></i> <?= e(t('back')) ?></a>
  <button class="btn btn-gold" onclick="window.print()"><i class="bi bi-printer me-1"></i> <?= e(t('print_save_pdf')) ?></button>
</div>

<div class="receipt">
  <div class="receipt-head">
    <div class="brand-mark big"><i class="bi bi-gem"></i></div>
    <h1><?= e(cfg('mart_name')) ?></h1>
    <?php if ($settings['mart_address'] !== ''): ?><p><?= e($settings['mart_address']) ?></p><?php endif; ?>
    <?php if ($settings['mart_phone'] !== ''): ?><p><?= e($settings['mart_phone']) ?></p><?php endif; ?>
    <?php if ($settings['mart_ntn'] !== '' || $settings['mart_strn'] !== ''): ?>
      <p><?= $settings['mart_ntn'] !== '' ? 'NTN: ' . e($settings['mart_ntn']) : '' ?><?= $settings['mart_ntn'] !== '' && $settings['mart_strn'] !== '' ? ' · ' : '' ?><?= $settings['mart_strn'] !== '' ? 'STRN: ' . e($settings['mart_strn']) : '' ?></p>
    <?php endif; ?>
    <h2><?= e(t('tax_invoice')) ?></h2>
  </div>

  <table class="receipt-table">
    <tr><th><?= e(t('invoice_no')) ?></th><td><?= e($invoiceNo) ?></td></tr>
    <tr><th><?= e(t('billed_to')) ?></th><td><strong><?= e($ag['supplier_name']) ?></strong> (<?= e($ag['ledger_code']) ?>)<?= $ag['contact_person'] ? ' · ' . e($ag['contact_person']) : '' ?></td></tr>
    <tr><th><?= e(t('period')) ?></th><td><?= e(monthLabel($bill['bill_month'])) ?> · <?= e(freqLabel($bill['billing_frequency'])) ?></td></tr>
    <tr><th><?= e(t('status')) ?></th><td><span class="pill pill-<?= e($bill['status']) ?>"><?= statusLabel($bill['status']) ?></span></td></tr>
    <tr><th><?= e(t('agreement_amount_label')) ?></th><td><?= money((float)$bill['expected_amount']) ?></td></tr>
    <?php if ($tax['has_tax']): ?>
      <tr class="receipt-sub"><th><?= e(t('gst')) ?> (<?= rtrim(rtrim(number_format($tax['gst_percent'], 2), '0'), '.') ?>%)</th><td>+ <?= money($tax['gst_amount']) ?></td></tr>
      <tr class="receipt-sub"><th><?= e(t('wht')) ?> (<?= rtrim(rtrim(number_format($tax['wht_percent'], 2), '0'), '.') ?>%)</th><td>− <?= money($tax['wht_amount']) ?></td></tr>
    <?php endif; ?>
    <tr class="receipt-amount"><th><?= e(t('net_payable')) ?></th><td><?= money($tax['net_payable']) ?></td></tr>
    <tr><th><?= e(t('amount_paid')) ?></th><td><?= money($bill['paid']) ?><?= $bill['paid'] >= $tax['net_payable'] && $tax['net_payable'] > 0 ? ' ✓' : '' ?></td></tr>
  </table>

  <?php if ($payments): ?>
    <h3 class="h6 mt-4 mb-2" style="font-family:'Inter',sans-serif;font-weight:700;"><?= e(t('payments_received')) ?></h3>
    <table class="table table-luxe table-sm">
      <thead><tr><th><?= e(t('date')) ?></th><th><?= e(t('method')) ?></th><th><?= e(t('reference')) ?></th><th class="text-end"><?= e(t('amount')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($payments as $p): ?>
        <tr>
          <td class="text-nowrap"><?= date('d M Y', strtotime($p['payment_date'])) ?></td>
          <td><?= e($p['method']) ?></td>
          <td><?= e($p['reference'] ?: '—') ?></td>
          <td class="text-end"><?= money($p['amount']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <div class="invoice-qr">
    <div class="qr-box" id="invoiceQr"></div>
    <div>
      <strong><?= e($invoiceNo) ?></strong>
      <div class="cell-sub"><?= e(t('invoice_qr_note')) ?></div>
    </div>
  </div>
  <p class="receipt-thanks"><?= e(t('receipt_footer')) ?></p>
</div>

<script src="assets/vendor/qrcodejs/qrcode.min.js"></script>
<script>
  if (window.QRCode) {
    new QRCode(document.getElementById('invoiceQr'), {
      text: <?= json_encode($qrText) ?>, width: 116, height: 116, correctLevel: QRCode.CorrectLevel.M
    });
  }
</script>
</body>
</html>
