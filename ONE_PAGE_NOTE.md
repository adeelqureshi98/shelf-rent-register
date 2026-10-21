# One-Page Note — Shelf Rent & Incentive Register

## Database design

Seven tables, deliberately small. **users** (login accounts; `role` enum;
passwords stored only as `password_hash()` output). **agreements** (one row per
supplier agreement: ledger code is `UNIQUE`, fixed agreements carry
`rent_amount`, percentage agreements carry `percentage_rate` instead, the scan
is a file *name* — the file itself lives in `uploads/`, never as a blob; v2
added `contact_email` for reminders). **incentives** (v2: promotion/display/
launch money on top of rent; percentage incentives keep `expected_amount`
NULL = Awaited, the same rule as bills). **reminder_log** (v2: every reminder
marked sent, by whom, or NULL = the automatic cron script). **audit_log**
(v2: who did what; deliberately no foreign key to `users`, so an audit row
survives the account it describes).
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

## Added in v2

- **Incentive tracking** — the register's name always promised this half.
  Fixed or percentage basis, the same Awaited rule as percentage rent, the
  same status engine style, and one shared read path so the incentives
  page, dashboard panel, company ledger and reports all agree.
- **Reminders page + schedulable email** — open bills and incentives
  grouped Overdue / due in 7 days / due in 30 days, with one-click
  WhatsApp (a `wa.me` link with the message pre-written) and email
  (`mailto:`), a "Mark sent" log, and `cron_reminders.php`, a CLI script
  that emails everything overdue or due within 7 days via `mail()` and
  logs its sends. **Honest limit:** true background WhatsApp sending
  needs the WhatsApp Business API — no web page can do it alone — so
  WhatsApp is one-click here, and the automatic path is email.
- **Supplier statements** — accounts-style, per company and period:
  opening balance, bills and payments in date order with a running
  balance, closing balance; print / Save-PDF clean.
- **Audit log** — logins, payments, agreements, users, incentives,
  backups and reminder sends are recorded (user, action, entity,
  details, IP) and viewable by Admin with filters. Auditing is
  best-effort by design: a failed audit write never blocks the
  business action it describes.
- **Urdu language toggle** — EN/اردو switch in the sidebar; Urdu mode
  is full RTL with a Nastaliq font stack and raised line-heights.
  Scope is the UI chrome only (nav, titles, KPI labels, buttons,
  status names, login) — data values are never translated, so the
  figures are identical in both languages.
- **Reports hub** — the two original reports plus company-wise
  summary, aging buckets (0–30/31–60/61–90/90+ days), payment-method
  summary, incentive report and expiring agreements (30/60/90 days),
  **every one with a CSV download**.

## Added in v3

- **Financial Hub (`financial.php`)** — three views computed by shared
  functions in `billing.php` (`incomeStatement()`, `financialPosition()`,
  `cashReceived()`), so the Hub, the new Reports panels and their CSV
  exports always show identical figures. Definitions:
  - *Income Statement* for a year (or one month): bills by billed month
    plus incentives by expected date, known amounts only — Rent Income,
    Incentive Income, Total Income, each with expected / received /
    outstanding / recovery %. Awaited items are counted, never money.
    **Honest limit:** this is shelf rent + incentive income only, not
    the mart's full trading profit & loss. The register holds no
    expense data, so nothing is deducted and no "profit" is claimed.
  - *Financial Position* as of today, on the dashboard's billed-to-date
    basis: total receivables outstanding (rent + incentive balances),
    received to date, the overdue portion, the not-yet-due portion and
    the rent aging buckets. **Honest limit:** a receivables position
    only — not a full balance sheet, because no other assets,
    liabilities or capital exist in this register.
  - *Cash Received* for a year: rent payments grouped by their real
    `payment_date` month (a cash-in view, deliberately different from
    the bill-month recovery view). **Honest limit:** incentive
    receipts are recorded as amounts with no monthly payment-date
    ledger (`received_date` is only stamped when fully received), so
    they are shown as one separate total and are never allocated to
    invented months.
