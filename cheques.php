<?php
/**
 * Cheque (PDC) Register — post-dated cheques received from
 * suppliers: what is sitting in the drawer, when it falls due,
 * and what cleared / bounced / was returned. Status changes are
 * written to the audit log (a bounce is exactly the kind of
 * event the trail exists for).
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/billing.php';
require_once __DIR__ . '/layout.php';

requireLogin();
$pdo = db();
$write = canWrite();

/* ---------------- writes ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        requireRole(['admin', 'accountant']);
        $id       = (int)($_POST['id'] ?? 0);
        $agId     = (int)($_POST['agreement_id'] ?? 0);
        $chequeNo = trim($_POST['cheque_no'] ?? '');
        $bank     = trim($_POST['bank'] ?? '');
        $date     = $_POST['cheque_date'] ?? '';
        $amount   = (float)($_POST['amount'] ?? 0);
        $status   = $_POST['status'] ?? 'Pending';
        $cleared  = ($_POST['cleared_date'] ?? '') !== '' ? $_POST['cleared_date'] : null;
        $payId    = ($_POST['payment_id'] ?? '') !== '' ? (int)$_POST['payment_id'] : null;
        $notes    = trim($_POST['notes'] ?? '');
        $errors = [];
        if ($agId <= 0) $errors[] = 'Please choose a company.';
        if ($chequeNo === '') $errors[] = 'Cheque number is required.';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $errors[] = 'Please choose the cheque date.';
        if ($amount <= 0) $errors[] = 'Amount must be greater than zero.';
        if (!in_array($status, ['Pending', 'Cleared', 'Bounced', 'Returned'], true)) $errors[] = 'Please choose a valid status.';
        if ($status === 'Cleared' && $cleared === null) $cleared = date('Y-m-d');
        if ($status !== 'Cleared') $cleared = null;
        if ($errors) {
            flash('error', implode(' ', $errors));
        } else {
            $data = [':ag' => $agId, ':pay' => $payId, ':no' => $chequeNo, ':bank' => $bank ?: null,
                     ':date' => $date, ':amt' => $amount, ':st' => $status, ':clr' => $cleared, ':notes' => $notes ?: null];
            if ($id > 0) {
                $data[':id'] = $id;
                $pdo->prepare('UPDATE cheques SET agreement_id=:ag, payment_id=:pay, cheque_no=:no, bank=:bank,
                                      cheque_date=:date, amount=:amt, status=:st, cleared_date=:clr, notes=:notes
                                WHERE id=:id')->execute($data);
                audit($pdo, 'update', 'cheque', $id, 'Updated cheque ' . $chequeNo . ' (' . money($amount) . '), status ' . $status . '.');
                flash('success', 'Cheque updated.');
            } else {
                $data[':by'] = currentUser()['id'];
                $pdo->prepare('INSERT INTO cheques (agreement_id, payment_id, cheque_no, bank, cheque_date, amount, status, cleared_date, notes, created_by)
                               VALUES (:ag, :pay, :no, :bank, :date, :amt, :st, :clr, :notes, :by)')->execute($data);
                $id = (int)$pdo->lastInsertId();
                audit($pdo, 'create', 'cheque', $id, 'Recorded cheque ' . $chequeNo . ' (' . money($amount) . ') from agreement #' . $agId . '.');
                flash('success', 'Cheque recorded.');
            }
        }
        header('Location: cheques.php');
        exit;
    }

    if ($action === 'status') {
        requireRole(['admin', 'accountant']);
        $id     = (int)($_POST['id'] ?? 0);
        $status = $_POST['status'] ?? '';
        if ($id > 0 && in_array($status, ['Pending', 'Cleared', 'Bounced', 'Returned'], true)) {
            $old = $pdo->prepare('SELECT cheque_no, amount, status FROM cheques WHERE id = :id');
            $old->execute([':id' => $id]);
            $row = $old->fetch();
            $pdo->prepare('UPDATE cheques SET status = :s, cleared_date = :c WHERE id = :id')
                ->execute([':s' => $status, ':c' => $status === 'Cleared' ? date('Y-m-d') : null, ':id' => $id]);
            if ($row) {
                audit($pdo, 'cheque_' . strtolower($status), 'cheque', $id,
                    'Cheque ' . $row['cheque_no'] . ' (' . money($row['amount']) . ') moved ' . $row['status'] . ' → ' . $status . '.');
            }
            flash('success', 'Cheque status updated.');
        }
        header('Location: cheques.php');
        exit;
    }

    if ($action === 'delete') {
        requireRole(['admin']);
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare('DELETE FROM cheques WHERE id = :id')->execute([':id' => $id]);
        audit($pdo, 'delete', 'cheque', $id, 'Deleted cheque #' . $id . '.');
        flash('success', 'Cheque deleted.');
        header('Location: cheques.php');
        exit;
    }
}

/* ---------------- reads ---------------- */
$statusF = $_GET['status'] ?? '';
$q       = trim($_GET['q'] ?? '');
$rows    = loadCheques($pdo, $statusF !== '' ? $statusF : null);
if ($q !== '') {
    $rows = array_values(array_filter($rows, fn($r) =>
        stripos($r['supplier_name'], $q) !== false || stripos($r['cheque_no'], $q) !== false || stripos($r['ledger_code'], $q) !== false));
}
[$page, $pages, $offset] = paginate(count($rows), 20);
$pageRows = array_slice($rows, $offset, 20);

