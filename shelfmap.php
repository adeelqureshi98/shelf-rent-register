<?php
/**
 * Shelf Map — a visual floor plan of the mart's rented
 * positions. Green/gold tile = an active agreement sits
 * there; grey dashed tile = vacant; red ring = the
 * agreement expires within 30 days. Admin manages the
 * shelf positions themselves (a shelf can only be deleted
 * while no agreement points at it).
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/billing.php';
require_once __DIR__ . '/layout.php';

requireLogin();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    requireRole(['admin']);
    $action = $_POST['action'] ?? '';
    if ($action === 'save') {
        $id    = (int)($_POST['id'] ?? 0);
        $label = trim($_POST['label'] ?? '');
        $zone  = trim($_POST['zone'] ?? '');
        $sort  = (int)($_POST['sort_order'] ?? 0);
        if ($label === '') {
            flash('error', 'Shelf label is required.');
        } elseif ($id > 0) {
            $pdo->prepare('UPDATE shelves SET label = :l, zone = :z, sort_order = :s WHERE id = :id')
                ->execute([':l' => $label, ':z' => $zone ?: null, ':s' => $sort, ':id' => $id]);
            audit($pdo, 'update', 'shelf', $id, 'Updated shelf "' . $label . '".');
            flash('success', 'Shelf updated.');
        } else {
            $pdo->prepare('INSERT INTO shelves (label, zone, sort_order) VALUES (:l, :z, :s)')
                ->execute([':l' => $label, ':z' => $zone ?: null, ':s' => $sort]);
            audit($pdo, 'create', 'shelf', (int)$pdo->lastInsertId(), 'Added shelf "' . $label . '".');
            flash('success', 'Shelf added.');
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $used = (int)$pdo->query('SELECT COUNT(*) FROM agreements WHERE shelf_id = ' . $id)->fetchColumn();
        if ($used > 0) {
            flash('error', t('shelf_in_use'));
        } else {
            $pdo->prepare('DELETE FROM shelves WHERE id = :id')->execute([':id' => $id]);
            audit($pdo, 'delete', 'shelf', $id, 'Deleted shelf #' . $id . '.');
            flash('success', 'Shelf deleted.');
        }
    }
    header('Location: shelfmap.php');
    exit;
}

$shelves  = loadShelves($pdo);
$byShelf  = [];
$unassigned = [];
$today = date('Y-m-d');
foreach ($pdo->query('SELECT * FROM agreements ORDER BY supplier_name')->fetchAll() as $ag) {
    $active = $ag['period_from'] <= $today && $ag['period_to'] >= $today;
    if (!empty($ag['shelf_id'])) {
        $byShelf[(int)$ag['shelf_id']] = ['ag' => $ag, 'active' => $active];
    } elseif ($active) {
        $unassigned[] = $ag;
    }
}
$editShelf = null;
if (isAdmin() && isset($_GET['edit'])) {
    foreach ($shelves as $sh) if ((int)$sh['id'] === (int)$_GET['edit']) $editShelf = $sh;
}

layoutHead(t('shelf_map'), 'shelfmap');
pageTitle(t('shelf_map'), t('shelf_map_subtitle'));
?>

<div class="legend mb-3">
  <span class="pill pill-received me-2"><?= e(t('occupied')) ?></span>
  <span class="pill pill-out me-2"><?= e(t('vacant')) ?></span>
  <span class="pill pill-overdue"><?= e(t('expiring_within_30')) ?></span>
</div>

<div class="shelf-grid">
  <?php foreach ($shelves as $sh):
    $occ = $byShelf[(int)$sh['id']] ?? null;
    $cls = 'vacant'; $expiring = false; $days = null;
    if ($occ && $occ['active']) {
        $cls = 'occupied';
        $days = (int)round((strtotime($occ['ag']['period_to']) - strtotime($today)) / 86400);
        if ($days <= 30) { $cls .= ' expiring'; $expiring = true; }
    }
  ?>
    <div class="shelf-tile <?= $cls ?>">
      <div class="d-flex justify-content-between align-items-start gap-2">
        <div>
          <div class="shelf-label"><i class="bi bi-grid-3x3-gap me-1"></i><?= e($sh['label']) ?></div>
          <?php if ($sh['zone']): ?><div class="shelf-zone"><?= e($sh['zone']) ?></div><?php endif; ?>
        </div>
        <?php if ($occ && $occ['active']): ?>
          <span class="pill <?= $expiring ? 'pill-overdue' : 'pill-received' ?>"><?= $expiring ? e(sprintf(t('days_left_paren'), $days)) : e(t('occupied')) ?></span>
        <?php else: ?>
          <span class="pill pill-out"><?= e(t('vacant')) ?></span>
        <?php endif; ?>
      </div>
      <?php if ($occ && $occ['active']): $ag = $occ['ag']; ?>
        <div class="shelf-company"><a href="company.php?id=<?= (int)$ag['id'] ?>"><?= e($ag['supplier_name']) ?></a></div>
        <div class="shelf-meta">
          <?= $ag['rent_type'] === 'percentage' ? pct($ag['percentage_rate']) . ' ' . e(t('of_sales')) : money($ag['rent_amount']) . ' / ' . e(freqLabel($ag['billing_frequency'])) ?>
          <br><?= date('d M Y', strtotime($ag['period_from'])) ?> → <?= date('d M Y', strtotime($ag['period_to'])) ?>
        </div>
      <?php elseif ($occ): ?>
        <div class="shelf-meta"><?= e(t('no_active_agreement')) ?> — <?= e($occ['ag']['supplier_name']) ?> (<?= date('d M Y', strtotime($occ['ag']['period_from'])) ?> → <?= date('d M Y', strtotime($occ['ag']['period_to'])) ?>)</div>
      <?php else: ?>
        <div class="shelf-meta"><?= e(t('vacant')) ?></div>
      <?php endif; ?>
      <?php if (isAdmin()): ?>
        <div class="shelf-actions">
          <a class="btn btn-sm btn-light border" href="shelfmap.php?edit=<?= (int)$sh['id'] ?>"><?= e(t('edit')) ?></a>
          <form method="post" onsubmit="return confirm('Delete this shelf position?');">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= (int)$sh['id'] ?>">
            <button class="btn btn-sm btn-outline-danger" type="submit"><?= e(t('delete')) ?></button>
          </form>
        </div>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
  <?php if (!$shelves): ?>
    <p class="empty-note"><?= e(t('no_shelves')) ?></p>
  <?php endif; ?>
</div>

<?php if ($unassigned): ?>
  <div class="panel mt-3">
    <h2 class="panel-title"><i class="bi bi-exclamation-circle me-2"></i><?= e(t('unassigned_agreements')) ?></h2>
    <ul class="activity-list">
      <?php foreach ($unassigned as $ag): ?>
        <li><a href="company.php?id=<?= (int)$ag['id'] ?>"><?= e($ag['supplier_name']) ?></a>
            <span class="cell-sub"><?= e($ag['ledger_code']) ?> · <?= date('d M Y', strtotime($ag['period_from'])) ?> → <?= date('d M Y', strtotime($ag['period_to'])) ?></span></li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<?php if (isAdmin()): ?>
<div class="panel mt-3">
  <h2 class="panel-title"><i class="bi <?= $editShelf ? 'bi-pencil' : 'bi-plus-circle' ?> me-2"></i><?= $editShelf ? e(t('edit_shelf')) : e(t('add_shelf')) ?></h2>
  <form method="post" class="row g-2">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= (int)($editShelf['id'] ?? 0) ?>">
    <div class="col-md-5">
      <label class="form-label"><?= e(t('shelf')) ?> *</label>
      <input class="form-control" name="label" required value="<?= e($editShelf['label'] ?? '') ?>" placeholder="Aisle 1 · Endcap 2">
    </div>
    <div class="col-md-4">
      <label class="form-label"><?= e(t('zone')) ?></label>
      <input class="form-control" name="zone" value="<?= e($editShelf['zone'] ?? '') ?>" placeholder="Ground floor · Grocery">
    </div>
    <div class="col-md-3">
      <label class="form-label"><?= e(t('sort')) ?></label>
      <input type="number" class="form-control" name="sort_order" value="<?= e($editShelf['sort_order'] ?? 0) ?>">
    </div>
    <div class="col-12 d-flex gap-2">
      <button class="btn btn-gold" type="submit"><i class="bi bi-check2-circle me-1"></i> <?= e(t('save')) ?></button>
      <?php if ($editShelf): ?><a class="btn btn-light border" href="shelfmap.php"><?= e(t('cancel')) ?></a><?php endif; ?>
    </div>
  </form>
</div>
<?php endif; ?>

<?php layoutFoot(); ?>
