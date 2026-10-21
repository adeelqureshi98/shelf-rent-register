<?php
/**
 * Supplier Portal — the ONLY surface a 'supplier' login can
 * see. One supplier account is linked to exactly one agreement
 * (users.company_id); every query on this page is scoped to
 * that agreement, and layout.php bounces supplier sessions
 * away from every staff page. Contract download goes through
 * portal_download.php, which re-checks the same scope.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/billing.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php'; // theme helpers only (layoutHead is NOT used here)

requireLogin();
$u = currentUser();
if (($u['role'] ?? '') !== 'supplier') {
    header('Location: index.php');
    exit;
}
$pdo = db();
$companyId = !empty($u['company_id']) ? (int)$u['company_id'] : 0;

$ag = null;
if ($companyId > 0) {
    $st = $pdo->prepare('SELECT * FROM agreements WHERE id = :id');
    $st->execute([':id' => $companyId]);
    $ag = $st->fetch() ?: null;
}

/* ---------------- slip upload ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_slip') {
    csrf_check();
    if (!$ag) {
        flash('error', 'Your account is not linked to a company yet. Please contact the mart office.');
    } else {
        $f = $_FILES['slip'] ?? null;
        $amount  = ($_POST['amount'] ?? '') !== '' ? (float)$_POST['amount'] : null;
        $slipDate = ($_POST['slip_date'] ?? '') !== '' ? $_POST['slip_date'] : null;
        $note    = trim($_POST['note'] ?? '');
        $err = null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK) {
            $err = 'Please choose a payment slip file to upload.';
        } else {
            $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'pdf'], true) || $f['size'] > 5 * 1024 * 1024) {
                $err = 'Slip must be a JPG, PNG or PDF file up to 5 MB.';
            }
        }
        if ($err) {
            flash('error', $err);
        } else {
            $dir = __DIR__ . '/uploads/slips';
            if (!is_dir($dir)) mkdir($dir, 0775, true);
            $name = 'slip_' . $companyId . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            if (move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) {
                $pdo->prepare('INSERT INTO payment_slips (agreement_id, uploaded_by, file_name, amount_claimed, slip_date, note)
                               VALUES (:a, :u, :f, :amt, :d, :n)')
                    ->execute([':a' => $companyId, ':u' => $u['id'], ':f' => $name,
                               ':amt' => $amount, ':d' => $slipDate, ':n' => $note ?: null]);
                audit($pdo, 'slip_upload', 'payment_slip', (int)$pdo->lastInsertId(),
                    $ag['supplier_name'] . ' uploaded a payment slip (' . $f['name'] . ').');
                flash('success', 'Payment slip uploaded — it is pending review by the mart office.');
            } else {
                flash('error', 'The upload failed. Please try again.');
            }
        }
    }
    header('Location: portal.php');
    exit;
}

/* ---------------- data (everything scoped) ---------------- */
$bills = [];
$tot = ['expected' => 0.0, 'received' => 0.0, 'overdue' => 0.0, 'balance' => 0.0];
$slips = [];
if ($ag) {
    syncBills($pdo);
    foreach (loadBills($pdo) as $b) {
        if ((int)$b['agreement_id'] !== $companyId) continue;
        $bills[] = $b;
        if ($b['expected_amount'] !== null) {
            $tot['expected'] += (float)$b['expected_amount'];
            $tot['received'] += min($b['paid'], (float)$b['expected_amount']);
            $bal = statementBalance($b);
            $tot['balance'] += $bal;
            if ($b['status'] === 'overdue') $tot['overdue'] += $bal;
        }
    }
    usort($bills, fn($a, $b) => strcmp($a['bill_month'], $b['bill_month']));
    $slips = loadSlips($pdo, $companyId);
}

