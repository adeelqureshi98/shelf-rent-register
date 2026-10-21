<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/billing.php';
require_once __DIR__ . '/layout.php';

requireLogin();
$pdo = db();

/* Delete — Admin only, enforced here on the server, not just in the UI. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    requireRole(['admin']);
    checkCsrf();
    $id = (int)($_POST['id'] ?? 0);
    $st = $pdo->prepare('SELECT scan_file, supplier_name, ledger_code FROM agreements WHERE id = :id');
    $st->execute([':id' => $id]);
    $row = $st->fetch();
    if ($row) {
        $pdo->prepare('DELETE FROM agreements WHERE id = :id')->execute([':id' => $id]);
        audit($pdo, 'delete', 'agreement', $id, 'Deleted agreement "' . $row['supplier_name'] . '" (' . $row['ledger_code'] . ') with all its bills and payments.');
        if ($row['scan_file']) {
            $path = UPLOAD_DIR . '/' . basename($row['scan_file']);
            if (is_file($path)) unlink($path);
        }
        flash('success', 'Agreement deleted (its bills and payments were removed with it).');
    }
    header('Location: agreements.php');
    exit;
}

$q      = trim($_GET['q'] ?? '');
$type   = $_GET['type'] ?? '';
$freq   = $_GET['freq'] ?? '';
$expiry = $_GET['expiry'] ?? '';

$sql  = 'SELECT * FROM agreements WHERE 1 = 1';
$args = [];
if ($q !== '') {
    $sql .= ' AND (supplier_name LIKE :q OR ledger_code LIKE :q)';
    $args[':q'] = '%' . $q . '%';
}
if ($type !== '') {
    $sql .= ' AND rent_type = :t';
    $args[':t'] = $type;
}
if ($freq !== '') {
    $sql .= ' AND billing_frequency = :f';
    $args[':f'] = $freq;
}
if ($expiry === 'soon') {
    $sql .= ' AND period_to BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ' . EXPIRY_WARN_DAYS . ' DAY)';
} elseif ($expiry === 'expired') {
    $sql .= ' AND period_to < CURDATE()';
} elseif ($expiry === 'active') {
    $sql .= ' AND period_to >= CURDATE()';
}
$sql .= ' ORDER BY supplier_name';
$st = $pdo->prepare($sql);
$st->execute($args);
$rows = $st->fetchAll();

$today = date('Y-m-d');
$limit = date('Y-m-d', strtotime('+' . EXPIRY_WARN_DAYS . ' days'));

layoutHead(t('agreements'), 'agreements');
pageTitle(t('agreements'), t('agreements_subtitle'));
?>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
  <form class="filter-bar m-0 flex-grow-1" method="get">
    <div class="input-group filter-search">
      <span class="input-group-text"><i class="bi bi-search"></i></span>
      <input class="form-control" name="q" value="<?= e($q) ?>" placeholder="<?= e(t('search_company_placeholder')) ?>">
    </div>
    <select class="form-select filter-status" name="type">
      <option value=""><?= e(t('all_types')) ?></option>
      <option value="fixed" <?= $type === 'fixed' ? 'selected' : '' ?>><?= e(t('fixed_rent')) ?></option>
      <option value="percentage" <?= $type === 'percentage' ? 'selected' : '' ?>><?= e(t('percentage')) ?></option>
    </select>
    <select class="form-select filter-status" name="freq">
      <option value=""><?= e(t('all_frequencies')) ?></option>
      <option value="monthly" <?= $freq === 'monthly' ? 'selected' : '' ?>><?= e(t('monthly')) ?></option>
      <option value="quarterly" <?= $freq === 'quarterly' ? 'selected' : '' ?>><?= e(t('quarterly')) ?></option>
      <option value="display_drive" <?= $freq === 'display_drive' ? 'selected' : '' ?>><?= e(t('display_drive')) ?></option>
    </select>
    <select class="form-select filter-status" name="expiry">
      <option value=""><?= e(t('any_period_state')) ?></option>
      <option value="active" <?= $expiry === 'active' ? 'selected' : '' ?>><?= e(t('active')) ?></option>
      <option value="soon" <?= $expiry === 'soon' ? 'selected' : '' ?>><?= e(sprintf(t('expiring_in_days_opt'), EXPIRY_WARN_DAYS)) ?></option>
      <option value="expired" <?= $expiry === 'expired' ? 'selected' : '' ?>><?= e(t('expired')) ?></option>
    </select>
    <button class="btn btn-gold" type="submit"><?= e(t('filter')) ?></button>
  </form>
  <?php if (canWrite()): ?>
    <a class="btn btn-gold" href="agreement_form.php"><i class="bi bi-plus-lg me-1"></i> <?= e(t('add_agreement')) ?></a>
  <?php endif; ?>
</div>

<div class="panel p-0 overflow-hidden">
  <div class="table-responsive">
    <table class="table table-luxe align-middle mb-0">
      <thead>
        <tr>
          <th><?= e(t('company')) ?></th><th><?= e(t('type')) ?></th><th><?= e(t('rent')) ?></th><th><?= e(t('frequency')) ?></th>
          <th><?= e(t('period')) ?></th><th><?= e(t('state')) ?></th><th><?= e(t('scan')) ?></th><th class="text-end"><?= e(t('actions')) ?></th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="8" class="text-center py-4 text-muted"><?= e(t('no_agreements_match')) ?></td></tr>
      <?php endif; ?>
      <?php foreach ($rows as $ag):
        if ($ag['period_to'] < $today)      $state = [t('expired'), 'pill-out'];
        elseif ($ag['period_to'] <= $limit) $state = [t('expiring_soon'), 'pill-overdue'];
        else                                $state = [t('active'), 'pill-received'];
      ?>
        <tr>
          <td>
            <a href="company.php?id=<?= (int)$ag['id'] ?>"><?= e($ag['supplier_name']) ?></a>
            <div class="cell-sub"><?= e($ag['ledger_code']) ?><?= $ag['contact_person'] ? ' · ' . e($ag['contact_person']) : '' ?></div>
          </td>
          <td><?= $ag['rent_type'] === 'fixed' ? e(t('fixed')) : e(t('percentage')) ?></td>
          <td class="text-nowrap"><?= $ag['rent_type'] === 'fixed' ? money($ag['rent_amount']) : e($ag['percentage_rate']) . '% ' . e(t('of_sales')) ?></td>
          <td><?= e(freqLabel($ag['billing_frequency'])) ?></td>
          <td class="text-nowrap"><span dir="ltr"><?= date('d M Y', strtotime($ag['period_from'])) ?> → <?= date('d M Y', strtotime($ag['period_to'])) ?></span></td>
          <td><span class="pill <?= $state[1] ?>"><?= $state[0] ?></span></td>
          <td>
            <?php if ($ag['scan_file']): ?>
              <a href="<?= e(UPLOAD_URL . '/' . $ag['scan_file']) ?>" target="_blank" title="View scanned agreement"><i class="bi bi-file-earmark-pdf text-danger fs-5"></i></a>
            <?php else: ?><span class="cell-sub">—</span><?php endif; ?>
          </td>
          <td class="text-end text-nowrap">
            <a class="btn btn-sm btn-light border" href="company.php?id=<?= (int)$ag['id'] ?>"><?= e(t('ledger')) ?></a>
            <?php if (canWrite()): ?>
              <a class="btn btn-sm btn-light border" href="agreement_form.php?id=<?= (int)$ag['id'] ?>"><?= e(t('edit')) ?></a>
            <?php endif; ?>
            <?php if (isAdmin()): ?>
              <form method="post" class="d-inline" onsubmit="return confirm('Delete this agreement? Its bills and payments will also be deleted. This cannot be undone.');">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int)$ag['id'] ?>">
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
