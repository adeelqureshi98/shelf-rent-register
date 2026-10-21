# Setup Instructions — Shelf Rent & Incentive Register

Everything below uses free, standard tools. Total setup time: about 10 minutes.

## Option A — Local run with XAMPP (recommended for the demo)

1. **Install XAMPP** (PHP 8.x + MySQL/MariaDB) from apachefriends.org and start
   **Apache** and **MySQL** in the XAMPP Control Panel.
2. **Copy the project** into the web root so the folder sits at
   `C:\xampp\htdocs\shelf-rent-register\` (Windows) or
   `/opt/lampp/htdocs/shelf-rent-register/` (Linux).
   The folder must contain `index.php`, `config.php`, `database.sql`, etc.
3. **Create the database.** Open `http://localhost/phpmyadmin`, click
   **Import**, choose this project's `database.sql`, and click **Go**.
   This creates the database `shelf_rent_register`, all tables, the default
   users and the sample data — nothing else to run.
   *(Command-line alternative: `mysql -u root -p < database.sql`.)*
4. **Check the settings.** Open `config.php`. The defaults already match
   XAMPP (`DB_HOST 127.0.0.1`, `DB_USER root`, `DB_PASS` empty). If your local
   MySQL has a different password, put it in `DB_PASS`.
5. **Make the uploads folder writable** (it stores scanned agreements).
   On Linux: `chmod 775 uploads`. On Windows/XAMPP it works as-is.
6. **Open the app:** `http://localhost/shelf-rent-register/`

## Default logins

| Role       | Username     | Password   | Can do |
|------------|--------------|------------|--------|
| Admin      | `admin`      | `admin123` | Everything, incl. users, delete, backup/restore |
| Accountant | `accountant` | `acc123`   | Add/edit agreements, record payments, enter billed amounts |
| Viewer     | `viewer`     | `view123`  | View only |

Change these passwords after first login on any real deployment
(Admin → Users → Reset).

## First things to try (sample data tour)

1. **Dashboard** — note the overdue total and the two agreements in the
   "Expiring in 30 days" panel (Green Valley Organics, HomeCare Essentials).
2. **Payment Grid (2026)** — Fresh Dairy shows green *Received* cells, an amber
   *Partial* for July and red *Overdue* for Aug–Oct. Green Valley's cells say
   *Awaited* until an amount is entered. Click any cell for details.
3. **Record a payment** (sign in as Accountant) — click an overdue cell, save a
   payment, and you are taken to a **printable receipt**. Dashboard, grid and
   reports all move together.
4. **Enter a percentage amount** — click a Green Valley *Awaited* cell, enter
   the actual billed amount, then record its payment.
5. **Reports** — monthly recovery for 2026 and the overdue summary.
6. **Financial Hub** — Income Statement (shelf rent + incentive income
   for a year or month), Financial Position (receivables outstanding,
   overdue vs not yet due) and Cash Received by payment month, each
   with a CSV download. It reads the same billing engine as everything
   else, so its figures always match the dashboard.
7. **Backup** (Admin) — download a `.sql` backup; it can be restored from the
   same page.

## Option B — Live shared hosting (cPanel)

1. In cPanel create a **MySQL database** and a **database user**, and add the
   user to the database with *All Privileges*.
2. Open **phpMyAdmin** from cPanel, select the new database, and **Import**
   `database.sql`. (If the import complains about the `CREATE DATABASE` line
   because your host prefixes database names, create the database in cPanel
   first and delete the first two lines of the file — `CREATE DATABASE ...`
   and `USE ...` — before importing, then make sure the selected database in
   phpMyAdmin is yours.)
3. Upload the project files to `public_html/shelf-rent-register/` with the
   File Manager or FTP.
4. Edit `config.php` on the server: set `DB_NAME`, `DB_USER`, `DB_PASS` to the
   cPanel values.
5. Set the `uploads/` folder permission to **775**.
6. Visit `https://your-domain/shelf-rent-register/` and sign in.

## Upgrading an existing v1 install to v2

If the register is already running with v1 data, do **not** re-import
`database.sql` (that is for fresh installs). Instead:

1. Back up first (Admin → Backup & Restore → Download Backup).
2. Import **`upgrade_v2.sql`** once (phpMyAdmin → Import, or
   `mysql -u root -p shelf_rent_register < upgrade_v2.sql`).
   It adds the supplier email column, the `incentives`, `reminder_log`
   and `audit_log` tables, and the v2 sample data. Nothing is dropped;
   all existing users, agreements, bills and payments are preserved.
3. Replace the PHP files with the v2 files (keep your edited
   `config.php`).

## Automatic email reminders (optional)

The Reminders page sends WhatsApp/email reminders one click at a
time and logs them. To also send **email** reminders automatically
for everything overdue or due within 7 days, schedule the CLI
script — it uses the server's mail function and logs every send:

- **Linux/cron:** `0 9 * * * php /path/to/shelf-rent-register/cron_reminders.php`
- **Windows Task Scheduler:** run `php.exe C:\xampp\htdocs\shelf-rent-register\cron_reminders.php` daily.

Suppliers without an email address on their agreement are skipped
(and printed in the script's output). WhatsApp cannot be sent in
the background by a web app — automatic WhatsApp requires the
WhatsApp Business API; the one-click WhatsApp button on the
Reminders page is the built-in path.

## Troubleshooting

| Problem | Fix |
|---|---|
| "could not connect" / PDO exception | MySQL is not started, or `DB_*` values in `config.php` are wrong. |
| Login page loads but sign-in bounces back | Cookies/sessions disabled, or the `users` table is empty — re-import `database.sql`. |
| Scan upload fails | `uploads/` is not writable (set 775), or the file is over 5 MB / not PDF-JPG-PNG. |
| Blank page | Enable `display_errors` temporarily in `php.ini`, or check the Apache error log — the exact PHP error will be there. |