$lang  = appLang();
$theme = currentTheme();
$slipBadge = ['Pending' => 'pill-pending', 'Verified' => 'pill-received', 'Rejected' => 'pill-overdue'];
?>
<!DOCTYPE html>
<html lang="<?= e($lang) ?>" dir="<?= $lang === 'ur' ? 'rtl' : 'ltr' ?>" data-theme="<?= e($theme) ?>" data-bs-theme="<?= e($theme) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(t('supplier_portal')) ?> · <?= e(APP_NAME) ?></title>
<?php themeInitScript(); ?>
<link href="assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
<link href="assets/vendor/bootstrap-icons/bootstrap-icons.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500;600;700&family=Inter:wght@400;500;600;700&family=Noto+Nastaliq+Urdu:wght@400;600;700&display=swap" rel="stylesheet">
<link href="assets/css/app.css" rel="stylesheet">
</head>
<body>
<div class="shell">
  <aside class="sidebar">
    <div class="brand">
      <div class="brand-mark"><i class="bi bi-gem"></i></div>
      <div>
        <div class="brand-name"><?= e(cfg('mart_name')) ?></div>
        <div class="brand-sub">Shelf Rent Register</div>
      </div>
    </div>
    <nav class="side-nav">
      <a class="side-link active" href="portal.php"><i class="bi bi-grid-1x2-fill"></i><span><?= e(t('supplier_portal')) ?></span></a>
    </nav>
    <button type="button" class="theme-toggle no-print" data-theme-toggle
            data-dark-label="<?= e(t('dark_mode')) ?>" data-light-label="<?= e(t('light_mode')) ?>">
      <i class="bi <?= $theme === 'dark' ? 'bi-sun' : 'bi-moon-stars' ?>"></i><span data-theme-label><?= e($theme === 'dark' ? t('light_mode') : t('dark_mode')) ?></span>
    </button>
    <div class="lang-switch no-print">
      <a href="<?= e(langUrl('en')) ?>" class="<?= $lang === 'en' ? 'active' : '' ?>">EN</a>
      <span class="lang-sep">|</span>
      <a href="<?= e(langUrl('ur')) ?>" class="<?= $lang === 'ur' ? 'active' : '' ?>">اردو</a>
    </div>
    <div class="side-user">
      <div class="avatar"><?= e(strtoupper(substr($u['name'], 0, 1))) ?></div>
      <div class="flex-grow-1">
        <div class="side-user-name"><?= e($u['name']) ?></div>
        <div class="side-user-role"><?= e(t('role_supplier')) ?></div>
      </div>
      <a class="logout" href="logout.php" title="<?= e(t('sign_out')) ?>"><i class="bi bi-box-arrow-right"></i></a>
    </div>
  </aside>
  <main class="content">
    <div class="luxe-toasts" aria-live="polite">
      <?php foreach (takeFlashes() as $f): ?>
        <div class="luxe-toast <?= $f['type'] === 'error' ? 'error' : 'success' ?>" role="alert">
          <i class="bi <?= $f['type'] === 'error' ? 'bi-exclamation-octagon' : 'bi-check-circle' ?>"></i>
          <div><?= e($f['msg']) ?></div>
        </div>
      <?php endforeach; ?>
    </div>

<?php pageTitle(t('supplier_portal'), $ag ? $ag['supplier_name'] . ' · ' . $ag['ledger_code'] : ''); ?>

<?php if (!$ag): ?>
    <div class="panel"><p class="empty-note mb-0">Your account is not linked to a company yet. Please contact the mart office.</p></div>
<?php else: ?>

<div class="row g-3 mb-3">
  <div class="col-md-3"><div class="stat-card">
    <div class="stat-icon gold"><i class="bi bi-wallet2"></i></div>
    <div class="stat-label"><?= e(t('total_expected')) ?></div>
    <div class="stat-value" data-countup="<?= (int)$tot['expected'] ?>" data-prefix="<?= e(cfg('currency')) ?> "><?= money($tot['expected']) ?></div>
  </div></div>
  <div class="col-md-3"><div class="stat-card">
    <div class="stat-icon green"><i class="bi bi-check2-circle"></i></div>
    <div class="stat-label"><?= e(t('total_received')) ?></div>
    <div class="stat-value" data-countup="<?= (int)$tot['received'] ?>" data-prefix="<?= e(cfg('currency')) ?> "><?= money($tot['received']) ?></div>
  </div></div>
  <div class="col-md-3"><div class="stat-card danger">
    <div class="stat-icon red"><i class="bi bi-alarm"></i></div>
    <div class="stat-label"><?= e(t('total_overdue')) ?></div>
    <div class="stat-value" data-countup="<?= (int)$tot['overdue'] ?>" data-prefix="<?= e(cfg('currency')) ?> "><?= money($tot['overdue']) ?></div>
  </div></div>
  <div class="col-md-3"><div class="stat-card">
    <div class="stat-icon plum"><i class="bi bi-scale"></i></div>
    <div class="stat-label"><?= e(t('statement_balance')) ?></div>
    <div class="stat-value" data-countup="<?= (int)$tot['balance'] ?>" data-prefix="<?= e(cfg('currency')) ?> "><?= money($tot['balance']) ?></div>
  </div></div>
</div>

