<?php
declare(strict_types=1);

/**
 * `payments` rows are the source of truth. reservations.amount_paid and
 * reservations.payment_status are a cache, rewritten by recompute() inside the
 * same transaction as every payment change.
 *
 * Policy implemented:
 *  - A payment is money claimed by the customer (or recorded by staff); it counts
 *    only after staff VERIFY it. A reference number or screenshot alone never verifies.
 *  - The first payment(s) must cover the down payment; later payments go to the balance.
 *  - Total claimed (verified + pending) can never exceed the booking total.
 *  - When verified payments reach the down payment, a PENDING / PAYMENT PENDING
 *    reservation is CONFIRMED in the same transaction.
 *  - Down payments are non-refundable: cancelling does not reverse verified payments.
 */
final class PaymentService
{
    public const METHODS = ['GCash', 'Maya', 'Bank transfer', 'Cash', 'Other'];

    public static function sums(int $reservationId): array
    {
        $row = q_one("SELECT
                COALESCE(SUM(CASE WHEN payment_status = 'VERIFIED' THEN amount_paid END), 0) AS verified,
                COALESCE(SUM(CASE WHEN payment_status = 'PENDING VERIFICATION' THEN amount_paid END), 0) AS pending,
                SUM(payment_status = 'PENDING VERIFICATION') AS pending_count
              FROM payments WHERE reservation_id = ?", [$reservationId]);
        return ['verified' => (float) $row['verified'], 'pending' => (float) $row['pending'], 'pending_count' => (int) $row['pending_count']];
    }

    /** Re-syncs the cached columns. Call inside tx() after locking the reservation. */
    public static function recompute(int $reservationId): array
    {
        $r = q_one('SELECT total_amount FROM reservations WHERE reservation_id = ?', [$reservationId]);
        $s = self::sums($reservationId);
        if ($s['verified'] > (float) $r['total_amount'] + 0.004) {
            throw new UserError('Verified payments would exceed the booking total of ' . money($r['total_amount']) . '.');
        }
        $status = $s['pending_count'] > 0 ? 'PENDING VERIFICATION'
            : ($s['verified'] + 0.004 >= (float) $r['total_amount'] ? 'PAID' : ($s['verified'] > 0 ? 'PARTIAL' : 'UNPAID'));
        q('UPDATE reservations SET amount_paid = ?, payment_status = ? WHERE reservation_id = ?', [$s['verified'], $status, $reservationId]);
        return $s + ['payment_status' => $status];
    }

    private static function parseAmount(string $raw): float
    {
        $raw = str_replace([',', '₱', ' '], '', $raw);
        if (!preg_match('/^\d{1,7}(\.\d{1,2})?$/', $raw) || (float) $raw <= 0) {
            throw new UserError('Enter the amount as a number greater than zero, e.g. 1000 or 1000.50.');
        }
        return round((float) $raw, 2);
    }

    /**
     * Records a payment awaiting verification.
     * $by = CUSTOMER (from My Booking) or STAFF (recorded in admin).
     */
    public static function submit(int $reservationId, array $in, string $by, ?int $adminId = null, ?string $proofPath = null): int
    {
        $amount = self::parseAmount((string) ($in['amount'] ?? ''));
        $method = (string) ($in['payment_method'] ?? '');
        $ref = trim((string) ($in['reference_number'] ?? ''));
        $date = trim((string) ($in['payment_date'] ?? ''));
        if (!in_array($method, self::METHODS, true)) {
            throw new UserError('Choose a payment method.');
        }
        if ($method !== 'Cash' && !preg_match('/^[A-Za-z0-9\- ]{4,80}$/', $ref)) {
            throw new UserError('Enter the reference number from your payment receipt (4-80 letters, numbers or dashes).');
        }
        if ($ref !== '' && mb_strlen($ref) > 80) {
            throw new UserError('Reference number is too long.');
        }
        if (!valid_date($date) || $date > date('Y-m-d') || $date < date('Y-m-d', strtotime('-1 year'))) {
            throw new UserError('Enter the date you paid (not in the future).');
        }
        $notes = mb_substr(trim((string) ($in['notes'] ?? '')), 0, 255);

        return (int) tx(function () use ($reservationId, $amount, $method, $ref, $date, $notes, $by, $adminId, $proofPath) {
            $r = ReservationService::lock($reservationId);
            // Customers pay only on active bookings; staff may also record a balance settled at/after the stay.
            $allowed = $by === 'STAFF' ? array_merge(ReservationService::ACTIVE, ['COMPLETED']) : ReservationService::ACTIVE;
            if (!in_array($r['status'], $allowed, true)) {
                throw new UserError('Payments cannot be added to a reservation that is ' . strtolower($r['status']) . '.');
            }
            if ($r['status'] === 'PENDING' && $r['hold_expires_at'] && strtotime($r['hold_expires_at']) < time()) {
                throw new UserError('This request has expired. Please make a new reservation.');
            }
            q('SELECT payment_id FROM payments WHERE reservation_id = ? FOR UPDATE', [$reservationId]);
            $s = self::sums($reservationId);
            $claimed = $s['verified'] + $s['pending'];
            $remaining = round((float) $r['total_amount'] - $claimed, 2);
            if ($remaining <= 0) {
                throw new UserError('The full amount has already been paid or submitted for verification.');
            }
            if ($amount > $remaining + 0.004) {
                throw new UserError('The amount is more than the remaining balance of ' . money($remaining) . '.');
            }
            $dpShort = round((float) $r['down_payment'] - $claimed, 2);
            if ($dpShort > 0 && $amount + 0.004 < $dpShort) {
                throw new UserError('The first payment must cover the down payment of ' . money($dpShort) . '.');
            }
            if ($ref !== '') {
                $dup = q_val("SELECT COUNT(*) FROM payments WHERE reference_number = ? AND payment_method = ? AND payment_status <> 'REJECTED'", [$ref, $method]);
                if ((int) $dup > 0) {
                    throw new UserError('That reference number has already been submitted.');
                }
            }
            $type = $dpShort > 0 ? 'DOWN PAYMENT' : 'BALANCE';
            q('INSERT INTO payments (reservation_id, payment_type, amount_paid, payment_method, reference_number, proof_path, payment_status, payment_date, submitted_by, recorded_by, notes)
               VALUES (?,?,?,?,?,?,?,?,?,?,?)', [
                $reservationId, $type, $amount, $method, $ref ?: null, $proofPath, 'PENDING VERIFICATION', $date, $by, $by === 'STAFF' ? $adminId : null, $notes ?: null,
            ]);
            $pid = (int) db()->lastInsertId();
            q('INSERT INTO payment_history (payment_id, old_status, new_status, changed_by, remarks) VALUES (?,?,?,?,?)',
              [$pid, null, 'PENDING VERIFICATION', $adminId, $by === 'STAFF' ? 'Recorded by staff' : 'Submitted by customer']);
            if ($r['status'] === 'PENDING') {
                ReservationService::transition($reservationId, 'PAYMENT PENDING', $adminId, 'Payment submitted for verification');
            }
            self::recompute($reservationId);
            notify('payment', 'Payment awaiting verification', "{$r['reservation_code']} - " . money($amount) . " via $method", $reservationId, null, "admin/reservation.php?id=$reservationId");
            if ($adminId) {
                log_activity('Payments', 'Record', "Payment #$pid " . money($amount) . " for {$r['reservation_code']}");
            }
            return $pid;
        });
    }

