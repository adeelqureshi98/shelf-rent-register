-- ============================================================
-- Shelf Rent & Incentive Register — database build script
-- MySQL 8 / MariaDB 10.4+. Run this file once (phpMyAdmin import,
-- or:  mysql -u root -p < database.sql ) to create the database,
-- all tables and the sample data set.
-- ============================================================

CREATE DATABASE IF NOT EXISTS shelf_rent_register
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE shelf_rent_register;
SET NAMES utf8mb4;

-- ------------------------------------------------------------
-- Users: login accounts. role is enforced by the PHP backend.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(100) NOT NULL,
  username      VARCHAR(60)  NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,           -- password_hash(), never plain text
  role          ENUM('admin','accountant','viewer','supplier') NOT NULL DEFAULT 'viewer',
  company_id    INT UNSIGNED NULL,               -- supplier role only: the ONE agreement this login may see
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_users_company (company_id)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Shelves: the physical shelf / bay / end-cap units of the
-- store, for the visual Shelf Map. An agreement may occupy one.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS shelves (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  label      VARCHAR(120) NOT NULL,              -- e.g. "Aisle 1 · Endcap 2"
  zone       VARCHAR(120) NULL,                  -- e.g. "Ground Floor — Dairy"
  sort_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Agreements: one row per supplier shelf-rent agreement.
-- Fixed agreements carry rent_amount; percentage agreements
-- carry percentage_rate and their bills start with no amount.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS agreements (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_name     VARCHAR(150) NOT NULL,
  ledger_code       VARCHAR(60)  NOT NULL UNIQUE,
  contact_person    VARCHAR(100) NULL,
  contact_phone     VARCHAR(40)  NULL,
  contact_email     VARCHAR(120) NULL,
  rent_type         ENUM('fixed','percentage') NOT NULL DEFAULT 'fixed',
  rent_amount       DECIMAL(12,2) NULL,          -- fixed agreements only
  percentage_rate   DECIMAL(5,2)  NULL,          -- percentage agreements only
  wht_percent       DECIMAL(5,2)  NOT NULL DEFAULT 0,  -- withholding tax % on bills (v4 tax engine)
  gst_percent       DECIMAL(5,2)  NOT NULL DEFAULT 0,  -- GST % added on bills (v4 tax engine)
  shelf_id          INT UNSIGNED NULL,           -- the physical shelf this agreement occupies (Shelf Map)
  billing_frequency ENUM('monthly','quarterly','display_drive') NOT NULL DEFAULT 'monthly',
  period_from       DATE NOT NULL,
  period_to         DATE NOT NULL,
  scan_file         VARCHAR(255) NULL,           -- file name inside uploads/ (path only)
  notes             VARCHAR(255) NULL,
  created_by        INT UNSIGNED NULL,
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_agreements_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_agreements_shelf FOREIGN KEY (shelf_id) REFERENCES shelves(id) ON DELETE SET NULL,
  CHECK (period_to >= period_from)
) ENGINE=InnoDB;

-- Supplier logins point at exactly one agreement (added after
-- agreements exists, because users is created first).
ALTER TABLE users
  ADD CONSTRAINT fk_users_company FOREIGN KEY (company_id) REFERENCES agreements(id) ON DELETE SET NULL;

-- ------------------------------------------------------------
-- Bills: one row per billed month per agreement, materialised
-- by the billing engine (billing.php). expected_amount is NULL
-- for percentage bills until staff enter the actual amount —
-- that is what makes a bill "Awaited".
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS bills (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  agreement_id    INT UNSIGNED NOT NULL,
  bill_month      DATE NOT NULL,                 -- always the 1st of the billed month
  due_date        DATE NOT NULL,
  expected_amount DECIMAL(12,2) NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_bill (agreement_id, bill_month),
  CONSTRAINT fk_bills_agreement FOREIGN KEY (agreement_id) REFERENCES agreements(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Payments: many payments may settle one bill (partial payments).
-- received_by protects the audit trail.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS payments (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  bill_id       INT UNSIGNED NOT NULL,
  amount        DECIMAL(12,2) NOT NULL,
  payment_date  DATE NOT NULL,
  method        ENUM('Cash','Bank Transfer','Cheque','Online') NOT NULL DEFAULT 'Cash',
  reference     VARCHAR(80) NULL,                -- transaction / cheque number
  cheque_status ENUM('Cleared','Pending','Bounced') NULL,
  notes         VARCHAR(255) NULL,
  received_by   INT UNSIGNED NOT NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_payments_bill FOREIGN KEY (bill_id) REFERENCES bills(id) ON DELETE CASCADE,
  CONSTRAINT fk_payments_user FOREIGN KEY (received_by) REFERENCES users(id) ON DELETE RESTRICT,
  CHECK (amount > 0)
) ENGINE=InnoDB;

-- ============================================================
-- Default users (passwords are bcrypt hashes — see README):
--   admin      / admin123   (Administrator)
--   accountant / acc123     (Accountant)
--   viewer     / view123    (Viewer, read-only)
-- ============================================================
INSERT INTO users (id, name, username, password_hash, role) VALUES
  (1, 'Store Admin',      'admin',      '$2y$12$ikW0LMksUiMC.aHNaqudV..wxSZPyRZWRLtL9MLjPcOd2RIDAxEBu',      'admin'),
  (2, 'Accounts Officer', 'accountant', '$2y$12$H.MNpN292eestOK1DOSAMuFNVMK.NY1UjU/P4gHOljsWCI6En7UR.', 'accountant'),
  (3, 'Store Viewer',     'viewer',     '$2y$12$z4pgeBo39dxSysS0igNoAuth0ysBRRQkF.noDgBv8Zkqj7qqXqE1m',     'viewer');

-- ============================================================
-- Sample agreements
-- ============================================================
INSERT INTO agreements
  (id, supplier_name, ledger_code, contact_person, contact_phone, contact_email, rent_type, rent_amount, percentage_rate, billing_frequency, period_from, period_to, notes, created_by) VALUES
  (1, 'Fresh Dairy Foods (Pvt) Ltd', 'SUP-DAI-001', 'Ahmed Raza',  '0300-1112233', 'ahmed.raza@freshdairyfoods.pk',   'fixed', 85000.00, NULL, 'monthly',       '2026-01-01', '2026-12-31', 'Ground floor dairy aisle, 4 shelves + chiller end-cap.', 1),
  (2, 'Sparkle Beverages Co',        'SUP-BEV-002', 'Sara Khan',   '0321-4455667', 'sara.khan@sparklebeverages.pk',   'fixed', 240000.00, NULL, 'quarterly',    '2026-02-01', '2027-01-31', 'Beverage bay at entrance, both sides.', 1),
  (3, 'Crunchy Snacks International','SUP-SNK-003', 'Imran Tariq', '0333-7788990', 'imran.tariq@crunchysnacks.pk',    'fixed', 500000.00, NULL, 'display_drive','2026-03-01', '2026-11-30', 'Seasonal display drive, front gondola + window branding.', 1),
  (4, 'Green Valley Organics',       'SUP-ORG-004', 'Mehwish Ali', '0301-2233445', 'mehwish.ali@greenvalleyorganics.pk', 'percentage', NULL, 4.50, 'monthly',  '2025-11-01', '2026-11-10', 'Organic corner. Rent is 4.5% of monthly category sales.', 1),
  (5, 'HomeCare Essentials',         'SUP-HOM-005', 'Bilal Ahmed', '0345-6677889', 'bilal.ahmed@homecareessentials.pk', 'fixed', 45000.00, NULL, 'monthly',      '2026-06-01', '2026-11-15', 'Household aisle, 3 shelves.', 1);

-- ============================================================
-- Sample bills (the engine also regenerates these on first
-- run with INSERT IGNORE — listing them here keeps the demo
-- complete even before the first page load).
-- ============================================================
INSERT INTO bills (id, agreement_id, bill_month, due_date, expected_amount) VALUES
  -- Fresh Dairy — monthly fixed 85,000 (bills 1-12)
  (1,  1, '2026-01-01', '2026-01-10', 85000.00),
  (2,  1, '2026-02-01', '2026-02-10', 85000.00),
  (3,  1, '2026-03-01', '2026-03-10', 85000.00),
  (4,  1, '2026-04-01', '2026-04-10', 85000.00),
  (5,  1, '2026-05-01', '2026-05-10', 85000.00),
  (6,  1, '2026-06-01', '2026-06-10', 85000.00),
  (7,  1, '2026-07-01', '2026-07-10', 85000.00),
  (8,  1, '2026-08-01', '2026-08-10', 85000.00),
  (9,  1, '2026-09-01', '2026-09-10', 85000.00),
  (10, 1, '2026-10-01', '2026-10-10', 85000.00),
  (11, 1, '2026-11-01', '2026-11-10', 85000.00),
  (12, 1, '2026-12-01', '2026-12-10', 85000.00),
  -- Sparkle Beverages — quarterly fixed 240,000 (bills 13-16)
  (13, 2, '2026-02-01', '2026-02-10', 240000.00),
  (14, 2, '2026-05-01', '2026-05-10', 240000.00),
  (15, 2, '2026-08-01', '2026-08-10', 240000.00),
  (16, 2, '2026-11-01', '2026-11-10', 240000.00),
  -- Crunchy Snacks — display drive, one bill at period end (bill 17)
  (17, 3, '2026-11-01', '2026-11-30', 500000.00),
  -- Green Valley Organics — percentage, amounts awaited (bills 18-30)
  (18, 4, '2025-11-01', '2025-11-10', NULL),
  (19, 4, '2025-12-01', '2025-12-10', NULL),
  (20, 4, '2026-01-01', '2026-01-10', NULL),
  (21, 4, '2026-02-01', '2026-02-10', NULL),
  (22, 4, '2026-03-01', '2026-03-10', NULL),
  (23, 4, '2026-04-01', '2026-04-10', NULL),
  (24, 4, '2026-05-01', '2026-05-10', NULL),
  (25, 4, '2026-06-01', '2026-06-10', NULL),
  (26, 4, '2026-07-01', '2026-07-10', NULL),
  (27, 4, '2026-08-01', '2026-08-10', 58000.00),  -- actual amount entered by staff
  (28, 4, '2026-09-01', '2026-09-10', 62000.00),  -- actual amount entered by staff
  (29, 4, '2026-10-01', '2026-10-10', NULL),
  (30, 4, '2026-11-01', '2026-11-10', NULL),
  -- HomeCare Essentials — monthly fixed 45,000 (bills 31-36)
  (31, 5, '2026-06-01', '2026-06-10', 45000.00),
  (32, 5, '2026-07-01', '2026-07-10', 45000.00),
  (33, 5, '2026-08-01', '2026-08-10', 45000.00),
  (34, 5, '2026-09-01', '2026-09-10', 45000.00),
  (35, 5, '2026-10-01', '2026-10-10', 45000.00),
  (36, 5, '2026-11-01', '2026-11-10', 45000.00);

-- ============================================================
-- Sample payments — settled bills, one PARTIAL payment
-- (bill 7: 50,000 of 85,000), and deliberate gaps so Overdue
-- bills exist (bills 8, 9, 10, 15, 35 unpaid past their due
-- dates; bill 17 not due yet).
-- ============================================================
INSERT INTO payments (bill_id, amount, payment_date, method, reference, cheque_status, notes, received_by) VALUES
  (1,  85000.00,  '2026-01-08', 'Bank Transfer', 'TRX-100231', NULL,     NULL, 2),
  (2,  85000.00,  '2026-02-07', 'Bank Transfer', 'TRX-100412', NULL,     NULL, 2),
  (3,  85000.00,  '2026-03-09', 'Cash',          NULL,         NULL,     'Paid at store counter.', 2),
  (4,  85000.00,  '2026-04-08', 'Bank Transfer', 'TRX-101105', NULL,     NULL, 1),
  (5,  85000.00,  '2026-05-06', 'Cheque',        'CHQ-77410',  'Cleared', NULL, 2),
  (6,  85000.00,  '2026-06-09', 'Bank Transfer', 'TRX-102337', NULL,     NULL, 2),
  (7,  50000.00,  '2026-07-09', 'Cheque',        'CHQ-88112',  'Cleared', 'Part payment — balance promised by month end.', 2),
  (13, 240000.00, '2026-02-09', 'Bank Transfer', 'TRX-200118', NULL,     NULL, 2),
  (14, 240000.00, '2026-05-08', 'Bank Transfer', 'TRX-200964', NULL,     NULL, 2),
  (27, 58000.00,  '2026-09-05', 'Online',        'PAY-ORG-7781', NULL,    '4.5% of August category sales.', 2),
  (28, 62000.00,  '2026-10-06', 'Online',        'PAY-ORG-8023', NULL,    '4.5% of September category sales.', 2),
  (31, 45000.00,  '2026-06-08', 'Cash',          NULL,         NULL,     NULL, 2),
  (32, 45000.00,  '2026-07-07', 'Bank Transfer', 'TRX-300551', NULL,     NULL, 2),
  (33, 45000.00,  '2026-08-09', 'Cash',          NULL,         NULL,     NULL, 2),
  (34, 45000.00,  '2026-09-08', 'Bank Transfer', 'TRX-301204', NULL,     NULL, 2);

-- ============================================================
-- v2 tables: incentives, reminder log, audit log
-- ============================================================

-- ------------------------------------------------------------
-- Incentives: promotional / display / launch money a supplier
-- owes the mart ON TOP of shelf rent (the register's name has
-- always promised this half). Fixed incentives carry an
-- expected_amount; percentage incentives carry a rate and
-- their expected_amount stays NULL ("Awaited") until the actual
-- figure is known — the same rule bills follow.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS incentives (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  agreement_id    INT UNSIGNED NOT NULL,
  title           VARCHAR(150) NOT NULL,
  type            ENUM('Promotion','Display','Launch','Target','Other') NOT NULL DEFAULT 'Promotion',
  basis           ENUM('Fixed','Percentage') NOT NULL DEFAULT 'Fixed',
  rate            DECIMAL(5,2)   NULL,           -- percent, when basis = 'Percentage'
  expected_amount DECIMAL(12,2)  NULL,           -- NULL = amount awaited
  received_amount DECIMAL(12,2)  NOT NULL DEFAULT 0,
  expected_date   DATE NOT NULL,                 -- when the money is expected
  received_date   DATE NULL,                     -- when it was fully received
  notes           VARCHAR(255) NULL,
  created_by      INT UNSIGNED NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_incentives_agreement FOREIGN KEY (agreement_id) REFERENCES agreements(id) ON DELETE CASCADE,
  CONSTRAINT fk_incentives_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  CHECK (received_amount >= 0)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Reminder log: every payment reminder staff mark as sent
-- (WhatsApp or email), so follow-ups are provable.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS reminder_log (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  agreement_id INT UNSIGNED NOT NULL,
  channel      ENUM('WhatsApp','Email') NOT NULL,
  item_label   VARCHAR(190) NOT NULL,
  amount       DECIMAL(12,2) NULL,
  sent_by      INT UNSIGNED NULL,                -- NULL when sent by the CLI cron script
  sent_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_reminder_agreement FOREIGN KEY (agreement_id) REFERENCES agreements(id) ON DELETE CASCADE,
  CONSTRAINT fk_reminder_user FOREIGN KEY (sent_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Audit log: who did what, when. Deliberately has NO foreign
-- key to users — an audit row must survive the account it
-- describes being deleted.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS audit_log (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED NULL,
  username   VARCHAR(60) NULL,
  action     VARCHAR(80) NOT NULL,
  entity     VARCHAR(60) NOT NULL,
  entity_id  INT NULL,
  details    VARCHAR(500) NULL,
  ip         VARCHAR(45) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_audit_created (created_at),
  KEY idx_audit_user (username)
) ENGINE=InnoDB;

-- ============================================================
-- v4 tables: settings, cheques (PDC register), payment slips
-- (shelves + the users.company_id / agreements tax & shelf
-- columns live with their own tables above.)
-- ============================================================

-- ------------------------------------------------------------
-- Settings: small key/value store managed from the Settings
-- page (Admin). Mart tax identity for formal invoices, plus
-- the WhatsApp Cloud API plumbing — empty until an Admin
-- connects the mart's own WhatsApp Business API account.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS settings (
  skey   VARCHAR(60) PRIMARY KEY,
  svalue VARCHAR(500) NOT NULL DEFAULT ''
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Cheques: the post-dated cheque (PDC) register. A cheque may
-- optionally be linked to the payment it (part-)settles.
-- Status changes are written to the audit log by the app.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS cheques (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  agreement_id INT UNSIGNED NOT NULL,
  payment_id   INT UNSIGNED NULL,               -- optional link to a payments row
  cheque_no    VARCHAR(60)  NOT NULL,
  bank         VARCHAR(120) NULL,
  cheque_date  DATE NOT NULL,                   -- the date written on the cheque
  amount       DECIMAL(12,2) NOT NULL,
  status       ENUM('Pending','Cleared','Bounced','Returned') NOT NULL DEFAULT 'Pending',
  cleared_date DATE NULL,
  notes        VARCHAR(255) NULL,
  created_by   INT UNSIGNED NULL,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_cheques_agreement FOREIGN KEY (agreement_id) REFERENCES agreements(id) ON DELETE CASCADE,
  CONSTRAINT fk_cheques_payment FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE SET NULL,
  CONSTRAINT fk_cheques_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  CHECK (amount > 0)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Payment slips: proof-of-payment files uploaded by supplier
-- logins through the Supplier Portal. Staff review them
-- (Pending → Verified / Rejected) from the company ledger.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS payment_slips (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  agreement_id   INT UNSIGNED NOT NULL,
  uploaded_by    INT UNSIGNED NOT NULL,          -- the supplier user
  file_name      VARCHAR(255) NOT NULL,          -- inside uploads/slips/
  amount_claimed DECIMAL(12,2) NULL,
  slip_date      DATE NULL,
  note           VARCHAR(255) NULL,
  status         ENUM('Pending','Verified','Rejected') NOT NULL DEFAULT 'Pending',
  reviewed_by    INT UNSIGNED NULL,
  reviewed_at    TIMESTAMP NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_slips_agreement FOREIGN KEY (agreement_id) REFERENCES agreements(id) ON DELETE CASCADE,
  CONSTRAINT fk_slips_user FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_slips_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ============================================================
-- Sample incentives — one per supplier, covering every status:
-- Received, Overdue, Pending, Awaited, Partial.
-- Hand-checkable totals: known expected 455,000 · received
-- 115,000 · overdue balance 140,000 · awaited 1.
-- ============================================================
INSERT INTO incentives
  (id, agreement_id, title, type, basis, rate, expected_amount, received_amount, expected_date, received_date, notes, created_by) VALUES
  (1, 1, 'Diwali Promotion Incentive',  'Promotion', 'Fixed',      NULL,  75000.00,  75000.00, '2026-10-15', '2026-10-14', 'Diwali end-cap promotion, paid in full.', 1),
  (2, 2, 'Summer Display Incentive',    'Display',   'Fixed',      NULL, 120000.00,      0.00, '2026-09-30', NULL,       'Summer entrance display incentive, still unpaid.', 1),
  (3, 3, 'New Launch Incentive',        'Launch',    'Fixed',      NULL, 200000.00,      0.00, '2026-11-30', NULL,       'New product launch gondola incentive.', 1),
  (4, 4, 'Festive Promotion Incentive', 'Promotion', 'Percentage', 10.00,      NULL,      0.00, '2026-12-10', NULL,       '10% of festive-season promotion sales; actual amount awaited.', 1),
  (5, 5, 'Shelf Promotion Incentive',   'Promotion', 'Fixed',      NULL,  60000.00,  40000.00, '2026-10-10', NULL,       'Part received; balance promised by month end.', 1);

-- ============================================================
-- v4 seeds: settings keys (empty until an Admin fills them),
-- shelves for the Shelf Map (built from the sample agreements'
-- own location notes, plus vacant units so the map reads like
-- a real store), shelf assignments, and ONE supplier login.
-- ============================================================
INSERT INTO settings (skey, svalue) VALUES
  ('mart_ntn', ''), ('mart_strn', ''), ('mart_address', ''), ('mart_phone', ''),
  ('wa_api_token', ''), ('wa_phone_number_id', ''), ('wa_template', '')
ON DUPLICATE KEY UPDATE skey = skey;

INSERT INTO shelves (id, label, zone, sort_order) VALUES
  (1, 'Dairy Aisle · Chiller End-Cap', 'Ground Floor — Dairy', 10),
  (2, 'Entrance · Beverage Bay',      'Entrance',             20),
  (3, 'Front Gondola · Display Drive','Front of Store',       30),
  (4, 'Organic Corner',               'Ground Floor — Fresh', 40),
  (5, 'Household Aisle · Shelves 1–3','Ground Floor — Household', 50),
  (6, 'Aisle 2 · Shelf 1',            'Ground Floor',         60),
  (7, 'Aisle 2 · Shelf 2',            'Ground Floor',         70),
  (8, 'Window Display · Left',        'Front of Store',       80),
  (9, 'Checkout End-Cap',             'Checkout',             90);

UPDATE agreements SET shelf_id = 1 WHERE id = 1;
UPDATE agreements SET shelf_id = 2 WHERE id = 2;
UPDATE agreements SET shelf_id = 3 WHERE id = 3;
UPDATE agreements SET shelf_id = 4 WHERE id = 4;
UPDATE agreements SET shelf_id = 5 WHERE id = 5;

-- Supplier portal login (password: supp123) — sees Fresh Dairy only.
INSERT INTO users (id, name, username, password_hash, role, company_id) VALUES
  (4, 'Fresh Dairy Foods (Supplier)', 'supplier', '$2y$12$5YbZQ8WkchzI5faQw5IP/eAsTecGTaNlHjO1Njg3WLwPnzrgZnt.e', 'supplier', 1);
