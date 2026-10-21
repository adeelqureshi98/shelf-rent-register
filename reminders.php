<?php
/**
 * Reminders — every open bill and incentive grouped by urgency
 * (overdue / due in 7 days / due in 30 days), with one-click
 * WhatsApp and email reminders and a "Mark sent" log.
 *
 * Honest note: a web page cannot push WhatsApp messages by
 * itself — the WhatsApp button opens a chat with the message
 * already written, and staff press send there. Fully automatic
 * background sending needs the WhatsApp Business API; the
 * schedulable automatic path built here is email, via
 * cron_reminders.php (see README_SETUP.md).
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/billing.php';
require_once __DIR__ . '/layout.php';

requireLogin();
$pdo = db();
syncBills($pdo);
$write = canWrite();

/* ---- Mark a reminder as sent ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_sent') {
    requireRole(['admin', 'accountant']);
    checkCsrf();
    $agId    = (int)($_POST['agreement_id'] ?? 0);
    $channel = $_POST['channel'] ?? '';
    $label   = trim($_POST['item_label'] ?? '');
    $amount  = ($_POST['amount'] ?? '') !== '' ? (float)$_POST['amount'] : null;
    if ($agId > 0 && in_array($channel, ['WhatsApp', 'Email'], true) && $label !== '') {
        $pdo->prepare('INSERT INTO reminder_log (agreement_id, channel, item_label, amount, sent_by) VALUES (:a, :c, :l, :amt, :u)')
            ->execute([':a' => $agId, ':c' => $channel, ':l' => $label, ':amt' => $amount, ':u' => currentUser()['id']]);
        $logId = (int)$pdo->lastInsertId();
        audit($pdo, 'reminder_sent', 'reminder', $logId, $channel . ' reminder marked sent: "' . $label . '"' . ($amount !== null ? ' — ' . money($amount) : '') . '.');
        flash('success', 'Reminder logged as sent.');
    } else {
        flash('error', 'That reminder could not be logged.');
    }
    header('Location: reminders.php');
    exit;
}

/* ---- Send one reminder through the WhatsApp Cloud API ----
 * Only possible when an Admin has configured the API on the
 * Settings page; otherwise this action explains itself and the
 * one-click wa.me button remains the WhatsApp path. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'wa_api_send') {
    requireRole(['admin', 'accountant']);
    checkCsrf();
    $agId   = (int)($_POST['agreement_id'] ?? 0);
    $phone  = preg_replace('/\D+/', '', $_POST['phone'] ?? '');
    if (str_starts_with($phone, '0')) $phone = '92' . substr($phone, 1);
    if (str_starts_with($phone, '3')) $phone = '92' . $phone;
    $label  = trim($_POST['item_label'] ?? '');
    $amount = ($_POST['amount'] ?? '') !== '' ? (float)$_POST['amount'] : null;
    $agSt = $pdo->prepare('SELECT supplier_name, contact_person FROM agreements WHERE id = :id');
    $agSt->execute([':id' => $agId]);
    $agRow = $agSt->fetch();
    if (!$agRow || $phone === '' || $label === '') {
        flash('error', 'That reminder could not be sent.');
    } else {
        [$ok, $msg] = whatsappApiSend($pdo, $phone, [
            $agRow['contact_person'] ?: $agRow['supplier_name'],
            $label,
            $amount !== null ? money($amount) : '',
            trim($_POST['due_date'] ?? ''),
        ]);
        if ($ok) {
            $pdo->prepare('INSERT INTO reminder_log (agreement_id, channel, item_label, amount, sent_by) VALUES (:a, \'WhatsApp\', :l, :amt, :u)')
                ->execute([':a' => $agId, ':l' => $label, ':amt' => $amount, ':u' => currentUser()['id']]);
            audit($pdo, 'reminder_sent', 'reminder', null, 'WhatsApp Cloud API reminder sent: "' . $label . '".');
        }
        flash($ok ? 'success' : 'error', $msg);
    }
    header('Location: reminders.php');
    exit;
}

/* ---- Contact details per agreement (loadBills does not carry them) ---- */
$contacts = [];
foreach ($pdo->query('SELECT id, contact_person, contact_phone, contact_email FROM agreements')->fetchAll() as $c) {
    $contacts[(int)$c['id']] = $c;
}

