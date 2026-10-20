<?php
/**
 * Billing engine — the single source of truth for bills, statuses and totals.
 *
 * The dashboard, payment grid and reports ALL read through the functions in
 * this file, so their numbers always agree with each other.
 *
 * Rules implemented (from the project brief):
 *  - Monthly agreements       : billed every month of the period.
 *  - Quarterly agreements     : billed every third month, counting from the
 *                               first month of the period.
 *  - Display Drive agreements : billed once, in the last month of the period.
 *  - Every billed month has a due date: the 10th of the billed month
 *    (config 'due_day'); for Display Drive it is the period end date.
 *  - Fixed agreements         : expected amount = the agreed rent (known).
 *  - Percentage agreements    : amount is NOT known in advance. Until staff
 *                               enter the actual billed amount, the bill is
 *                               "Awaited" and never counted as overdue money.
 *  - Partial payments         : a bill can receive several payments; the
 *                               unpaid balance stays Due / Overdue.
 *
 * Bills are materialised into the `bills` table by syncBills() (idempotent),
 * which lets payments link to one concrete bill and keeps history stable
 * even if an agreement is later edited.
 */
require_once __DIR__ . '/db.php';

/** List of ['month' => 'Y-m-01', 'due' => 'Y-m-d'] for one agreement. */
function billedMonths(array $ag): array
{
    $from      = new DateTime($ag['period_from']);
    $to        = new DateTime($ag['period_to']);
    $dueDay    = (int)cfg('due_day');
    $startMon  = (clone $from)->modify('first day of this month');
    $endMon    = (clone $to)->modify('first day of this month');

    if ($ag['billing_frequency'] === 'display_drive') {
        // Billed once, at the end of the period; due on the period end date.
        return [[
            'month' => $endMon->format('Y-m-01'),
            'due'   => $to->format('Y-m-d'),
        ]];
    }

    $step  = $ag['billing_frequency'] === 'quarterly' ? 3 : 1;
    $out   = [];
    $m     = clone $startMon;
    while ($m <= $endMon) {
        $due = (clone $m)->setDate((int)$m->format('Y'), (int)$m->format('m'), $dueDay);
        // A bill can never fall due before the agreement itself starts.
        if ($due < $from) {
            $due = clone $from;
        }
        $out[] = ['month' => $m->format('Y-m-01'), 'due' => $due->format('Y-m-d')];
        $m = (clone $m)->modify("+{$step} months");
    }
    return $out;
}

/**
 * Make sure every agreement has bill rows for all of its billed months.
 * Safe to call on every page load: INSERT IGNORE + a unique key do the rest.
 */
function syncBills(PDO $pdo): void
{
    $agreements = $pdo->query('SELECT * FROM agreements')->fetchAll();
    $ins = $pdo->prepare(
        'INSERT IGNORE INTO bills (agreement_id, bill_month, due_date, expected_amount)
         VALUES (:a, :m, :d, :e)'
    );
    foreach ($agreements as $ag) {
        $expected = $ag['rent_type'] === 'fixed' ? $ag['rent_amount'] : null;
        foreach (billedMonths($ag) as $b) {
            $ins->execute([
                ':a' => $ag['id'],
                ':m' => $b['month'],
                ':d' => $b['due'],
                ':e' => $expected,
            ]);
        }
    }
}

/**
 * Status of one bill.
 *   awaited  — percentage bill, actual amount not entered yet
 *   received — paid in full
 *   partial  — part paid, balance still outstanding
 *   overdue  — due date passed, unpaid (known amount only)
 *   due      — billed, due date still ahead, unpaid
 */
function billStatus(array $bill, float $paid, ?string $today = null): string
{
    $today = $today ?? date('Y-m-d');
    if ($bill['expected_amount'] === null) {
        return 'awaited';
    }
    $expected = (float)$bill['expected_amount'];
    if ($paid >= $expected - 0.005) {
        return 'received';
    }
    if ($paid > 0) {
        return 'partial';
    }
    return $bill['due_date'] < $today ? 'overdue' : 'due';
}

/**
 * All bills (optionally for one year / one agreement), each decorated with
 * 'paid' (sum of its payments) and 'status'. This is the one read path used
 * by the dashboard, grid and reports.
 */
