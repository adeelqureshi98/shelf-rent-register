<?php
/**
 * Automatic email reminders — CLI ONLY.
 *
 * Run it from cron (Linux) or Task Scheduler (Windows), e.g. daily:
 *     php /path/to/shelf-rent-register/cron_reminders.php
 *
 * It emails every supplier whose bill or incentive is overdue or
 * falls due within the next 7 days (one email per open item),
 * using PHP's mail(), and writes each sent reminder into
 * reminder_log (sent_by = NULL marks it as automatic). Items for
 * suppliers without an email address are printed as SKIP lines.
 *
 * WhatsApp is intentionally NOT automated here: background
 * WhatsApp sending requires the WhatsApp Business API. The
 * Reminders page provides one-click WhatsApp instead.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script runs from the command line only.\n");
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php'; // money()/moneyFull() formatters (session is unused in CLI)
require_once __DIR__ . '/billing.php';

$pdo = db();
syncBills($pdo);

$today = date('Y-m-d');
$limit = date('Y-m-d', strtotime('+7 days'));

$contacts = [];
foreach ($pdo->query('SELECT id, supplier_name, contact_person, contact_email FROM agreements')->fetchAll() as $c) {
    $contacts[(int)$c['id']] = $c;
}

$items = [];
foreach (loadBills($pdo) as $b) {
    if ($b['expected_amount'] === null) continue;
    $balance = (float)$b['expected_amount'] - $b['paid'];
    if ($balance <= 0 || $b['due_date'] > $limit) continue;
    $items[] = [
        'agreement_id' => (int)$b['agreement_id'],
        'item' => 'Shelf rent — ' . date('F Y', strtotime($b['bill_month'])) . ' bill',
        'amount' => $balance, 'date' => $b['due_date'],
    ];
}
foreach (loadIncentives($pdo) as $inc) {
    if ($inc['expected_amount'] === null || $inc['balance'] === null || $inc['balance'] <= 0) continue;
    if ($inc['expected_date'] > $limit) continue;
    $items[] = [
        'agreement_id' => (int)$inc['agreement_id'],
        'item' => 'Incentive — ' . $inc['title'],
        'amount' => $inc['balance'], 'date' => $inc['expected_date'],
    ];
}

$log = $pdo->prepare('INSERT INTO reminder_log (agreement_id, channel, item_label, amount, sent_by) VALUES (:a, \'Email\', :l, :amt, NULL)');
$sent = 0; $skipped = 0; $failed = 0;
foreach ($items as $it) {
    $c = $contacts[$it['agreement_id']] ?? [];
    $name = $c['supplier_name'] ?? ('agreement #' . $it['agreement_id']);
    if (empty($c['contact_email'])) {
        echo "SKIP  {$name} — {$it['item']} (" . money($it['amount']) . "): no email address on file.\n";
        $skipped++;
        continue;
    }
    $who = !empty($c['contact_person']) ? ' ' . $c['contact_person'] : '';
    $subject = 'Payment reminder — ' . MART_NAME;
    $body = 'Assalam-o-Alaikum' . $who . ",\n\n"
        . 'This is an automatic reminder from ' . MART_NAME . ' (' . APP_NAME . ").\n"
        . 'Item: ' . $it['item'] . "\n"
        . 'Amount outstanding: ' . moneyFull($it['amount']) . "\n"
        . 'Due date: ' . date('d F Y', strtotime($it['date'])) . "\n\n"
        . "We would be grateful if it could be settled at the earliest.\nThank you.\n";
    $ok = @mail($c['contact_email'], $subject, $body, 'From: ' . MART_NAME . ' <no-reply@localhost>');
    if ($ok) {
        $log->execute([':a' => $it['agreement_id'], ':l' => $it['item'], ':amt' => $it['amount']]);
        echo "SENT  {$name} <{$c['contact_email']}> — {$it['item']} (" . money($it['amount']) . ")\n";
        $sent++;
    } else {
        echo "FAIL  {$name} <{$c['contact_email']}> — {$it['item']}: mail() returned false (no local mail server?).\n";
        $failed++;
    }
}
echo "Done: {$sent} sent, {$skipped} skipped (no email), {$failed} failed, " . count($items) . " item(s) due by {$limit}.\n";