/* ---- Build the reminder items ---- */
$today = date('Y-m-d');
$d7    = date('Y-m-d', strtotime('+7 days'));
$d30   = date('Y-m-d', strtotime('+30 days'));

function bucketOf(string $due, string $today, string $d7, string $d30): ?string
{
    if ($due < $today)  return 'overdue';
    if ($due <= $d7)    return 'due7';
    if ($due <= $d30)   return 'due30';
    return null;
}

$items = [];
foreach (loadBills($pdo) as $b) {
    if ($b['expected_amount'] === null) continue;
    $balance = (float)$b['expected_amount'] - $b['paid'];
    if ($balance <= 0) continue;
    $bucket = bucketOf($b['due_date'], $today, $d7, $d30);
    if ($bucket === null) continue;
    $c = $contacts[(int)$b['agreement_id']] ?? [];
    $items[] = [
        'bucket' => $bucket, 'agreement_id' => (int)$b['agreement_id'],
        'supplier_name' => $b['supplier_name'], 'ledger_code' => $b['ledger_code'],
        'contact_person' => $c['contact_person'] ?? null, 'contact_phone' => $c['contact_phone'] ?? null,
        'contact_email' => $c['contact_email'] ?? null,
        'item' => 'Shelf rent — ' . date('F Y', strtotime($b['bill_month'])) . ' bill',
        'amount' => $balance, 'date' => $b['due_date'],
    ];
}
foreach (loadIncentives($pdo) as $inc) {
    if ($inc['expected_amount'] === null || $inc['balance'] === null || $inc['balance'] <= 0) continue;
    $bucket = bucketOf($inc['expected_date'], $today, $d7, $d30);
    if ($bucket === null) continue;
    $c = $contacts[(int)$inc['agreement_id']] ?? [];
    $items[] = [
        'bucket' => $bucket, 'agreement_id' => (int)$inc['agreement_id'],
        'supplier_name' => $inc['supplier_name'], 'ledger_code' => $inc['ledger_code'],
        'contact_person' => $c['contact_person'] ?? null, 'contact_phone' => $c['contact_phone'] ?? null,
        'contact_email' => $c['contact_email'] ?? null,
        'item' => 'Incentive — ' . $inc['title'],
        'amount' => $inc['balance'], 'date' => $inc['expected_date'],
    ];
}

/** Local number (0300-…) → international digits for wa.me (92…). */
function waNumber(?string $phone): ?string
{
    if (!$phone) return null;
    $d = preg_replace('/\D+/', '', $phone);
    if ($d === '') return null;
    if (str_starts_with($d, '0'))  $d = '92' . substr($d, 1);
    if (str_starts_with($d, '3'))  $d = '92' . $d;
    return $d;
}

function reminderText(array $it): string
{
    $who = $it['contact_person'] ? ' ' . $it['contact_person'] : '';
    return 'Assalam-o-Alaikum' . $who . ', this is ' . MART_NAME . '. A polite reminder that '
        . money($it['amount']) . ' for ' . $it['item'] . ' (due ' . date('d M Y', strtotime($it['date'])) . ') '
        . 'is outstanding at our end. We would be grateful if it could be settled at the earliest. Thank you.';
}

