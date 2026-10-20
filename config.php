<?php
/**
 * Shelf Rent & Incentive Register — central configuration.
 *
 * ALL environment-specific values live in this one file.
 * On live hosting (cPanel etc.), create the MySQL database + user there
 * first, then replace the DB_* values below with the live ones.
 * Local XAMPP defaults work out of the box: host 127.0.0.1, user "root",
 * empty password.
 */

/* ---- Database (MySQL / MariaDB only) ---- */
define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3306');
define('DB_NAME', 'shelf_rent_register');
define('DB_USER', 'root');
define('DB_PASS', '');

/* ---- Branding ---- */
define('MART_NAME', 'City Mart');
define('APP_NAME', 'Shelf Rent & Incentive Register');
define('CURRENCY', 'Rs');

/* ---- Business defaults ---- */
define('DUE_DAY', 10);          // bills fall due on the 10th of the billed month
define('EXPIRY_WARN_DAYS', 30); // agreements ending within this window are flagged

/* ---- Uploads (scanned agreements) ---- */
define('UPLOAD_DIR', __DIR__ . '/uploads');
define('UPLOAD_URL', 'uploads');
define('MAX_UPLOAD_BYTES', 5 * 1024 * 1024); // 5 MB

/** Small accessor so pages can read settings by key. */
function cfg(string $key)
{
    $map = [
        'db_host' => DB_HOST, 'db_port' => DB_PORT, 'db_name' => DB_NAME,
        'db_user' => DB_USER, 'db_pass' => DB_PASS,
        'mart_name' => MART_NAME, 'app_name' => APP_NAME, 'currency' => CURRENCY,
        'due_day' => DUE_DAY, 'expiry_warn_days' => EXPIRY_WARN_DAYS,
        'upload_dir' => UPLOAD_DIR, 'upload_url' => UPLOAD_URL,
        'max_upload_bytes' => MAX_UPLOAD_BYTES,
    ];
    return $map[$key] ?? null;
}
