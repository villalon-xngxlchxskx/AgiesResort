-- Read-only. Run against the OLD database before migrate_existing_db.sql.
USE `agies_resort_db`;

-- A. Future slots that would hold two active bookings once PENDING also
--    locks the slot. EXPECT 0 rows; otherwise cancel/decline one first,
--    or the migration stops at "ADD UNIQUE KEY uq_slot_lock".
SELECT area_id, booking_date, booking_type, GROUP_CONCAT(reservation_code) AS codes
FROM reservations
WHERE status IN ('PENDING','PAYMENT PENDING','CONFIRMED','RESCHEDULED') AND booking_date >= CURDATE()
GROUP BY area_id, booking_date, booking_type HAVING COUNT(*) > 1;

-- B. More than one package per area + booking type. EXPECT 0 rows.
SELECT area_id, booking_type, COUNT(*) FROM packages GROUP BY area_id, booking_type HAVING COUNT(*) > 1;

-- C. Reservations without a package. EXPECT 0 rows (package_id becomes NOT NULL).
SELECT reservation_code FROM reservations WHERE package_id IS NULL;

-- D. Package down payment would exceed its rate. EXPECT 0 rows.
SELECT p.package_name, p.rate, a.down_payment FROM packages p JOIN resort_areas a ON a.area_id = p.area_id WHERE a.down_payment > p.rate;

-- E. Informational: what will be flagged needs_review.
SELECT r.reservation_code, r.num_guests, p.max_guests FROM reservations r JOIN packages p ON p.package_id = r.package_id WHERE r.num_guests > p.max_guests;
SELECT r.reservation_code, r.total_amount, SUM(y.amount_paid) AS verified
FROM reservations r JOIN payments y ON y.reservation_id = r.reservation_id AND y.payment_status = 'VERIFIED'
GROUP BY r.reservation_id HAVING verified > r.total_amount;

-- F. Informational: payment placeholder rows that will be removed (0 paid; backed up first).
SELECT payment_id, reservation_id, payment_status, amount_paid FROM payments WHERE amount_paid <= 0;
