<?php
/**
 * Shared page layout: Deep Slate / Emerald luxury sidebar shell used
 * by every protected staff page. (Supplier logins never reach these
 * pages — layoutHead() sends them to the Supplier Portal.)
 */
require_once __DIR__ . '/auth.php';

/** Theme chosen by the user: cookie first, light by default. */
function currentTheme(): string
{
    return ($_COOKIE['srr_theme'] ?? 'light') === 'dark' ? 'dark' : 'light';
}

/**
 * Inline pre-paint theme script: applies the stored theme (localStorage
 * wins over the cookie) BEFORE the CSS paints, so a dark-mode user
 * never sees a flash of light theme. Emitted in <head> of every page.
 */
function themeInitScript(): void
{
    ?>
<script>(function(){try{var t=localStorage.getItem('srr-theme');if(t!=='dark'&&t!=='light'){t=(document.cookie.match(/(?:^|; )srr_theme=(dark|light)/)||[])[1]||'light';}document.documentElement.setAttribute('data-theme',t);document.documentElement.setAttribute('data-bs-theme',t);}catch(e){document.documentElement.setAttribute('data-theme','light');document.documentElement.setAttribute('data-bs-theme','light');}})();</script>
<?php
}

function layoutHead(string $title, string $active): void
{
    $u = currentUser();
    // Supplier logins belong to the portal — never the staff shell.
    if ($u && ($u['role'] ?? '') === 'supplier') {
        header('Location: portal.php');
        exit;
    }
    $lang = appLang();
    $theme = currentTheme();
    $roleLabel = ['admin' => t('role_administrator'), 'accountant' => t('role_accountant'), 'viewer' => t('role_viewer'), 'supplier' => t('role_supplier')][$u['role']] ?? $u['role'];
    $nav = [
        'index'      => ['index.php',      'bi-grid-1x2-fill',  t('dashboard')],
        'grid'       => ['grid.php',       'bi-calendar3',      t('payment_grid')],
        'agreements' => ['agreements.php', 'bi-file-earmark-text-fill', t('agreements')],
        'incentives' => ['incentives.php', 'bi-gift-fill',      t('incentives')],
        'cheques'    => ['cheques.php',    'bi-wallet2',        t('cheques')],
        'shelfmap'   => ['shelfmap.php',   'bi-grid-3x3-gap-fill', t('shelf_map')],
        'reminders'  => ['reminders.php',  'bi-bell-fill',      t('reminders')],
        'reports'    => ['reports.php',    'bi-bar-chart-fill', t('reports')],
        'financial'  => ['financial.php',  'bi-bank',           t('financial')],
        'followups'  => ['followups.php',  'bi-telephone-fill', t('followups')],
    ];
    if (isAdmin()) {
        $nav['users']    = ['users.php',    'bi-people-fill',  t('users')];
        $nav['settings'] = ['settings.php', 'bi-gear-fill',    t('settings')];
        $nav['audit']    = ['audit.php',    'bi-journal-text', t('audit_log')];
        $nav['backup']   = ['backup.php',   'bi-database-fill-down', t('backup_restore')];
    }
    ?>
<!DOCTYPE html>
<html lang="<?= e($lang) ?>" dir="<?= $lang === 'ur' ? 'rtl' : 'ltr' ?>" data-theme="<?= e($theme) ?>" data-bs-theme="<?= e($theme) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> · <?= e(cfg('app_name')) ?></title>
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
      <?php foreach ($nav as $key => [$href, $icon, $label]): ?>
        <a class="side-link <?= $active === $key ? 'active' : '' ?>" href="<?= $href ?>">
          <i class="bi <?= $icon ?>"></i><span><?= $label ?></span>
        </a>
      <?php endforeach; ?>
    </nav>
    <button type="button" class="theme-toggle no-print" data-theme-toggle
            data-dark-label="<?= e(t('dark_mode')) ?>" data-light-label="<?= e(t('light_mode')) ?>"
            title="<?= e(t('dark_mode')) ?> / <?= e(t('light_mode')) ?>">
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
        <div class="side-user-role"><?= e($roleLabel) ?></div>
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
<?php
}

function layoutFoot(): void
{
    ?>
  </main>
</div>
<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script src="assets/vendor/chart.js/chart.umd.min.js"></script>
<script src="assets/js/app.js"></script>
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

/** Server-side pagination helper: returns [page, pages, offset] for a total. */
function paginate(int $total, int $perPage = 20): array
{
    $pages = max(1, (int)ceil($total / $perPage));
    $page  = max(1, min($pages, (int)($_GET['page'] ?? 1)));
    return [$page, $pages, ($page - 1) * $perPage];
}

/** Pagination bar that keeps every current GET filter. */
function pageNav(int $page, int $pages, string $base): void
{
    if ($pages <= 1) return;
    $params = $_GET;
    unset($params['page']);
    $mk = function (int $p) use ($params, $base) {
        $params['page'] = $p;
        return $base . '?' . http_build_query($params);
    };
    echo '<nav class="page-nav no-print">';
    if ($page > 1) echo '<a class="btn btn-sm btn-light border" href="' . e($mk($page - 1)) . '">' . e(t('previous')) . '</a>';
    echo '<span class="page-info">' . e(sprintf(t('page_of'), $page, $pages)) . '</span>';
    if ($page < $pages) echo '<a class="btn btn-sm btn-light border" href="' . e($mk($page + 1)) . '">' . e(t('next')) . '</a>';
    echo '</nav>';
}
