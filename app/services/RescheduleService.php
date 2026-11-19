<?php
declare(strict_types=1);

/**
 * Policy (from the proposal / existing settings):
 *  - Only CONFIRMED reservations (down payment verified) can be rescheduled.
 *  - At most `reschedule_max_count` approved reschedules (default 1).
 *  - The REQUEST must be made at least `reschedule_min_days` (default 5) days before
 *    the currently scheduled booking date. Management may decide later.
 *  - Same area and same session type; only the date changes.
 *  - The new slot must be free when requested AND again when approved.
 *  - The reservation date changes only on approval; the old date is kept in the request row
 *    and in the status history.
 */
final class RescheduleService
{
    /** @return string|null reason it is NOT allowed, or null if eligible */
    public static function ineligibility(array $r): ?string
    {
        if ($r['status'] !== 'CONFIRMED') {
            return 'Only confirmed reservations (down payment verified) can be rescheduled.';
        }
        $max = setting_int('reschedule_max_count', 1);
        if ((int) $r['reschedule_count'] >= $max) {
            return "This reservation has already been rescheduled the maximum number of times ($max).";
        }
        $min = setting_int('reschedule_min_days', 5);
        if (days_until($r['booking_date']) < $min) {
            return "Rescheduling requests must be made at least $min days before the booking date.";
        }
        $pending = q_val("SELECT COUNT(*) FROM rescheduling_requests WHERE reservation_id = ? AND approval_status = 'PENDING'", [$r['reservation_id']]);
        if ((int) $pending > 0) {
            return 'A rescheduling request for this reservation is already waiting for a decision.';
        }
        return null;
    }

    public static function request(int $reservationId, string $newDate, string $reason, string $via, ?int $adminId = null): int
    {
        $reason = trim($reason);
        if (!valid_date($newDate)) {
            throw new UserError('Choose a valid new date.');
        }
        if (!str_len_ok($reason, 3, 255)) {
            throw new UserError('Tell us briefly why you need to reschedule (3-255 characters).');
        }
        [$min, $max] = ReservationService::window($via === 'STAFF');
        if ($newDate < $min || $newDate > $max) {
            throw new UserError('Choose a new date between ' . fmt_date($min) . ' and ' . fmt_date($max) . '.');
        }
        return (int) tx(function () use ($reservationId, $newDate, $reason, $via, $adminId) {
            $r = ReservationService::lock($reservationId);
            if ($why = self::ineligibility($r)) {
                throw new UserError($why);
            }
            if ($newDate === $r['booking_date']) {
                throw new UserError('The new date is the same as the current booking date.');
            }
            [$s, $e] = ReservationService::interval($newDate, $r['start_time'], $r['end_time']);
            if (ReservationService::conflicts((int) $r['area_id'], $s, $e, (int) $r['reservation_id'])) {
                throw new UserError('That date is not available for the same area and session. Please choose another date.');
            }
            try {
                q('INSERT INTO rescheduling_requests (reservation_id, original_date, requested_date, booking_type, reason, request_date, requested_via)
                   VALUES (?,?,?,?,?,CURDATE(),?)', [$reservationId, $r['booking_date'], $newDate, $r['booking_type'], $reason, $via]);
            } catch (PDOException $ex) {
                if (is_duplicate_key($ex, 'uq_rs_pending')) {
                    throw new UserError('A rescheduling request for this reservation is already waiting for a decision.');
                }
                throw $ex;
            }
            $id = (int) db()->lastInsertId();
            notify('reschedule', 'Reschedule request', "{$r['reservation_code']}: " . fmt_date($r['booking_date']) . ' -> ' . fmt_date($newDate), $reservationId, null, 'admin/reschedules.php');
            if ($adminId) {
                log_activity('Rescheduling', 'Request', "Request #$id for {$r['reservation_code']} to $newDate");
            }
            return $id;
        });
    }

    public static function approve(int $requestId, int $adminId, string $notes = ''): void
    {
        tx(function () use ($requestId, $adminId, $notes) {
            $q = q_one('SELECT * FROM rescheduling_requests WHERE request_id = ? FOR UPDATE', [$requestId]);
            if (!$q || $q['approval_status'] !== 'PENDING') {
                throw new UserError('This request has already been decided.');
            }
            $r = ReservationService::lock((int) $q['reservation_id']);
            if ($r['status'] !== 'CONFIRMED') {
                throw new UserError('The reservation is no longer confirmed (' . strtolower($r['status']) . '). Reject this request instead.');
            }
            if ((int) $r['reschedule_count'] >= setting_int('reschedule_max_count', 1)) {
                throw new UserError('The reservation has already used its reschedule allowance.');
            }
            [$s, $e] = ReservationService::interval($q['requested_date'], $r['start_time'], $r['end_time']);
            if (strtotime($s) <= time()) {
                throw new UserError('The requested date has already passed. Reject this request.');
            }
            if (ReservationService::conflicts((int) $r['area_id'], $s, $e, (int) $r['reservation_id'], true)) {
                throw new UserError('The requested date has since been booked. Reject this request or ask the guest for another date.');
            }
            try {
                q('UPDATE reservations SET booking_date = ?, start_at = ?, end_at = ?, reschedule_count = reschedule_count + 1 WHERE reservation_id = ?',
                  [$q['requested_date'], $s, $e, $r['reservation_id']]);
            } catch (PDOException $ex) {
                if (is_duplicate_key($ex, 'uq_slot_lock')) {
                    throw new UserError('The requested date was just booked by someone else.');
                }
                throw $ex;
            }
            q("UPDATE rescheduling_requests SET approval_status = 'APPROVED', approved_by = ?, approval_date = NOW(), notes = ? WHERE request_id = ?",
              [$adminId, mb_substr(trim($notes), 0, 255) ?: null, $requestId]);
            q('INSERT INTO reservation_status_history (reservation_id, old_status, new_status, changed_by, remarks) VALUES (?,?,?,?,?)', [
                $r['reservation_id'], 'CONFIRMED', 'CONFIRMED', $adminId,
                'Rescheduled from ' . $r['booking_date'] . ' to ' . $q['requested_date'] . " (request #$requestId)",
            ]);
            log_activity('Rescheduling', 'Approve', "{$r['reservation_code']} moved from {$r['booking_date']} to {$q['requested_date']}");
        });
    }

    public static function reject(int $requestId, int $adminId, string $notes): void
    {
        $notes = trim($notes);
        if (!str_len_ok($notes, 3, 255)) {
            throw new UserError('Enter the reason for rejecting (3-255 characters).');
        }
        tx(function () use ($requestId, $adminId, $notes) {
            $q = q_one('SELECT q.*, r.reservation_code FROM rescheduling_requests q JOIN reservations r ON r.reservation_id = q.reservation_id WHERE q.request_id = ? FOR UPDATE', [$requestId]);
            if (!$q || $q['approval_status'] !== 'PENDING') {
                throw new UserError('This request has already been decided.');
            }
            q("UPDATE rescheduling_requests SET approval_status = 'REJECTED', approved_by = ?, approval_date = NOW(), notes = ? WHERE request_id = ?",
              [$adminId, $notes, $requestId]);
            log_activity('Rescheduling', 'Reject', "Request #$requestId for {$q['reservation_code']} rejected: $notes");
        });
    }

    public static function forReservation(int $reservationId): array
    {
        return q_all('SELECT q.*, a.full_name AS decided_by FROM rescheduling_requests q LEFT JOIN admins a ON a.admin_id = q.approved_by
                      WHERE q.reservation_id = ? ORDER BY q.created_at DESC', [$reservationId]);
    }
}
