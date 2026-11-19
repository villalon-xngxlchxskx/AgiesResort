-- =====================================================================
-- Agie's Resort - database verification queries
-- Run in phpMyAdmin (SQL tab) after importing. Each query states the
-- expected result. Anything other than the expected result needs review.
-- =====================================================================
USE `agies_resort_db`;

-- 1. Seeded fictional customers. EXPECT: >= 100
SELECT COUNT(*) AS demo_customers FROM customers WHERE is_demo = 1;

-- 1b. Business rule: every customer is linked to at least one reservation. EXPECT: 0
--     (if not 0, run database/enforce_customer_booking_link.sql)
SELECT COUNT(*) AS orphan_customers FROM customers c WHERE NOT EXISTS (SELECT 1 FROM reservations r WHERE r.customer_id = c.customer_id);

-- 2. Required tables exist. EXPECT: 17 rows
SELECT TABLE_NAME FROM information_schema.TABLES
WHERE TABLE_SCHEMA = 'agies_resort_db' AND TABLE_NAME IN
('admins','login_attempts','customers','resort_areas','packages','package_inclusions','reservations',
 'reservation_status_history','payments','payment_history','rescheduling_requests','inquiries',
 'notifications','activity_logs','gallery','system_settings','website_content');

-- 3. All tables InnoDB + utf8mb4. EXPECT: 0 rows
SELECT TABLE_NAME, ENGINE, TABLE_COLLATION FROM information_schema.TABLES
WHERE TABLE_SCHEMA = 'agies_resort_db' AND (ENGINE <> 'InnoDB' OR TABLE_COLLATION NOT LIKE 'utf8mb4%');

-- 4. Key unique indexes exist. EXPECT: 4 rows
SELECT TABLE_NAME, INDEX_NAME FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = 'agies_resort_db' AND INDEX_NAME IN ('uq_slot_lock','uq_reservation_code','uq_customer_email','uq_rs_pending')
GROUP BY TABLE_NAME, INDEX_NAME;

-- 5. Orphans: reservations pointing to missing customer/area/package. EXPECT: 0
SELECT COUNT(*) AS orphan_reservations FROM reservations r
LEFT JOIN customers c ON c.customer_id = r.customer_id
LEFT JOIN resort_areas a ON a.area_id = r.area_id
LEFT JOIN packages p ON p.package_id = r.package_id
WHERE c.customer_id IS NULL OR a.area_id IS NULL OR p.package_id IS NULL OR p.area_id <> r.area_id;

-- 6. Orphan payments / history / reschedules. EXPECT: 0, 0, 0
SELECT (SELECT COUNT(*) FROM payments p LEFT JOIN reservations r ON r.reservation_id = p.reservation_id WHERE r.reservation_id IS NULL) AS orphan_payments,
       (SELECT COUNT(*) FROM reservation_status_history h LEFT JOIN reservations r ON r.reservation_id = h.reservation_id WHERE r.reservation_id IS NULL) AS orphan_history,
       (SELECT COUNT(*) FROM rescheduling_requests q LEFT JOIN reservations r ON r.reservation_id = q.reservation_id WHERE r.reservation_id IS NULL) AS orphan_reschedules;

-- 7. Overlapping ACTIVE reservations for the same area. EXPECT: 0 rows
SELECT a.reservation_code, b.reservation_code, a.area_id, a.start_at, a.end_at
FROM reservations a JOIN reservations b
  ON a.area_id = b.area_id AND a.reservation_id < b.reservation_id
 AND a.start_at < b.end_at AND b.start_at < a.end_at
WHERE a.status IN ('PENDING','PAYMENT PENDING','CONFIRMED')
  AND b.status IN ('PENDING','PAYMENT PENDING','CONFIRMED');

-- 8. Cached amount_paid differs from SUM of verified payments. EXPECT: 0 rows
SELECT r.reservation_code, r.amount_paid, COALESCE(SUM(p.amount_paid),0) AS verified_sum
FROM reservations r LEFT JOIN payments p ON p.reservation_id = r.reservation_id AND p.payment_status = 'VERIFIED'
GROUP BY r.reservation_id HAVING ABS(r.amount_paid - verified_sum) > 0.004 AND r.needs_review = 0;

-- 9. Cached payment_status inconsistent with payments. EXPECT: 0 rows
SELECT r.reservation_code, r.payment_status, x.pending, r.amount_paid, r.total_amount FROM reservations r
JOIN (SELECT r2.reservation_id, SUM(p.payment_status = 'PENDING VERIFICATION') AS pending
      FROM reservations r2 LEFT JOIN payments p ON p.reservation_id = r2.reservation_id GROUP BY r2.reservation_id) x
  ON x.reservation_id = r.reservation_id
WHERE r.payment_status <> CASE
   WHEN COALESCE(x.pending,0) > 0 THEN 'PENDING VERIFICATION'
   WHEN r.amount_paid >= r.total_amount AND r.total_amount > 0 THEN 'PAID'
   WHEN r.amount_paid > 0 THEN 'PARTIAL'
   ELSE 'UNPAID' END;

-- 10. Verified payments exceeding the booking total (overpayment). EXPECT: 0 rows (or needs_review = 1)
SELECT r.reservation_code, r.total_amount, SUM(p.amount_paid) AS verified, r.needs_review
FROM reservations r JOIN payments p ON p.reservation_id = r.reservation_id AND p.payment_status = 'VERIFIED'
GROUP BY r.reservation_id HAVING verified > r.total_amount;

-- 11. CONFIRMED without verified down payment. EXPECT: 0 rows
SELECT reservation_code, amount_paid, down_payment FROM reservations
WHERE status IN ('CONFIRMED','COMPLETED') AND amount_paid < down_payment AND needs_review = 0;

-- 12. Guest count above package capacity + allowed extras. EXPECT: 0 rows (or needs_review = 1)
SELECT r.reservation_code, r.num_guests, p.max_guests, p.max_extra_guests, r.needs_review
FROM reservations r JOIN packages p ON p.package_id = r.package_id
WHERE r.num_guests > p.max_guests + p.max_extra_guests;

-- 13. Price snapshot arithmetic. EXPECT: 0 rows
SELECT reservation_code, base_rate, additional_fee, total_amount FROM reservations
WHERE ABS(base_rate + additional_fee - total_amount) > 0.004;

-- 14. Reschedule count matches approved requests. EXPECT: 0 rows
SELECT r.reservation_code, r.reschedule_count, COUNT(q.request_id) AS approved
FROM reservations r LEFT JOIN rescheduling_requests q ON q.reservation_id = r.reservation_id AND q.approval_status = 'APPROVED'
GROUP BY r.reservation_id HAVING r.reschedule_count <> approved;

-- 15. Staff accounts with a usable password (bcrypt/argon). Informational.
SELECT username, role, status, must_change_password,
       (password_hash LIKE '$2y$%' OR password_hash LIKE '$argon2%') AS has_modern_hash
FROM admins;

-- 16. Rows flagged for manual review (from migration). Informational.
SELECT reservation_code, status, review_note FROM reservations WHERE needs_review = 1;
