<?php
/**
 * Add / edit an incentive — Admin + Accountant (same role pattern
 * as agreements). A Fixed incentive must carry an expected amount;
 * a Percentage incentive carries a rate and its expected amount
 * may stay empty ("Awaited") until the actual figure is known.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/billing.php';
require_once __DIR__ . '/layout.php';

requireRole(['admin', 'accountant']); // viewers never reach this page
$pdo = db();

$id  = (int)($_GET['id'] ?? 0);
$inc = null;
if ($id > 0) {
    $st = $pdo->prepare('SELECT * FROM incentives WHERE id = :id');
    $st->execute([':id' => $id]);
    $inc = $st->fetch();
    if (!$inc) {
        flash('error', 'Incentive not found.');
        header('Location: incentives.php');
        exit;
    }
}

$agreements = $pdo->query('SELECT id, supplier_name, ledger_code FROM agreements ORDER BY supplier_name')->fetchAll();

$errors = [];
$old = $inc ?? [
    'agreement_id' => '', 'title' => '', 'type' => 'Promotion', 'basis' => 'Fixed',
    'rate' => '', 'expected_amount' => '', 'received_amount' => '0',
    'expected_date' => '', 'received_date' => '', 'notes' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $old = array_merge($old, $_POST);
    $agId       = (int)($_POST['agreement_id'] ?? 0);
    $title      = trim($_POST['title'] ?? '');
    $type       = $_POST['type'] ?? 'Promotion';
    $basis      = $_POST['basis'] ?? 'Fixed';
    $rate       = ($_POST['rate'] ?? '') !== '' ? (float)$_POST['rate'] : null;
    $expected   = ($_POST['expected_amount'] ?? '') !== '' ? (float)$_POST['expected_amount'] : null;
    $received   = ($_POST['received_amount'] ?? '') !== '' ? (float)$_POST['received_amount'] : 0.0;
    $expDate    = $_POST['expected_date'] ?? '';
    $recDate    = ($_POST['received_date'] ?? '') !== '' ? $_POST['received_date'] : null;
    $notes      = trim($_POST['notes'] ?? '');

    if ($agId <= 0) $errors[] = 'Please choose the company this incentive belongs to.';
    if ($title === '') $errors[] = 'Incentive title is required.';
    if (!in_array($type, ['Promotion', 'Display', 'Launch', 'Target', 'Other'], true)) $errors[] = 'Please choose a valid incentive type.';
    if (!in_array($basis, ['Fixed', 'Percentage'], true)) $errors[] = 'Please choose a valid basis.';
    if ($basis === 'Fixed' && ($expected === null || $expected <= 0)) $errors[] = 'Fixed incentives need an expected amount greater than zero.';
    if ($basis === 'Percentage' && ($rate === null || $rate <= 0 || $rate > 100)) $errors[] = 'Percentage incentives need a rate between 0 and 100.';
    if ($basis === 'Fixed') $rate = null;
    if ($expected !== null && $expected <= 0) $errors[] = 'The expected amount must be greater than zero.';
    if ($received < 0) $errors[] = 'The received amount cannot be negative.';
    if ($expected !== null && $received > $expected + 0.005) $errors[] = 'The received amount cannot be more than the expected amount.';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $expDate)) $errors[] = 'Please choose the date this money is expected.';
    if ($recDate !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $recDate)) $errors[] = 'The received date is not valid.';
    if (!$errors && $agId > 0) {
        $chk = $pdo->prepare('SELECT COUNT(*) FROM agreements WHERE id = :id');
        $chk->execute([':id' => $agId]);
        if ($chk->fetchColumn() == 0) $errors[] = 'Please choose the company this incentive belongs to.';
    }

    if (!$errors) {
        /* Fully received but no date given: stamp today, so the record is complete. */
        if ($expected !== null && $received >= $expected - 0.005 && $recDate === null) {
            $recDate = date('Y-m-d');
        }
        $data = [
            ':ag' => $agId, ':title' => $title, ':type' => $type, ':basis' => $basis,
            ':rate' => $basis === 'Percentage' ? $rate : null,
            ':exp' => $expected, ':rec' => $received,
            ':expd' => $expDate, ':recd' => $recDate,
            ':notes' => $notes !== '' ? $notes : null,
        ];
        if ($id > 0) {
            $data[':id'] = $id;
            $pdo->prepare(
                'UPDATE incentives SET agreement_id=:ag, title=:title, type=:type, basis=:basis, rate=:rate,
                        expected_amount=:exp, received_amount=:rec, expected_date=:expd, received_date=:recd, notes=:notes
                  WHERE id=:id'
            )->execute($data);
            audit($pdo, 'update', 'incentive', $id, 'Updated incentive "' . $title . '".');
            flash('success', 'Incentive updated.');
        } else {
            $data[':by'] = currentUser()['id'];
            $pdo->prepare(
                'INSERT INTO incentives (agreement_id, title, type, basis, rate, expected_amount, received_amount, expected_date, received_date, notes, created_by)
                 VALUES (:ag, :title, :type, :basis, :rate, :exp, :rec, :expd, :recd, :notes, :by)'
            )->execute($data);
            $newId = (int)$pdo->lastInsertId();
            audit($pdo, 'create', 'incentive', $newId, 'Added incentive "' . $title . '"' . ($expected !== null ? ' — expected ' . money($expected) : ' (percentage @ ' . $rate . '%, amount awaited)') . '.');
            flash('success', 'Incentive added.');
        }
        header('Location: incentives.php');
        exit;
    }
}

