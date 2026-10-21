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

/** Human label for a status key (translated when the language pack is loaded). */
function statusLabel(string $s): string
{
    if (function_exists('t')) {
        return t($s);
    }
    return [
        'received' => 'Received',
        'partial'  => 'Partial',
        'due'      => 'Due',
        'overdue'  => 'Overdue',
        'awaited'  => 'Awaited',
        'pending'  => 'Pending',
        'out'      => 'Out of Period',
        'nobill'   => 'No Bill',
    ][$s] ?? $s;
}

/* ============================================================
 * Incentives — the register's second half. Same philosophy as
 * bills: one read path (loadIncentives) feeds the incentives
 * page, the dashboard panel, the company ledger and reports,
 * so their numbers always agree.
 *
 * Status precedence mirrors billStatus():
 *   awaited  — percentage incentive, actual amount not known yet
 *   received — received in full
 *   partial  — part received, balance still outstanding
 *   overdue  — expected date passed, nothing received
 *   pending  — expected date still ahead, nothing received
 * (A partial incentive past its date is still labelled Partial —
 * its remaining balance counts towards the overdue money total.)
 * ============================================================ */
function incentiveStatus(array $inc, ?string $today = null): string
{
    $today = $today ?? date('Y-m-d');
    if ($inc['expected_amount'] === null) {
        return 'awaited';
    }
    $expected = (float)$inc['expected_amount'];
    $received = (float)$inc['received_amount'];
    if ($received >= $expected - 0.005) {
        return 'received';
    }
    if ($received > 0) {
        return 'partial';
    }
    return $inc['expected_date'] < $today ? 'overdue' : 'pending';
}

/** All incentives (optionally one company's), decorated with 'status' + 'balance'. */
function loadIncentives(PDO $pdo, ?int $agreementId = null): array
{
    $sql = 'SELECT i.*, a.supplier_name, a.ledger_code
              FROM incentives i
              JOIN agreements a ON a.id = i.agreement_id';
    $args = [];
    if ($agreementId !== null) {
        $sql .= ' WHERE i.agreement_id = :ag';
        $args[':ag'] = $agreementId;
    }
    $sql .= ' ORDER BY i.expected_date, i.id';
    $st = $pdo->prepare($sql);
    $st->execute($args);
    $rows = $st->fetchAll();
    $today = date('Y-m-d');
    foreach ($rows as &$r) {
        $r['status']  = incentiveStatus($r, $today);
        $r['balance'] = $r['expected_amount'] === null
            ? null
            : max(0.0, (float)$r['expected_amount'] - (float)$r['received_amount']);
    }
    unset($r);
    return $rows;
}

/**
 * Incentive headline totals. "Overdue" here means money: the unpaid
 * balance of every known-amount incentive whose expected date has
 * passed (including part-paid ones). Awaited amounts never count
 * as money — exactly like percentage bills.
 */
function incentiveTotals(PDO $pdo): array
{
    $tot = ['expected' => 0.0, 'received' => 0.0, 'overdue' => 0.0, 'awaited_count' => 0];
    $today = date('Y-m-d');
    foreach (loadIncentives($pdo) as $inc) {
        if ($inc['expected_amount'] === null) {
            $tot['awaited_count']++;
            continue;
        }
        $expected = (float)$inc['expected_amount'];
        $received = (float)$inc['received_amount'];
        $tot['expected'] += $expected;
        $tot['received'] += min($received, $expected);
        if ($inc['expected_date'] < $today && $received < $expected - 0.005) {
            $tot['overdue'] += $expected - $received;
        }
    }
    return $tot;
}

/* ============================================================
 * Financial Hub (v3) — three views over the SAME read paths
 * above, so the Financial Hub page, the Reports panels and the
 * CSV exports can never drift from the dashboard or each other.
 *
 * Honest scope (printed on the page and in ONE_PAGE_NOTE.md):
 *  - The Income Statement covers shelf rent + incentive income
 *    ONLY. It is not the mart's full trading profit & loss: the
 *    register holds no expense data, so nothing is deducted.
 *  - The Financial Position is a RECEIVABLES position only —
 *    not a full balance sheet (no other assets, liabilities or
 *    capital exist in this register).
 *  - Cash Received month-allocates RENT payments by their real
 *    payment_date. Incentive receipts are recorded as amounts
 *    with no monthly payment-date ledger, so they appear only
 *    as a separate total — never allocated to invented months.
 * ============================================================ */

/**
 * Income Statement for one period: bills whose bill_month falls in
 * the period + incentives whose expected_date falls in the period.
 * $month = null means the whole year. Known amounts only; awaited
 * items are counted, never turned into money.
 * Returns rent / incentive / total rows, each:
 *   expected, received, outstanding, recovery_pct (null if no expected)
 * plus 'awaited_bills' and 'awaited_incentives' counts.
 */
