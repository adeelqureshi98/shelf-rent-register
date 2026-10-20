# Shelf Rent & Incentive Register

A complete, working web application for a retail mart that charges supplier
companies rent for shelf and display space. It replaces the manual notebook /
spreadsheet with a proper multi-user register that always answers three
questions instantly:

1. **Which companies have paid for this month?**
2. **How much is overdue, and from whom?**
3. **Which agreements are ending soon?**

![Stack](https://img.shields.io/badge/PHP-8.x-777BB4) ![DB](https://img.shields.io/badge/MySQL-8%20%2F%20MariaDB-4479A1) ![UI](https://img.shields.io/badge/Bootstrap-5.3-7952B3)

## Screenshots

| Dashboard | Payment Grid |
|---|---|
| ![Dashboard](screenshots/dashboard.png) | ![Payment Grid](screenshots/payment-grid.png) |
| Monthly expected vs received chart, headline totals, follow-ups, expiring agreements | Companies × months with colour-coded bill statuses |

| Sign In | Reports |
|---|---|
| ![Sign In](screenshots/login.png) | ![Reports](screenshots/reports.png) |

## Features

- **Dashboard** — total expected / received / overdue, recovery %, monthly
  expected-vs-received bar chart, follow-up table, "expiring in 30 days"
  alert panel, recent payment activity.
- **Payment Grid** — companies in rows, months in columns, year navigation,
  search + status filters. Every cell shows its status: **Received, Partial,
  Due, Overdue, Awaited** (percentage bills), **Out of Period** and
  **No Bill** are all visually distinct. Click a cell to see the bill, its
  payment history, record a payment, or enter a percentage bill's amount.
- **Agreements** — add / edit / delete with ledger code, contact details,
  scanned signed-agreement upload (PDF/JPG/PNG, stored on the server).
- **Company Ledger** — one page per company: agreement, all bills, all
  payments, balances.
- **Billing engine** — monthly, quarterly (every third month from the period
  start) and Display Drive (once, at period end) frequencies; due date on the
  10th of the billed month; partial payments supported; percentage agreements
  stay **Awaited** — never shown as a false overdue figure — until staff enter
  the actual billed amount. One shared engine feeds the dashboard, grid and
  reports, so every total agrees.
- **Reports** — monthly recovery (expected vs received) and an overdue
  summary by company, both print-clean.
- **Printable payment receipt** after every recorded payment.
- **Follow-up calling list** — companies sorted by overdue amount, with
  contact names and phone numbers.
- **Roles enforced on the server** — Admin (full access + user management +
  backup/restore), Accountant (agreements + payments, no delete, no users),
  Viewer (read-only). Passwords are hashed with `password_hash()`.
- **Backup & Restore** — admin-only one-click `.sql` export and restore.

## Tech stack

| Layer    | Technology                                  |
|----------|---------------------------------------------|
| Frontend | HTML5, CSS3, JavaScript, Bootstrap 5        |
| Backend  | PHP 8.x (plain PHP, PDO, prepared statements) |
| Database | MySQL 8 / MariaDB 10.4+                     |
| Runs on  | XAMPP locally, cPanel shared hosting live   |

## Quick setup (XAMPP)

1. Copy this folder into `htdocs/` (e.g. `htdocs/shelf-rent-register/`).
2. Start **Apache** and **MySQL** in the XAMPP control panel.
3. Open **phpMyAdmin → Import** and import `database.sql`
   (or run `mysql -u root -p < database.sql`).
   This creates the database `shelf_rent_register`, all tables, the default
   users and a full sample data set.
4. If your MySQL user/password differ from `root` / _(empty)_, edit the
   `DB_*` constants at the top of `config.php`.
5. Visit `http://localhost/shelf-rent-register/` and sign in.

Full step-by-step instructions, including live-hosting notes, are in
[README_SETUP.md](README_SETUP.md). Design decisions and the reasoning behind
the billing rules are in [ONE_PAGE_NOTE.md](ONE_PAGE_NOTE.md).

## Default logins

| Role       | Username     | Password   |
|------------|--------------|------------|
| Admin      | `admin`      | `admin123` |
| Accountant | `accountant` | `acc123`   |
| Viewer     | `viewer`     | `view123`  |

> Change these passwords immediately on any real deployment (Users page,
> Admin only).

## Project layout

```
index.php            Dashboard
grid.php             Payment grid (companies × months)
agreements.php       Agreement list + filters + delete
agreement_form.php   Add / edit agreement (+ scan upload)
company.php          Company ledger (agreement, bills, payments)
receipt.php          Printable payment receipt
reports.php          Monthly recovery + overdue summary
followups.php        Follow-up calling list
payment_action.php   All payment write actions (server-side checks)
users.php            User management (Admin)
backup.php           Backup export / restore (Admin)
login.php logout.php Authentication
billing.php          Billing engine — bills, statuses, totals
auth.php             Sessions, role guards, CSRF, escaping
db.php config.php    PDO connection + all settings (DB_* constants)
layout.php           Shared luxury sidebar layout
assets/css/app.css   Theme (espresso + gold + ivory)
uploads/             Scanned agreements (PHP execution disabled)
database.sql         Schema + default users + sample data
```

## Sample data at a glance

Five suppliers cover every case: monthly fixed (with a partial payment and
overdue months), quarterly fixed (one quarter overdue), a Display Drive
agreement (single end-of-period bill), a percentage agreement (Awaited bills,
two months entered and paid) and a short agreement expiring within 30 days.
