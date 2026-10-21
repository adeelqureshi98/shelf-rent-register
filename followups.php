<?php
/**
 * Follow-up calling list: every company with money overdue or amounts
 * awaited, sorted by the size of the problem — the page staff work from
 * when phoning suppliers.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/billing.php';
require_once __DIR__ . '/layout.php';

requireLogin();
$pdo = db();
syncBills($pdo);

$today = date('Y-m-d');
$rows  = [];
foreach (loadBills($pdo) as $b) {
    if ($b['bill_month'] > date('Y-m-t')) continue; // not yet billed
    $id = $b['agreement_id'];
    if (!isset($rows[$id])) {
        $st = $pdo->prepare('SELECT contact_person, contact_phone FROM agreements WHERE id = :id');
        $st->execute([':id' => $id]);
        $c = $st->fetch();
        $rows[$id] = [
            'agreement_id' => $id, 'supplier_name' => $b['supplier_name'], 'ledger_code' => $b['ledger_code'],
            'contact_person' => $c['contact_person'] ?? null, 'contact_phone' => $c['contact_phone'] ?? null,
            'overdue' => 0.0, 'due' => 0.0, 'awaited' => 0, 'oldest_due' => null, 'open_bills' => 0,
        ];
    }
    if ($b['expected_amount'] === null) {
        $rows[$id]['awaited']++;
        $rows[$id]['open_bills']++;
        continue;
    }
    $balance = (float)$b['expected_amount'] - $b['paid'];
    if ($balance <= 0) continue;
    $rows[$id]['open_bills']++;
    if ($b['due_date'] < $today) {
        $rows[$id]['overdue'] += $balance;
        if ($rows[$id]['oldest_due'] === null || $b['due_date'] < $rows[$id]['oldest_due']) {
            $rows[$id]['oldest_due'] = $b['due_date'];
        }
    } else {
        $rows[$id]['due'] += $balance;
    }
}
$rows = array_values(array_filter($rows, fn($r) => $r['open_bills'] > 0));
usort($rows, fn($a, $b) => ($b['overdue'] <=> $a['overdue']) ?: ($b['awaited'] <=> $a['awaited']) ?: ($b['due'] <=> $a['due']));
[$page, $pages, $offset] = paginate(count($rows), 20);
$pageRows = array_slice($rows, $offset, 20);

layoutHead(t('followup_list'), 'followups');
pageTitle(t('followup_list'), t('followups_subtitle'));
?>

<div class="panel p-0 overflow-hidden">
  <div class="table-responsive">
    <table class="table table-luxe align-middle mb-0">
      <thead>
        <tr><th>#</th><th><?= e(t('company')) ?></th><th><?= e(t('contact')) ?></th><th class="text-end"><?= e(t('overdue')) ?></th><th class="text-end"><?= e(t('due_not_late')) ?></th><th class="text-end"><?= e(t('awaited_bills')) ?></th><th><?= e(t('oldest_due_date')) ?></th><th></th></tr>
      </thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="8" class="text-center py-4 text-muted"><?= e(t('nothing_to_followup')) ?></td></tr>
      <?php endif; ?>
      <?php foreach ($pageRows as $i => $r): ?>
        <tr>
          <td><?= $offset + $i + 1 ?></td>
          <td><a href="company.php?id=<?= (int)$r['agreement_id'] ?>"><?= e($r['supplier_name']) ?></a>
              <div class="cell-sub"><?= e($r['ledger_code']) ?> · <?= $r['open_bills'] ?> <?= e(t('open_bills')) ?></div></td>
          <td><?= e($r['contact_person'] ?? '—') ?><?php if ($r['contact_phone']): ?><div class="cell-sub"><?= e($r['contact_phone']) ?></div><?php endif; ?></td>
          <td class="text-end fw-semibold <?= $r['overdue'] > 0 ? 'text-danger' : '' ?>"><?= $r['overdue'] > 0 ? money($r['overdue']) : '—' ?></td>
          <td class="text-end"><?= $r['due'] > 0 ? money($r['due']) : '—' ?></td>
          <td class="text-end"><?= $r['awaited'] > 0 ? $r['awaited'] . ' <span class="pill pill-awaited">' . e(t('awaited')) . '</span>' : '—' ?></td>
          <td><?= $r['oldest_due'] ? date('d M Y', strtotime($r['oldest_due'])) : '—' ?></td>
          <td class="text-end"><a class="btn btn-sm btn-light border" href="company.php?id=<?= (int)$r['agreement_id'] ?>"><?= e(t('ledger')) ?></a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php pageNav($page, $pages, 'followups.php'); ?>
<p class="cell-sub mt-2"><?= e(t('print_tip')) ?></p>

<?php layoutFoot(); ?>
