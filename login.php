<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

if (isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $st = db()->prepare('SELECT * FROM users WHERE username = :u AND is_active = 1');
    $st->execute([':u' => $username]);
    $user = $st->fetch();
    if ($user && password_verify($password, $user['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['user'] = [
            'id'       => (int)$user['id'],
            'name'     => $user['name'],
            'username' => $user['username'],
            'role'     => $user['role'],
        ];
        audit(db(), 'login', 'user', (int)$user['id'], 'Signed in (' . $user['role'] . ').');
        header('Location: index.php');
        exit;
    }
    $error = 'Invalid username or password. Please try again.';
}
$lang = appLang();
?>
<!DOCTYPE html>
<html lang="<?= e($lang) ?>" dir="<?= $lang === 'ur' ? 'rtl' : 'ltr' ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(t('sign_in')) ?> · <?= e(APP_NAME) ?></title>
<link href="assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
<link href="assets/vendor/bootstrap-icons/bootstrap-icons.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500;600;700&family=Inter:wght@400;500;600;700&family=Noto+Nastaliq+Urdu:wght@400;600;700&display=swap" rel="stylesheet">
<link href="assets/css/app.css" rel="stylesheet">
</head>
<body class="login-body">
<div class="login-card">
  <div class="login-brand">
    <div class="brand-mark big"><i class="bi bi-gem"></i></div>
    <h1><?= e(MART_NAME) ?></h1>
    <p><?= e(APP_NAME) ?></p>
  </div>
  <?php if ($error): ?>
    <div class="alert alert-danger py-2"><?= e($error) ?></div>
  <?php endif; ?>
  <form method="post" novalidate>
    <?= csrfField() ?>
    <label class="form-label" for="username"><?= e(t('username')) ?></label>
    <div class="input-group mb-3">
      <span class="input-group-text"><i class="bi bi-person"></i></span>
      <input class="form-control" id="username" name="username" required autofocus
             value="<?= e($_POST['username'] ?? '') ?>" placeholder="e.g. admin">
    </div>
    <label class="form-label" for="password"><?= e(t('password')) ?></label>
    <div class="input-group mb-4">
      <span class="input-group-text"><i class="bi bi-lock"></i></span>
      <input type="password" class="form-control" id="password" name="password" required placeholder="Your password">
    </div>
    <button class="btn btn-gold w-100" type="submit"><i class="bi bi-box-arrow-in-right me-1"></i> <?= e(t('sign_in')) ?></button>
  </form>
  <p class="login-hint">Demo logins — admin / admin123 · accountant / acc123 · viewer / view123</p>
  <div class="lang-switch-login">
    <a href="<?= e(langUrl('en')) ?>" class="<?= $lang === 'en' ? 'active' : '' ?>">English</a> ·
    <a href="<?= e(langUrl('ur')) ?>" class="<?= $lang === 'ur' ? 'active' : '' ?>">اردو</a>
  </div>
</div>
</body>
</html>
