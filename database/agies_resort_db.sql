-- =====================================================================
-- Agie's Resort - corrected database schema (FRESH INSTALL)
-- Target: MariaDB 10.4+ / MySQL 8 (XAMPP), InnoDB, utf8mb4
--
-- IMPORT THIS INTO AN EMPTY `agies_resort_db` ONLY.
-- If you already have the old database with data you want to keep,
-- use database/migrate_existing_db.sql instead (see README).
-- CREATE TABLE statements intentionally have no IF NOT EXISTS / DROP,
-- so importing over an existing database fails loudly instead of
-- silently destroying data.
--
-- After import:
--   1. (optional) seed_100_customers.sql
--   2. (optional, demo only) seed_demo_data.sql
--   3. Set staff passwords with install/setup.php (accounts below have
--      NO usable password until you do).
-- =====================================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO,STRICT_TRANS_TABLES";
SET time_zone = "+08:00";
SET NAMES utf8mb4;

CREATE DATABASE IF NOT EXISTS `agies_resort_db` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE `agies_resort_db`;

-- ---------------------------------------------------------------------
-- Staff accounts (authoritative login table; replaces `administrators`)
-- ---------------------------------------------------------------------
CREATE TABLE `admins` (
  `admin_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `full_name` varchar(100) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `email` varchar(120) DEFAULT NULL,
  `contact_number` varchar(20) DEFAULT NULL,
  `role` enum('SUPER ADMIN','ADMIN') NOT NULL DEFAULT 'ADMIN',
  `status` enum('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  `must_change_password` tinyint(1) NOT NULL DEFAULT 1,
  `last_login` datetime DEFAULT NULL,
  `last_login_ip` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`admin_id`),
  UNIQUE KEY `uq_admin_username` (`username`),
  UNIQUE KEY `uq_admin_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Login / lookup throttling
CREATE TABLE `login_attempts` (
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
-- Customers
-- ---------------------------------------------------------------------
CREATE TABLE `customers` (
  `customer_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `full_name` varchar(120) NOT NULL,
  `contact_number` varchar(20) NOT NULL,
  `email` varchar(120) NOT NULL,
  `address` varchar(255) DEFAULT NULL,
  `status` enum('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  `notes` text DEFAULT NULL,
  `is_demo` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`customer_id`),
  UNIQUE KEY `uq_customer_email` (`email`),
  KEY `idx_customer_contact` (`contact_number`),
  KEY `idx_customer_name` (`full_name`),
  KEY `idx_customer_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
-- Resort catalogue: areas -> packages -> inclusions
-- Rates, extra-guest fee and down payment live ONLY on `packages`.
-- ---------------------------------------------------------------------
CREATE TABLE `resort_areas` (
  `area_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `area_code` varchar(20) NOT NULL,
  `area_name` varchar(80) NOT NULL,
  `description` text DEFAULT NULL,
  `capacity` smallint(5) UNSIGNED NOT NULL DEFAULT 30,
  `main_image` varchar(255) DEFAULT NULL,
  `status` enum('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  `sort_order` smallint(6) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`area_id`),
  UNIQUE KEY `uq_area_code` (`area_code`),
  UNIQUE KEY `uq_area_name` (`area_name`),
  CONSTRAINT `chk_area_capacity` CHECK (`capacity` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `packages` (
  `package_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `area_id` int(10) UNSIGNED NOT NULL,
  `package_name` varchar(120) NOT NULL,
  `booking_type` enum('DAYTIME','OVERNIGHT') NOT NULL,
  `rate` decimal(10,2) NOT NULL,
  `max_guests` smallint(5) UNSIGNED NOT NULL,
  `additional_guest_fee` decimal(10,2) NOT NULL DEFAULT 50.00,
  `max_extra_guests` smallint(5) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Extra guests allowed above max_guests (0 = strict capacity). Set only after management confirms.',
  `down_payment` decimal(10,2) NOT NULL DEFAULT 1000.00,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `image` varchar(255) DEFAULT NULL,
  `status` enum('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  `sort_order` smallint(6) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`package_id`),
  UNIQUE KEY `uq_pkg_area_type` (`area_id`,`booking_type`),
  KEY `idx_pkg_status` (`status`),
  CONSTRAINT `fk_pkg_area` FOREIGN KEY (`area_id`) REFERENCES `resort_areas` (`area_id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_pkg_rate` CHECK (`rate` >= 0 AND `additional_guest_fee` >= 0 AND `down_payment` >= 0 AND `down_payment` <= `rate`),
  CONSTRAINT `chk_pkg_guests` CHECK (`max_guests` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `package_inclusions` (
  `inclusion_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `package_id` int(10) UNSIGNED NOT NULL,
  `inclusion_text` varchar(255) NOT NULL,
  `sort_order` smallint(6) NOT NULL DEFAULT 0,
  PRIMARY KEY (`inclusion_id`),
  KEY `idx_incl_package` (`package_id`,`sort_order`),
  CONSTRAINT `fk_incl_pkg` FOREIGN KEY (`package_id`) REFERENCES `packages` (`package_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
-- Reservations
-- A bookable resource = (area, booking_date, booking_type).
-- Daytime 08:00-17:00 and overnight 18:00-07:00(+1 day) never overlap,
-- so one active reservation per resource is enforced by `slot_lock`.
-- Active (slot-holding) statuses: PENDING (time-limited hold),
-- PAYMENT PENDING, CONFIRMED. Historical statuses release the slot.
-- ---------------------------------------------------------------------
CREATE TABLE `reservations` (
  `reservation_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `reservation_code` varchar(20) NOT NULL,
  `customer_id` int(10) UNSIGNED NOT NULL,
  `area_id` int(10) UNSIGNED NOT NULL,
  `package_id` int(10) UNSIGNED NOT NULL,
  `package_name` varchar(120) NOT NULL COMMENT 'Snapshot at booking time',
  `booking_type` enum('DAYTIME','OVERNIGHT') NOT NULL,
  `booking_date` date NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `start_at` datetime NOT NULL,
  `end_at` datetime NOT NULL,
  `num_guests` smallint(5) UNSIGNED NOT NULL,
  `base_rate` decimal(10,2) NOT NULL COMMENT 'Snapshot',
  `extra_guests` smallint(5) UNSIGNED NOT NULL DEFAULT 0,
  `additional_fee` decimal(10,2) NOT NULL DEFAULT 0.00,
  `total_amount` decimal(10,2) NOT NULL,
  `down_payment` decimal(10,2) NOT NULL DEFAULT 1000.00,
  `amount_paid` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Cached SUM of VERIFIED payments; synced in the same transaction',
  `payment_status` enum('UNPAID','PENDING VERIFICATION','PARTIAL','PAID') NOT NULL DEFAULT 'UNPAID' COMMENT 'Cached summary derived from payments',
  `status` enum('PENDING','PAYMENT PENDING','CONFIRMED','COMPLETED','CANCELLED','DECLINED','EXPIRED') NOT NULL DEFAULT 'PENDING',
  `hold_expires_at` datetime DEFAULT NULL,
  `guest_name` varchar(120) NOT NULL,
  `guest_contact` varchar(20) NOT NULL,
  `notes` text DEFAULT NULL,
  `admin_notes` text DEFAULT NULL,
  `cancel_reason` varchar(255) DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `cancelled_by` int(10) UNSIGNED DEFAULT NULL,
  `confirmed_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `reschedule_count` tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
  `source` enum('WEBSITE','STAFF') NOT NULL DEFAULT 'WEBSITE',
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `needs_review` tinyint(1) NOT NULL DEFAULT 0,
  `review_note` varchar(255) DEFAULT NULL,
  `is_demo` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `slot_lock` varchar(40) GENERATED ALWAYS AS (if(`status` in ('PENDING','PAYMENT PENDING','CONFIRMED'),concat(`area_id`,'|',`booking_date`,'|',`booking_type`),NULL)) STORED,
  PRIMARY KEY (`reservation_id`),
  UNIQUE KEY `uq_reservation_code` (`reservation_code`),
  UNIQUE KEY `uq_slot_lock` (`slot_lock`),
  KEY `idx_res_slot` (`area_id`,`booking_date`,`booking_type`,`status`),
  KEY `idx_res_interval` (`area_id`,`start_at`,`end_at`),
  KEY `idx_res_status` (`status`,`hold_expires_at`),
  KEY `idx_res_customer` (`customer_id`),
  KEY `idx_res_date` (`booking_date`),
  KEY `idx_res_cancelled` (`cancelled_at`),
  KEY `fk_res_package` (`package_id`),
  KEY `fk_res_cancel_admin` (`cancelled_by`),
  KEY `fk_res_created_admin` (`created_by`),
  CONSTRAINT `fk_res_area` FOREIGN KEY (`area_id`) REFERENCES `resort_areas` (`area_id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_res_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`customer_id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_res_package` FOREIGN KEY (`package_id`) REFERENCES `packages` (`package_id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_res_cancel_admin` FOREIGN KEY (`cancelled_by`) REFERENCES `admins` (`admin_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_res_created_admin` FOREIGN KEY (`created_by`) REFERENCES `admins` (`admin_id`) ON DELETE SET NULL,
  CONSTRAINT `chk_res_guests` CHECK (`num_guests` > 0),
  CONSTRAINT `chk_res_money` CHECK (`total_amount` >= 0 AND `amount_paid` >= 0 AND `amount_paid` <= `total_amount` AND `down_payment` <= `total_amount`),
  CONSTRAINT `chk_res_interval` CHECK (`end_at` > `start_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `reservation_status_history` (
  `history_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `reservation_id` int(10) UNSIGNED NOT NULL,
  `old_status` varchar(20) DEFAULT NULL,
  `new_status` varchar(20) NOT NULL,
  `changed_by` int(10) UNSIGNED DEFAULT NULL,
  `remarks` varchar(255) DEFAULT NULL,
  `changed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`history_id`),
  KEY `idx_hist_res` (`reservation_id`,`changed_at`),
  KEY `fk_hist_admin` (`changed_by`),
  CONSTRAINT `fk_hist_admin` FOREIGN KEY (`changed_by`) REFERENCES `admins` (`admin_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_hist_res` FOREIGN KEY (`reservation_id`) REFERENCES `reservations` (`reservation_id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
-- Payments: one row per submitted payment. This table is AUTHORITATIVE;
-- reservations.amount_paid / payment_status are cached and re-synced.
-- ---------------------------------------------------------------------
CREATE TABLE `payments` (
  `payment_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `reservation_id` int(10) UNSIGNED NOT NULL,
  `payment_type` enum('DOWN PAYMENT','BALANCE','OTHER') NOT NULL DEFAULT 'DOWN PAYMENT',
  `amount_paid` decimal(10,2) NOT NULL,
  `payment_method` varchar(40) NOT NULL,
  `reference_number` varchar(80) DEFAULT NULL,
  `proof_path` varchar(255) DEFAULT NULL,
  `payment_status` enum('PENDING VERIFICATION','VERIFIED','REJECTED') NOT NULL DEFAULT 'PENDING VERIFICATION',
  `payment_date` date DEFAULT NULL,
  `submitted_by` enum('CUSTOMER','STAFF') NOT NULL DEFAULT 'CUSTOMER',
  `recorded_by` int(10) UNSIGNED DEFAULT NULL,
  `verified_by` int(10) UNSIGNED DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL,
  `rejection_reason` varchar(255) DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `is_demo` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`payment_id`),
  KEY `idx_pay_res` (`reservation_id`,`payment_status`),
  KEY `idx_pay_status` (`payment_status`),
  KEY `idx_pay_ref` (`reference_number`),
  KEY `idx_pay_verified_at` (`verified_at`),
  KEY `fk_pay_admin` (`verified_by`),
  KEY `fk_pay_recorder` (`recorded_by`),
  CONSTRAINT `fk_pay_admin` FOREIGN KEY (`verified_by`) REFERENCES `admins` (`admin_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_pay_recorder` FOREIGN KEY (`recorded_by`) REFERENCES `admins` (`admin_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_pay_res` FOREIGN KEY (`reservation_id`) REFERENCES `reservations` (`reservation_id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_pay_amount` CHECK (`amount_paid` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `payment_history` (
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

-- ---------------------------------------------------------------------
-- Rescheduling (max one PENDING request per reservation via pending_lock)
-- ---------------------------------------------------------------------
CREATE TABLE `rescheduling_requests` (
  `request_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `reservation_id` int(10) UNSIGNED NOT NULL,
  `original_date` date NOT NULL,
  `requested_date` date NOT NULL,
  `booking_type` enum('DAYTIME','OVERNIGHT') NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `request_date` date NOT NULL,
  `requested_via` enum('CUSTOMER','STAFF') NOT NULL DEFAULT 'CUSTOMER',
  `approval_status` enum('PENDING','APPROVED','REJECTED') NOT NULL DEFAULT 'PENDING',
  `approved_by` int(10) UNSIGNED DEFAULT NULL COMMENT 'Staff member who made the decision (approve or reject)',
  `approval_date` datetime DEFAULT NULL COMMENT 'Decision date',
  `notes` varchar(255) DEFAULT NULL COMMENT 'Decision remarks',
  `is_demo` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `pending_lock` int(10) UNSIGNED GENERATED ALWAYS AS (if(`approval_status` = 'PENDING',`reservation_id`,NULL)) STORED,
  PRIMARY KEY (`request_id`),
  UNIQUE KEY `uq_rs_pending` (`pending_lock`),
  KEY `idx_rs_res` (`reservation_id`),
  KEY `idx_rs_status` (`approval_status`),
  KEY `idx_rs_reqdate` (`request_date`),
  KEY `fk_rs_admin` (`approved_by`),
  CONSTRAINT `fk_rs_admin` FOREIGN KEY (`approved_by`) REFERENCES `admins` (`admin_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_rs_res` FOREIGN KEY (`reservation_id`) REFERENCES `reservations` (`reservation_id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
-- Inquiries, notifications, activity log
-- ---------------------------------------------------------------------
CREATE TABLE `inquiries` (
  `inquiry_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `full_name` varchar(120) NOT NULL,
  `email` varchar(120) NOT NULL,
  `contact_number` varchar(20) DEFAULT NULL,
  `subject` varchar(160) NOT NULL,
  `message` text NOT NULL,
  `status` enum('NEW','READ','REPLIED','ARCHIVED') NOT NULL DEFAULT 'NEW',
  `admin_notes` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `is_demo` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`inquiry_id`),
  KEY `idx_inq_status` (`status`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `notifications` (
  `notification_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `type` varchar(30) NOT NULL DEFAULT 'general',
  `reservation_id` int(10) UNSIGNED DEFAULT NULL,
  `inquiry_id` int(10) UNSIGNED DEFAULT NULL,
  `title` varchar(120) NOT NULL,
  `message` varchar(255) NOT NULL,
  `link` varchar(255) DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`notification_id`),
  KEY `idx_notif_read` (`is_read`,`created_at`),
  KEY `fk_notif_res` (`reservation_id`),
  KEY `fk_notif_inq` (`inquiry_id`),
  CONSTRAINT `fk_notif_res` FOREIGN KEY (`reservation_id`) REFERENCES `reservations` (`reservation_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_notif_inq` FOREIGN KEY (`inquiry_id`) REFERENCES `inquiries` (`inquiry_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `activity_logs` (
  `log_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` int(10) UNSIGNED DEFAULT NULL,
  `module` varchar(40) NOT NULL,
  `action` varchar(60) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`log_id`),
  KEY `idx_log_created` (`created_at`),
  KEY `idx_log_module` (`module`),
  KEY `fk_log_admin` (`admin_id`),
  CONSTRAINT `fk_log_admin` FOREIGN KEY (`admin_id`) REFERENCES `admins` (`admin_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
-- Website content, gallery, settings
-- ---------------------------------------------------------------------
CREATE TABLE `gallery` (
  `image_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `area_id` int(10) UNSIGNED DEFAULT NULL,
  `image_path` varchar(255) NOT NULL,
  `caption` varchar(160) DEFAULT NULL,
  `category` enum('AREA 1','AREA 2','AREA 3','GENERAL') NOT NULL DEFAULT 'GENERAL',
  `status` enum('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  `sort_order` smallint(6) NOT NULL DEFAULT 0,
  `uploaded_by` int(10) UNSIGNED DEFAULT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`image_id`),
  KEY `idx_gallery_cat` (`category`,`status`,`sort_order`),
  KEY `fk_gal_area` (`area_id`),
  KEY `fk_gal_admin` (`uploaded_by`),
  CONSTRAINT `fk_gal_admin` FOREIGN KEY (`uploaded_by`) REFERENCES `admins` (`admin_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_gal_area` FOREIGN KEY (`area_id`) REFERENCES `resort_areas` (`area_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `system_settings` (
  `setting_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(60) NOT NULL,
  `setting_value` text NOT NULL,
  `description` varchar(160) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`setting_id`),
  UNIQUE KEY `uq_setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `website_content` (
  `content_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `content_key` varchar(60) NOT NULL,
  `content_label` varchar(120) NOT NULL,
  `content_type` enum('text','textarea','image') NOT NULL DEFAULT 'text',
  `content_value` text DEFAULT NULL,
  `content_group` varchar(40) NOT NULL DEFAULT 'general',
  `updated_by` int(10) UNSIGNED DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`content_id`),
  UNIQUE KEY `uq_content_key` (`content_key`),
  KEY `fk_content_admin` (`updated_by`),
  CONSTRAINT `fk_content_admin` FOREIGN KEY (`updated_by`) REFERENCES `admins` (`admin_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
-- LEGACY (not used by the application). Generic hotel-room tables from
-- an earlier prototype; they do not describe the resort's real inventory
-- (three private areas). Kept so no data is lost; safe to drop once the
-- team confirms nothing depends on them.
-- ---------------------------------------------------------------------
CREATE TABLE `room_categories` (
  `category_id` int(11) NOT NULL AUTO_INCREMENT,
  `category_name` varchar(50) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`category_id`),
  UNIQUE KEY `category_name` (`category_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='LEGACY - not used';

CREATE TABLE `rooms` (
  `room_id` int(11) NOT NULL AUTO_INCREMENT,
  `category_id` int(11) NOT NULL,
  `room_number` varchar(20) NOT NULL,
  `rate` decimal(10,2) NOT NULL,
  `capacity` int(11) NOT NULL DEFAULT 2,
  `amenities` varchar(255) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'Available',
  `description` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`room_id`),
  UNIQUE KEY `room_number` (`room_number`),
  KEY `category_id` (`category_id`),
  CONSTRAINT `rooms_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `room_categories` (`category_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='LEGACY - not used';

-- =====================================================================
-- CORE SEED DATA
-- =====================================================================
START TRANSACTION;

-- Staff accounts. password_hash '!' can never match password_verify(),
-- so neither account can log in until a password is set with
-- install/setup.php (which also clears nothing else). must_change_password=1.
INSERT INTO `admins` (`admin_id`,`full_name`,`username`,`password_hash`,`email`,`role`,`status`,`must_change_password`) VALUES
(1,'Resort Administrator','admin','!','admin@agiesresort.local','SUPER ADMIN','ACTIVE',1),
(2,'Resort Manager','manager','!','manager@agiesresort.local','ADMIN','ACTIVE',1);

INSERT INTO `resort_areas` (`area_id`,`area_code`,`area_name`,`description`,`capacity`,`main_image`,`status`,`sort_order`) VALUES
(1,'AREA 1','Area 1','Area 1 has an air-conditioned room, three cottages, an adult pool, a kids pool and a jacuzzi-size pool, plus a grilling station. Good for groups of 35 pax and below.',35,'assets/images/area1.svg','ACTIVE',1),
(2,'AREA 2','Area 2','Area 2 has a kubo, an air-conditioned room, a cottage, an adult pool, a kids pool with slide, a grilling station and a small basketball court. Good for groups of 38 pax and below.',38,'assets/images/area2.svg','ACTIVE',2),
(3,'AREA 3','Area 3','Area 3 has two cottages, a triple-deck room with comfort room, an adult pool, a kids pool with slide, a grilling station and a basketball court. Good for groups of 30 pax and below.',30,'assets/images/area3.svg','ACTIVE',3);

INSERT INTO `packages` (`package_id`,`area_id`,`package_name`,`booking_type`,`rate`,`max_guests`,`additional_guest_fee`,`max_extra_guests`,`down_payment`,`start_time`,`end_time`,`description`,`status`,`sort_order`) VALUES
(1,1,'Area 1 - Daytime','DAYTIME',4000.00,35,50.00,0,1000.00,'08:00:00','17:00:00','Daytime stay, 8:00 AM to 5:00 PM','ACTIVE',1),
(2,1,'Area 1 - Overnight','OVERNIGHT',4500.00,35,50.00,0,1000.00,'18:00:00','07:00:00','Overnight stay, 6:00 PM to 7:00 AM','ACTIVE',2),
(3,2,'Area 2 - Daytime','DAYTIME',5000.00,38,50.00,0,1000.00,'08:00:00','17:00:00','Daytime stay, 8:00 AM to 5:00 PM','ACTIVE',3),
(4,2,'Area 2 - Overnight','OVERNIGHT',5000.00,38,50.00,0,1000.00,'18:00:00','07:00:00','Overnight stay, 6:00 PM to 7:00 AM','ACTIVE',4),
(5,3,'Area 3 - Daytime','DAYTIME',3800.00,30,50.00,0,1000.00,'08:00:00','17:00:00','Daytime stay, 8:00 AM to 5:00 PM','ACTIVE',5),
(6,3,'Area 3 - Overnight','OVERNIGHT',4000.00,30,50.00,0,1000.00,'18:00:00','07:00:00','Overnight stay, 6:00 PM to 7:00 AM','ACTIVE',6);

INSERT INTO `package_inclusions` (`package_id`,`inclusion_text`,`sort_order`) VALUES
(1,'1 AC Room, good for 10-15 pax',1),(1,'3 Cottages',2),(1,'1 Adult Pool, 4-6 ft',3),(1,'1 Kids Pool, 3-4 ft',4),(1,'1 Jacuzzi-size pool, knee-deep',5),(1,'Grilling station',6),(1,'Outdoor restroom',7),(1,'Free videoke until 12 midnight',8),(1,'Free billiard games for all areas, sharing basis',9),
(2,'1 AC Room, good for 10-15 pax',1),(2,'3 Cottages',2),(2,'1 Adult Pool, 4-6 ft',3),(2,'1 Kids Pool, 3-4 ft',4),(2,'1 Jacuzzi-size pool, knee-deep',5),(2,'Grilling station',6),(2,'Outdoor restroom',7),(2,'Free videoke until 12 midnight',8),(2,'Free billiard games for all areas, sharing basis',9),
(3,'1 Kubo, fan only',1),(3,'1 AC Room, good for 8-10 pax',2),(3,'1 Cottage',3),(3,'1 Adult Pool, 4-5\'7" ft',4),(3,'1 Kids Pool with slide, knee-deep',5),(3,'Grilling station',6),(3,'Outdoor restrooms',7),(3,'Small basketball court (guests bring their own basketball)',8),(3,'Free videoke until 12 midnight',9),(3,'Free billiard games for all areas, sharing basis',10),
(4,'1 Kubo, fan only',1),(4,'1 AC Room, good for 8-10 pax',2),(4,'1 Cottage',3),(4,'1 Adult Pool, 4-5\'7" ft',4),(4,'1 Kids Pool with slide, knee-deep',5),(4,'Grilling station',6),(4,'Outdoor restrooms',7),(4,'Small basketball court (guests bring their own basketball)',8),(4,'Free videoke until 12 midnight',9),(4,'Free billiard games for all areas, sharing basis',10),
(5,'2 Cottages',1),(5,'1 Triple-deck room with CR, fan only',2),(5,'1 Adult Pool, 4-5\'7" ft',3),(5,'1 Kids Pool with slide, knee-deep',4),(5,'Grilling station',5),(5,'Outdoor restroom',6),(5,'Basketball court (guests bring their own basketball)',7),(5,'Free videoke until 12 midnight',8),(5,'Free billiard games for all areas, sharing basis',9),
(6,'2 Cottages',1),(6,'1 Triple-deck room with CR, fan only',2),(6,'1 Adult Pool, 4-5\'7" ft',3),(6,'1 Kids Pool with slide, knee-deep',4),(6,'Grilling station',5),(6,'Outdoor restroom',6),(6,'Basketball court (guests bring their own basketball)',7),(6,'Free videoke until 12 midnight',8),(6,'Free billiard games for all areas, sharing basis',9);

INSERT INTO `gallery` (`area_id`,`image_path`,`caption`,`category`,`status`,`sort_order`) VALUES
(1,'assets/images/area1.svg','Area 1 (photo placeholder - upload a real photo in Admin > Gallery)','AREA 1','ACTIVE',1),
(2,'assets/images/area2.svg','Area 2 (photo placeholder - upload a real photo in Admin > Gallery)','AREA 2','ACTIVE',2),
(3,'assets/images/area3.svg','Area 3 (photo placeholder - upload a real photo in Admin > Gallery)','AREA 3','ACTIVE',3),
(NULL,'assets/images/general.svg','Resort photo placeholder','GENERAL','ACTIVE',4);

INSERT INTO `system_settings` (`setting_key`,`setting_value`,`description`) VALUES
('resort_name','Agie\'s Resort','Name shown across the website'),
('facebook_url','https://www.facebook.com/agies.resort','Official Facebook page (payment instructions via Messenger)'),
('reschedule_min_days','5','Minimum days before the booking date to request rescheduling'),
('reschedule_max_count','1','How many times one reservation may be rescheduled'),
('booking_min_days_ahead','1','Earliest bookable date, in days from today (provisional - confirm with management)'),
('booking_max_days_ahead','365','How far ahead (in days) a booking date may be chosen'),
('pending_hold_hours','48','Hours an unpaid PENDING request holds its slot before it expires (provisional - confirm with management)'),
('session_timeout_minutes','30','Staff session idle timeout'),
('login_max_attempts','5','Failed staff logins allowed per window before lockout'),
('login_lockout_minutes','15','Lockout window for failed staff logins'),
('payment_instructions','Send your down payment using the details provided by the resort on our official Facebook page, then submit the amount and reference number on the My Booking page. Your reservation is confirmed only after staff verify the payment.','Shown to customers after booking and on My Booking');

INSERT INTO `website_content` (`content_key`,`content_label`,`content_type`,`content_value`,`content_group`) VALUES
('hero_title','Hero headline','text','Welcome to Agie\'s Resort','Home page'),
('hero_subtitle','Hero sub-headline','textarea','Three private resort areas with pools, cottages, grilling stations, free videoke and free billiards. Check a date, send your request, and secure it with a down payment.','Home page'),
('hero_banner_image','Hero banner image','image','assets/images/hero.svg','Home page'),
('intro_text','Home introduction','textarea','Agie\'s Resort offers three separate resort areas that you can book for a daytime or overnight stay. Each area has its own pools, cottages and amenities so your group has the space to itself.','Home page'),
('about_text','About the resort','textarea','Agie\'s Resort is a private-use resort with three areas: Area 1, Area 2 and Area 3. Each area can be reserved for a Daytime stay (8:00 AM - 5:00 PM) or an Overnight stay (6:00 PM - 7:00 AM).\r\n\r\nEvery area comes with its own swimming pools, cottages or rooms, a grilling station and outdoor restrooms. Videoke is free until 12 midnight, and billiard games are free for all areas on a sharing basis.','About page'),
('about_atmosphere','Resort atmosphere','textarea','A relaxed, family-and-barkada setting where your group can swim, grill, sing and spend time together. Please replace this paragraph with the resort\'s own description.','About page'),
('general_info','General information','textarea','Reservations are made through this website. A reservation is confirmed only after the required down payment is received and verified by the resort.','About page'),
('contact_address','Address','text','Brgy. Bibirao, Daet, Camarines Norte, Philippines','Contact'),
('contact_phone','Contact number','text','','Contact'),
('contact_email','Email address','text','','Contact'),
('contact_hours','Reservation inquiry hours','text','Message us on Facebook Messenger for reservation and payment concerns.','Contact'),
('policies','Resort policies (one per line)','textarea','No down payment, no confirmed reservation.\r\nDown payment is non-refundable.\r\nRescheduling is allowed only once, subject to availability and management approval.\r\nRescheduling request must be made at least five days before the scheduled booking.\r\nGuests must follow the maximum number of guests allowed for the selected package/area.\r\nChildren must always be supervised by responsible adults.\r\nRunning, pushing, and diving in shallow pools are prohibited.\r\nGlass containers and food are not allowed inside the pool.\r\nProper swimming attire must be observed.\r\nVideoke must end at 12:00 midnight.\r\nGuests must practice Clean-As-You-Go (CLAYGO).\r\nGuests are responsible for damages caused by misuse or negligence.','Policies');

-- Legacy rows preserved as-is
INSERT INTO `room_categories` (`category_id`,`category_name`,`description`) VALUES
(1,'Standard Room','Basic room with essential amenities'),(2,'Deluxe Room','Spacious room with upgraded amenities'),(3,'Family Suite','Large room suitable for families or groups'),(4,'Cottage','Standalone cottage near the pool area');
INSERT INTO `rooms` (`room_id`,`category_id`,`room_number`,`rate`,`capacity`,`amenities`,`status`,`description`) VALUES
(1,1,'STD-101',1500.00,2,'Air Conditioning, Free Wi-Fi, TV','Available','Cozy standard room'),(2,1,'STD-102',1500.00,2,'Air Conditioning, Free Wi-Fi, TV','Available','Cozy standard room'),(3,2,'DLX-201',2500.00,3,'Air Conditioning, Free Wi-Fi, TV, Mini Fridge','Available','Comfortable deluxe room'),(4,3,'FAM-301',4000.00,6,'Air Conditioning, Free Wi-Fi, TV, Kitchenette','Available','Ideal for family gatherings'),(5,4,'COT-401',3000.00,4,'Fan, Terrace, Outdoor Seating','Under Maintenance','Cottage beside the pool');

COMMIT;
