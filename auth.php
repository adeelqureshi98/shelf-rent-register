<?php
/**
 * Authentication + server-side role enforcement.
 *
 * Roles:
 *   admin      — full access, user management, delete, backup/restore
 *   accountant — add/edit agreements, record payments, enter billed amounts
 *   viewer     — read-only
 *
 * Every protected page calls requireLogin(); every write action calls
 * requireRole() so permissions are enforced on the server, not just by
 * hiding buttons in the UI.
 */
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/lang.php';

function currentUser(): ?array
{
    return $_SESSION['user'] ?? null;
}

function isLoggedIn(): bool
{
    return isset($_SESSION['user']);
}

function requireLogin(): void
{
    if (!isLoggedIn()) {
        header('Location: login.php');
        exit;
    }
}

/** Stop the request unless the signed-in user has one of $roles. */
function requireRole(array $roles): void
{
    requireLogin();
    if (!in_array($_SESSION['user']['role'], $roles, true)) {
        http_response_code(403);
        flash('error', 'You do not have permission to perform this action.');
        // Send the user back to a page they can see.
        $fallback = 'index.php';
        header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? $fallback));
        exit;
    }
}

function canWrite(): bool
{
    $u = currentUser();
    return $u && in_array($u['role'], ['admin', 'accountant'], true);
}

function isAdmin(): bool
{
    $u = currentUser();
    return $u && $u['role'] === 'admin';
}

/* ---------------- flash messages ---------------- */
function flash(string $type, string $msg): void
{
    $_SESSION['flash'][] = ['type' => $type, 'msg' => $msg];
}

function takeFlashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

/* ---------------- CSRF ---------------- */
function csrfToken(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrfField(): string
{
    return '<input type="hidden" name="csrf" value="' . htmlspecialchars(csrfToken()) . '">';
}

function checkCsrf(): void
{
    $sent = $_POST['csrf'] ?? '';
    if (!hash_equals($_SESSION['csrf'] ?? '', $sent)) {
        http_response_code(400);
        exit('Invalid form token. Please go back and try again.');
    }
}

/* ---------------- audit trail ---------------- */
/**
 * Record one action in the audit_log table (who, what, when, from
 * where). Deliberately never throws: auditing is best-effort and
 * must not break the business action it describes — e.g. on a
 * database that has not run upgrade_v2.sql yet.
 */
function audit(PDO $pdo, string $action, string $entity, ?int $entityId = null, string $details = ''): void
{
    try {
        $u = currentUser();
        $pdo->prepare(
            'INSERT INTO audit_log (user_id, username, action, entity, entity_id, details, ip)
             VALUES (:uid, :un, :a, :e, :eid, :d, :ip)'
        )->execute([
            ':uid' => $u['id'] ?? null,
            ':un'  => $u['username'] ?? null,
            ':a'   => $action,
            ':e'   => $entity,
            ':eid' => $entityId,
            ':d'   => $details !== '' ? $details : null,
            ':ip'  => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (Throwable $ex) {
        // Swallowed on purpose — see docblock.
    }
}

/* ---------------- output escaping ---------------- */
function e($v): string
{
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}

function money($n): string
{
    return cfg('currency') . ' ' . number_format((float)$n, 0);
}

function moneyFull($n): string
{
    return cfg('currency') . ' ' . number_format((float)$n, 2);
}