    public static function verify(int $paymentId, int $adminId, string $notes = ''): void
    {
        tx(function () use ($paymentId, $adminId, $notes) {
            $p = q_one('SELECT * FROM payments WHERE payment_id = ? FOR UPDATE', [$paymentId]);
            if (!$p) {
                throw new UserError('Payment not found.');
            }
            if ($p['payment_status'] !== 'PENDING VERIFICATION') {
                throw new UserError('This payment was already ' . strtolower($p['payment_status']) . '.');
            }
            $r = ReservationService::lock((int) $p['reservation_id']);
            $s = self::sums((int) $p['reservation_id']);
            if ($s['verified'] + (float) $p['amount_paid'] > (float) $r['total_amount'] + 0.004) {
                throw new UserError('Verifying this would exceed the booking total. Reject it or record the correct amount.');
            }
            q("UPDATE payments SET payment_status = 'VERIFIED', verified_by = ?, verified_at = NOW(), notes = COALESCE(NULLIF(?, ''), notes) WHERE payment_id = ?",
              [$adminId, mb_substr(trim($notes), 0, 255), $paymentId]);
            q('INSERT INTO payment_history (payment_id, old_status, new_status, changed_by, remarks) VALUES (?,?,?,?,?)',
              [$paymentId, 'PENDING VERIFICATION', 'VERIFIED', $adminId, $notes ?: 'Verified']);
            $after = self::recompute((int) $p['reservation_id']);
            log_activity('Payments', 'Verify', "Payment #$paymentId " . money($p['amount_paid']) . " for {$r['reservation_code']} verified");
            if (in_array($r['status'], ['PENDING', 'PAYMENT PENDING'], true) && $after['verified'] + 0.004 >= (float) $r['down_payment']) {
                ReservationService::transition((int) $r['reservation_id'], 'CONFIRMED', $adminId, 'Down payment verified; reservation confirmed');
            }
        });
    }