function incomeStatement(PDO $pdo, int $year, ?int $month = null): array
{
    $mk = fn() => ['expected' => 0.0, 'received' => 0.0, 'outstanding' => 0.0, 'recovery_pct' => null];
    $rent = $mk(); $inc = $mk();
    $awaitedBills = 0; $awaitedInc = 0;

    foreach (loadBills($pdo, $year) as $b) {
        if ($month !== null && (int)date('n', strtotime($b['bill_month'])) !== $month) continue;
        if ($b['expected_amount'] === null) { $awaitedBills++; continue; }
        $expected = (float)$b['expected_amount'];
        $rent['expected'] += $expected;
        $rent['received'] += min($b['paid'], $expected);
    }
    foreach (loadIncentives($pdo) as $i) {
        if ((int)date('Y', strtotime($i['expected_date'])) !== $year) continue;
        if ($month !== null && (int)date('n', strtotime($i['expected_date'])) !== $month) continue;
        if ($i['expected_amount'] === null) { $awaitedInc++; continue; }
        $expected = (float)$i['expected_amount'];
        $inc['expected'] += $expected;
        $inc['received'] += min((float)$i['received_amount'], $expected);
    }
    foreach ([&$rent, &$inc] as &$r) {
        $r['outstanding'] = max(0.0, $r['expected'] - $r['received']);
        $r['recovery_pct'] = $r['expected'] > 0 ? $r['received'] / $r['expected'] * 100 : null;
    }
    unset($r);
    $total = $mk();
    $total['expected']    = $rent['expected'] + $inc['expected'];
    $total['received']    = $rent['received'] + $inc['received'];
    $total['outstanding'] = $rent['outstanding'] + $inc['outstanding'];
    $total['recovery_pct'] = $total['expected'] > 0 ? $total['received'] / $total['expected'] * 100 : null;

    return [
        'rent' => $rent, 'incentive' => $inc, 'total' => $total,
        'awaited_bills' => $awaitedBills, 'awaited_incentives' => $awaitedInc,
        'year' => $year, 'month' => $month,
    ];
}

/**
 * Financial Position (receivables) as of today. Rent uses the same
 * billed-to-date rule as headlineTotals(); incentives count every
 * known-amount incentive. Overdue = balance past its due/expected
 * date; not-yet-due = the rest of the outstanding money.
 */
function financialPosition(PDO $pdo): array
{
    $rent = headlineTotals($pdo);
    $incT = incentiveTotals($pdo);
    $today = date('Y-m-d');

    $incOutstanding = 0.0; $incOverdue = 0.0;
    foreach (loadIncentives($pdo) as $i) {
        if ($i['expected_amount'] === null || $i['balance'] === null) continue;
        $incOutstanding += $i['balance'];
        if ($i['expected_date'] < $today) $incOverdue += $i['balance'];
    }

    $rentOutstanding = $rent['overdue'] + $rent['due'];
    $overdue = $rent['overdue'] + $incOverdue;
    $totalOutstanding = $rentOutstanding + $incOutstanding;

    /* Rent aging buckets (same definition as the Reports aging panel). */
    $aging = ['0_30' => [0, 0.0], '31_60' => [0, 0.0], '61_90' => [0, 0.0], '90p' => [0, 0.0]];
    foreach (loadBills($pdo) as $b) {
        if ($b['expected_amount'] === null || $b['due_date'] >= $today) continue;
        $balance = (float)$b['expected_amount'] - $b['paid'];
        if ($balance <= 0) continue;
        $days = (int)floor((strtotime($today) - strtotime($b['due_date'])) / 86400);
        $key = $days <= 30 ? '0_30' : ($days <= 60 ? '31_60' : ($days <= 90 ? '61_90' : '90p'));
        $aging[$key][0]++;
        $aging[$key][1] += $balance;
    }

    return [
        'as_of'             => $today,
        'rent_expected'     => $rent['expected'],
        'rent_received'     => $rent['received'],
        'rent_outstanding'  => $rentOutstanding,
        'rent_overdue'      => $rent['overdue'],
        'inc_expected'      => $incT['expected'],
        'inc_received'      => $incT['received'],
        'inc_outstanding'   => $incOutstanding,
        'inc_overdue'       => $incOverdue,
        'total_expected'    => $rent['expected'] + $incT['expected'],
        'total_received'    => $rent['received'] + $incT['received'],
        'total_outstanding' => $totalOutstanding,
        'overdue'           => $overdue,
        'not_yet_due'       => max(0.0, $totalOutstanding - $overdue),
        'awaited_bills'     => $rent['awaited_count'],
        'awaited_incentives'=> $incT['awaited_count'],
        'aging'             => $aging,
    ];
}

/**
 * Cash Received for one year: RENT payments grouped by the month
 * of their real payment_date (cash-in view — deliberately different
 * from the bill-month recovery view). Plus the incentive receipts
 * total (all years, as recorded amounts) which is NOT allocated
 * to months, because incentives carry no payment-date ledger.
 */
function cashReceived(PDO $pdo, int $year): array
{
    $months = [];
    for ($m = 1; $m <= 12; $m++) $months[$m] = ['count' => 0, 'amount' => 0.0];
    $st = $pdo->prepare(
        'SELECT MONTH(payment_date) AS m, COUNT(*) AS n, SUM(amount) AS total
           FROM payments WHERE YEAR(payment_date) = :y GROUP BY MONTH(payment_date)'
    );
    $st->execute([':y' => $year]);
    foreach ($st->fetchAll() as $r) {
        $months[(int)$r['m']] = ['count' => (int)$r['n'], 'amount' => (float)$r['total']];
    }
    $incReceived = 0.0;
    foreach (loadIncentives($pdo) as $i) {
        if ($i['expected_amount'] === null) continue;
        $incReceived += min((float)$i['received_amount'], (float)$i['expected_amount']);
    }
    $count = 0; $amount = 0.0;
    foreach ($months as $v) { $count += $v['count']; $amount += $v['amount']; }
    return [
        'year' => $year, 'months' => $months,
        'total_count' => $count, 'total_amount' => $amount,
        'incentive_received_total' => $incReceived,
    ];
}
