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
                   a.wht_percent, a.gst_percent, a.shelf_id,
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

/** "October 2026" style label for a bill_month date. */
function monthLabel(string $billMonth): string
{
    return date('F Y', strtotime($billMonth));
}

/** "4.5%" style label for a percentage rate value. */
function pct($rate): string
{
    if ($rate === null || $rate === '') return '—';
    return rtrim(rtrim(number_format((float)$rate, 2), '0'), '.') . '%';
}

/* ============================================================
 * v4 additions — settings, tax engine, chart data, cheques,
 * shelves, supplier slips and WhatsApp Cloud API plumbing.
 * Everything here is ADDITIVE: tax rates default to 0 and the
 * new tables start empty, so every v1–v3 figure is unchanged
 * until a user deliberately configures them.
 * ============================================================ */

/* ---------------- Settings (key/value, Admin-managed) ---------------- */
function getSettings(PDO $pdo): array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [
        'mart_ntn' => '', 'mart_strn' => '', 'mart_address' => '', 'mart_phone' => '',
        'wa_api_token' => '', 'wa_phone_number_id' => '', 'wa_template' => '',
    ];
    try {
        foreach ($pdo->query('SELECT skey, svalue FROM settings')->fetchAll() as $r) {
            $cache[$r['skey']] = $r['svalue'];
        }
    } catch (Throwable $ex) {
        // settings table not migrated yet — defaults apply.
    }
    return $cache;
}

function getSetting(PDO $pdo, string $key, string $default = ''): string
{
    $s = getSettings($pdo);
    return isset($s[$key]) && $s[$key] !== '' ? $s[$key] : $default;
}

function setSetting(PDO $pdo, string $key, string $value): void
{
    $pdo->prepare('INSERT INTO settings (skey, svalue) VALUES (:k, :v)
                   ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)')
        ->execute([':k' => $key, ':v' => $value]);
}

/* ---------------- Tax engine ----------------
 * WHT (withholding tax) and GST are configured per agreement
 * (percentages, default 0). For a bill of amount A:
 *   gst_amount = A × gst% / 100        (added on top)
 *   wht_amount = A × wht% / 100        (withheld by the mart)
 *   net_payable = A + gst − wht
 * With both rates 0 every figure collapses to A, so receipts,
 * statements and reports keep their exact v1–v3 numbers. */
function billTax(float $amount, float $whtPct, float $gstPct): array
{
    $gst = round($amount * $gstPct / 100, 2);
    $wht = round($amount * $whtPct / 100, 2);
    return [
        'amount'      => $amount,
        'gst_percent' => $gstPct,
        'wht_percent' => $whtPct,
        'gst_amount'  => $gst,
        'wht_amount'  => $wht,
        'net_payable' => round($amount + $gst - $wht, 2),
        'has_tax'     => ($gstPct > 0 || $whtPct > 0),
    ];
}

/** Tax view of one loaded bill row (loadBills carries the agreement's rates). */
function taxForBill(array $bill): array
{
    if ($bill['expected_amount'] === null) {
        return billTax(0.0, 0.0, 0.0) + ['has_tax' => false];
    }
    return billTax((float)$bill['expected_amount'], (float)($bill['wht_percent'] ?? 0), (float)($bill['gst_percent'] ?? 0));
}

/**
 * Tax summary for one year: totals over every bill raised in that
 * year (bill-month basis), per company and in total. Only bills
 * with a known amount and a non-zero rate contribute money.
 */
function taxSummary(PDO $pdo, int $year): array
{
    $per = [];
    $tot = ['bills' => 0, 'amount' => 0.0, 'gst' => 0.0, 'wht' => 0.0, 'net' => 0.0];
    foreach (loadBills($pdo, $year) as $b) {
        $tax = taxForBill($b);
        if (!$tax['has_tax'] || $b['expected_amount'] === null) continue;
        $id = $b['agreement_id'];
        $per[$id] ??= ['supplier_name' => $b['supplier_name'], 'ledger_code' => $b['ledger_code'],
                       'bills' => 0, 'amount' => 0.0, 'gst' => 0.0, 'wht' => 0.0, 'net' => 0.0];
        foreach (['amount', 'gst', 'wht', 'net'] as $k) {
            $v = $k === 'gst' ? $tax['gst_amount'] : ($k === 'wht' ? $tax['wht_amount'] : ($k === 'net' ? $tax['net_payable'] : $tax['amount']));
            $per[$id][$k] += $v;
            $tot[$k] += $v;
        }
        $per[$id]['bills']++;
        $tot['bills']++;
    }
    return ['per_company' => $per, 'total' => $tot, 'year' => $year];
}

/* ---------------- Dashboard chart data ---------------- */
/** Expected vs received per month of one year (bills by bill-month). */
function monthlyTrend(PDO $pdo, int $year): array
{
    $out = [];
    for ($m = 1; $m <= 12; $m++) $out[$m] = ['expected' => 0.0, 'received' => 0.0];
    foreach (loadBills($pdo, $year) as $b) {
        if ($b['expected_amount'] === null) continue;
        $m = (int)date('n', strtotime($b['bill_month']));
        $out[$m]['expected'] += (float)$b['expected_amount'];
        $out[$m]['received'] += min($b['paid'], (float)$b['expected_amount']);
    }
    return $out;
}

/** Recovery (received money) grouped by company, biggest first. */
function recoveryByCompany(PDO $pdo): array
{
    $per = [];
    foreach (loadBills($pdo) as $b) {
        if ($b['expected_amount'] === null) continue;
        $name = $b['supplier_name'];
        $per[$name] = ($per[$name] ?? 0.0) + min($b['paid'], (float)$b['expected_amount']);
    }
    $per = array_filter($per, fn($v) => $v > 0);
    arsort($per);
    return $per;
}

