-- ============================================================
-- Shelf Rent & Incentive Register — upgrade v3 → v4
-- Run ONCE on an existing v3 database:
--     mysql -u root shelf_rent_register < upgrade_v4.sql
-- Everything is additive: new columns default to 0 / NULL and
-- the new tables start empty, so no existing figure changes.
-- ============================================================
USE shelf_rent_register;
SET NAMES utf8mb4;

-- Supplier role + the one agreement a supplier login may see.
ALTER TABLE users
  MODIFY role ENUM('admin','accountant','viewer','supplier') NOT NULL DEFAULT 'viewer',
  ADD COLUMN company_id INT UNSIGNED NULL AFTER role,
  ADD KEY idx_users_company (company_id);

-- Shelves (created before agreements gains its shelf_id FK).
CREATE TABLE IF NOT EXISTS shelves (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  label      VARCHAR(120) NOT NULL,
  zone       VARCHAR(120) NULL,
  sort_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Tax engine percentages + shelf assignment on agreements.
ALTER TABLE agreements
  ADD COLUMN wht_percent DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER percentage_rate,
  ADD COLUMN gst_percent DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER wht_percent,
  ADD COLUMN shelf_id INT UNSIGNED NULL AFTER gst_percent,
  ADD CONSTRAINT fk_agreements_shelf FOREIGN KEY (shelf_id) REFERENCES shelves(id) ON DELETE SET NULL;

ALTER TABLE users
  ADD CONSTRAINT fk_users_company FOREIGN KEY (company_id) REFERENCES agreements(id) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS settings (
  skey   VARCHAR(60) PRIMARY KEY,
  svalue VARCHAR(500) NOT NULL DEFAULT ''
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS cheques (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  agreement_id INT UNSIGNED NOT NULL,
  payment_id   INT UNSIGNED NULL,
  cheque_no    VARCHAR(60)  NOT NULL,
  bank         VARCHAR(120) NULL,
  cheque_date  DATE NOT NULL,
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

CREATE TABLE IF NOT EXISTS payment_slips (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  agreement_id   INT UNSIGNED NOT NULL,
  uploaded_by    INT UNSIGNED NOT NULL,
  file_name      VARCHAR(255) NOT NULL,
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

-- Seeds (idempotent): settings keys, demo shelves + assignments,
-- and the demo supplier login (supplier / supp123 → Fresh Dairy).
INSERT INTO settings (skey, svalue) VALUES
  ('mart_ntn', ''), ('mart_strn', ''), ('mart_address', ''), ('mart_phone', ''),
  ('wa_api_token', ''), ('wa_phone_number_id', ''), ('wa_template', '')
ON DUPLICATE KEY UPDATE skey = skey;

INSERT IGNORE INTO shelves (id, label, zone, sort_order) VALUES
  (1, 'Dairy Aisle · Chiller End-Cap', 'Ground Floor — Dairy', 10),
  (2, 'Entrance · Beverage Bay',      'Entrance',             20),
  (3, 'Front Gondola · Display Drive','Front of Store',       30),
  (4, 'Organic Corner',               'Ground Floor — Fresh', 40),
  (5, 'Household Aisle · Shelves 1–3','Ground Floor — Household', 50),
  (6, 'Aisle 2 · Shelf 1',            'Ground Floor',         60),
  (7, 'Aisle 2 · Shelf 2',            'Ground Floor',         70),
  (8, 'Window Display · Left',        'Front of Store',       80),
  (9, 'Checkout End-Cap',             'Checkout',             90);

UPDATE agreements SET shelf_id = 1 WHERE id = 1 AND shelf_id IS NULL;
UPDATE agreements SET shelf_id = 2 WHERE id = 2 AND shelf_id IS NULL;
UPDATE agreements SET shelf_id = 3 WHERE id = 3 AND shelf_id IS NULL;
UPDATE agreements SET shelf_id = 4 WHERE id = 4 AND shelf_id IS NULL;
UPDATE agreements SET shelf_id = 5 WHERE id = 5 AND shelf_id IS NULL;

INSERT IGNORE INTO users (id, name, username, password_hash, role, company_id) VALUES
  (4, 'Fresh Dairy Foods (Supplier)', 'supplier', '$2y$12$5YbZQ8WkchzI5faQw5IP/eAsTecGTaNlHjO1Njg3WLwPnzrgZnt.e', 'supplier', 1);
