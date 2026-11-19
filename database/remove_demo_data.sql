-- =====================================================================
-- Removes every row flagged is_demo = 1 (and rows that only belong to
-- demo reservations). Run before using the system with real customers.
-- Real (is_demo = 0) records are never touched. A demo customer who has
-- a real reservation is kept.
-- =====================================================================
USE `agies_resort_db`;
START TRANSACTION;

DELETE ph FROM payment_history ph JOIN payments p ON p.payment_id = ph.payment_id
  JOIN reservations r ON r.reservation_id = p.reservation_id WHERE r.is_demo = 1;
DELETE p FROM payments p JOIN reservations r ON r.reservation_id = p.reservation_id WHERE r.is_demo = 1;
DELETE q FROM rescheduling_requests q JOIN reservations r ON r.reservation_id = q.reservation_id WHERE r.is_demo = 1;
DELETE h FROM reservation_status_history h JOIN reservations r ON r.reservation_id = h.reservation_id WHERE r.is_demo = 1;
DELETE n FROM notifications n JOIN reservations r ON r.reservation_id = n.reservation_id WHERE r.is_demo = 1;
DELETE FROM reservations WHERE is_demo = 1;
DELETE n FROM notifications n JOIN inquiries i ON i.inquiry_id = n.inquiry_id WHERE i.is_demo = 1;
DELETE FROM inquiries WHERE is_demo = 1;
DELETE c FROM customers c
 WHERE c.is_demo = 1 AND NOT EXISTS (SELECT 1 FROM reservations r WHERE r.customer_id = c.customer_id);

COMMIT;
SELECT (SELECT COUNT(*) FROM customers WHERE is_demo = 1) AS demo_customers_left,
       (SELECT COUNT(*) FROM reservations WHERE is_demo = 1) AS demo_reservations_left;
