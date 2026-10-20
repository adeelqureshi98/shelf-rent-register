<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/billing.php';
require_once __DIR__ . '/layout.php';

requireRole(['admin', 'accountant']); // viewers never reach this page
$pdo = db();

$id  = (int)($_GET['id'] ?? 0);
$ag  = null;
if ($id > 0) {
    $st = $pdo->prepare('SELECT * FROM agreements WHERE id = :id');
    $st->execute([':id' => $id]);
    $ag = $st->fetch();
    if (!$ag) {
        flash('error', 'Agreement not found.');
        header('Location: agreements.php');
        exit;
    }
}

$errors = [];
$old = $ag ?? [
    'supplier_name' => '', 'ledger_code' => '', 'contact_person' => '', 'contact_phone' => '',
    'rent_type' => 'fixed', 'rent_amount' => '', 'percentage_rate' => '',
    'billing_frequency' => 'monthly', 'period_from' => '', 'period_to' => '',
    'scan_file' => null, 'notes' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $old = array_merge($old, $_POST);
    $supplier = trim($_POST['supplier_name'] ?? '');
    $ledger   = trim($_POST['ledger_code'] ?? '');
    $type     = $_POST['rent_type'] ?? 'fixed';
    $freq     = $_POST['billing_frequency'] ?? 'monthly';
    $from     = $_POST['period_from'] ?? '';
    $to       = $_POST['period_to'] ?? '';
    $amount   = $_POST['rent_amount'] !== '' ? (float)$_POST['rent_amount'] : null;
    $rate     = $_POST['percentage_rate'] !== '' ? (float)$_POST['percentage_rate'] : null;

    if ($supplier === '') $errors[] = 'Supplier name is required.';
    if ($ledger === '')   $errors[] = 'Ledger name / code is required.';
    if (!in_array($type, ['fixed', 'percentage'], true)) $errors[] = 'Please choose a rent type.';
    if ($type === 'fixed' && ($amount === null || $amount <= 0)) $errors[] = 'Fixed agreements need a rent amount greater than zero.';
    if ($type === 'percentage' && ($rate === null || $rate <= 0 || $rate > 100)) $errors[] = 'Percentage agreements need a rate between 0 and 100.';
    if (!in_array($freq, ['monthly', 'quarterly', 'display_drive'], true)) $errors[] = 'Please choose a billing frequency.';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        $errors[] = 'Please choose both period dates.';
    } elseif ($to < $from) {
        $errors[] = 'The period end date must be after the start date.';
    }

    /* Duplicate ledger code check (another agreement may not reuse it). */
    if (!$errors) {
        $dup = $pdo->prepare('SELECT COUNT(*) FROM agreements WHERE ledger_code = :l AND id <> :id');
        $dup->execute([':l' => $ledger, ':id' => $id]);
        if ($dup->fetchColumn() > 0) $errors[] = 'That ledger code is already used by another agreement.';
    }

    /* Optional scanned agreement upload. */
    $scanFile = $ag['scan_file'] ?? null;
    if (!$errors && !empty($_FILES['scan']['name'])) {
        $f = $_FILES['scan'];
        if ($f['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'The scan upload failed. Please try a smaller file.';
        } else {
            $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['pdf', 'jpg', 'jpeg', 'png'], true)) {
                $errors[] = 'The scan must be a PDF, JPG or PNG file.';
            } elseif ($f['size'] > MAX_UPLOAD_BYTES) {
                $errors[] = 'The scan is larger than 5 MB.';
            } else {
                if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0775, true);
                $scanFile = 'agreement_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                if (!move_uploaded_file($f['tmp_name'], UPLOAD_DIR . '/' . $scanFile)) {
                    $errors[] = 'Could not save the uploaded scan.';
                    $scanFile = $ag['scan_file'] ?? null;
                } elseif ($ag && $ag['scan_file'] && $ag['scan_file'] !== $scanFile) {
                    $oldPath = UPLOAD_DIR . '/' . basename($ag['scan_file']);
                    if (is_file($oldPath)) unlink($oldPath);
                }
            }
        }
    }

    if (!$errors) {
        $data = [
            ':supplier' => $supplier, ':ledger' => $ledger,
            ':cp' => trim($_POST['contact_person'] ?? '') ?: null,
            ':ph' => trim($_POST['contact_phone'] ?? '') ?: null,
            ':type' => $type,
            ':amount' => $type === 'fixed' ? $amount : null,
            ':rate' => $type === 'percentage' ? $rate : null,
            ':freq' => $freq, ':from' => $from, ':to' => $to,
            ':scan' => $scanFile,
            ':notes' => trim($_POST['notes'] ?? '') ?: null,
        ];
        if ($id > 0) {
            $data[':id'] = $id;
            $pdo->prepare(
                'UPDATE agreements SET supplier_name=:supplier, ledger_code=:ledger, contact_person=:cp,
                        contact_phone=:ph, rent_type=:type, rent_amount=:amount, percentage_rate=:rate,
                        billing_frequency=:freq, period_from=:from, period_to=:to, scan_file=:scan, notes=:notes
                  WHERE id=:id'
            )->execute($data);
            audit($pdo, 'update', 'agreement', $id, 'Updated agreement "' . $supplier . '" (' . $ledger . ').');
            flash('success', 'Agreement updated.');
        } else {
            $data[':by'] = currentUser()['id'];
            $pdo->prepare(
                'INSERT INTO agreements (supplier_name, ledger_code, contact_person, contact_phone, rent_type,
                        rent_amount, percentage_rate, billing_frequency, period_from, period_to, scan_file, notes, created_by)
                 VALUES (:supplier, :ledger, :cp, :ph, :type, :amount, :rate, :freq, :from, :to, :scan, :notes, :by)'
            )->execute($data);
            $id = (int)$pdo->lastInsertId();
            audit($pdo, 'create', 'agreement', $id, 'Added agreement "' . $supplier . '" (' . $ledger . ').');
            flash('success', 'Agreement added. Its bills have been generated in the Payment Grid.');
        }
        syncBills($pdo);
        header('Location: company.php?id=' . $id);
        exit;
    }
}

