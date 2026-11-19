-- =====================================================================
-- Agie's Resort - MIGRATION of the existing `agies_resort_db`
-- (the 2026-10-18 phpMyAdmin export, MariaDB 10.4) to the corrected
-- schema used by the application. Result is column-for-column the same
-- as database/agies_resort_db.sql.
--
-- BEFORE RUNNING:
--   1. phpMyAdmin > agies_resort_db > Export > Quick > SQL. Keep that file.
--      DDL statements auto-commit, so if this script stops halfway the only
--      clean recovery is: drop the database, re-import your export, fix the
--      cause, run this script again.
--   2. Run database/tools/pre_migration_checks.sql and read the results.
--
-- RUN ONCE. Requires MariaDB 10.2+ (uses ADD COLUMN IF NOT EXISTS etc).
--
-- WHAT IT DOES (details in docs/DATABASE_AUDIT.md):
--   * Copies every affected table to bak20261019_* before changing it.
--   * Renames `administrators` -> `legacy_administrators` and blanks its
--     unsalted SHA-256 password (it is the hash of "admin123"). The
--     password is NOT migrated; staff set new passwords via install/setup.php.
--   * Forces a password change for every `admins` account.
--   * Moves rates / extra-guest fee / down payment to `packages` only.
--   * Rebuilds the slot lock so unpaid holds also reserve the slot.
--   * Makes payments authoritative; recomputes the cached totals.
--   * FLAGS (does not alter) over-capacity bookings and the over-verified
--     payment on AGR-0005 with needs_review = 1 for staff to resolve.
--   * Replaces ON DELETE CASCADE on history/payments with RESTRICT.
-- =====================================================================
USE `agies_resort_db`;
SET NAMES utf8mb4;
SET time_zone = '+08:00';

-- ---------------------------------------------------------------------
-- 0. Backups (plain copies; generated columns become normal columns)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `bak20261019_admins` AS SELECT * FROM `admins`;
CREATE TABLE IF NOT EXISTS `bak20261019_customers` AS SELECT * FROM `customers`;
CREATE TABLE IF NOT EXISTS `bak20261019_resort_areas` AS SELECT * FROM `resort_areas`;
CREATE TABLE IF NOT EXISTS `bak20261019_packages` AS SELECT * FROM `packages`;
CREATE TABLE IF NOT EXISTS `bak20261019_reservations` AS SELECT * FROM `reservations`;
CREATE TABLE IF NOT EXISTS `bak20261019_payments` AS SELECT * FROM `payments`;
CREATE TABLE IF NOT EXISTS `bak20261019_rescheduling_requests` AS SELECT * FROM `rescheduling_requests`;
CREATE TABLE IF NOT EXISTS `bak20261019_reservation_status_history` AS SELECT * FROM `reservation_status_history`;
CREATE TABLE IF NOT EXISTS `bak20261019_notifications` AS SELECT * FROM `notifications`;
CREATE TABLE IF NOT EXISTS `bak20261019_inquiries` AS SELECT * FROM `inquiries`;
CREATE TABLE IF NOT EXISTS `bak20261019_system_settings` AS SELECT * FROM `system_settings`;
CREATE TABLE IF NOT EXISTS `bak20261019_gallery` AS SELECT * FROM `gallery`;

-- ---------------------------------------------------------------------
-- 1. Staff accounts
-- ---------------------------------------------------------------------
ALTER TABLE `admins`
  ADD COLUMN IF NOT EXISTS `must_change_password` tinyint(1) NOT NULL DEFAULT 1 AFTER `status`,
  ADD COLUMN IF NOT EXISTS `last_login_ip` varchar(45) DEFAULT NULL AFTER `last_login`;
UPDATE `admins` SET `must_change_password` = 1;

RENAME TABLE `administrators` TO `legacy_administrators`;
UPDATE `legacy_administrators` SET `password` = 'REMOVED: unsalted SHA-256, not migrated';
ALTER TABLE `legacy_administrators` COMMENT = 'LEGACY - superseded by admins. Not used. Drop after review.';

