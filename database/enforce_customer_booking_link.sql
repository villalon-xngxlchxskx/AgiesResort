-- =====================================================================
-- Agie's Resort - enforce "a customer exists only through a reservation"
-- Run in phpMyAdmin (database agies_resort_db -> SQL tab or Import).
--
-- Customers are created only inside the reservation transaction (public booking
-- form, admin/reservation-new.php, desktop New Booking). This script removes
-- customer records that were created on their own (no reservation at all),
-- e.g. by an older version of seed_100_customers.sql or the old "Add customer"
-- button. Customers with ANY reservation (any status) are never touched, and
-- reservations/payments are never deleted.
--
-- Safe to run more than once. Export a backup first if you are unsure.
-- =====================================================================
USE `agies_resort_db`;

-- 1) Preview: orphan customers that will be removed
SELECT c.customer_id, c.full_name, c.email, c.is_demo, c.created_at
  FROM customers c
 WHERE NOT EXISTS (SELECT 1 FROM reservations r WHERE r.customer_id = c.customer_id)
 ORDER BY c.customer_id;

-- 2) Remove them
START TRANSACTION;
DELETE c FROM customers c
 WHERE NOT EXISTS (SELECT 1 FROM reservations r WHERE r.customer_id = c.customer_id);
COMMIT;

-- 3) Check. EXPECT: orphan_customers = 0
SELECT (SELECT COUNT(*) FROM customers c WHERE NOT EXISTS (SELECT 1 FROM reservations r WHERE r.customer_id = c.customer_id)) AS orphan_customers,
       (SELECT COUNT(*) FROM customers) AS customers_total;

-- Tip: to get the 100 demo customers back WITH bookings, import seed_100_customers.sql again
-- (it now gives every demo customer a past completed stay).