- **Alignment pass (owner asked: "things don't look right in the
  screenshots")** — every page was screenshotted in English AND Urdu
  and inspected. Found & fixed: money wrapping mid-figure
  ("Rs / 290,000") on the dashboard, incentives, statement and grid
  (right-aligned cells and stat values are now no-wrap); dashboard
  recent-payment dates wrapping; the Reminders tables crushing their
  columns, clipping the action buttons off the panel and disagreeing
  with each other band-to-band (now one fixed layout + shared
  colgroup, actions stacked in two tidy rows); agreement period
  ranges wrapping; user names wrapping on the Users page; and in
  Urdu, Latin dates inside RTL cells reordering ("Jul 2026 10") —
  fixed with per-cell content direction plus LTR spans around date
  ranges. Final screenshots for all 17 pages × 2 languages are in
  `screenshots/`.
- **Deliberately NOT added: HR and Payroll.** They belong to a full
  ERP/accounting suite; a shelf-rent register has no employee,
  attendance or salary data, and bolting them on would fake a scope
  the brief never asked for. The same reasoning kept a full balance
  sheet and trading P&L out — what is shown is exactly what the
  register's own data can honestly support.

## Added in v4

An external review suggested modernising the register; v4 implements
those suggestions in the way that fits this product, and says plainly
what was NOT done and why.

- **New luxury theme, replaced (owner's explicit order).** The old
  espresso/ivory look is gone. v4 is a **Deep Slate / Emerald luxury**
  theme: deep slate surfaces, emerald as the primary accent, gold kept
  subtle for the brand mark and highlights. A **dark / light toggle**
  in the sidebar switches between deep-slate dark and clean
  ivory-slate light; the choice is remembered (cookie + localStorage)
  and applied before the page paints, so there is no flash of the
  wrong theme. Urdu (RTL) is fully styled in both modes.
- **Login page no longer prints any demo logins** (owner's order,
  security). Demo credentials are shared separately by the admin; the
  README keeps the local-install defaults for a fresh setup only.
- **CSRF protection everywhere.** Every form carries a per-session
  token; a POST with a missing or wrong token is rejected with
  HTTP 403 and a friendly message (verified in QA).
- **Real charts (Chart.js, vendored locally — no CDN dependency).**
  Dashboard: Monthly Recovery Trend (expected vs received per month,
  Rs tooltips) and a Recovery by Company doughnut, both computed by
  the same billing engine as every other figure.
- **Live search** on Agreements and the Payment Grid filters rows as
  you type (progressive enhancement — the server search still works
  with JavaScript off). **Pagination** (20 per page, server-side,
  filters preserved) on Agreements, Follow-ups, Reminders history
  and Cheques. **Toasts** replace inline flash alerts, submit buttons
  show a spinner and lock against double-submits, and dashboard KPIs
  count up (respecting `prefers-reduced-motion`). On phones the
  Payment Grid becomes per-company cards with month status chips —
  no more horizontal-scroll struggle.
- **Tax engine.** Each agreement can carry **WHT % and GST %**
  (default 0). Net payable = bill amount + GST − WHT, computed once
  in `billing.php` and shown consistently on the receipt, the company
  statement, and the new formal **Tax Invoice** (`invoice.php`:
  invoice number, mart NTN/STRN/address/phone from the new Admin
  **Settings** page, tax lines, QR code of the invoice summary,
  print = PDF). Reports gain a **Tax Summary** (per company + CSV).
  With the default 0% every v1–v3 total is bit-for-bit unchanged —
  all the original sample baselines were re-verified exactly.
- **Cheque (PDC) register** (`cheques.php` + `cheques` table):
  post-dated cheques with bank, date, amount, optional link to a
  payment record, a "due this week" panel, and a
  Pending → Cleared / Bounced / Returned flow; every transition is
  written to the audit log. CSV export in Reports.
- **Shelf Map** (`shelfmap.php` + `shelves` table): a visual grid of
  the mart's physical positions — occupied (green, company + rent +
  period), vacant (grey, dashed), expiring within 30 days (red ring).
  Agreements can be assigned a shelf; Admin manages the positions
  (a shelf in use cannot be deleted). Sample shelves are seeded from
  the demo agreements' locations.
- **Supplier Portal** (new `supplier` role, `users.company_id`):
  a supplier login sees ONLY its own dashboard totals, its own
  statement with invoice downloads, its own agreement, its contract
  scan download, and can upload payment slips (JPG/PNG/PDF) that
  staff then Verify or Reject on the company page. Every portal
  query is scoped server-side to the linked company — QA included a
  URL-tampering test (another company's statement/invoice) which is
  refused. Demo login: `supplier / supp123` → Fresh Dairy Foods.
- **Percentage sales CSV import** (`sales_import.php`): upload
  `company, month, sales_amount` → awaited percentage bills are
  filled at each agreement's rate, row-by-row results reported,
  everything audited. Template CSV downloadable.
- **WhatsApp Cloud API plumbing (inactive by default).** Settings
  holds the API token, phone-number ID and template name; when — and
  only when — an Admin fills all three, the Reminders page gains a
  "Send via API" action that sends through Meta's official Cloud
  API. **Honest limit:** automatic WhatsApp sending needs the mart's
  own WhatsApp Business API account and an approved message
  template; no credentials are shipped or invented. Until then the
  one-click wa.me flow remains the WhatsApp path.
- **Honest limits, unchanged:** a **live POS integration** needs the
  mart's POS system access/credentials — the CSV import above is the
  practical stand-in and works with any POS that can export a
  spreadsheet. **Multi-branch is NOT added:** the original brief is a
  single-store register; faking branch support would corrupt the
  totals model, so the register stays honestly single-store.
- **Data:** fresh installs use the updated `database.sql`; existing
  v3 databases upgrade with `upgrade_v4.sql` (verified against a
  real v3 import). Backup & Restore now includes the new tables.

## With more time I would still add

Per-shelf/per-location rent breakdown, and a read-only API for the
mart's accounting software.
