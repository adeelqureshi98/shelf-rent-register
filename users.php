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
                flash('success', 'User deleted.');
            }
        }
    }

    header('Location: users.php');
    exit;
}

$users = $pdo->query('SELECT * FROM users ORDER BY role, name')->fetchAll();

layoutHead('Users', 'users');
pageTitle('Users', 'Staff accounts and roles. Permissions are enforced on the server for every action.');
?>

<div class="row g-3">
  <div class="col-xl-8">
    <div class="panel p-0 overflow-hidden">
      <div class="table-responsive">
        <table class="table table-luxe align-middle mb-0">
          <thead><tr><th>Name</th><th>Username</th><th>Role</th><th>Active</th><th class="text-end">Manage</th></tr></thead>
          <tbody>
          <?php foreach ($users as $u): ?>
            <tr>
              <td><?= e($u['name']) ?><?= (int)$u['id'] === (int)$me['id'] ? ' <span class="cell-sub">(you)</span>' : '' ?></td>
              <td><?= e($u['username']) ?></td>
              <td><span class="pill pill-role-<?= e($u['role']) ?>"><?= ucfirst($u['role']) ?></span></td>
              <td><?= $u['is_active'] ? '<span class="text-success">Active</span>' : '<span class="text-muted">Inactive</span>' ?></td>
              <td class="text-end">
                <form method="post" class="d-inline-flex gap-1 align-items-center justify-content-end flex-wrap">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="update">
                  <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                  <select class="form-select form-select-sm w-auto" name="role">
                    <?php foreach (['admin', 'accountant', 'viewer'] as $r): ?>
                      <option value="<?= $r ?>" <?= $u['role'] === $r ? 'selected' : '' ?>><?= ucfirst($r) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <label class="form-check form-switch m-0">
                    <input class="form-check-input" type="checkbox" name="is_active" <?= $u['is_active'] ? 'checked' : '' ?>>
                  </label>
                  <button class="btn btn-sm btn-light border" type="submit">Save</button>
                </form>
                <form method="post" class="d-inline-flex gap-1 align-items-center mt-1" onsubmit="return this.password.value.length >= 6 || (alert('New password must be at least 6 characters.'), false);">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="reset">
                  <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                  <input type="password" class="form-control form-control-sm w-auto" name="password" placeholder="New password">
                  <button class="btn btn-sm btn-light border" type="submit">Reset</button>
                </form>
                <?php if ((int)$u['id'] !== (int)$me['id']): ?>
                <form method="post" class="d-inline mt-1" onsubmit="return confirm('Delete this user account?');">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                  <button class="btn btn-sm btn-outline-danger" type="submit">Delete</button>
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
      <h2 class="panel-title"><i class="bi bi-person-plus me-2"></i>Add User</h2>
      <form method="post" class="row g-2">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="create">
        <div class="col-12">
          <label class="form-label">Full name</label>
          <input class="form-control" name="name" required>
        </div>
        <div class="col-12">
          <label class="form-label">Username</label>
          <input class="form-control" name="username" required>
        </div>
        <div class="col-12">
          <label class="form-label">Password (min 6 characters)</label>
          <input type="password" class="form-control" name="password" required minlength="6">
        </div>
        <div class="col-12">
          <label class="form-label">Role</label>
          <select class="form-select" name="role">
            <option value="viewer">Viewer — read only</option>
            <option value="accountant">Accountant — agreements + payments</option>
            <option value="admin">Admin — full access</option>
          </select>
        </div>
        <div class="col-12"><button class="btn btn-gold w-100" type="submit">Create User</button></div>
      </form>
    </div>
  </div>
</div>

<?php layoutFoot(); ?>