    public static function reject(int $paymentId, int $adminId, string $reason): void
    {
        $reason = trim($reason);
        if (!str_len_ok($reason, 3, 255)) {
            throw new UserError('Enter a reason for rejecting the payment (3-255 characters).');
        }
        tx(function () use ($paymentId, $adminId, $reason) {
            $p = q_one('SELECT * FROM payments WHERE payment_id = ? FOR UPDATE', [$paymentId]);
            if (!$p) {
                throw new UserError('Payment not found.');
            }
            if ($p['payment_status'] !== 'PENDING VERIFICATION') {
                throw new UserError('This payment was already ' . strtolower($p['payment_status']) . '.');
            }
            $r = ReservationService::lock((int) $p['reservation_id']);
            q("UPDATE payments SET payment_status = 'REJECTED', rejection_reason = ?, verified_by = ?, verified_at = NOW() WHERE payment_id = ?",
              [$reason, $adminId, $paymentId]);
            q('INSERT INTO payment_history (payment_id, old_status, new_status, changed_by, remarks) VALUES (?,?,?,?,?)',
              [$paymentId, 'PENDING VERIFICATION', 'REJECTED', $adminId, $reason]);
            $after = self::recompute((int) $p['reservation_id']);
            log_activity('Payments', 'Reject', "Payment #$paymentId for {$r['reservation_code']} rejected: $reason");
            if ($r['status'] === 'PAYMENT PENDING' && $after['pending_count'] === 0 && $after['verified'] + 0.004 < (float) $r['down_payment']) {
                ReservationService::transition((int) $r['reservation_id'], 'PENDING', $adminId, 'Payment rejected: ' . $reason);
            }
        });
    }

    /**
     * Super Admin correction tool for a payment verified by mistake (e.g. 10000 typed for 1000).
     * The row is kept (status REJECTED, reason prefixed "Reversed:") with full history.
     * A confirmed booking is NOT un-confirmed automatically; it is flagged for review instead.
     */
    public static function reverse(int $paymentId, int $adminId, string $reason): void
    {
        $reason = trim($reason);
        if (!str_len_ok($reason, 3, 200)) {
            throw new UserError('Enter why the payment is being reversed (3-200 characters).');
        }
        tx(function () use ($paymentId, $adminId, $reason) {
            $p = q_one('SELECT * FROM payments WHERE payment_id = ? FOR UPDATE', [$paymentId]);
            if (!$p || $p['payment_status'] !== 'VERIFIED') {
                throw new UserError('Only verified payments can be reversed.');
            }
            $r = ReservationService::lock((int) $p['reservation_id']);
            q("UPDATE payments SET payment_status = 'REJECTED', rejection_reason = ? WHERE payment_id = ?", ['Reversed: ' . $reason, $paymentId]);
            q('INSERT INTO payment_history (payment_id, old_status, new_status, changed_by, remarks) VALUES (?,?,?,?,?)',
              [$paymentId, 'VERIFIED', 'REJECTED', $adminId, 'Reversed: ' . $reason]);
            $after = self::recompute((int) $p['reservation_id']);
            if (in_array($r['status'], ['CONFIRMED', 'COMPLETED'], true) && $after['verified'] + 0.004 < (float) $r['down_payment']) {
                q('UPDATE reservations SET needs_review = 1, review_note = ? WHERE reservation_id = ?',
                  ['Payment #' . $paymentId . ' reversed; verified total is now below the down payment.', $r['reservation_id']]);
            }
            log_activity('Payments', 'Reverse', "Payment #$paymentId (" . money($p['amount_paid']) . ") on {$r['reservation_code']} reversed: $reason");
        });
    }

    public static function forReservation(int $reservationId): array
    {
        return q_all('SELECT p.*, v.full_name AS verified_by_name, rb.full_name AS recorded_by_name FROM payments p
                   LEFT JOIN admins v ON v.admin_id = p.verified_by LEFT JOIN admins rb ON rb.admin_id = p.recorded_by
                   WHERE p.reservation_id = ? ORDER BY p.created_at, p.payment_id', [$reservationId]);
    }
}
