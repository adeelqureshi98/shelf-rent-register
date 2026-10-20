<?php
/**
 * Audit Log — Admin only. Every significant write in the system
 * (logins, payments, agreements, users, incentives, backups,
 * reminders) records a row here via audit() in auth.php, so there
 * is always an answer to "who changed this, and when?".
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/layout.php';

requireRole(['admin']);
$pdo = db();

$fUser   = trim($_GET['user'] ?? '');
$fAction = trim($_GET['action'] ?? '');
$fDate   = trim($_GET['date'] ?? '');

$sql  = 'SELECT * FROM audit_log WHERE 1 = 1';
$args = [];
if ($fUser !== '') {
    $sql .= ' AND username = :u';
    $args[':u'] = $fUser;
}
if ($fAction !== '') {
    $sql .= ' AND action = :a';
    $args[':a'] = $fAction;
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fDate)) {
    $sql .= ' AND created_at >= :d1 AND created_at < DATE_ADD(:d2, INTERVAL 1 DAY)';
    $args[':d1'] = $fDate . ' 00:00:00';
    $args[':d2'] = $fDate;
}
$sql .= ' ORDER BY id DESC LIMIT 200';
$st = $pdo->prepare($sql);
$st->execute($args);
$rows = $st->fetchAll();

$usernames = $pdo->query('SELECT DISTINCT username FROM audit_log WHERE username IS NOT NULL ORDER BY username')->fetchAll(PDO::FETCH_COLUMN);
$actions   = $pdo->query('SELECT DISTINCT action FROM audit_log ORDER BY action')->fetchAll(PDO::FETCH_COLUMN);

layoutHead(t('audit_log'), 'audit');
pageTitle(t('audit_log'), t('audit_subtitle'));
?>

<form class="filter-bar" method="get">
  <select class="form-select filter-status" name="user">
    <option value=""><?= e(t('all_users')) ?></option>
    <?php foreach ($usernames as $un): ?>
      <option value="<?= e($un) ?>" <?= $fUser === $un ? 'selected' : '' ?>><?= e($un) ?></option>
    <?php endforeach; ?>
  </select>
  <select class="form-select filter-status" name="action">
    <option value=""><?= e(t('all_actions')) ?></option>
    <?php foreach ($actions as $ac): ?>
      <option value="<?= e($ac) ?>" <?= $fAction === $ac ? 'selected' : '' ?>><?= e($ac) ?></option>
    <?php endforeach; ?>
  </select>
  <input type="date" class="form-control filter-status" name="date" value="<?= e($fDate) ?>">
  <button class="btn btn-gold" type="submit"><?= e(t('filter')) ?></button>
  <?php if ($fUser !== '' || $fAction !== '' || $fDate !== ''): ?>
    <a class="btn btn-light border" href="audit.php"><?= e(t('clear')) ?></a>
  <?php endif; ?>
</form>

<div class="panel p-0 overflow-hidden">
  <div class="table-responsive">
    <table class="table table-luxe align-middle mb-0">
      <thead><tr><th><?= e(t('time')) ?></th><th><?= e(t('user')) ?></th><th><?= e(t('action')) ?></th><th><?= e(t('entity')) ?></th><th><?= e(t('details')) ?></th><th>IP</th></tr></thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="6" class="text-center py-4 text-muted"><?= e(t('no_audit_entries')) ?></td></tr>
      <?php endif; ?>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td class="text-nowrap"><?= date('d M Y, H:i:s', strtotime($r['created_at'])) ?></td>
          <td><?= e($r['username'] ?? '—') ?><?php if ($r['user_id']): ?> <span class="cell-sub">#<?= (int)$r['user_id'] ?></span><?php endif; ?></td>
          <td><span class="pill pill-due"><?= e($r['action']) ?></span></td>
          <td><?= e($r['entity']) ?><?php if ($r['entity_id']): ?> <span class="cell-sub">#<?= (int)$r['entity_id'] ?></span><?php endif; ?></td>
          <td><?= e($r['details'] ?? '—') ?></td>
          <td class="cell-sub"><?= e($r['ip'] ?? '—') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php layoutFoot(); ?>