layoutHead($id ? t('edit_incentive') : t('add_incentive'), 'incentives');
pageTitle($id ? t('edit_incentive') : t('add_incentive'), t('incentive_form_subtitle'));
?>

<?php if ($errors): ?>
  <div class="alert alert-danger shadow-sm">
    <strong><?= e(t('please_fix')) ?></strong>
    <ul class="mb-0 mt-1">
      <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<div class="panel">
  <form method="post" class="row g-3" novalidate>
    <?= csrfField() ?>
    <div class="col-md-6">
      <label class="form-label" for="agreement_id"><?= e(t('company')) ?> *</label>
      <select class="form-select" id="agreement_id" name="agreement_id" required>
        <option value=""><?= e(t('choose_company')) ?></option>
        <?php foreach ($agreements as $ag): ?>
          <option value="<?= (int)$ag['id'] ?>" <?= (string)$old['agreement_id'] === (string)$ag['id'] ? 'selected' : '' ?>><?= e($ag['supplier_name']) ?> (<?= e($ag['ledger_code']) ?>)</option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-6">
      <label class="form-label" for="title"><?= e(t('incentive_title')) ?> *</label>
      <input class="form-control" id="title" name="title" required value="<?= e($old['title']) ?>" placeholder="e.g. Diwali Promotion Incentive">
    </div>

    <div class="col-md-4">
      <label class="form-label" for="type"><?= e(t('type')) ?> *</label>
      <select class="form-select" id="type" name="type">
        <?php foreach (['Promotion', 'Display', 'Launch', 'Target', 'Other'] as $tp): ?>
          <option value="<?= $tp ?>" <?= $old['type'] === $tp ? 'selected' : '' ?>><?= $tp ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-4">
      <label class="form-label" for="basis"><?= e(t('basis')) ?> *</label>
      <select class="form-select" id="basis" name="basis">
        <option value="Fixed" <?= $old['basis'] === 'Fixed' ? 'selected' : '' ?>><?= e(t('fixed_amount')) ?></option>
        <option value="Percentage" <?= $old['basis'] === 'Percentage' ? 'selected' : '' ?>><?= e(t('percentage_of_sales')) ?></option>
      </select>
    </div>
    <div class="col-md-4" id="rateWrap">
      <label class="form-label" for="rate"><?= e(t('percentage_rate')) ?> *</label>
      <input type="number" step="0.01" min="0" max="100" class="form-control" id="rate" name="rate" value="<?= e($old['rate']) ?>">
      <div class="form-text"><?= e(t('rate_awaited_note')) ?></div>
    </div>

    <div class="col-md-4">
      <label class="form-label" for="expected_amount"><?= e(t('expected_amount')) ?> (<?= e(CURRENCY) ?>)</label>
      <input type="number" step="0.01" min="0" class="form-control" id="expected_amount" name="expected_amount" value="<?= e($old['expected_amount']) ?>" placeholder="Required for Fixed">
    </div>
    <div class="col-md-4">
      <label class="form-label" for="received_amount"><?= e(t('received_so_far')) ?> (<?= e(CURRENCY) ?>)</label>
      <input type="number" step="0.01" min="0" class="form-control" id="received_amount" name="received_amount" value="<?= e($old['received_amount']) ?>">
    </div>
    <div class="col-md-4">
      <label class="form-label" for="expected_date"><?= e(t('expected_date')) ?> *</label>
      <input type="date" class="form-control" id="expected_date" name="expected_date" required value="<?= e($old['expected_date']) ?>">
    </div>

    <div class="col-md-4">
      <label class="form-label" for="received_date"><?= e(t('received_date_label')) ?></label>
      <input type="date" class="form-control" id="received_date" name="received_date" value="<?= e($old['received_date']) ?>">
    </div>
    <div class="col-md-8">
      <label class="form-label" for="notes"><?= e(t('notes')) ?></label>
      <input class="form-control" id="notes" name="notes" value="<?= e($old['notes']) ?>" placeholder="Terms, promises, references">
    </div>

    <div class="col-12 d-flex gap-2">
      <button class="btn btn-gold" type="submit"><i class="bi bi-check2-circle me-1"></i> <?= $id ? e(t('save_changes')) : e(t('add_incentive')) ?></button>
      <a class="btn btn-light border" href="incentives.php"><?= e(t('cancel')) ?></a>
    </div>
  </form>
</div>

<script>
const basisSel = document.getElementById('basis');
function syncBasis() {
  document.getElementById('rateWrap').classList.toggle('d-none', basisSel.value !== 'Percentage');
}
basisSel.addEventListener('change', syncBasis);
syncBasis();
</script>

<?php layoutFoot(); ?>
