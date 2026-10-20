<?php
/**
 * Shared page layout: luxury sidebar shell used by every protected page.
 */
require_once __DIR__ . '/auth.php';

function layoutHead(string $title, string $active): void
{
    $u = currentUser();
    $roleLabel = ['admin' => 'Administrator', 'accountant' => 'Accountant', 'viewer' => 'Viewer'][$u['role']] ?? $u['role'];
    $nav = [
        'index'      => ['index.php',      'bi-grid-1x2-fill',  'Dashboard'],
        'grid'       => ['grid.php',       'bi-calendar3',      'Payment Grid'],
        'agreements' => ['agreements.php', 'bi-file-earmark-text-fill', 'Agreements'],
        'reports'    => ['reports.php',    'bi-bar-chart-fill', 'Reports'],
        'followups'  => ['followups.php',  'bi-telephone-fill', 'Follow-ups'],
    ];
    if (isAdmin()) {
        $nav['users']  = ['users.php',  'bi-people-fill',  'Users'];
        $nav['backup'] = ['backup.php', 'bi-database-fill-down', 'Backup & Restore'];
    }
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> · <?= e(cfg('app_name')) ?></title>
<link href="assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
<link href="assets/vendor/bootstrap-icons/bootstrap-icons.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500;600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
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
      <?php foreach ($nav as $key => [$href, $icon, $label]): ?>
        <a class="side-link <?= $active === $key ? 'active' : '' ?>" href="<?= $href ?>">
          <i class="bi <?= $icon ?>"></i><span><?= $label ?></span>
        </a>
      <?php endforeach; ?>
    </nav>
    <div class="side-user">
      <div class="avatar"><?= e(strtoupper(substr($u['name'], 0, 1))) ?></div>
      <div class="flex-grow-1">
        <div class="side-user-name"><?= e($u['name']) ?></div>
        <div class="side-user-role"><?= e($roleLabel) ?></div>
      </div>
      <a class="logout" href="logout.php" title="Sign out"><i class="bi bi-box-arrow-right"></i></a>
    </div>
  </aside>
  <main class="content">
    <?php foreach (takeFlashes() as $f): ?>
      <div class="alert alert-<?= $f['type'] === 'error' ? 'danger' : 'success' ?> alert-dismissible fade show shadow-sm" role="alert">
        <?= e($f['msg']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
      </div>
    <?php endforeach; ?>
<?php
}

function layoutFoot(): void
{
    ?>
  </main>
</div>
<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php
}

function pageTitle(string $title, string $sub = ''): void
{
    echo '<div class="page-head"><h1>' . e($title) . '</h1>';
    if ($sub !== '') {
        echo '<p>' . e($sub) . '</p>';
    }
    echo '</div>';
}
