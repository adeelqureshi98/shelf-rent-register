<?php
/**
 * Shared page layout: luxury sidebar shell used by every protected page.
 */
require_once __DIR__ . '/auth.php';

function layoutHead(string $title, string $active): void
{
    $u = currentUser();
    $lang = appLang();
    $roleLabel = ['admin' => t('role_administrator'), 'accountant' => t('role_accountant'), 'viewer' => t('role_viewer')][$u['role']] ?? $u['role'];
    $nav = [
        'index'      => ['index.php',      'bi-grid-1x2-fill',  t('dashboard')],
        'grid'       => ['grid.php',       'bi-calendar3',      t('payment_grid')],
        'agreements' => ['agreements.php', 'bi-file-earmark-text-fill', t('agreements')],
        'incentives' => ['incentives.php', 'bi-gift-fill',      t('incentives')],
        'reminders'  => ['reminders.php',  'bi-bell-fill',      t('reminders')],
        'reports'    => ['reports.php',    'bi-bar-chart-fill', t('reports')],
        'financial'  => ['financial.php',  'bi-bank',           t('financial')],
        'followups'  => ['followups.php',  'bi-telephone-fill', t('followups')],
    ];
    if (isAdmin()) {
        $nav['users']  = ['users.php',  'bi-people-fill',  t('users')];
        $nav['audit']  = ['audit.php',  'bi-journal-text', t('audit_log')];
        $nav['backup'] = ['backup.php', 'bi-database-fill-down', t('backup_restore')];
    }
    ?>
<!DOCTYPE html>
<html lang="<?= e($lang) ?>" dir="<?= $lang === 'ur' ? 'rtl' : 'ltr' ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> · <?= e(cfg('app_name')) ?></title>
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
      <?php foreach ($nav as $key => [$href, $icon, $label]): ?>
        <a class="side-link <?= $active === $key ? 'active' : '' ?>" href="<?= $href ?>">
          <i class="bi <?= $icon ?>"></i><span><?= $label ?></span>
        </a>
      <?php endforeach; ?>
    </nav>
    <div class="lang-switch no-print">
      <a href="<?= e(langUrl('en')) ?>" class="<?= $lang === 'en' ? 'active' : '' ?>">EN</a>
      <span class="lang-sep">|</span>
      <a href="<?= e(langUrl('ur')) ?>" class="<?= $lang === 'ur' ? 'active' : '' ?>">اردو</a>
    </div>
    <div class="side-user">
      <div class="avatar"><?= e(strtoupper(substr($u['name'], 0, 1))) ?></div>
      <div class="flex-grow-1">
        <div class="side-user-name"><?= e($u['name']) ?></div>
        <div class="side-user-role"><?= e($roleLabel) ?></div>
      </div>
      <a class="logout" href="logout.php" title="<?= e(t('sign_out')) ?>"><i class="bi bi-box-arrow-right"></i></a>
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
        echo '<p dir="auto">' . e($sub) . '</p>';
    }
    echo '</div>';
}