<div class="panel mb-3">
  <div class="panel-head"><h2><i class="bi bi-receipt me-2"></i><?= e(t('my_statement')) ?></h2></div>
  <div class="table-responsive">
    <table class="table table-luxe align-middle mb-0">
      <thead><tr><th><?= e(t('period')) ?></th><th><?= e(t('status')) ?></th><th class="text-end"><?= e(t('amount')) ?></th><th class="text-end"><?= e(t('paid')) ?></th><th class="text-end"><?= e(t('balance')) ?></th><th class="text-end"><?= e(t('documents')) ?></th></tr></thead>
      <tbody>
      <?php if (!$bills): ?><tr><td colspan="6" class="text-center py-4 text-muted"><?= e(t('no_bills_agreement')) ?></td></tr><?php endif; ?>
      <?php foreach ($bills as $b): ?>
        <tr>
          <td class="text-nowrap"><strong><?= e(monthLabel($b['bill_month'])) ?></strong></td>
          <td><span class="pill pill-<?= $b['status'] ?>"><?= statusLabel($b['status']) ?></span></td>
          <td class="text-end"><?= $b['expected_amount'] === null ? '—' : money($b['expected_amount']) ?></td>
          <td class="text-end"><?= money($b['paid']) ?></td>
          <td class="text-end"><?= money(statementBalance($b)) ?></td>
          <td class="text-end text-nowrap">
            <?php if ($b['expected_amount'] !== null): ?>
              <a class="btn btn-sm btn-light border" href="invoice.php?bill=<?= (int)$b['id'] ?>"><?= e(t('invoice')) ?></a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="row g-3">
  <div class="col-xl-6">
    <div class="panel h-100">
      <h2 class="panel-title"><i class="bi bi-file-earmark-text me-2"></i><?= e(t('my_agreements')) ?></h2>
      <table class="receipt-table">
        <tr><th><?= e(t('agreement')) ?></th><td><?= e($ag['ledger_code']) ?> · <?= $ag['rent_type'] === 'percentage' ? pct($ag['percentage_rate']) . ' ' . e(t('of_sales')) : money($ag['rent_amount']) . ' / ' . e(freqLabel($ag['billing_frequency'])) ?></td></tr>
        <tr><th><?= e(t('period')) ?></th><td><?= date('d M Y', strtotime($ag['period_from'])) ?> → <?= date('d M Y', strtotime($ag['period_to'])) ?></td></tr>
        <tr><th><?= e(t('frequency')) ?></th><td><?= e(freqLabel($ag['billing_frequency'])) ?></td></tr>
      </table>
      <?php if (!empty($ag['scan_file'])): ?>
        <a class="btn btn-outline-gold mt-3" href="portal_download.php"><i class="bi bi-file-earmark-arrow-down me-1"></i> <?= e(t('download_contract')) ?></a>
      <?php else: ?>
        <p class="cell-sub mt-3 mb-0"><?= e(t('no_contract_scan')) ?></p>
      <?php endif; ?>
    </div>
  </div>
  <div class="col-xl-6">
    <div class="panel h-100">
      <h2 class="panel-title"><i class="bi bi-cloud-arrow-up me-2"></i><?= e(t('upload_slip')) ?></h2>
      <form method="post" enctype="multipart/form-data" class="row g-2">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="upload_slip">
        <div class="col-md-6">
          <label class="form-label"><?= e(t('slip_date')) ?></label>
          <input type="date" class="form-control" name="slip_date">
        </div>
        <div class="col-md-6">
          <label class="form-label"><?= e(t('amount_claimed')) ?></label>
          <input type="number" step="0.01" min="0" class="form-control" name="amount">
        </div>
        <div class="col-12">
          <label class="form-label"><?= e(t('slip_file')) ?> *</label>
          <input type="file" class="form-control" name="slip" accept=".jpg,.jpeg,.png,.pdf" required>
        </div>
        <div class="col-12">
          <label class="form-label"><?= e(t('note_optional')) ?></label>
          <input class="form-control" name="note">
        </div>
        <div class="col-12">
          <button class="btn btn-gold" type="submit"><i class="bi bi-upload me-1"></i> <?= e(t('upload')) ?></button>
        </div>
      </form>
      <?php if ($slips): ?>
        <h3 class="panel-title mt-4"><?= e(t('my_slips')) ?></h3>
        <ul class="activity-list">
          <?php foreach ($slips as $s): ?>
            <li>
              <span class="flex-grow-1"><?= e(t('payment_slips')) ?> · <?= date('d M Y', strtotime($s['slip_date'] ?? $s['created_at'])) ?>
                <span class="cell-sub">· <?= e(t('upload')) ?> <?= date('d M Y', strtotime($s['created_at'])) ?><?= $s['amount_claimed'] !== null ? ' · ' . money($s['amount_claimed']) : '' ?></span></span>
              <span class="pill <?= $slipBadge[$s['status']] ?? 'pill-pending' ?>"><?= e(slipStatusLabel($s['status'])) ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php endif; ?>

  </main>
</div>
<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script src="assets/js/app.js"></script>
</body>
</html>