layoutHead($id ? t('edit_agreement') : t('add_agreement'), 'agreements');
pageTitle($id ? t('edit_agreement') : t('add_agreement'), t('agreement_form_subtitle'));
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
  <form method="post" enctype="multipart/form-data" class="row g-3" novalidate>
    <?= csrfField() ?>
    <div class="col-md-6">
      <label class="form-label" for="supplier_name"><?= e(t('supplier_name')) ?> *</label>
      <input class="form-control" id="supplier_name" name="supplier_name" required value="<?= e($old['supplier_name']) ?>" placeholder="e.g. Fresh Dairy Foods (Pvt) Ltd">
    </div>
    <div class="col-md-6">
      <label class="form-label" for="ledger_code"><?= e(t('ledger_name_code')) ?> *</label>
      <input class="form-control" id="ledger_code" name="ledger_code" required value="<?= e($old['ledger_code']) ?>" placeholder="e.g. SUP-DAI-001">
    </div>
    <div class="col-md-6">
      <label class="form-label" for="contact_person"><?= e(t('contact_person')) ?></label>
      <input class="form-control" id="contact_person" name="contact_person" value="<?= e($old['contact_person']) ?>">
    </div>
    <div class="col-md-6">
      <label class="form-label" for="contact_phone"><?= e(t('contact_phone')) ?></label>
      <input class="form-control" id="contact_phone" name="contact_phone" value="<?= e($old['contact_phone']) ?>" placeholder="For follow-up calls">
    </div>

    <div class="col-md-4">
      <label class="form-label" for="rent_type"><?= e(t('rent_type')) ?> *</label>
      <select class="form-select" id="rent_type" name="rent_type">
        <option value="fixed" <?= $old['rent_type'] === 'fixed' ? 'selected' : '' ?>><?= e(t('fixed_amount')) ?></option>
        <option value="percentage" <?= $old['rent_type'] === 'percentage' ? 'selected' : '' ?>><?= e(t('percentage_of_sales')) ?></option>
      </select>
    </div>
    <div class="col-md-4" id="amountWrap">
      <label class="form-label" for="rent_amount"><?= e(t('fixed_rent_amount')) ?> (<?= e(CURRENCY) ?>) *</label>
      <input type="number" step="0.01" min="0" class="form-control" id="rent_amount" name="rent_amount" value="<?= e($old['rent_amount']) ?>">
    </div>
    <div class="col-md-4 d-none" id="rateWrap">
      <label class="form-label" for="percentage_rate"><?= e(t('percentage_rate')) ?> *</label>
      <input type="number" step="0.01" min="0" max="100" class="form-control" id="percentage_rate" name="percentage_rate" value="<?= e($old['percentage_rate']) ?>">
      <div class="form-text"><?= e(t('pct_billed_note')) ?></div>
    </div>
    <div class="col-md-4">
      <label class="form-label" for="billing_frequency"><?= e(t('billing_frequency')) ?> *</label>
      <select class="form-select" id="billing_frequency" name="billing_frequency">
        <option value="monthly" <?= $old['billing_frequency'] === 'monthly' ? 'selected' : '' ?>><?= e(t('freq_monthly_desc')) ?></option>
        <option value="quarterly" <?= $old['billing_frequency'] === 'quarterly' ? 'selected' : '' ?>><?= e(t('freq_quarterly_desc')) ?></option>
        <option value="display_drive" <?= $old['billing_frequency'] === 'display_drive' ? 'selected' : '' ?>><?= e(t('freq_display_drive_desc')) ?></option>
      </select>
    </div>

    <div class="col-md-6">
      <label class="form-label" for="period_from"><?= e(t('period_from_label')) ?> *</label>
      <input type="date" class="form-control" id="period_from" name="period_from" required value="<?= e($old['period_from']) ?>">
    </div>
    <div class="col-md-6">
      <label class="form-label" for="period_to"><?= e(t('period_to_label')) ?> *</label>
      <input type="date" class="form-control" id="period_to" name="period_to" required value="<?= e($old['period_to']) ?>">
    </div>

    <div class="col-md-6">
      <label class="form-label" for="scan"><?= e(t('scan_label')) ?></label>
      <input type="file" class="form-control" id="scan" name="scan" accept=".pdf,.jpg,.jpeg,.png">
      <?php if (!empty($old['scan_file'])): ?>
        <div class="form-text"><?= e(t('current_file')) ?>: <a href="<?= e(UPLOAD_URL . '/' . $old['scan_file']) ?>" target="_blank"><?= e(t('view_scan')) ?></a> — <?= e(t('replace_scan_note')) ?></div>
      <?php endif; ?>
    </div>
    <div class="col-md-6">
      <label class="form-label" for="notes"><?= e(t('notes')) ?></label>
      <input class="form-control" id="notes" name="notes" value="<?= e($old['notes']) ?>" placeholder="Shelf location, display terms, etc.">
    </div>

    <div class="col-12 d-flex gap-2">
      <button class="btn btn-gold" type="submit"><i class="bi bi-check2-circle me-1"></i> <?= $id ? e(t('save_changes')) : e(t('add_agreement')) ?></button>
      <a class="btn btn-light border" href="agreements.php"><?= e(t('cancel')) ?></a>
    </div>
  </form>
</div>

<script>
const typeSel = document.getElementById('rent_type');
function syncType() {
  const pct = typeSel.value === 'percentage';
  document.getElementById('amountWrap').classList.toggle('d-none', pct);
  document.getElementById('rateWrap').classList.toggle('d-none', !pct);
}
typeSel.addEventListener('change', syncType);
syncType();
</script>

<?php layoutFoot(); ?>
