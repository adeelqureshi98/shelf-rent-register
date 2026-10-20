-- ============================================================
-- Shelf Rent & Incentive Register — UPGRADE to v2
-- For an EXISTING v1 install only. A fresh install should just
-- import database.sql (it already contains everything below).
--
-- Adds: agreements.contact_email, incentives, reminder_log,
-- audit_log, and the v2 sample incentives + supplier emails.
-- Nothing is dropped or renamed; all v1 data is preserved.
--
-- Run:  mysql -u root -p shelf_rent_register < upgrade_v2.sql
-- (or import it in phpMyAdmin with the database selected)
-- ============================================================

USE shelf_rent_register;

-- 1) Supplier email addresses (used by the Reminders page) ----
ALTER TABLE agreements ADD COLUMN contact_email VARCHAR(120) NULL AFTER contact_phone;

UPDATE agreements SET contact_email = 'ahmed.raza@freshdairyfoods.pk'    WHERE id = 1;
UPDATE agreements SET contact_email = 'sara.khan@sparklebeverages.pk'    WHERE id = 2;
UPDATE agreements SET contact_email = 'imran.tariq@crunchysnacks.pk'     WHERE id = 3;
UPDATE agreements SET contact_email = 'mehwish.ali@greenvalleyorganics.pk' WHERE id = 4;
UPDATE agreements SET contact_email = 'bilal.ahmed@homecareessentials.pk'  WHERE id = 5;

-- 2) Incentives ----------------------------------------------
CREATE TABLE IF NOT EXISTS incentives (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  agreement_id    INT UNSIGNED NOT NULL,
  title           VARCHAR(150) NOT NULL,
  type            ENUM('Promotion','Display','Launch','Target','Other') NOT NULL DEFAULT 'Promotion',
  basis           ENUM('Fixed','Percentage') NOT NULL DEFAULT 'Fixed',
  rate            DECIMAL(5,2)   NULL,
  expected_amount DECIMAL(12,2)  NULL,
  received_amount DECIMAL(12,2)  NOT NULL DEFAULT 0,
  expected_date   DATE NOT NULL,
  received_date   DATE NULL,
  notes           VARCHAR(255) NULL,
  created_by      INT UNSIGNED NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_incentives_agreement FOREIGN KEY (agreement_id) REFERENCES agreements(id) ON DELETE CASCADE,
  CONSTRAINT fk_incentives_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  CHECK (received_amount >= 0)
) ENGINE=InnoDB;

-- 3) Reminder log ---------------------------------------------
CREATE TABLE IF NOT EXISTS reminder_log (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  agreement_id INT UNSIGNED NOT NULL,
  channel      ENUM('WhatsApp','Email') NOT NULL,
  item_label   VARCHAR(190) NOT NULL,
  amount       DECIMAL(12,2) NULL,
  sent_by      INT UNSIGNED NULL,
  sent_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_reminder_agreement FOREIGN KEY (agreement_id) REFERENCES agreements(id) ON DELETE CASCADE,
  CONSTRAINT fk_reminder_user FOREIGN KEY (sent_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- 4) Audit log -------------------------------------------------
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

-- 5) v2 sample incentives (same rows database.sql seeds) -----
INSERT INTO incentives
  (id, agreement_id, title, type, basis, rate, expected_amount, received_amount, expected_date, received_date, notes, created_by) VALUES
  (1, 1, 'Diwali Promotion Incentive',  'Promotion', 'Fixed',      NULL,  75000.00,  75000.00, '2026-10-15', '2026-10-14', 'Diwali end-cap promotion, paid in full.', 1),
  (2, 2, 'Summer Display Incentive',    'Display',   'Fixed',      NULL, 120000.00,      0.00, '2026-09-30', NULL,       'Summer entrance display incentive, still unpaid.', 1),
  (3, 3, 'New Launch Incentive',        'Launch',    'Fixed',      NULL, 200000.00,      0.00, '2026-11-30', NULL,       'New product launch gondola incentive.', 1),
  (4, 4, 'Festive Promotion Incentive', 'Promotion', 'Percentage', 10.00,      NULL,      0.00, '2026-12-10', NULL,       '10% of festive-season promotion sales; actual amount awaited.', 1),
  (5, 5, 'Shelf Promotion Incentive',   'Promotion', 'Fixed',      NULL,  60000.00,  40000.00, '2026-10-10', NULL,       'Part received; balance promised by month end.', 1);