/* ---------------- Cheques (PDC register) ---------------- */
function loadCheques(PDO $pdo, ?string $status = null, ?int $agreementId = null): array
{
    $sql = 'SELECT c.*, a.supplier_name, a.ledger_code
              FROM cheques c JOIN agreements a ON a.id = c.agreement_id WHERE 1 = 1';
    $args = [];
    if ($status !== null && $status !== '') { $sql .= ' AND c.status = :s'; $args[':s'] = $status; }
    if ($agreementId !== null) { $sql .= ' AND c.agreement_id = :ag'; $args[':ag'] = $agreementId; }
    $sql .= ' ORDER BY c.cheque_date, c.id';
    $st = $pdo->prepare($sql);
    $st->execute($args);
    return $st->fetchAll();
}

function chequeStatusLabel(string $s): string
{
    if (function_exists('t')) {
        $map = ['Pending' => 'pending', 'Cleared' => 'received', 'Bounced' => 'cheque_bounced', 'Returned' => 'cheque_returned'];
        if (isset($map[$s])) return t($map[$s]);
    }
    return $s;
}

function slipStatusLabel(string $s): string
{
    if (function_exists('t')) {
        $map = ['Pending' => 'pending_review', 'Verified' => 'verified', 'Rejected' => 'rejected'];
        if (isset($map[$s])) return t($map[$s]);
    }
    return $s;
}

/** Outstanding balance of one bill (0 for awaited bills). */
function statementBalance(array $bill): float
{
    if (($bill['expected_amount'] ?? null) === null) return 0.0;
    return max(0.0, (float)$bill['expected_amount'] - (float)($bill['paid'] ?? 0));
}

/* ---------------- Shelves / shelf map ---------------- */
function loadShelves(PDO $pdo): array
{
    try {
        return $pdo->query('SELECT * FROM shelves ORDER BY sort_order, label')->fetchAll();
    } catch (Throwable $ex) {
        return [];
    }
}

/** agreement_id => shelf row, for agreements that have a shelf assigned. */
function shelfByAgreement(PDO $pdo): array
{
    $out = [];
    try {
        $rows = $pdo->query(
            'SELECT a.id AS agreement_id, s.* FROM agreements a JOIN shelves s ON s.id = a.shelf_id'
        )->fetchAll();
        foreach ($rows as $r) $out[(int)$r['agreement_id']] = $r;
    } catch (Throwable $ex) {
        // pre-v4 database
    }
    return $out;
}

/* ---------------- Supplier payment slips ---------------- */
function loadSlips(PDO $pdo, ?int $agreementId = null, ?string $status = null): array
{
    $sql = 'SELECT ps.*, a.supplier_name, a.ledger_code, u.name AS uploaded_name, r.name AS reviewer_name
              FROM payment_slips ps
              JOIN agreements a ON a.id = ps.agreement_id
              JOIN users u ON u.id = ps.uploaded_by
              LEFT JOIN users r ON r.id = ps.reviewed_by
             WHERE 1 = 1';
    $args = [];
    if ($agreementId !== null) { $sql .= ' AND ps.agreement_id = :ag'; $args[':ag'] = $agreementId; }
    if ($status !== null && $status !== '') { $sql .= ' AND ps.status = :s'; $args[':s'] = $status; }
    $sql .= ' ORDER BY ps.created_at DESC, ps.id DESC';
    $st = $pdo->prepare($sql);
    $st->execute($args);
    return $st->fetchAll();
}

/* ---------------- WhatsApp Cloud API (plumbing) ----------------
 * Inactive until an Admin fills the API token, phone-number ID
 * and template name on the Settings page. When configured, a
 * reminder can be sent through the official Cloud API; until
 * then the one-click wa.me flow is the only WhatsApp path and
 * this function is never called. No credentials ship with the
 * app — nothing is invented or hard-coded.
 * Returns [bool ok, string message]. */
function whatsappApiConfigured(PDO $pdo): bool
{
    return getSetting($pdo, 'wa_api_token') !== ''
        && getSetting($pdo, 'wa_phone_number_id') !== ''
        && getSetting($pdo, 'wa_template') !== '';
}

function whatsappApiSend(PDO $pdo, string $toDigits, array $templateParams): array
{
    if (!whatsappApiConfigured($pdo)) {
        return [false, 'WhatsApp Cloud API is not configured in Settings.'];
    }
    $url = 'https://graph.facebook.com/v21.0/' . rawurlencode(getSetting($pdo, 'wa_phone_number_id')) . '/messages';
    $components = [];
    if ($templateParams) {
        $params = [];
        foreach ($templateParams as $p) $params[] = ['type' => 'text', 'text' => (string)$p];
        $components[] = ['type' => 'body', 'parameters' => $params];
    }
    $payload = [
        'messaging_product' => 'whatsapp',
        'to' => $toDigits,
        'type' => 'template',
        'template' => [
            'name' => getSetting($pdo, 'wa_template'),
            'language' => ['code' => 'en'],
            'components' => $components,
        ],
    ];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . getSetting($pdo, 'wa_api_token'),
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($resp === false) {
        return [false, 'WhatsApp API request failed: ' . $err];
    }
    if ($code >= 200 && $code < 300) {
        return [true, 'Sent via WhatsApp Cloud API.'];
    }
    $data = json_decode((string)$resp, true);
    $msg = $data['error']['message'] ?? ('HTTP ' . $code);
    return [false, 'WhatsApp API error: ' . $msg];
}
