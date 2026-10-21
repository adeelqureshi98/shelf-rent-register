<?php
/**
 * Settings — Admin only. Mart identity used on formal invoices
 * (name comes from config; NTN / STRN / address / phone live
 * here), plus the optional WhatsApp Cloud API connection.
 * The WhatsApp fields stay empty — and the API path inactive —
 * until the mart connects its own WhatsApp Business account.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/billing.php';
require_once __DIR__ . '/layout.php';

requireRole(['admin']);
$pdo = db();

$keys = ['mart_ntn', 'mart_strn', 'mart_address', 'mart_phone', 'wa_api_token', 'wa_phone_number_id', 'wa_template'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    foreach ($keys as $k) {
        setSetting($pdo, $k, trim($_POST[$k] ?? ''));
    }
    audit($pdo, 'update', 'settings', null, 'Updated mart / WhatsApp API settings.');
    flash('success', 'Settings saved.');
    header('Location: settings.php');
    exit;
}

$s = getSettings($pdo);
$waReady = whatsappApiConfigured($pdo);

layoutHead(t('settings'), 'settings');
pageTitle(t('settings'), t('settings_subtitle'));
?>

<form method="post" class="row g-3">
  <?= csrfField() ?>
  <div class="col-xl-6">
    <div class="panel h-100">
      <h2 class="panel-title"><i class="bi bi-building me-2"></i><?= e(t('mart_details')) ?></h2>
      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label" for="mart_ntn"><?= e(t('mart_ntn')) ?></label>
          <input class="form-control" id="mart_ntn" name="mart_ntn" value="<?= e($s['mart_ntn']) ?>" placeholder="e.g. 1234567-8">
        </div>
        <div class="col-md-6">
          <label class="form-label" for="mart_strn"><?= e(t('mart_strn')) ?></label>
          <input class="form-control" id="mart_strn" name="mart_strn" value="<?= e($s['mart_strn']) ?>" placeholder="e.g. 01-2345678-001-90">
        </div>
        <div class="col-12">
          <label class="form-label" for="mart_address"><?= e(t('mart_address')) ?></label>
          <input class="form-control" id="mart_address" name="mart_address" value="<?= e($s['mart_address']) ?>" placeholder="Street, area, city">
        </div>
        <div class="col-md-6">
          <label class="form-label" for="mart_phone"><?= e(t('mart_phone')) ?></label>
          <input class="form-control" id="mart_phone" name="mart_phone" value="<?= e($s['mart_phone']) ?>" placeholder="03xx-xxxxxxx">
        </div>
      </div>
    </div>
  </div>
  <div class="col-xl-6">
    <div class="panel h-100">
      <h2 class="panel-title"><i class="bi bi-whatsapp me-2"></i><?= e(t('whatsapp_api')) ?></h2>
      <div class="row g-3">
        <div class="col-12">
          <label class="form-label" for="wa_api_token"><?= e(t('wa_token')) ?></label>
          <input type="password" class="form-control" id="wa_api_token" name="wa_api_token" value="<?= e($s['wa_api_token']) ?>" autocomplete="off" placeholder="EAAB…">
        </div>
        <div class="col-md-6">
          <label class="form-label" for="wa_phone_number_id"><?= e(t('wa_phone_id')) ?></label>
          <input class="form-control" id="wa_phone_number_id" name="wa_phone_number_id" value="<?= e($s['wa_phone_number_id']) ?>" placeholder="123456789012345">
        </div>
        <div class="col-md-6">
          <label class="form-label" for="wa_template"><?= e(t('wa_template')) ?></label>
          <input class="form-control" id="wa_template" name="wa_template" value="<?= e($s['wa_template']) ?>" placeholder="payment_reminder">
        </div>
        <div class="col-12">
          <p class="cell-sub mb-0"><?= e(t('wa_api_note')) ?></p>
          <p class="mt-2 mb-0">
            <?php if ($waReady): ?>
              <span class="pill pill-received"><i class="bi bi-check-circle me-1"></i><?= e(t('wa_api_configured')) ?></span>
            <?php else: ?>
              <span class="pill pill-awaited"><?= e(t('wa_api_not_configured')) ?></span>
            <?php endif; ?>
          </p>
        </div>
      </div>
    </div>
  </div>
  <div class="col-12">
    <button class="btn btn-gold" type="submit"><i class="bi bi-check2-circle me-1"></i> <?= e(t('save_settings')) ?></button>
  </div>
</form>

<?php layoutFoot(); ?>
