<?php
/**
 * All payment write actions live here so every rule is enforced server-side
 * in exactly one place. GET requests are rejected.
 *
 * Actions:
 *   record_payment — admin/accountant. Adds one payment to one bill.
 *                    Partial payments are allowed; the balance stays open.
 *   set_amount     — admin/accountant. Enters the actual billed amount on a
 *                    percentage ("Awaited") bill so it becomes a normal bill.
 *   delete_payment — admin only. Removes a payment (mistake correction).
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/billing.php';

requireRole(['admin', 'accountant', 'viewer']); // must be signed in first
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: grid.php');
    exit;
}
checkCsrf();

$pdo    = db();
$action = $_POST['action'] ?? '';
$back   = $_POST['back'] ?? 'grid.php';
// Only allow local relative back-targets.
if (!preg_match('#^[a-z0-9_\-]+\.php(\?[a-z0-9_\-=&%]*)?$#i', $back)) {
    $back = 'grid.php';
}

function billOrFail(PDO $pdo, int $id): array
{
    $st = $pdo->prepare('SELECT * FROM bills WHERE id = :id');
    $st->execute([':id' => $id]);
    $bill = $st->fetch();
    if (!$bill) {
        flash('error', 'That bill could not be found.');
        header('Location: grid.php');
        exit;
    }
    return $bill;
}

if ($action === 'record_payment') {
    requireRole(['admin', 'accountant']);
    $bill = billOrFail($pdo, (int)($_POST['bill_id'] ?? 0));

    if ($bill['expected_amount'] === null) {
        flash('error', 'This is a percentage agreement. Enter the actual billed amount first, then record the payment.');
        header('Location: ' . $back);
        exit;
    }
    $amount = (float)($_POST['amount'] ?? 0);
    $date   = $_POST['payment_date'] ?? date('Y-m-d');
    $method = $_POST['method'] ?? 'Cash';
    $ref    = trim($_POST['reference'] ?? '');
    $cheque = $_POST['cheque_status'] ?? null;
    $notes  = trim($_POST['notes'] ?? '');

    $paidSt = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM payments WHERE bill_id = :b');
    $paidSt->execute([':b' => $bill['id']]);
    $paid    = (float)$paidSt->fetchColumn();
    $balance = (float)$bill['expected_amount'] - $paid;

    $errors = [];
    if ($amount <= 0)                    $errors[] = 'Payment amount must be greater than zero.';
    if ($amount > $balance + 0.005)      $errors[] = 'Payment amount is more than the open balance of ' . money($balance) . '.';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $errors[] = 'Please choose a valid payment date.';
    if (!in_array($method, ['Cash', 'Bank Transfer', 'Cheque', 'Online'], true)) $errors[] = 'Please choose a payment method.';
    if ($method !== 'Cheque') $cheque = null;
    if ($cheque !== null && !in_array($cheque, ['Cleared', 'Pending', 'Bounced'], true)) $cheque = 'Pending';

    if ($errors) {
        flash('error', implode(' ', $errors));
        header('Location: ' . $back);
        exit;
    }

    $ins = $pdo->prepare(
        'INSERT INTO payments (bill_id, amount, payment_date, method, reference, cheque_status, notes, received_by)
         VALUES (:b, :a, :d, :m, :r, :c, :n, :u)'
    );
    $ins->execute([
        ':b' => $bill['id'], ':a' => $amount, ':d' => $date, ':m' => $method,
        ':r' => $ref !== '' ? $ref : null, ':c' => $cheque,
        ':n' => $notes !== '' ? $notes : null, ':u' => currentUser()['id'],
    ]);
    $pid = (int)$pdo->lastInsertId();
    audit($pdo, 'create', 'payment', $pid, 'Recorded ' . money($amount) . ' (' . $method . ') on bill #' . (int)$bill['id'] . '.');
    flash('success', 'Payment of ' . money($amount) . ' recorded successfully.');
    header('Location: receipt.php?payment_id=' . $pid . '&back=' . urlencode($back));
    exit;
}

if ($action === 'set_amount') {
    requireRole(['admin', 'accountant']);
    $bill   = billOrFail($pdo, (int)($_POST['bill_id'] ?? 0));
    $amount = (float)($_POST['actual_amount'] ?? 0);
    if ($bill['expected_amount'] !== null) {
        flash('error', 'The billed amount is already set for this bill.');
    } elseif ($amount <= 0) {
        flash('error', 'The actual billed amount must be greater than zero.');
    } else {
        $up = $pdo->prepare('UPDATE bills SET expected_amount = :a WHERE id = :id');
        $up->execute([':a' => $amount, ':id' => $bill['id']]);
        audit($pdo, 'set_amount', 'bill', (int)$bill['id'], 'Entered actual billed amount ' . money($amount) . ' on an awaited percentage bill.');
        flash('success', 'Billed amount of ' . money($amount) . ' saved. The bill is now a normal payable bill.');
    }
    header('Location: ' . $back);
    exit;
}

if ($action === 'delete_payment') {
    requireRole(['admin']);
    $pid = (int)($_POST['payment_id'] ?? 0);
    $info = $pdo->prepare('SELECT amount, bill_id FROM payments WHERE id = :id');
    $info->execute([':id' => $pid]);
    $prow = $info->fetch();
    $del = $pdo->prepare('DELETE FROM payments WHERE id = :id');
    $del->execute([':id' => $pid]);
    if ($prow) {
        audit($pdo, 'delete', 'payment', $pid, 'Deleted payment of ' . money($prow['amount']) . ' from bill #' . (int)$prow['bill_id'] . '.');
    }
    flash('success', 'Payment deleted.');
    header('Location: ' . $back);
    exit;
}

flash('error', 'Unknown action.');
header('Location: ' . $back);
exit;