$histTotal = (int)$pdo->query('SELECT COUNT(*) FROM reminder_log')->fetchColumn();
[$hPage, $hPages, $hOffset] = paginate($histTotal, 20);
$histSt = $pdo->prepare(
    'SELECT rl.*, a.supplier_name, u.name AS by_name
       FROM reminder_log rl
       JOIN agreements a ON a.id = rl.agreement_id
       LEFT JOIN users u ON u.id = rl.sent_by
      ORDER BY rl.sent_at DESC, rl.id DESC
      LIMIT :lim OFFSET :off'
);
$histSt->bindValue(':lim', 20, PDO::PARAM_INT);
$histSt->bindValue(':off', $hOffset, PDO::PARAM_INT);
$histSt->execute();
$history = $histSt->fetchAll();
$waApiReady = whatsappApiConfigured($pdo);

$sections = [
    'overdue' => [t('overdue'), 'bi-exclamation-octagon text-danger', 'pill-overdue'],
    'due7'    => [t('due_next_7'), 'bi-alarm text-warning', 'pill-partial'],
    'due30'   => [t('due_next_30'), 'bi-calendar-event', 'pill-due'],
];

layoutHead(t('reminders'), 'reminders');
pageTitle(t('reminders'), t('reminders_subtitle'));
?>

<?php foreach ($sections as $key => [$label, $icon, $pill]): $rows = array_values(array_filter($items, fn($i) => $i['bucket'] === $key)); ?>
<div class="panel mb-3 p-0 overflow-hidden">
  <div class="panel-head px-4 pt-3">
    <h2><i class="bi <?= $icon ?> me-2"></i><?= e($label) ?> <span class="cell-sub">(<?= count($rows) ?>)</span></h2>
  </div>
  <div class="table-responsive">
    <table class="table table-luxe reminders-table align-middle mb-0">
      <colgroup><col style="width:15%"><col style="width:20%"><col style="width:14%"><col style="width:9%"><col style="width:10%"><col style="width:11%"><col style="width:21%"></colgroup>
      <thead><tr><th><?= e(t('company')) ?></th><th><?= e(t('contact')) ?></th><th><?= e(t('item')) ?></th><th><?= e(t('due_date')) ?></th><th class="text-end"><?= e(t('amount')) ?></th><th><?= e(t('days')) ?></th><th class="text-end no-print"><?= e(t('actions')) ?></th></tr></thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="7" class="text-center py-3 text-muted"><?= e(t('nothing_in_band')) ?></td></tr>
      <?php endif; ?>
      <?php foreach ($rows as $it):
        $days = (int)round((strtotime($it['date']) - strtotime($today)) / 86400);
        $wa = waNumber($it['contact_phone']);
        $text = reminderText($it);
      ?>
        <tr>
          <td><a href="company.php?id=<?= (int)$it['agreement_id'] ?>"><?= e($it['supplier_name']) ?></a>
              <div class="cell-sub"><?= e($it['ledger_code']) ?></div></td>
          <td><?= e($it['contact_person'] ?? '—') ?>
            <?php if ($it['contact_phone']): ?><div class="cell-sub"><?= e($it['contact_phone']) ?></div><?php endif; ?>
            <?php if ($it['contact_email']): ?><div class="cell-sub"><?= e($it['contact_email']) ?></div><?php endif; ?>
          </td>
          <td><?= e($it['item']) ?></td>
          <td class="text-nowrap"><?= date('d M Y', strtotime($it['date'])) ?></td>
          <td class="text-end fw-semibold"><?= money($it['amount']) ?></td>
          <td><span class="pill <?= $pill ?>"><?= $days < 0 ? e(sprintf(t('days_late'), abs($days))) : ($days === 0 ? e(t('due_today')) : e(sprintf(t('days_left_paren'), $days))) ?></span></td>
          <td class="no-print">
            <div class="d-flex flex-wrap gap-1 justify-content-end mb-1">
            <?php if ($wa): ?>
              <a class="btn btn-sm btn-success text-nowrap" target="_blank" rel="noopener" href="https://wa.me/<?= e($wa) ?>?text=<?= urlencode($text) ?>"><i class="bi bi-whatsapp"></i> <?= e(t('whatsapp')) ?></a>
            <?php endif; ?>
            <?php if ($it['contact_email']): ?>
              <a class="btn btn-sm btn-light border text-nowrap" href="mailto:<?= e($it['contact_email']) ?>?subject=<?= urlencode('Payment reminder — ' . MART_NAME) ?>&body=<?= urlencode($text) ?>"><i class="bi bi-envelope"></i> <?= e(t('email')) ?></a>
            <?php endif; ?>
            </div>
            <?php if ($write && $waApiReady && $wa): ?>
              <form method="post" class="d-flex gap-1 align-items-center justify-content-end mb-1">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="wa_api_send">
                <input type="hidden" name="agreement_id" value="<?= (int)$it['agreement_id'] ?>">
                <input type="hidden" name="phone" value="<?= e($it['contact_phone'] ?? '') ?>">
                <input type="hidden" name="item_label" value="<?= e($it['item']) ?>">
                <input type="hidden" name="amount" value="<?= e($it['amount']) ?>">
                <input type="hidden" name="due_date" value="<?= e(date('d M Y', strtotime($it['date']))) ?>">
                <button class="btn btn-sm btn-outline-success text-nowrap" type="submit"><i class="bi bi-whatsapp"></i> <?= e(t('send_via_api')) ?></button>
              </form>
            <?php endif; ?>
            <?php if ($write): ?>
              <form method="post" class="d-flex gap-1 align-items-center justify-content-end">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="mark_sent">
                <input type="hidden" name="agreement_id" value="<?= (int)$it['agreement_id'] ?>">
                <input type="hidden" name="item_label" value="<?= e($it['item']) ?>">
                <input type="hidden" name="amount" value="<?= e($it['amount']) ?>">
                <select class="form-select form-select-sm w-auto" name="channel">
                  <option>WhatsApp</option><option>Email</option>
                </select>
                <button class="btn btn-sm btn-gold text-nowrap" type="submit"><?= e(t('mark_sent')) ?></button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endforeach; ?>

