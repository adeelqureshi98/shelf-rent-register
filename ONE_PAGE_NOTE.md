# One-Page Note — Shelf Rent & Incentive Register

## Database design

Four tables, deliberately small. **users** (login accounts; `role` enum;
passwords stored only as `password_hash()` output). **agreements** (one row per
supplier agreement: ledger code is `UNIQUE`, fixed agreements carry
`rent_amount`, percentage agreements carry `percentage_rate` instead, the scan
is a file *name* — the file itself lives in `uploads/`, never as a blob).
**bills** (one materialised row per billed month per agreement, `UNIQUE(agreement_id, bill_month)`,
with `due_date` and a nullable `expected_amount`). **payments** (many rows per
bill; amount, date, method, reference, cheque status, and `received_by` for the
audit trail — a user who has recorded payments cannot be deleted, only
deactivated, because of the `RESTRICT` foreign key).

**Why materialise bills instead of computing them on the fly?** A bill is a
real obligation the mart has raised: it must stay identical in the grid, the
dashboard and the reports, and payments need a stable row to attach to. The
billing engine (`billing.php`) generates bills idempotently (`INSERT IGNORE`),
and every screen reads through that one engine, so totals cannot drift apart.

## How the billing and overdue rules work

- **Frequency → bills.** Monthly: every month from period start to end.
  Quarterly: every third month *counting from the period's first month* (an
  agreement starting in February bills Feb/May/Aug/Nov). Display Drive: exactly
  one bill, in the period's final month.
- **Due dates (assumption, brief left this open).** The 10th of the billed
  month; for Display Drive, the period end date itself. A bill also never falls
  due before its agreement starts. This is one constant (`DUE_DAY`) in
  `config.php`.
- **Overdue.** If the due date has passed and the known amount is not fully
  paid, the unpaid balance is overdue. Status precedence per bill:
  *Awaited → Received → Partial → Overdue → Due*.
- **Percentage agreements.** Their bill starts with `expected_amount = NULL`:
  the status is **Awaited**, excluded from expected/overdue money entirely —
  the system never invents a figure. When staff learn the month's sales, they
  enter the actual billed amount on that bill; from that moment it behaves
  exactly like a fixed bill (due date already set, so a late entry can be
  immediately overdue — which is correct).
- **Partial payments.** Decided deliberately: a bill accepts **multiple
  payments**, each with date, method, reference and cheque status. The paid sum
  drives the status; the remaining balance stays Due/Overdue — the same model a
  real accounts ledger uses, and it makes the receipt-per-payment natural.
- **Periods crossing a year.** Bills are keyed by their actual month, so an
  agreement running Nov 2025 → Nov 2026 simply shows in both years' grids; the
  financial year is the calendar year (Jan–Dec), and the grid has prev/next
  year navigation.

## What I researched / decided on the open points

Payment details worth recording (method, reference/cheque number, cheque
clearing status) follow ordinary Pakistani retail accounting practice, where
supplier payments are frequently by cheque. Expiring agreements are surfaced
in three places (dashboard alert panel, Agreements state pill/filter, company
ledger header) because renewals are where shelf income is actually lost.
Reports count "received" against the *bill's* month, not the payment date, so
a late payment still repairs its own month — the only definition under which
grid, dashboard and reports agree.

## What I added beyond the brief and why

- **Dashboard chart + recent payments panel** — totals alone don't show trend;
  the chart makes a recovery dip visible in one glance.
- **Company ledger page** — one page per supplier with agreement, bills and
  payment history; this is the screen staff actually need in front of them
  during a supplier call or visit.
- **Printable receipt per payment** — suppliers ask for proof on the spot; a
  receipt that shows the balance remaining prevents most disputes.
- **Follow-up calling list** — overdue + awaited companies sorted by amount,
  with contact person and phone: turns "who owes us?" into a same-day worklist.
- **Search/filter on grid and agreements** — with dozens of suppliers, finding
  beats scrolling.
- **Friendly validation, delete confirmations, success messages** — the users
  are non-technical store staff, so every destructive action asks first and
  every save confirms itself.
- **Self-contained assets** — Bootstrap 5.3 and Bootstrap Icons are vendored
  under `assets/vendor/`, so the system runs identically on a shop PC with no
  internet connection; nothing depends on a CDN being reachable.

## With more time I would add

Per-shelf/per-location rent breakdown, automated renewal reminders (email/
WhatsApp) before expiry, an accounts-style supplier statement PDF per company,
incentive tracking alongside rent (the register's name promises it), and a
read-only API for the mart's accounting software.