$today = date('Y-m-d');
$week  = date('Y-m-d', strtotime('+7 days'));
$dueWeek = array_values(array_filter(loadCheques($pdo, 'Pending'), fn($c) => $c['cheque_date'] <= $week));

$agreements = $pdo->query('SELECT id, supplier_name, ledger_code FROM agreements ORDER BY supplier_name')->fetchAll();
$payments = $pdo->query(
    'SELECT p.id, p.amount, p.payment_date, a.supplier_name, b.bill_month
       FROM payments p JOIN bills b ON b.id = p.bill_id JOIN agreements a ON a.id = b.agreement_id
      ORDER BY p.payment_date DESC LIMIT 200'
)->fetchAll();

$edit = null;
if (isset($_GET['edit']) && $write) {
    $st = $pdo->prepare('SELECT * FROM cheques WHERE id = :id');
    $st->execute([':id' => (int)$_GET['edit']]);
    $edit = $st->fetch() ?: null;
}
$pillFor = ['Pending' => 'pill-pending', 'Cleared' => 'pill-received', 'Bounced' => 'pill-overdue', 'Returned' => 'pill-partial'];

layoutHead(t('cheques'), 'cheques');
pageTitle(t('cheques'), t('cheques_subtitle'));
?>

<div class="panel mb-3 alert-panel">
  <div class="panel-head"><h2><i class="bi bi-alarm me-2"></i><?= e(t('due_this_week')) ?></h2></div>
  <?php if (!$dueWeek): ?>
    <p class="empty-note"><?= e(t('no_cheques_week')) ?></p>
  <?php else: ?>
    <div class="table-responsive">
      <table class="table table-luxe align-middle mb-0">
        <thead><tr><th><?= e(t('company')) ?></th><th><?= e(t('cheque_no')) ?></th><th><?= e(t('cheque_date')) ?></th><th class="text-end"><?= e(t('amount')) ?></th><th><?= e(t('days')) ?></th></tr></thead>
        <tbody>
        <?php foreach ($dueWeek as $c): $days = (int)round((strtotime($c['cheque_date']) - strtotime($today)) / 86400); ?>
          <tr>
            <td><a href="company.php?id=<?= (int)$c['agreement_id'] ?>"><?= e($c['supplier_name']) ?></a></td>
            <td><?= e($c['cheque_no']) ?><?= $c['bank'] ? ' <span class="cell-sub">· ' . e($c['bank']) . '</span>' : '' ?></td>
            <td class="text-nowrap"><?= date('d M Y', strtotime($c['cheque_date'])) ?></td>
            <td class="text-end"><?= money($c['amount']) ?></td>
            <td><span class="pill <?= $days < 0 ? 'pill-overdue' : 'pill-partial' ?>"><?= $days < 0 ? e(sprintf(t('days_late'), abs($days))) : ($days === 0 ? e(t('due_today')) : e(sprintf(t('days_left_paren'), $days))) ?></span></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
  <form class="filter-bar m-0 flex-grow-1" method="get">
    <div class="input-group filter-search">
      <span class="input-group-text"><i class="bi bi-search"></i></span>
      <input class="form-control" name="q" value="<?= e($q) ?>" placeholder="<?= e(t('search_company_placeholder')) ?>">
    </div>
    <select class="form-select filter-status" name="status">
      <option value=""><?= e(t('all_statuses')) ?></option>
      <?php foreach (['Pending', 'Cleared', 'Bounced', 'Returned'] as $s): ?>
        <option value="<?= $s ?>" <?= $statusF === $s ? 'selected' : '' ?>><?= e(chequeStatusLabel($s)) ?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn btn-gold" type="submit"><?= e(t('filter')) ?></button>
  </form>
