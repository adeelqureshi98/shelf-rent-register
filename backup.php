<?php
/**
 * Backup & Restore — Admin only.
 *
 * Export downloads a self-contained .sql file of the application's
 * data tables (one INSERT per row, so a restore can be replayed line
 * by line). Restore accepts only files produced by this export
 * (marker checked), wipes those tables and replays the INSERT lines
 * inside the file. The audit log is deliberately NOT part of the
 * backup — an audit trail must not be restorable. Uploaded scan
 * files are not part of the SQL backup either — copy the uploads/
 * folder separately when moving servers.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/layout.php';

requireRole(['admin']);
$pdo = db();

const BACKUP_MARKER = '-- SHELF-RENT-BACKUP v1';
const BACKUP_TABLES = ['payments', 'bills', 'incentives', 'reminder_log', 'agreements', 'users']; // children first for restore deletes

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'export') {
    checkCsrf();
    audit($pdo, 'export', 'backup', null, 'Downloaded a .sql backup.');
    $out = [BACKUP_MARKER, '-- ' . APP_NAME . ' — exported ' . date('Y-m-d H:i:s'), 'SET FOREIGN_KEY_CHECKS=0;'];
    foreach (['users', 'agreements', 'bills', 'payments', 'incentives', 'reminder_log'] as $table) {
        $rows = $pdo->query("SELECT * FROM `$table`")->fetchAll();
        $out[] = "-- Table: $table (" . count($rows) . ' rows)';
        foreach ($rows as $row) {
            $cols = array_map(fn($c) => "`$c`", array_keys($row));
            $vals = array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v), array_values($row));
            $out[] = "INSERT INTO `$table` (" . implode(', ', $cols) . ') VALUES (' . implode(', ', $vals) . ');';
        }
    }
    $out[] = 'SET FOREIGN_KEY_CHECKS=1;';
    $sql = implode("\n", $out) . "\n";
    header('Content-Type: application/sql');
    header('Content-Disposition: attachment; filename="shelf-rent-backup-' . date('Ymd-His') . '.sql"');
    header('Content-Length: ' . strlen($sql));
    echo $sql;
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'restore') {
    checkCsrf();
    $f = $_FILES['backup'] ?? null;
    if (!$f || $f['error'] !== UPLOAD_ERR_OK) {
        flash('error', 'Please choose a backup file to restore.');
    } else {
        $sql = file_get_contents($f['tmp_name']);
        if ($sql === false || strpos($sql, BACKUP_MARKER) === false) {
            flash('error', 'That file is not a Shelf Rent backup created by this system. Nothing was changed.');
        } else {
            try {
                $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
                foreach (BACKUP_TABLES as $table) {
                    $pdo->exec("DELETE FROM `$table`");
                }
                $count = 0;
                foreach (preg_split('/\r\n|\r|\n/', $sql) as $line) {
                    $line = trim($line);
                    if (str_starts_with($line, 'INSERT INTO')) {
                        $pdo->exec($line);
                        $count++;
                    }
                }
                $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
                audit($pdo, 'restore', 'backup', null, "Restored a backup — $count records were loaded.");
                flash('success', "Backup restored — $count records were loaded. Please sign in again.");
                // Restoring users may have replaced the current account: sign out.
                $_SESSION = [];
                session_destroy();
                header('Location: login.php');
                exit;
            } catch (Throwable $ex) {
                $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
                flash('error', 'Restore failed: ' . $ex->getMessage());
            }
        }
    }
    header('Location: backup.php');
    exit;
}

$counts = [];
foreach (['users', 'agreements', 'bills', 'payments', 'incentives', 'reminder_log'] as $t) {
    $counts[$t] = (int)$pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
}

layoutHead(t('backup_restore'), 'backup');
pageTitle(t('backup_restore'), 'Admin only. Export a full copy of the register, or restore one.');
?>

<div class="row g-3">
  <div class="col-md-6">
    <div class="panel h-100">
      <h2 class="panel-title"><i class="bi bi-download me-2"></i>Export Backup</h2>
      <p>Downloads one <strong>.sql</strong> file containing every user, agreement, bill, payment, incentive and reminder-log entry:</p>
      <ul class="mb-3">
        <?php foreach ($counts as $t => $n): ?><li><?= ucfirst($t) ?>: <strong><?= $n ?></strong> record(s)</li><?php endforeach; ?>
      </ul>
      <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="export">
        <button class="btn btn-gold" type="submit"><i class="bi bi-file-earmark-arrow-down me-1"></i> Download Backup (.sql)</button>
      </form>
      <p class="cell-sub mt-3 mb-0">Scanned agreement files live in the <code>uploads/</code> folder — copy that folder separately when moving servers.</p>
    </div>
  </div>
  <div class="col-md-6">
    <div class="panel h-100 danger-zone">
      <h2 class="panel-title"><i class="bi bi-upload me-2"></i>Restore Backup</h2>
      <p><strong class="text-danger">Warning:</strong> restoring replaces ALL current data with the backup's data, and signs you out. Only files exported by this system are accepted.</p>
      <form method="post" enctype="multipart/form-data" onsubmit="return confirm('This will replace ALL current data with the backup. Continue?');">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="restore">
        <div class="mb-3">
          <input type="file" class="form-control" name="backup" accept=".sql" required>
        </div>
        <button class="btn btn-outline-danger" type="submit"><i class="bi bi-database-up me-1"></i> Restore This Backup</button>
      </form>
    </div>
  </div>
</div>

<?php layoutFoot(); ?>
