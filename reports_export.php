<?php
/**
 * CSV downloads for every report on the Reports page.
 *   reports_export.php?type=monthly&year=2026
 *   reports_export.php?type=overdue | company | aging | methods |
 *                        incentives | expiring
 * Any signed-in user may export (same visibility as the Reports
 * page itself); figures come from billing.php, so a CSV always
 * agrees with the screen it was downloaded from.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/billing.php';
require_once __DIR__ . '/auth.php';

requireLogin();
$pdo = db();
syncBills($pdo);

function csvOut(string $filename, array $header, array $rows): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM so Excel opens UTF-8 cleanly
    fputcsv($out, $header);
    foreach ($rows as $r) {
        fputcsv($out, $r);
    }
    fclose($out);
    exit;
}

$type = $_GET['type'] ?? '';
$today = date('Y-m-d');

if ($type === 'monthly') {
    $year = max(2020, min(2100, (int)($_GET['year'] ?? date('Y'))));
    $months = [];
    for ($m = 1; $m <= 12; $m++) $months[$m] = ['expected' => 0.0, 'received' => 0.0, 'awaited' => 0];
    foreach (loadBills($pdo, $year) as $b) {
        $m = (int)date('n', strtotime($b['bill_month']));
        if ($b['expected_amount'] === null) { $months[$m]['awaited']++; continue; }
        $months[$m]['expected'] += (float)$b['expected_amount'];
        $months[$m]['received'] += min($b['paid'], (float)$b['expected_amount']);
    }
    $rows = []; $tE = 0; $tR = 0;
    foreach ($months as $m => $v) {
        $tE += $v['expected']; $tR += $v['received'];
        $rows[] = [date('F', mktime(0, 0, 0, $m, 1)), number_format($v['expected'], 2, '.', ''), number_format($v['received'], 2, '.', ''),
                   number_format(max(0, $v['expected'] - $v['received']), 2, '.', ''),
                   $v['expected'] > 0 ? number_format($v['received'] / $v['expected'] * 100, 1) : '', $v['awaited']];
    }
    $rows[] = ['Total ' . $year, number_format($tE, 2, '.', ''), number_format($tR, 2, '.', ''), number_format(max(0, $tE - $tR), 2, '.', ''), $tE > 0 ? number_format($tR / $tE * 100, 1) : '', ''];
    csvOut('monthly-recovery-' . $year . '.csv', ['Month', 'Expected', 'Received', 'Outstanding', 'Recovery %', 'Awaited bills'], $rows);
}

if ($type === 'overdue') {
    $overdue = [];
    foreach (loadBills($pdo) as $b) {
        if ($b['expected_amount'] === null || $b['due_date'] >= $today) continue;
        $balance = (float)$b['expected_amount'] - $b['paid'];
        if ($balance <= 0) continue;
        $id = $b['agreement_id'];
        $overdue[$id] ??= ['name' => $b['supplier_name'], 'code' => $b['ledger_code'], 'bills' => 0, 'amount' => 0.0, 'oldest' => $b['due_date']];
        $overdue[$id]['bills']++;
        $overdue[$id]['amount'] += $balance;
        if ($b['due_date'] < $overdue[$id]['oldest']) $overdue[$id]['oldest'] = $b['due_date'];
    }
    usort($overdue, fn($a, $b) => $b['amount'] <=> $a['amount']);
    $rows = []; $tot = 0;
    foreach ($overdue as $o) {
        $tot += $o['amount'];
        $rows[] = [$o['name'], $o['code'], $o['bills'], $o['oldest'], number_format($o['amount'], 2, '.', '')];
    }
    $rows[] = ['Total overdue', '', '', '', number_format($tot, 2, '.', '')];
    csvOut('overdue-by-company.csv', ['Company', 'Ledger code', 'Overdue bills', 'Oldest due date', 'Overdue amount'], $rows);
}

if ($type === 'company') {
    $sum = [];
    foreach (loadBills($pdo) as $b) {
        $id = $b['agreement_id'];
        $sum[$id] ??= ['name' => $b['supplier_name'], 'code' => $b['ledger_code'], 'expected' => 0.0, 'received' => 0.0];
        if ($b['expected_amount'] === null) continue;
        $sum[$id]['expected'] += (float)$b['expected_amount'];
        $sum[$id]['received'] += min($b['paid'], (float)$b['expected_amount']);
    }
    $rows = [];
    foreach ($sum as $s) {
        $rows[] = [$s['name'], $s['code'], number_format($s['expected'], 2, '.', ''), number_format($s['received'], 2, '.', ''),
                   number_format(max(0, $s['expected'] - $s['received']), 2, '.', ''),
                   $s['expected'] > 0 ? number_format($s['received'] / $s['expected'] * 100, 1) : ''];
    }
    csvOut('company-summary.csv', ['Company', 'Ledger code', 'Expected', 'Received', 'Outstanding', 'Recovery %'], $rows);
}

if ($type === 'aging') {
    $buckets = ['0–30 days' => [0, 0.0], '31–60 days' => [0, 0.0], '61–90 days' => [0, 0.0], '90+ days' => [0, 0.0]];
    foreach (loadBills($pdo) as $b) {
        if ($b['expected_amount'] === null || $b['due_date'] >= $today) continue;
        $balance = (float)$b['expected_amount'] - $b['paid'];
        if ($balance <= 0) continue;
        $days = (int)floor((strtotime($today) - strtotime($b['due_date'])) / 86400);
        $key = $days <= 30 ? '0–30 days' : ($days <= 60 ? '31–60 days' : ($days <= 90 ? '61–90 days' : '90+ days'));
        $buckets[$key][0]++;
        $buckets[$key][1] += $balance;
    }
    $rows = [];
    foreach ($buckets as $k => [$n, $amt]) $rows[] = [$k, $n, number_format($amt, 2, '.', '')];
    csvOut('aging-report.csv', ['Overdue bucket', 'Bills', 'Amount'], $rows);
}

if ($type === 'methods') {
    $rows = [];
    foreach ($pdo->query('SELECT method, COUNT(*) AS n, SUM(amount) AS total FROM payments GROUP BY method ORDER BY total DESC')->fetchAll() as $r) {
        $rows[] = [$r['method'], (int)$r['n'], number_format((float)$r['total'], 2, '.', '')];
    }
    csvOut('payment-methods.csv', ['Method', 'Payments', 'Total received'], $rows);
}

if ($type === 'incentives') {
    $per = [];
    foreach (loadIncentives($pdo) as $inc) {
        $id = $inc['agreement_id'];
        $per[$id] ??= ['name' => $inc['supplier_name'], 'code' => $inc['ledger_code'], 'expected' => 0.0, 'received' => 0.0, 'overdue' => 0.0, 'awaited' => 0];
        if ($inc['expected_amount'] === null) { $per[$id]['awaited']++; continue; }
        $per[$id]['expected'] += (float)$inc['expected_amount'];
        $per[$id]['received'] += min((float)$inc['received_amount'], (float)$inc['expected_amount']);
        if ($inc['expected_date'] < $today && $inc['balance'] !== null) $per[$id]['overdue'] += $inc['balance'];
    }
    $rows = [];
    foreach ($per as $s) {
        $rows[] = [$s['name'], $s['code'], number_format($s['expected'], 2, '.', ''), number_format($s['received'], 2, '.', ''),
                   number_format($s['overdue'], 2, '.', ''), $s['awaited']];
    }
    csvOut('incentive-report.csv', ['Company', 'Ledger code', 'Expected', 'Received', 'Overdue balance', 'Awaited'], $rows);
}

if ($type === 'expiring') {
    $rows = [];
    $st = $pdo->prepare('SELECT * FROM agreements WHERE period_to BETWEEN :t AND :l ORDER BY period_to ASC');
    $st->execute([':t' => $today, ':l' => date('Y-m-d', strtotime('+90 days'))]);
    foreach ($st->fetchAll() as $ag) {
        $days = (int)round((strtotime($ag['period_to']) - strtotime($today)) / 86400);
        $rows[] = [$ag['supplier_name'], $ag['ledger_code'], $ag['period_to'], $days, $days <= 30 ? 'Within 30 days' : ($days <= 60 ? 'Within 60 days' : 'Within 90 days')];
    }
    csvOut('expiring-agreements.csv', ['Company', 'Ledger code', 'Period ends', 'Days left', 'Window'], $rows);
}

if ($type === 'income') {
    $year = max(2020, min(2100, (int)($_GET['year'] ?? date('Y'))));
    $month = isset($_GET['month']) && $_GET['month'] !== '' ? (int)$_GET['month'] : null;
    if ($month !== null && ($month < 1 || $month > 12)) $month = null;
    $s = incomeStatement($pdo, $year, $month);
    $row = fn(string $label, array $r) => [$label, number_format($r['expected'], 2, '.', ''), number_format($r['received'], 2, '.', ''),
        number_format($r['outstanding'], 2, '.', ''), $r['recovery_pct'] === null ? '' : number_format($r['recovery_pct'], 1)];
    $rows = [$row('Shelf Rent Income', $s['rent']), $row('Incentive Income', $s['incentive']), $row('Total Income', $s['total']),
             ['Awaited bills (count only, never money)', $s['awaited_bills'], '', '', ''],
             ['Awaited incentives (count only, never money)', $s['awaited_incentives'], '', '', '']];
    $suffix = $year . ($month !== null ? '-' . str_pad((string)$month, 2, '0', STR_PAD_LEFT) : '');
    csvOut('income-statement-' . $suffix . '.csv', ['Income', 'Expected', 'Received', 'Outstanding', 'Recovery %'], $rows);
}

if ($type === 'position') {
    $p = financialPosition($pdo);
    $f = fn($v) => number_format((float)$v, 2, '.', '');
    $rows = [
        ['Shelf rent', $f($p['rent_expected']), $f($p['rent_received']), $f($p['rent_outstanding']), $f($p['rent_overdue'])],
        ['Incentives', $f($p['inc_expected']), $f($p['inc_received']), $f($p['inc_outstanding']), $f($p['inc_overdue'])],
        ['Total', $f($p['total_expected']), $f($p['total_received']), $f($p['total_outstanding']), $f($p['overdue'])],
        ['Not yet due (part of outstanding)', '', '', $f($p['not_yet_due']), ''],
        ['Awaited bills (count only)', $p['awaited_bills'], '', '', ''],
        ['Awaited incentives (count only)', $p['awaited_incentives'], '', '', ''],
        ['Rent aging 0-30 days', $p['aging']['0_30'][0], $f($p['aging']['0_30'][1]), '', ''],
        ['Rent aging 31-60 days', $p['aging']['31_60'][0], $f($p['aging']['31_60'][1]), '', ''],
        ['Rent aging 61-90 days', $p['aging']['61_90'][0], $f($p['aging']['61_90'][1]), '', ''],
        ['Rent aging 90+ days', $p['aging']['90p'][0], $f($p['aging']['90p'][1]), '', ''],
    ];
    csvOut('financial-position-' . $p['as_of'] . '.csv', ['Item', 'Expected / Bills', 'Received / Amount', 'Outstanding', 'Overdue'], $rows);
}

if ($type === 'cash') {
    $year = max(2020, min(2100, (int)($_GET['year'] ?? date('Y'))));
    $c = cashReceived($pdo, $year);
    $rows = [];
    foreach ($c['months'] as $m => $v) {
        $rows[] = [date('F', mktime(0, 0, 0, $m, 1)), $v['count'], number_format($v['amount'], 2, '.', '')];
    }
    $rows[] = ['Total cash in ' . $year, $c['total_count'], number_format($c['total_amount'], 2, '.', '')];
    $rows[] = ['Incentive receipts (all periods, as recorded - not month-allocated)', '', number_format($c['incentive_received_total'], 2, '.', '')];
    csvOut('cash-received-' . $year . '.csv', ['Month', 'Payments', 'Cash in'], $rows);
}

http_response_code(400);
exit('Unknown report type.');