function loadBills(PDO $pdo, ?int $year = null, ?int $agreementId = null): array
{
    $sql = 'SELECT b.*, a.supplier_name, a.ledger_code, a.rent_type,
                   a.rent_amount, a.percentage_rate, a.billing_frequency,
                   a.period_from, a.period_to,
                   COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.bill_id = b.id), 0) AS paid
              FROM bills b
              JOIN agreements a ON a.id = b.agreement_id
             WHERE 1 = 1';
    $args = [];
    if ($year !== null) {
        $sql .= ' AND YEAR(b.bill_month) = :y';
        $args[':y'] = $year;
    }
    if ($agreementId !== null) {
        $sql .= ' AND b.agreement_id = :ag';
        $args[':ag'] = $agreementId;
    }
    $sql .= ' ORDER BY a.supplier_name, b.bill_month';
    $st = $pdo->prepare($sql);
    $st->execute($args);
    $bills = $st->fetchAll();
    $today = date('Y-m-d');
    foreach ($bills as &$b) {
        $b['paid']   = (float)$b['paid'];
        $b['status'] = billStatus($b, $b['paid'], $today);
    }
    unset($b);
    return $bills;
}

/**
 * Headline totals. Only bills in months up to and including the current
 * month count as "billed to date" (future bills are not yet obligations).
 * Overdue = unpaid balance of known-amount bills whose due date has passed.
 */
function headlineTotals(PDO $pdo): array
{
    $tot = [
        'expected'       => 0.0,  // known-amount bills, billed to date
        'received'       => 0.0,  // payments received against those bills
        'overdue'        => 0.0,  // unpaid balance past its due date
        'due'            => 0.0,  // unpaid balance not yet due
        'awaited_count'  => 0,    // percentage bills waiting for an amount
        'overdue_bills'  => 0,
        'companies_owing'=> 0,
    ];
    $monthEnd = date('Y-m-t');
    $today    = date('Y-m-d');
    $owing    = [];
    foreach (loadBills($pdo) as $b) {
        if ($b['bill_month'] > $monthEnd) {
            continue; // future bill — not yet an obligation
        }
        if ($b['expected_amount'] === null) {
            $tot['awaited_count']++;
            continue;
        }
        $expected = (float)$b['expected_amount'];
        $balance  = max(0.0, $expected - $b['paid']);
        $tot['expected'] += $expected;
        $tot['received'] += min($b['paid'], $expected);
        if ($balance > 0) {
            $owing[$b['agreement_id']] = true;
            if ($b['due_date'] < $today) {
                $tot['overdue'] += $balance;
                $tot['overdue_bills']++;
            } else {
                $tot['due'] += $balance;
            }
        }
    }
    $tot['companies_owing'] = count($owing);
    return $tot;
}

/** Per-company follow-up list: who owes what, worst first. */
function followUps(PDO $pdo): array
{
    $today = date('Y-m-d');
    $rows  = [];
    foreach (loadBills($pdo) as $b) {
        if ($b['expected_amount'] === null || $b['bill_month'] > date('Y-m-t')) {
            continue;
        }
        $balance = (float)$b['expected_amount'] - $b['paid'];
        if ($balance <= 0) {
            continue;
        }
        $id = $b['agreement_id'];
        if (!isset($rows[$id])) {
            $rows[$id] = [
                'agreement_id'  => $id,
                'supplier_name' => $b['supplier_name'],
                'ledger_code'   => $b['ledger_code'],
                'overdue'       => 0.0,
                'due'           => 0.0,
                'oldest_due'    => null,
                'bills_open'    => 0,
            ];
        }
        $rows[$id]['bills_open']++;
        if ($b['due_date'] < $today) {
            $rows[$id]['overdue'] += $balance;
            if ($rows[$id]['oldest_due'] === null || $b['due_date'] < $rows[$id]['oldest_due']) {
                $rows[$id]['oldest_due'] = $b['due_date'];
            }
        } else {
            $rows[$id]['due'] += $balance;
        }
    }
    $rows = array_values(array_filter($rows, fn($r) => $r['overdue'] > 0 || $r['due'] > 0));
    usort($rows, fn($a, $b) => $b['overdue'] <=> $a['overdue']);
    return $rows;
}

/** Agreements whose period ends within the warning window (or already ended). */
function expiringSoon(PDO $pdo): array
{
    $today = date('Y-m-d');
    $limit = date('Y-m-d', strtotime('+' . (int)cfg('expiry_warn_days') . ' days'));
    $st = $pdo->prepare(
        'SELECT * FROM agreements WHERE period_to BETWEEN :t AND :l ORDER BY period_to ASC'
    );
    $st->execute([':t' => $today, ':l' => $limit]);
    return $st->fetchAll();
}

/** Human label for a status key. */
function statusLabel(string $s): string
{
    return [
        'received' => 'Received',
        'partial'  => 'Partial',
        'due'      => 'Due',
        'overdue'  => 'Overdue',
        'awaited'  => 'Awaited',
        'out'      => 'Out of Period',
        'nobill'   => 'No Bill',
    ][$s] ?? $s;
}