</div>

<div class="row g-3">
  <div class="<?= $write ? 'col-xl-8' : 'col-12' ?>">
    <div class="panel p-0 overflow-hidden">
      <div class="table-responsive">
        <table class="table table-luxe align-middle mb-0">
          <thead><tr><th><?= e(t('company')) ?></th><th><?= e(t('cheque_no')) ?></th><th><?= e(t('cheque_date')) ?></th><th class="text-end"><?= e(t('amount')) ?></th><th><?= e(t('status')) ?></th><th class="text-end"><?= e(t('actions')) ?></th></tr></thead>
          <tbody>
          <?php if (!$pageRows): ?>
            <tr><td colspan="6" class="text-center py-4 text-muted"><?= e(t('no_cheques')) ?></td></tr>
          <?php endif; ?>
          <?php foreach ($pageRows as $c): ?>
            <tr>
              <td><a href="company.php?id=<?= (int)$c['agreement_id'] ?>"><?= e($c['supplier_name']) ?></a>
                  <div class="cell-sub"><?= e($c['ledger_code']) ?><?= $c['payment_id'] ? ' · ' . e(t('linked_payment')) . ' #' . (int)$c['payment_id'] : '' ?></div></td>
              <td><?= e($c['cheque_no']) ?><?php if ($c['bank']): ?><div class="cell-sub"><?= e($c['bank']) ?></div><?php endif; ?></td>
              <td class="text-nowrap"><?= date('d M Y', strtotime($c['cheque_date'])) ?>
                <?php if ($c['cleared_date']): ?><div class="cell-sub"><?= e(t('cleared_date')) ?>: <?= date('d M Y', strtotime($c['cleared_date'])) ?></div><?php endif; ?></td>
              <td class="text-end"><?= money($c['amount']) ?></td>
              <td><span class="pill <?= $pillFor[$c['status']] ?>"><?= e(chequeStatusLabel($c['status'])) ?></span></td>
              <td class="text-end text-nowrap">
                <?php if ($write): ?>
                  <form method="post" class="d-inline-flex gap-1 align-items-center">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="status">
                    <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                    <select class="form-select form-select-sm w-auto" name="status">
                      <?php foreach (['Pending', 'Cleared', 'Bounced', 'Returned'] as $s): ?>
                        <option value="<?= $s ?>" <?= $c['status'] === $s ? 'selected' : '' ?>><?= e(chequeStatusLabel($s)) ?></option>
                      <?php endforeach; ?>
                    </select>
                    <button class="btn btn-sm btn-light border" type="submit"><?= e(t('save')) ?></button>
                  </form>
                  <a class="btn btn-sm btn-light border" href="cheques.php?edit=<?= (int)$c['id'] ?>"><?= e(t('edit')) ?></a>
                <?php endif; ?>
                <?php if (isAdmin()): ?>
                  <form method="post" class="d-inline" onsubmit="return confirm('Delete this cheque record?');">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
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
    <?php pageNav($page, $pages, 'cheques.php'); ?>
  </div>

  <?php if ($write): ?>
  <div class="col-xl-4">
    <div class="panel">
      <h2 class="panel-title"><i class="bi bi-plus-circle me-2"></i><?= $edit ? e(t('edit_cheque')) : e(t('add_cheque')) ?></h2>
      <form method="post" class="row g-2">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
        <div class="col-12">
          <label class="form-label"><?= e(t('company')) ?> *</label>
          <select class="form-select" name="agreement_id" required>
            <option value="">—</option>
            <?php foreach ($agreements as $ag): ?>
              <option value="<?= (int)$ag['id'] ?>" <?= (int)($edit['agreement_id'] ?? 0) === (int)$ag['id'] ? 'selected' : '' ?>><?= e($ag['supplier_name']) ?> (<?= e($ag['ledger_code']) ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-6">
          <label class="form-label"><?= e(t('cheque_no')) ?> *</label>
          <input class="form-control" name="cheque_no" required value="<?= e($edit['cheque_no'] ?? '') ?>" placeholder="CHQ-000000">
        </div>
        <div class="col-md-6">
          <label class="form-label"><?= e(t('bank')) ?></label>
          <input class="form-control" name="bank" value="<?= e($edit['bank'] ?? '') ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label"><?= e(t('cheque_date')) ?> *</label>
          <input type="date" class="form-control" name="cheque_date" required value="<?= e($edit['cheque_date'] ?? date('Y-m-d')) ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label"><?= e(t('amount')) ?> (<?= e(CURRENCY) ?>) *</label>
          <input type="number" step="0.01" min="0.01" class="form-control" name="amount" required value="<?= e($edit['amount'] ?? '') ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label"><?= e(t('status')) ?></label>
          <select class="form-select" name="status">
            <?php foreach (['Pending', 'Cleared', 'Bounced', 'Returned'] as $s): ?>
              <option value="<?= $s ?>" <?= ($edit['status'] ?? 'Pending') === $s ? 'selected' : '' ?>><?= e(chequeStatusLabel($s)) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-6">
          <label class="form-label"><?= e(t('cleared_date')) ?></label>
          <input type="date" class="form-control" name="cleared_date" value="<?= e($edit['cleared_date'] ?? '') ?>">
        </div>
        <div class="col-12">
          <label class="form-label"><?= e(t('linked_payment')) ?></label>
          <select class="form-select" name="payment_id">
            <option value="">—</option>
            <?php foreach ($payments as $p): ?>
              <option value="<?= (int)$p['id'] ?>" <?= (int)($edit['payment_id'] ?? 0) === (int)$p['id'] ? 'selected' : '' ?>>#<?= (int)$p['id'] ?> · <?= e($p['supplier_name']) ?> · <?= date('M Y', strtotime($p['bill_month'])) ?> · <?= money($p['amount']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-12">
          <label class="form-label"><?= e(t('notes')) ?></label>
          <input class="form-control" name="notes" value="<?= e($edit['notes'] ?? '') ?>">
        </div>
        <div class="col-12 d-flex gap-2">
          <button class="btn btn-gold" type="submit"><i class="bi bi-check2-circle me-1"></i> <?= e(t('save')) ?></button>
          <?php if ($edit): ?><a class="btn btn-light border" href="cheques.php"><?= e(t('cancel')) ?></a><?php endif; ?>
        </div>
      </form>
    </div>
  </div>
  <?php endif; ?>
</div>

<?php layoutFoot(); ?>