<div class="panel">
  <div class="panel-head"><h2><i class="bi bi-clock-history me-2"></i><?= e(t('reminder_history')) ?></h2></div>
  <?php if (!$history): ?>
    <p class="empty-note"><?= e(t('no_reminders_logged')) ?></p>
  <?php else: ?>
    <div class="table-responsive">
      <table class="table table-luxe align-middle mb-0">
        <thead><tr><th><?= e(t('sent_at')) ?></th><th><?= e(t('company')) ?></th><th><?= e(t('channel')) ?></th><th><?= e(t('item')) ?></th><th class="text-end"><?= e(t('amount')) ?></th><th><?= e(t('sent_by')) ?></th></tr></thead>
        <tbody>
        <?php foreach ($history as $h): ?>
          <tr>
            <td><?= date('d M Y, H:i', strtotime($h['sent_at'])) ?></td>
            <td><?= e($h['supplier_name']) ?></td>
            <td><?= $h['channel'] === 'WhatsApp' ? '<i class="bi bi-whatsapp text-success"></i> ' : '<i class="bi bi-envelope"></i> ' ?><?= e($h['channel']) ?></td>
            <td><?= e($h['item_label']) ?></td>
            <td class="text-end"><?= $h['amount'] === null ? '—' : money($h['amount']) ?></td>
            <td><?= e($h['by_name'] ?? t('automatic_cron')) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
  <?php pageNav($hPage, $hPages, 'reminders.php'); ?>
  <p class="cell-sub mt-3 mb-0">
    <?= e(t('auto_note_1')) ?><code>php cron_reminders.php</code><?= e(t('auto_note_2')) ?>
  </p>
  <p class="cell-sub mt-1 mb-0">
    <i class="bi bi-whatsapp"></i> <?= e($waApiReady ? t('wa_api_configured') : t('wa_api_not_configured')) ?>
  </p>
</div>

<?php layoutFoot(); ?>
