-- =============================================================================
--  Printer Shop POS — database schema
--  Compatible with MySQL 5.7+ and MariaDB 10.4+ (XAMPP default).
--
--  Import this file once through phpMyAdmin. It creates the database
--  "printer_pos" if it does not exist, then builds every table and inserts
--  the default settings. It is safe to re-import (tables use IF NOT EXISTS
--  and settings are inserted without duplicating).
-- =============================================================================

SET NAMES utf8mb4;
SET time_zone = '+05:00';

CREATE DATABASE IF NOT EXISTS printer_pos
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE printer_pos;

-- -----------------------------------------------------------------------------
-- users — the shop staff who can log in.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
  id            INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  username      VARCHAR(60)      NOT NULL,
  password_hash VARCHAR(255)     NOT NULL,          -- created with password_hash()
  display_name  VARCHAR(120)     NOT NULL,
  created_at    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_username (username)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- products — printers, spare parts, ink, toner and other sellable stock.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS products (
  id             INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  name           VARCHAR(160)     NOT NULL,
  sku            VARCHAR(80)      DEFAULT NULL,     -- optional code (auto-generated if blank)
  barcode        VARCHAR(40)      DEFAULT NULL,     -- auto-generated EAN-13 barcode value
  cost_price     DECIMAL(12,2)    NOT NULL DEFAULT 0.00,
  selling_price  DECIMAL(12,2)    NOT NULL DEFAULT 0.00,
  stock_quantity INT              NOT NULL DEFAULT 0,
  is_active      TINYINT(1)       NOT NULL DEFAULT 1, -- 1 = active, 0 = inactive
  created_at     DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_products_sku (sku),                 -- several NULL SKUs are allowed
  UNIQUE KEY uq_products_barcode (barcode),
  KEY idx_products_name (name),
  KEY idx_products_active (is_active)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- services — refilling, repair, cleaning and other labour/charge items.
-- A NULL price means the price is entered manually at the point of sale.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS services (
  id          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  name        VARCHAR(160)  NOT NULL,
  price       DECIMAL(12,2) DEFAULT NULL,           -- NULL = manual price at sale
  description VARCHAR(500)  DEFAULT NULL,
  is_active   TINYINT(1)    NOT NULL DEFAULT 1,
  created_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_services_active (is_active)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- bills — one row per sale (the header / totals).
-- Money is stored as DECIMAL, never floating point.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS bills (
  id              INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  bill_number     VARCHAR(40)      DEFAULT NULL,    -- stamped after insert (unique)
  subtotal        DECIMAL(12,2)    NOT NULL DEFAULT 0.00,
  discount_type   ENUM('fixed','percent') NOT NULL DEFAULT 'fixed',
  discount_value  DECIMAL(12,2)    NOT NULL DEFAULT 0.00, -- PKR, or % number
  discount_amount DECIMAL(12,2)    NOT NULL DEFAULT 0.00, -- PKR actually subtracted
  total           DECIMAL(12,2)    NOT NULL DEFAULT 0.00,
  amount_paid     DECIMAL(12,2)    NOT NULL DEFAULT 0.00,
  balance         DECIMAL(12,2)    NOT NULL DEFAULT 0.00, -- + = due, - = change
  payment_status  ENUM('paid','partial','unpaid') NOT NULL DEFAULT 'unpaid',
  created_by      INT UNSIGNED     NOT NULL,
  updated_by      INT UNSIGNED     DEFAULT NULL,
  created_at      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_bills_bill_number (bill_number),
  KEY idx_bills_created_at (created_at),
  KEY idx_bills_status (payment_status),
  CONSTRAINT fk_bills_created_by FOREIGN KEY (created_by) REFERENCES users (id),
  CONSTRAINT fk_bills_updated_by FOREIGN KEY (updated_by) REFERENCES users (id)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- bill_items — the lines on a bill. Each line stores a snapshot of the item's
-- name and unit price so old receipts stay correct even if the product/service
-- is later renamed, re-priced or marked inactive.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS bill_items (
  id          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  bill_id     INT UNSIGNED  NOT NULL,
  item_type   ENUM('product','service','custom') NOT NULL,
  product_id  INT UNSIGNED  DEFAULT NULL,
  service_id  INT UNSIGNED  DEFAULT NULL,
  item_name   VARCHAR(160)  NOT NULL,
  quantity    INT           NOT NULL DEFAULT 1,
  unit_price  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  line_total  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (id),
  KEY idx_bill_items_bill (bill_id),
  CONSTRAINT fk_bill_items_bill    FOREIGN KEY (bill_id)    REFERENCES bills (id)    ON DELETE CASCADE,
  CONSTRAINT fk_bill_items_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE SET NULL,
  CONSTRAINT fk_bill_items_service FOREIGN KEY (service_id) REFERENCES services (id) ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- bill_edit_history — a small record of important edits (mainly discount
-- changes): old and new values, totals, who made the change and when.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS bill_edit_history (
  id          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  bill_id     INT UNSIGNED  NOT NULL,
  user_id     INT UNSIGNED  NOT NULL,
  old_discount VARCHAR(100) NOT NULL,
  new_discount VARCHAR(100) NOT NULL,
  old_total   DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  new_total   DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  created_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_bill_edit_history_bill (bill_id),
  CONSTRAINT fk_edit_history_bill FOREIGN KEY (bill_id) REFERENCES bills (id) ON DELETE CASCADE,
  CONSTRAINT fk_edit_history_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- settings — simple key/value shop configuration.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS settings (
  setting_key   VARCHAR(60) NOT NULL,
  setting_value TEXT,
  PRIMARY KEY (setting_key)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- Default settings (no-op on re-import so values are never overwritten).
INSERT INTO settings (setting_key, setting_value) VALUES
  ('shop_name',            'Printer Shop'),
  ('contact_number',       ''),
  ('address',              ''),
  ('receipt_width',        '80'),   -- 58 or 80 (mm)
  ('receipt_footer',       'Thank you for your business!'),
  ('allow_negative_stock', '0')     -- 0 = block overselling, 1 = allow negative stock
ON DUPLICATE KEY UPDATE setting_value = setting_value;