CREATE TABLE IF NOT EXISTS `login_attempts` (
  `attempt_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `scope` varchar(20) NOT NULL DEFAULT 'staff',
  `identifier` varchar(120) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `success` tinyint(1) NOT NULL DEFAULT 0,
  `attempted_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`attempt_id`),
  KEY `idx_att_ip` (`scope`,`ip_address`,`attempted_at`),
  KEY `idx_att_id` (`scope`,`identifier`,`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
-- 2. Customers
-- ---------------------------------------------------------------------
ALTER TABLE `customers`
  ADD COLUMN IF NOT EXISTS `is_demo` tinyint(1) NOT NULL DEFAULT 0 AFTER `notes`,
  ADD KEY IF NOT EXISTS `idx_customer_status` (`status`);
UPDATE `customers` SET `full_name` = TRIM(`full_name`) WHERE `full_name` <> TRIM(`full_name`);
UPDATE `customers` SET `is_demo` = 1 WHERE `email` LIKE '%.sample@example.com';

-- ---------------------------------------------------------------------
-- 3. Catalogue: packages become the single source of pricing
-- ---------------------------------------------------------------------
ALTER TABLE `packages`
  ADD COLUMN IF NOT EXISTS `max_extra_guests` smallint(5) UNSIGNED NOT NULL DEFAULT 0 AFTER `additional_guest_fee`,
  ADD COLUMN IF NOT EXISTS `down_payment` decimal(10,2) NOT NULL DEFAULT 1000.00 AFTER `max_extra_guests`,
  ADD COLUMN IF NOT EXISTS `sort_order` smallint(6) NOT NULL DEFAULT 0 AFTER `status`;
UPDATE `packages` p JOIN `resort_areas` a ON a.area_id = p.area_id
   SET p.down_payment = a.down_payment, p.sort_order = p.package_id;
ALTER TABLE `packages` DROP FOREIGN KEY `fk_pkg_area`;
ALTER TABLE `packages`
  ADD CONSTRAINT `fk_pkg_area` FOREIGN KEY (`area_id`) REFERENCES `resort_areas` (`area_id`) ON DELETE RESTRICT,
  ADD UNIQUE KEY `uq_pkg_area_type` (`area_id`,`booking_type`),
  ADD KEY IF NOT EXISTS `idx_pkg_status` (`status`),
  ADD CONSTRAINT `chk_pkg_rate` CHECK (`rate` >= 0 AND `additional_guest_fee` >= 0 AND `down_payment` >= 0 AND `down_payment` <= `rate`),
  ADD CONSTRAINT `chk_pkg_guests` CHECK (`max_guests` > 0);

ALTER TABLE `resort_areas`
  DROP COLUMN IF EXISTS `extra_guest_fee`,
  DROP COLUMN IF EXISTS `daytime_rate`,
  DROP COLUMN IF EXISTS `overnight_rate`,
  DROP COLUMN IF EXISTS `down_payment`,
  ADD CONSTRAINT `chk_area_capacity` CHECK (`capacity` > 0);
-- The old placeholder images (assets/images/areaN/main.png) do not exist; point to bundled placeholders.
UPDATE `resort_areas` SET `main_image` = CONCAT('assets/images/area', `area_id`, '.svg') WHERE `main_image` LIKE 'assets/images/area_/main.png';

ALTER TABLE `gallery` ADD COLUMN IF NOT EXISTS `sort_order` smallint(6) NOT NULL DEFAULT 0 AFTER `status`;
UPDATE `gallery` SET `image_path` = CONCAT('assets/images/area', `area_id`, '.svg') WHERE `image_path` LIKE 'assets/images/area_/main.png' AND `area_id` IS NOT NULL;
UPDATE `gallery` SET `image_path` = 'assets/images/general.svg' WHERE `image_path` LIKE 'assets/images/gallery/placeholder_%.png';
UPDATE `gallery` SET `sort_order` = `image_id`;
ALTER TABLE `gallery` DROP INDEX `idx_gallery_cat`, ADD KEY `idx_gallery_cat` (`category`,`status`,`sort_order`);

-- ---------------------------------------------------------------------
-- 4. Reservations
-- ---------------------------------------------------------------------
ALTER TABLE `reservations` DROP INDEX `uq_slot_lock`;
ALTER TABLE `reservations` DROP COLUMN `slot_lock`;

-- superset enums so old and new values coexist during the data fix
ALTER TABLE `reservations`
  MODIFY `status` enum('PENDING','PAYMENT PENDING','CONFIRMED','COMPLETED','CANCELLED','DECLINED','EXPIRED','RESCHEDULED') NOT NULL DEFAULT 'PENDING',
  MODIFY `payment_status` enum('UNPAID','PENDING VERIFICATION','VERIFIED','REJECTED','PARTIAL','PAID') NOT NULL DEFAULT 'UNPAID';
-- RESCHEDULED was an event, not a state: a rescheduled booking is still CONFIRMED.
UPDATE `reservations` SET `status` = 'CONFIRMED' WHERE `status` = 'RESCHEDULED';

ALTER TABLE `reservations`
  ADD COLUMN IF NOT EXISTS `package_name` varchar(120) NOT NULL DEFAULT '' AFTER `package_id`,
  ADD COLUMN IF NOT EXISTS `start_at` datetime DEFAULT NULL AFTER `end_time`,
  ADD COLUMN IF NOT EXISTS `end_at` datetime DEFAULT NULL AFTER `start_at`,
  ADD COLUMN IF NOT EXISTS `amount_paid` decimal(10,2) NOT NULL DEFAULT 0.00 AFTER `down_payment`,
  ADD COLUMN IF NOT EXISTS `hold_expires_at` datetime DEFAULT NULL AFTER `status`,
  ADD COLUMN IF NOT EXISTS `guest_name` varchar(120) NOT NULL DEFAULT '' AFTER `hold_expires_at`,
  ADD COLUMN IF NOT EXISTS `guest_contact` varchar(20) NOT NULL DEFAULT '' AFTER `guest_name`,
  ADD COLUMN IF NOT EXISTS `cancel_reason` varchar(255) DEFAULT NULL AFTER `admin_notes`,
  ADD COLUMN IF NOT EXISTS `cancelled_at` datetime DEFAULT NULL AFTER `cancel_reason`,
  ADD COLUMN IF NOT EXISTS `cancelled_by` int(10) UNSIGNED DEFAULT NULL AFTER `cancelled_at`,
  ADD COLUMN IF NOT EXISTS `confirmed_at` datetime DEFAULT NULL AFTER `cancelled_by`,
  ADD COLUMN IF NOT EXISTS `completed_at` datetime DEFAULT NULL AFTER `confirmed_at`,
  ADD COLUMN IF NOT EXISTS `source` enum('WEBSITE','STAFF') NOT NULL DEFAULT 'WEBSITE' AFTER `reschedule_count`,
  ADD COLUMN IF NOT EXISTS `created_by` int(10) UNSIGNED DEFAULT NULL AFTER `source`,
  ADD COLUMN IF NOT EXISTS `needs_review` tinyint(1) NOT NULL DEFAULT 0 AFTER `created_by`,
  ADD COLUMN IF NOT EXISTS `review_note` varchar(255) DEFAULT NULL AFTER `needs_review`,
  ADD COLUMN IF NOT EXISTS `is_demo` tinyint(1) NOT NULL DEFAULT 0 AFTER `review_note`;

UPDATE `reservations` SET `reservation_code` = CONCAT('AGR-', LPAD(`reservation_id`, 4, '0')) WHERE `reservation_code` IS NULL OR `reservation_code` = '';
UPDATE `reservations` r JOIN `packages` p ON p.package_id = r.package_id SET r.package_name = p.package_name WHERE r.package_name = '';
UPDATE `reservations` r JOIN `customers` c ON c.customer_id = r.customer_id
   SET r.guest_name = c.full_name, r.guest_contact = c.contact_number WHERE r.guest_name = '';
UPDATE `reservations`
   SET `start_at` = TIMESTAMP(`booking_date`, `start_time`),
       `end_at`   = IF(`end_time` <= `start_time`, TIMESTAMP(`booking_date` + INTERVAL 1 DAY, `end_time`), TIMESTAMP(`booking_date`, `end_time`));
UPDATE `reservations` SET `is_demo` = 1 WHERE `notes` LIKE '%sample data%';
UPDATE `reservations` r SET r.source = 'STAFF',
       r.created_by = (SELECT h.changed_by FROM `reservation_status_history` h WHERE h.reservation_id = r.reservation_id AND h.remarks LIKE 'Created via Management System%' LIMIT 1)
 WHERE EXISTS (SELECT 1 FROM `reservation_status_history` h WHERE h.reservation_id = r.reservation_id AND h.remarks LIKE 'Created via Management System%');

-- lifecycle timestamps from the status history
UPDATE `reservations` r SET
  r.cancelled_at  = (SELECT MAX(h.changed_at) FROM `reservation_status_history` h WHERE h.reservation_id = r.reservation_id AND h.new_status = 'CANCELLED'),
  r.cancel_reason = (SELECT h.remarks FROM `reservation_status_history` h WHERE h.reservation_id = r.reservation_id AND h.new_status = 'CANCELLED' ORDER BY h.changed_at DESC LIMIT 1),
  r.cancelled_by  = (SELECT h.changed_by FROM `reservation_status_history` h WHERE h.reservation_id = r.reservation_id AND h.new_status = 'CANCELLED' ORDER BY h.changed_at DESC LIMIT 1)
 WHERE r.status = 'CANCELLED';
UPDATE `reservations` SET `cancelled_at` = `updated_at` WHERE `status` = 'CANCELLED' AND `cancelled_at` IS NULL;
UPDATE `reservations` r SET r.confirmed_at = (SELECT MIN(h.changed_at) FROM `reservation_status_history` h WHERE h.reservation_id = r.reservation_id AND h.new_status = 'CONFIRMED')
 WHERE r.status IN ('CONFIRMED','COMPLETED');
UPDATE `reservations` r SET r.completed_at = (SELECT MAX(h.changed_at) FROM `reservation_status_history` h WHERE h.reservation_id = r.reservation_id AND h.new_status = 'COMPLETED')
 WHERE r.status = 'COMPLETED';

-- ---------------------------------------------------------------------
-- 5. Payments (authoritative). Placeholder rows with 0 paid are removed
--    (they record no money; originals remain in bak20261019_payments).
-- ---------------------------------------------------------------------
UPDATE `payments` SET `payment_status` = 'PENDING VERIFICATION',
       `notes` = CONCAT_WS(' ', `notes`, '[migration: was UNPAID with an amount]')
 WHERE `payment_status` = 'UNPAID' AND `amount_paid` > 0;
DELETE FROM `payments` WHERE `amount_paid` <= 0;
UPDATE `payments` SET `payment_method` = 'Unspecified' WHERE `payment_method` IS NULL OR `payment_method` = '';

ALTER TABLE `payments` DROP FOREIGN KEY `fk_pay_res`;
ALTER TABLE `payments`
  ADD COLUMN IF NOT EXISTS `payment_type` enum('DOWN PAYMENT','BALANCE','OTHER') NOT NULL DEFAULT 'DOWN PAYMENT' AFTER `reservation_id`,
  ADD COLUMN IF NOT EXISTS `proof_path` varchar(255) DEFAULT NULL AFTER `reference_number`,
  ADD COLUMN IF NOT EXISTS `submitted_by` enum('CUSTOMER','STAFF') NOT NULL DEFAULT 'CUSTOMER' AFTER `payment_date`,
  ADD COLUMN IF NOT EXISTS `recorded_by` int(10) UNSIGNED DEFAULT NULL AFTER `submitted_by`,
  ADD COLUMN IF NOT EXISTS `rejection_reason` varchar(255) DEFAULT NULL AFTER `verified_at`,
  ADD COLUMN IF NOT EXISTS `is_demo` tinyint(1) NOT NULL DEFAULT 0 AFTER `notes`,
  ADD COLUMN IF NOT EXISTS `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp() AFTER `created_at`,
  MODIFY `amount_paid` decimal(10,2) NOT NULL,
  MODIFY `payment_method` varchar(40) NOT NULL,
  MODIFY `payment_status` enum('PENDING VERIFICATION','VERIFIED','REJECTED') NOT NULL DEFAULT 'PENDING VERIFICATION',
  DROP COLUMN IF EXISTS `amount_required`;
ALTER TABLE `payments`
  DROP INDEX `idx_pay_res`,
  ADD KEY `idx_pay_res` (`reservation_id`,`payment_status`),
  ADD KEY IF NOT EXISTS `idx_pay_ref` (`reference_number`),
  ADD KEY IF NOT EXISTS `idx_pay_verified_at` (`verified_at`),
  ADD KEY IF NOT EXISTS `fk_pay_recorder` (`recorded_by`),
  ADD CONSTRAINT `fk_pay_res` FOREIGN KEY (`reservation_id`) REFERENCES `reservations` (`reservation_id`) ON DELETE RESTRICT,
  ADD CONSTRAINT `fk_pay_recorder` FOREIGN KEY (`recorded_by`) REFERENCES `admins` (`admin_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `chk_pay_amount` CHECK (`amount_paid` > 0);
UPDATE `payments` p JOIN `reservations` r ON r.reservation_id = p.reservation_id SET p.is_demo = r.is_demo;

CREATE TABLE IF NOT EXISTS `payment_history` (
  `history_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `payment_id` int(10) UNSIGNED NOT NULL,
  `old_status` varchar(30) DEFAULT NULL,
  `new_status` varchar(30) NOT NULL,
  `changed_by` int(10) UNSIGNED DEFAULT NULL,
  `remarks` varchar(255) DEFAULT NULL,
  `changed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`history_id`),
  KEY `idx_payhist_pay` (`payment_id`),
  KEY `fk_payhist_admin` (`changed_by`),
  CONSTRAINT `fk_payhist_pay` FOREIGN KEY (`payment_id`) REFERENCES `payments` (`payment_id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_payhist_admin` FOREIGN KEY (`changed_by`) REFERENCES `admins` (`admin_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
INSERT INTO `payment_history` (`payment_id`,`old_status`,`new_status`,`changed_by`,`remarks`,`changed_at`)
SELECT `payment_id`, NULL, `payment_status`, `verified_by`, 'Migrated existing payment record', COALESCE(`verified_at`, `created_at`) FROM `payments`;

-- cached totals: amount_paid never exceeds total; overpayments are FLAGGED, not rewritten
UPDATE `reservations` r
LEFT JOIN (SELECT reservation_id, SUM(amount_paid) AS s FROM `payments` WHERE payment_status = 'VERIFIED' GROUP BY reservation_id) v
       ON v.reservation_id = r.reservation_id
   SET r.needs_review = IF(COALESCE(v.s,0) > r.total_amount, 1, r.needs_review),
       r.review_note  = IF(COALESCE(v.s,0) > r.total_amount,
                           CONCAT('Verified payments (', COALESCE(v.s,0), ') exceed booking total (', r.total_amount, '); check the payment record.'),
                           r.review_note),
       r.amount_paid  = LEAST(COALESCE(v.s,0), r.total_amount);
UPDATE `reservations` r SET r.payment_status = CASE
    WHEN EXISTS (SELECT 1 FROM `payments` p WHERE p.reservation_id = r.reservation_id AND p.payment_status = 'PENDING VERIFICATION') THEN 'PENDING VERIFICATION'
    WHEN r.amount_paid >= r.total_amount THEN 'PAID'
    WHEN r.amount_paid > 0 THEN 'PARTIAL'
    ELSE 'UNPAID' END;

-- capacity & confirmation flags (values are NOT changed)
UPDATE `reservations` r JOIN `packages` p ON p.package_id = r.package_id
   SET r.needs_review = 1,
       r.review_note = LEFT(CONCAT_WS(' ', r.review_note, CONCAT('Guest count ', r.num_guests, ' exceeds the package limit of ', p.max_guests, '.')), 255)
 WHERE r.num_guests > p.max_guests + p.max_extra_guests;
UPDATE `reservations`
   SET `needs_review` = 1, `review_note` = LEFT(CONCAT_WS(' ', `review_note`, 'Confirmed without a verified down payment.'), 255)
 WHERE `status` IN ('CONFIRMED','COMPLETED') AND `amount_paid` < `down_payment`;

-- unpaid requests: past-dated ones expire; future ones get a fresh 48h hold from now
INSERT INTO `reservation_status_history` (`reservation_id`,`old_status`,`new_status`,`remarks`)
SELECT `reservation_id`, `status`, 'EXPIRED', 'Migration: booking date passed without confirmation'
  FROM `reservations` WHERE `status` IN ('PENDING','PAYMENT PENDING') AND `booking_date` < CURDATE();
UPDATE `reservations` SET `status` = 'EXPIRED', `cancelled_at` = NOW(), `cancel_reason` = 'Booking date passed without confirmation (migration)'
 WHERE `status` IN ('PENDING','PAYMENT PENDING') AND `booking_date` < CURDATE();
UPDATE `reservations` SET `hold_expires_at` = NOW() + INTERVAL 48 HOUR WHERE `status` = 'PENDING';

-- final types, keys and constraints
ALTER TABLE `reservations` DROP FOREIGN KEY `fk_res_package`;
ALTER TABLE `reservations` DROP FOREIGN KEY `fk_res_area`;
ALTER TABLE `reservations` DROP FOREIGN KEY `fk_res_customer`;
ALTER TABLE `reservations`
  MODIFY `reservation_code` varchar(20) NOT NULL,
  MODIFY `package_id` int(10) UNSIGNED NOT NULL,
  MODIFY `package_name` varchar(120) NOT NULL,
  MODIFY `start_at` datetime NOT NULL,
  MODIFY `end_at` datetime NOT NULL,
  MODIFY `guest_name` varchar(120) NOT NULL,
  MODIFY `guest_contact` varchar(20) NOT NULL,
  MODIFY `status` enum('PENDING','PAYMENT PENDING','CONFIRMED','COMPLETED','CANCELLED','DECLINED','EXPIRED') NOT NULL DEFAULT 'PENDING',
  MODIFY `payment_status` enum('UNPAID','PENDING VERIFICATION','PARTIAL','PAID') NOT NULL DEFAULT 'UNPAID',
  ADD COLUMN `slot_lock` varchar(40) GENERATED ALWAYS AS (if(`status` in ('PENDING','PAYMENT PENDING','CONFIRMED'),concat(`area_id`,'|',`booking_date`,'|',`booking_type`),NULL)) STORED;
ALTER TABLE `reservations`
  ADD UNIQUE KEY `uq_slot_lock` (`slot_lock`),
  DROP INDEX `idx_res_status`,
  ADD KEY `idx_res_status` (`status`,`hold_expires_at`),
  ADD KEY `idx_res_interval` (`area_id`,`start_at`,`end_at`),
  ADD KEY `idx_res_cancelled` (`cancelled_at`),
  ADD KEY `fk_res_cancel_admin` (`cancelled_by`),
  ADD KEY `fk_res_created_admin` (`created_by`),
  ADD CONSTRAINT `fk_res_area` FOREIGN KEY (`area_id`) REFERENCES `resort_areas` (`area_id`) ON DELETE RESTRICT,
  ADD CONSTRAINT `fk_res_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`customer_id`) ON DELETE RESTRICT,
  ADD CONSTRAINT `fk_res_package` FOREIGN KEY (`package_id`) REFERENCES `packages` (`package_id`) ON DELETE RESTRICT,
  ADD CONSTRAINT `fk_res_cancel_admin` FOREIGN KEY (`cancelled_by`) REFERENCES `admins` (`admin_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_res_created_admin` FOREIGN KEY (`created_by`) REFERENCES `admins` (`admin_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `chk_res_guests` CHECK (`num_guests` > 0),
  ADD CONSTRAINT `chk_res_money` CHECK (`total_amount` >= 0 AND `amount_paid` >= 0 AND `amount_paid` <= `total_amount` AND `down_payment` <= `total_amount`),
  ADD CONSTRAINT `chk_res_interval` CHECK (`end_at` > `start_at`);

-- ---------------------------------------------------------------------
-- 6. History / reschedules / notifications / inquiries: no more cascades
-- ---------------------------------------------------------------------
ALTER TABLE `reservation_status_history` DROP FOREIGN KEY `fk_hist_res`;
ALTER TABLE `reservation_status_history`
  DROP INDEX `idx_hist_res`, ADD KEY `idx_hist_res` (`reservation_id`,`changed_at`),
  ADD CONSTRAINT `fk_hist_res` FOREIGN KEY (`reservation_id`) REFERENCES `reservations` (`reservation_id`) ON DELETE RESTRICT;

ALTER TABLE `rescheduling_requests` DROP FOREIGN KEY `fk_rs_res`;
ALTER TABLE `rescheduling_requests`
  ADD COLUMN IF NOT EXISTS `booking_type` enum('DAYTIME','OVERNIGHT') NOT NULL DEFAULT 'DAYTIME' AFTER `requested_date`,
  ADD COLUMN IF NOT EXISTS `requested_via` enum('CUSTOMER','STAFF') NOT NULL DEFAULT 'CUSTOMER' AFTER `request_date`,
  ADD COLUMN IF NOT EXISTS `is_demo` tinyint(1) NOT NULL DEFAULT 0 AFTER `notes`;
UPDATE `rescheduling_requests` q JOIN `reservations` r ON r.reservation_id = q.reservation_id SET q.booking_type = r.booking_type, q.is_demo = r.is_demo;
ALTER TABLE `rescheduling_requests`
  MODIFY `booking_type` enum('DAYTIME','OVERNIGHT') NOT NULL,
  ADD COLUMN `pending_lock` int(10) UNSIGNED GENERATED ALWAYS AS (if(`approval_status` = 'PENDING',`reservation_id`,NULL)) STORED;
ALTER TABLE `rescheduling_requests`
  ADD UNIQUE KEY `uq_rs_pending` (`pending_lock`),
  ADD KEY IF NOT EXISTS `idx_rs_reqdate` (`request_date`),
  ADD CONSTRAINT `fk_rs_res` FOREIGN KEY (`reservation_id`) REFERENCES `reservations` (`reservation_id`) ON DELETE RESTRICT;

ALTER TABLE `inquiries`
  MODIFY `status` enum('NEW','READ','REPLIED','ARCHIVED') NOT NULL DEFAULT 'NEW',
  ADD COLUMN IF NOT EXISTS `admin_notes` text DEFAULT NULL AFTER `status`,
  ADD COLUMN IF NOT EXISTS `ip_address` varchar(45) DEFAULT NULL AFTER `admin_notes`,
  ADD COLUMN IF NOT EXISTS `is_demo` tinyint(1) NOT NULL DEFAULT 0 AFTER `ip_address`,
  ADD COLUMN IF NOT EXISTS `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp() AFTER `created_at`;
ALTER TABLE `inquiries` DROP INDEX `idx_inq_status`, ADD KEY `idx_inq_status` (`status`,`created_at`);

ALTER TABLE `notifications` DROP FOREIGN KEY `fk_notif_res`;
ALTER TABLE `notifications`
  ADD COLUMN IF NOT EXISTS `type` varchar(30) NOT NULL DEFAULT 'general' AFTER `notification_id`,
  ADD COLUMN IF NOT EXISTS `inquiry_id` int(10) UNSIGNED DEFAULT NULL AFTER `reservation_id`,
  ADD COLUMN IF NOT EXISTS `link` varchar(255) DEFAULT NULL AFTER `message`;
ALTER TABLE `notifications`
  ADD KEY IF NOT EXISTS `fk_notif_inq` (`inquiry_id`),
  ADD CONSTRAINT `fk_notif_res` FOREIGN KEY (`reservation_id`) REFERENCES `reservations` (`reservation_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_notif_inq` FOREIGN KEY (`inquiry_id`) REFERENCES `inquiries` (`inquiry_id`) ON DELETE SET NULL;
UPDATE `notifications` SET `type` = 'reservation', `link` = CONCAT('admin/reservation.php?id=', `reservation_id`) WHERE `title` = 'New reservation' AND `reservation_id` IS NOT NULL;
UPDATE `notifications` SET `type` = 'inquiry', `link` = 'admin/inquiries.php' WHERE `title` = 'New inquiry';

ALTER TABLE `activity_logs` ADD KEY IF NOT EXISTS `idx_log_module` (`module`);

-- ---------------------------------------------------------------------
-- 7. Settings
-- ---------------------------------------------------------------------
ALTER TABLE `system_settings` MODIFY `setting_value` text NOT NULL;
-- max_guests_limit=100 overrode the real package capacities; removed (copy in backup table).
DELETE FROM `system_settings` WHERE `setting_key` = 'max_guests_limit';
INSERT INTO `system_settings` (`setting_key`,`setting_value`,`description`) VALUES
('booking_min_days_ahead','1','Earliest bookable date, in days from today (provisional - confirm with management)'),
('pending_hold_hours','48','Hours an unpaid PENDING request holds its slot before it expires (provisional - confirm with management)'),
('session_timeout_minutes','30','Staff session idle timeout'),
('login_max_attempts','5','Failed staff logins allowed per window before lockout'),
('login_lockout_minutes','15','Lockout window for failed staff logins'),
('payment_instructions','Send your down payment using the details provided by the resort on our official Facebook page, then submit the amount and reference number on the My Booking page. Your reservation is confirmed only after staff verify the payment.','Shown to customers after booking and on My Booking')
ON DUPLICATE KEY UPDATE `setting_key` = `setting_key`;

ALTER TABLE `rooms` COMMENT = 'LEGACY - not used';
ALTER TABLE `room_categories` COMMENT = 'LEGACY - not used';

INSERT INTO `activity_logs` (`admin_id`,`module`,`action`,`description`) VALUES
(NULL,'System','Migration','Schema migrated to corrected model (bak20261019_* tables created)');

-- Done. Now run database/verify_database.sql and review rows with needs_review = 1.
