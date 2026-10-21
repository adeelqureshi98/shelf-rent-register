<?php
/**
 * User management — Admin only. Create staff accounts, change roles,
 * reset passwords, activate/deactivate, delete (never yourself, and never
 * the last active admin).
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/layout.php';

requireRole(['admin']);
$pdo = db();
$me  = currentUser();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $name = trim($_POST['name'] ?? '');
        $user = trim($_POST['username'] ?? '');
        $pass = $_POST['password'] ?? '';
        $role = $_POST['role'] ?? 'viewer';
        if ($name === '' || $user === '' || strlen($pass) < 6) {
            flash('error', 'Name and username are required, and the password must be at least 6 characters.');
        } elseif (!in_array($role, ['admin', 'accountant', 'viewer'], true)) {
            flash('error', 'Please choose a valid role.');
        } else {
            $dup = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = :u');
            $dup->execute([':u' => $user]);
            if ($dup->fetchColumn() > 0) {
                flash('error', 'That username is already taken.');
            } else {
                $pdo->prepare('INSERT INTO users (name, username, password_hash, role) VALUES (:n, :u, :p, :r)')
                    ->execute([':n' => $name, ':u' => $user, ':p' => password_hash($pass, PASSWORD_DEFAULT), ':r' => $role]);
                audit($pdo, 'create', 'user', (int)$pdo->lastInsertId(), "Created user '$user' with role $role.");
                flash('success', "User '$user' created with role $role.");
            }
        }
    }

    if ($action === 'update') {
        $id   = (int)($_POST['id'] ?? 0);
        $role = $_POST['role'] ?? 'viewer';
        $active = isset($_POST['is_active']) ? 1 : 0;
        if (!in_array($role, ['admin', 'accountant', 'viewer'], true)) {
            flash('error', 'Please choose a valid role.');
        } elseif ($id === (int)$me['id'] && ($role !== 'admin' || $active === 0)) {
            flash('error', 'You cannot demote or deactivate your own account.');
        } else {
            $pdo->prepare('UPDATE users SET role = :r, is_active = :a WHERE id = :id')
                ->execute([':r' => $role, ':a' => $active, ':id' => $id]);
            audit($pdo, 'update', 'user', $id, 'Set role to ' . $role . ', active = ' . ($active ? 'yes' : 'no') . '.');
            flash('success', 'User updated.');
        }
    }

    if ($action === 'reset') {
        $id   = (int)($_POST['id'] ?? 0);
        $pass = $_POST['password'] ?? '';
        if (strlen($pass) < 6) {
            flash('error', 'The new password must be at least 6 characters.');
        } else {
            $pdo->prepare('UPDATE users SET password_hash = :p WHERE id = :id')
                ->execute([':p' => password_hash($pass, PASSWORD_DEFAULT), ':id' => $id]);
            audit($pdo, 'password_reset', 'user', $id, 'Password was reset by an administrator.');
            flash('success', 'Password reset.');
        }
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id === (int)$me['id']) {
            flash('error', 'You cannot delete your own account.');
        } else {
            $has = $pdo->prepare('SELECT COUNT(*) FROM payments WHERE received_by = :id');
            $has->execute([':id' => $id]);
            if ($has->fetchColumn() > 0) {
                flash('error', 'This user has recorded payments, so the account cannot be deleted. Deactivate it instead, to protect the audit trail.');
            } else {
                $pdo->prepare('DELETE FROM users WHERE id = :id')->execute([':id' => $id]);
                audit($pdo, 'delete', 'user', $id, 'User account deleted.');
                flash('success', 'User deleted.');
            }
        }
    }

    header('Location: users.php');
    exit;
}

$users = $pdo->query('SELECT * FROM users ORDER BY role, name')->fetchAll();

layoutHead(t('users'), 'users');
pageTitle(t('users'), t('users_subtitle'));
?>

<div class="row g-3">
  <div class="col-xl-8">
    <div class="panel p-0 overflow-hidden">
      <div class="table-responsive">
        <table class="table table-luxe align-middle mb-0">
          <thead><tr><th><?= e(t('name')) ?></th><th><?= e(t('username')) ?></th><th><?= e(t('role')) ?></th><th><?= e(t('active')) ?></th><th class="text-end"><?= e(t('manage')) ?></th></tr></thead>
          <tbody>
          <?php foreach ($users as $u): ?>
            <tr>
              <td class="text-nowrap fw-semibold"><?= e($u['name']) ?><?= (int)$u['id'] === (int)$me['id'] ? ' <span class="cell-sub">' . e(t('you_suffix')) . '</span>' : '' ?></td>
              <td><?= e($u['username']) ?></td>
              <td><span class="pill pill-role-<?= e($u['role']) ?>"><?= e(t('role_' . $u['role'])) ?></span></td>
              <td><?= $u['is_active'] ? '<span class="text-success">' . e(t('active')) . '</span>' : '<span class="text-muted">' . e(t('inactive')) . '</span>' ?></td>
              <td class="text-end allow-wrap">
                <form method="post" class="d-inline-flex gap-1 align-items-center justify-content-end flex-wrap">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="update">
                  <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                  <select class="form-select form-select-sm w-auto" name="role">
                    <?php foreach (['admin', 'accountant', 'viewer'] as $r): ?>
                      <option value="<?= $r ?>" <?= $u['role'] === $r ? 'selected' : '' ?>><?= e(t('role_' . $r)) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <label class="form-check form-switch m-0">
                    <input class="form-check-input" type="checkbox" name="is_active" <?= $u['is_active'] ? 'checked' : '' ?>>
                  </label>
                  <button class="btn btn-sm btn-light border" type="submit"><?= e(t('save')) ?></button>
                </form>
                <form method="post" class="d-inline-flex gap-1 align-items-center mt-1" onsubmit="return this.password.value.length >= 6 || (alert('New password must be at least 6 characters.'), false);">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="reset">
                  <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                  <input type="password" class="form-control form-control-sm" style="width:150px" name="password" placeholder="<?= e(t('new_password')) ?>">
                  <button class="btn btn-sm btn-light border" type="submit"><?= e(t('reset')) ?></button>
                </form>
                <?php if ((int)$u['id'] !== (int)$me['id']): ?>
                <form method="post" class="d-inline mt-1" onsubmit="return confirm('Delete this user account?');">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
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
  </div>
  <div class="col-xl-4">
    <div class="panel">
      <h2 class="panel-title"><i class="bi bi-person-plus me-2"></i><?= e(t('add_user')) ?></h2>
      <form method="post" class="row g-2">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="create">
        <div class="col-12">
          <label class="form-label"><?= e(t('full_name')) ?></label>
          <input class="form-control" name="name" required>
        </div>
        <div class="col-12">
          <label class="form-label"><?= e(t('username')) ?></label>
          <input class="form-control" name="username" required>
        </div>
        <div class="col-12">
          <label class="form-label"><?= e(t('password_min')) ?></label>
          <input type="password" class="form-control" name="password" required minlength="6">
        </div>
        <div class="col-12">
          <label class="form-label"><?= e(t('role')) ?></label>
          <select class="form-select" name="role">
            <option value="viewer"><?= e(t('role_viewer_desc')) ?></option>
            <option value="accountant"><?= e(t('role_accountant_desc')) ?></option>
            <option value="admin"><?= e(t('role_admin_desc')) ?></option>
          </select>
        </div>
        <div class="col-12"><button class="btn btn-gold w-100" type="submit"><?= e(t('create_user')) ?></button></div>
      </form>
    </div>
  </div>
</div>

<?php layoutFoot(); ?>
