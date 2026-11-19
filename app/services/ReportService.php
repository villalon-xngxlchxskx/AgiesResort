<?php
declare(strict_types=1);

/**
 * Every report returns:
 *   title, description (what is counted and by which date), tables => [[title, columns, rows]], summary => [[label, value]]
 * CSV export uses the first table.
 */
final class ReportService
{
    public const REPORTS = [
        'occupancy'     => ['Occupancy (daily slot utilization)', 'reports.view', 'today'],
        'cancellations' => ['Cancellation report', 'reports.view', 'today'],
        'reschedules'   => ['Rescheduled booking report', 'reports.view', 'last30'],
        'reservations'  => ['Reservation summary', 'reports.view', 'today'],
        'upcoming'      => ['Upcoming bookings', 'reports.view', 'next30'],
        'payments'      => ['Payment verification & totals', 'reports.view', 'month'],
        'revenue'       => ['Revenue & collections', 'reports.financial', 'month'],
        'customers'     => ['Customer list & activity', 'reports.view', 'all'],
    ];

    public static function defaultRange(string $kind): array
    {
        return match ($kind) {
            'today'  => [date('Y-m-d'), date('Y-m-d')],
            'last30' => [date('Y-m-d', strtotime('-30 days')), date('Y-m-d')],
            'next30' => [date('Y-m-d'), date('Y-m-d', strtotime('+30 days'))],
            'month'  => [date('Y-m-01'), date('Y-m-t')],
            default  => ['2000-01-01', date('Y-m-d', strtotime('+2 years'))],
        };
    }

    private static function demo(array $f, string $alias = 'r'): string
    {
        return !empty($f['exclude_demo']) ? " AND $alias.is_demo = 0" : '';
    }

    private static function areaType(array $f, array &$p, string $alias = 'r'): string
    {
        $sql = '';
        if (!empty($f['area_id'])) {
            $sql .= " AND $alias.area_id = ?";
            $p[] = (int) $f['area_id'];
        }
        if (in_array($f['type'] ?? '', ['DAYTIME', 'OVERNIGHT'], true)) {
            $sql .= " AND $alias.booking_type = ?";
            $p[] = $f['type'];
        }
        return $sql;
    }

    public static function run(string $key, array $f): array
    {
        return match ($key) {
            'occupancy' => self::occupancy($f),
            'cancellations' => self::cancellations($f),
            'reschedules' => self::reschedules($f),
            'reservations' => self::reservations($f),
            'upcoming' => self::upcoming($f),
            'payments' => self::payments($f),
            'revenue' => self::revenue($f),
            'customers' => self::customers($f),
        };
    }

    // ------------------------------------------------------------------
    public static function occupancy(array $f): array
    {
        $areas = q_all("SELECT area_id, area_name FROM resort_areas WHERE status = 'ACTIVE'" . (!empty($f['area_id']) ? ' AND area_id = ' . (int) $f['area_id'] : '') . ' ORDER BY sort_order, area_id');
        $types = in_array($f['type'] ?? '', ['DAYTIME', 'OVERNIGHT'], true) ? [$f['type']] : ['DAYTIME', 'OVERNIGHT'];
        $p = [$f['from'], $f['to']];
        $res = q_all("SELECT r.area_id, r.booking_date, r.booking_type, r.status, r.reservation_code, r.num_guests, r.guest_name
                        FROM reservations r WHERE r.booking_date BETWEEN ? AND ?
                         AND r.status IN ('PENDING','PAYMENT PENDING','CONFIRMED','COMPLETED')" . self::demo($f), $p);
        $idx = [];
        foreach ($res as $r) {
            $idx[$r['booking_date']][$r['area_id']][$r['booking_type']] = $r;
        }
        $detail = [];
        $byDay = [];
        $byArea = [];
        $tot = ['slots' => 0, 'booked' => 0, 'held' => 0];
        for ($d = $f['from']; $d <= $f['to']; $d = date('Y-m-d', strtotime("$d +1 day"))) {
            $day = ['slots' => 0, 'booked' => 0, 'held' => 0, 'guests' => 0];
            foreach ($areas as $a) {
                foreach ($types as $t) {
                    $r = $idx[$d][$a['area_id']][$t] ?? null;
                    $state = !$r ? 'Available' : (in_array($r['status'], ['CONFIRMED', 'COMPLETED'], true) ? 'Booked' : 'On hold (unconfirmed)');
                    $day['slots']++;
                    $byArea[$a['area_name']][$t]['slots'] = ($byArea[$a['area_name']][$t]['slots'] ?? 0) + 1;
                    if ($state === 'Booked') {
                        $day['booked']++;
                        $day['guests'] += (int) $r['num_guests'];
                        $byArea[$a['area_name']][$t]['booked'] = ($byArea[$a['area_name']][$t]['booked'] ?? 0) + 1;
                    } elseif ($r) {
                        $day['held']++;
                    }
                    $detail[] = [fmt_date($d, 'D, M j, Y'), $a['area_name'], type_short($t), $state, $r['reservation_code'] ?? '', $r['guest_name'] ?? '', $r ? (int) $r['num_guests'] : ''];
                }
            }
            $byDay[] = [fmt_date($d, 'D, M j, Y'), $day['slots'], $day['booked'], $day['held'], $day['slots'] - $day['booked'] - $day['held'], $day['guests'], self::pct($day['booked'], $day['slots'])];
            foreach (['slots', 'booked', 'held'] as $k) {
                $tot[$k] += $day[$k];
            }
        }
        $areaRows = [];
        foreach ($byArea as $name => $ts) {
            foreach ($ts as $t => $v) {
                $areaRows[] = [$name, type_short($t), $v['slots'], $v['booked'] ?? 0, self::pct($v['booked'] ?? 0, $v['slots'])];
            }
        }
        return [
            'title' => 'Occupancy report',
            'description' => 'Agie\'s Resort rents whole areas, not rooms, so occupancy is measured in bookable slots: each active area has one Daytime (8 AM–5 PM) and one Overnight (6 PM–7 AM) slot per day. '
                . 'A slot is Booked when a CONFIRMED or COMPLETED reservation holds it, and On hold when an unpaid or unverified request holds it. Utilization = booked slots ÷ total slots. Dates are booking (stay) dates. Default period: today.',
            'tables' => [
                ['By day', ['Date', 'Total slots', 'Booked', 'On hold', 'Available', 'Booked guests', 'Utilization'], $byDay],
                ['By area and session', ['Area', 'Session', 'Slots', 'Booked', 'Utilization'], $areaRows],
                ['Slot detail', ['Date', 'Area', 'Session', 'Status', 'Reservation', 'Guest', 'Guests'], $detail],
            ],
            'summary' => [['Total slots', $tot['slots']], ['Booked', $tot['booked']], ['On hold', $tot['held']], ['Utilization', self::pct($tot['booked'], $tot['slots'])]],
        ];
    }

    // ------------------------------------------------------------------
    public static function cancellations(array $f): array
    {
        $statuses = !empty($f['include_expired']) ? "'CANCELLED','DECLINED','EXPIRED'" : "'CANCELLED','DECLINED'";
        $p = [$f['from'], $f['to']];
        $where = "r.status IN ($statuses) AND DATE(r.cancelled_at) BETWEEN ? AND ?" . self::areaType($f, $p) . self::demo($f);
        $rows = q_all("SELECT r.reservation_code, c.full_name, a.area_name, r.booking_type, r.booking_date, r.cancelled_at, r.status, r.cancel_reason,
                              ad.full_name AS by_name, r.payment_status, r.amount_paid, r.total_amount, DATE(r.cancelled_at) AS cday
                         FROM reservations r JOIN customers c ON c.customer_id = r.customer_id JOIN resort_areas a ON a.area_id = r.area_id
                    LEFT JOIN admins ad ON ad.admin_id = r.cancelled_by
                        WHERE $where ORDER BY r.cancelled_at", $p);
        $byDay = $byArea = $byType = [];
        $kept = 0.0;
        foreach ($rows as $r) {
            $byDay[$r['cday']] = ($byDay[$r['cday']] ?? 0) + 1;
            $byArea[$r['area_name']] = ($byArea[$r['area_name']] ?? 0) + 1;
            $byType[type_short($r['booking_type'])] = ($byType[type_short($r['booking_type'])] ?? 0) + 1;
            $kept += (float) $r['amount_paid'];
        }
        $detail = array_map(fn($r) => [$r['reservation_code'], $r['full_name'], $r['area_name'], type_short($r['booking_type']), fmt_date($r['booking_date']), fmt_dt($r['cancelled_at']), ucfirst(strtolower($r['status'])), $r['cancel_reason'], $r['by_name'] ?: ($r['status'] === 'EXPIRED' ? 'System' : ''), $r['payment_status'], money($r['amount_paid']), money($r['total_amount'])], $rows);
        return [
            'title' => 'Cancellation report',
            'description' => 'Counts reservations by the date they were cancelled or declined (cancellation date), not by their original stay date, which is shown in its own column. '
                . 'Expired unpaid holds are excluded unless “Include expired holds” is ticked. Verified down payments on cancelled bookings are non-refundable and are listed as retained. Default period: today.',
            'tables' => [
                ['Cancelled reservations', ['Code', 'Customer', 'Area', 'Session', 'Original booking date', 'Cancelled on', 'Status', 'Reason', 'Cancelled by', 'Payment status', 'Verified paid (retained)', 'Booking total'], $detail],
                ['Count by cancellation day', ['Day', 'Cancellations'], array_map(fn($k, $v) => [fmt_date($k), $v], array_keys($byDay), $byDay)],
                ['Count by area', ['Area', 'Cancellations'], array_map(null, array_keys($byArea), array_values($byArea))],
                ['Count by session type', ['Session', 'Cancellations'], array_map(null, array_keys($byType), array_values($byType))],
            ],
            'summary' => [['Cancellations', count($rows)], ['Verified payments retained', money($kept)]],
        ];
    }

    // ------------------------------------------------------------------
    public static function reschedules(array $f): array
    {
        $p = [$f['from'], $f['to']];
        $where = 'q.request_date BETWEEN ? AND ?' . self::areaType($f, $p) . self::demo($f);
        if (in_array($f['status'] ?? '', ['PENDING', 'APPROVED', 'REJECTED'], true)) {
            $where .= ' AND q.approval_status = ?';
            $p[] = $f['status'];
        }
        $rows = q_all("SELECT r.reservation_code, c.full_name, a.area_name, q.booking_type, q.original_date, q.requested_date, q.request_date,
                              q.approval_status, q.approval_date, ad.full_name AS decided_by, q.reason, q.notes, r.reschedule_count, q.requested_via
                         FROM rescheduling_requests q JOIN reservations r ON r.reservation_id = q.reservation_id
                         JOIN customers c ON c.customer_id = r.customer_id JOIN resort_areas a ON a.area_id = r.area_id
                    LEFT JOIN admins ad ON ad.admin_id = q.approved_by
                        WHERE $where ORDER BY q.request_date, q.request_id", $p);
        $counts = ['PENDING' => 0, 'APPROVED' => 0, 'REJECTED' => 0];
        foreach ($rows as $r) {
            $counts[$r['approval_status']]++;
        }
        $detail = array_map(fn($r) => [
            $r['reservation_code'], $r['full_name'], $r['area_name'], type_short($r['booking_type']),
            fmt_date($r['original_date']), fmt_date($r['requested_date']),
            $r['approval_status'] === 'APPROVED' ? fmt_date($r['requested_date']) : '—',
            fmt_date($r['request_date']), ucfirst(strtolower($r['approval_status'])), fmt_dt($r['approval_date']),
            $r['decided_by'] ?: '—', $r['reason'], $r['notes'], (int) $r['reschedule_count'], ucfirst(strtolower($r['requested_via'])),
        ], $rows);
        return [
            'title' => 'Rescheduled booking report',
            'description' => 'Lists every rescheduling request submitted in the period (by request date), with the booking\'s original date, the date asked for, and the decision. '
                . 'Only APPROVED requests are completed reschedules: for those, “New approved date” is the reservation\'s new booking date. Pending and rejected requests did not move the booking. Default period: last 30 days.',
            'tables' => [['Rescheduling requests', ['Code', 'Customer', 'Area', 'Session', 'Original booking date', 'Requested date', 'New approved date', 'Request submitted', 'Decision', 'Decision date', 'Decided by', 'Reason for request', 'Decision remarks', 'Reschedule count', 'Requested via'], $detail]],
            'summary' => [['Requests', count($rows)], ['Approved (rescheduled)', $counts['APPROVED']], ['Rejected', $counts['REJECTED']], ['Awaiting decision', $counts['PENDING']]],
        ];
    }

    // ------------------------------------------------------------------
    public static function reservations(array $f, bool $upcoming = false): array
    {
        $p = [$f['from'], $f['to']];
        $where = 'r.booking_date BETWEEN ? AND ?' . self::areaType($f, $p) . self::demo($f);
        if ($upcoming) {
            $where .= " AND r.status IN ('PENDING','PAYMENT PENDING','CONFIRMED')";
        } elseif (in_array($f['status'] ?? '', ReservationService::ALL, true)) {
            $where .= ' AND r.status = ?';
            $p[] = $f['status'];
        }
        $rows = q_all("SELECT r.reservation_code, r.booking_date, a.area_name, r.booking_type, r.guest_name, r.guest_contact, r.num_guests,
                              r.status, r.payment_status, r.total_amount, r.amount_paid
                         FROM reservations r JOIN resort_areas a ON a.area_id = r.area_id
                        WHERE $where ORDER BY r.booking_date, a.sort_order, r.booking_type", $p);
        $byStatus = $byArea = [];
        $value = 0.0;
        foreach ($rows as $r) {
            $byStatus[$r['status']] = ($byStatus[$r['status']] ?? 0) + 1;
            $byArea[$r['area_name']] = ($byArea[$r['area_name']] ?? 0) + 1;
            if (in_array($r['status'], ['CONFIRMED', 'COMPLETED'], true)) {
                $value += (float) $r['total_amount'];
            }
        }
        $detail = array_map(fn($r) => [$r['reservation_code'], fmt_date($r['booking_date']), $r['area_name'], type_short($r['booking_type']), $r['guest_name'], $r['guest_contact'], (int) $r['num_guests'], $r['status'], $r['payment_status'], money($r['total_amount']), money($r['amount_paid']), money((float) $r['total_amount'] - (float) $r['amount_paid'])], $rows);
        return [
            'title' => $upcoming ? 'Upcoming bookings' : 'Reservation summary',
            'description' => $upcoming
                ? 'Active reservations (pending, payment submitted, confirmed) whose stay date falls in the period. Default: next 30 days.'
                : 'Reservations by stay (booking) date. Filter by status for the pending or confirmed reservation lists, or set a whole month for the monthly report. Default period: today (daily reservation report).',
            'tables' => [
                ['Reservations', ['Code', 'Booking date', 'Area', 'Session', 'Guest', 'Contact', 'Guests', 'Status', 'Payment', 'Total', 'Verified paid', 'Balance'], $detail],
                ['By status', ['Status', 'Count'], array_map(null, array_keys($byStatus), array_values($byStatus))],
                ['By area', ['Area', 'Count'], array_map(null, array_keys($byArea), array_values($byArea))],
            ],
            'summary' => [['Reservations', count($rows)], ['Confirmed/completed booking value', money($value)]],
        ];
    }

    public static function upcoming(array $f): array
    {
        return self::reservations($f, true);
    }

    // ------------------------------------------------------------------
    public static function payments(array $f): array
    {
        $p = [$f['from'], $f['to']];
        $where = 'COALESCE(y.payment_date, DATE(y.created_at)) BETWEEN ? AND ?' . self::demo($f, 'y');
        if (in_array($f['status'] ?? '', ['PENDING VERIFICATION', 'VERIFIED', 'REJECTED'], true)) {
            $where .= ' AND y.payment_status = ?';
            $p[] = $f['status'];
        }
        $rows = q_all("SELECT y.payment_id, r.reservation_code, r.guest_name, y.payment_type, y.payment_method, y.reference_number, y.amount_paid,
                              y.payment_status, y.payment_date, ad.full_name AS decided_by, y.verified_at, y.rejection_reason
                         FROM payments y JOIN reservations r ON r.reservation_id = y.reservation_id
                    LEFT JOIN admins ad ON ad.admin_id = y.verified_by
                        WHERE $where ORDER BY y.payment_date, y.payment_id", $p);
        $t = ['PENDING VERIFICATION' => 0.0, 'VERIFIED' => 0.0, 'REJECTED' => 0.0];
        foreach ($rows as $r) {
            $t[$r['payment_status']] += (float) $r['amount_paid'];
        }
        $detail = array_map(fn($r) => ['#' . $r['payment_id'], $r['reservation_code'], $r['guest_name'], $r['payment_type'], $r['payment_method'], $r['reference_number'], money($r['amount_paid']), $r['payment_status'], fmt_date($r['payment_date']), $r['decided_by'] ?: '—', fmt_dt($r['verified_at']), $r['rejection_reason']], $rows);
        return [
            'title' => 'Payment verification & totals',
            'description' => 'Payments by the date the customer says they paid. Only VERIFIED payments are money confirmed received; PENDING VERIFICATION amounts are claims not yet checked and REJECTED amounts were not accepted. Default period: this month.',
            'tables' => [['Payments', ['Payment', 'Reservation', 'Guest', 'Type', 'Method', 'Reference', 'Amount', 'Status', 'Paid on', 'Decided by', 'Decided at', 'Rejection reason'], $detail]],
            'summary' => [['Verified (received)', money($t['VERIFIED'])], ['Awaiting verification', money($t['PENDING VERIFICATION'])], ['Rejected', money($t['REJECTED'])], ['Payments', count($rows)]],
        ];
    }

    // ------------------------------------------------------------------
    public static function revenue(array $f): array
    {
        $p = [$f['from'], $f['to']];
        $collected = q_all("SELECT DATE(y.verified_at) AS d, SUM(y.amount_paid) AS amt, COUNT(*) AS n
                              FROM payments y WHERE y.payment_status = 'VERIFIED' AND DATE(y.verified_at) BETWEEN ? AND ?" . self::demo($f, 'y') . '
                          GROUP BY DATE(y.verified_at) ORDER BY d', $p);
        $p2 = [$f['from'], $f['to']];
        $booked = q_all("SELECT r.booking_date AS d, COUNT(*) AS n, SUM(r.total_amount) AS value, SUM(r.amount_paid) AS paid
                           FROM reservations r WHERE r.status IN ('CONFIRMED','COMPLETED') AND r.booking_date BETWEEN ? AND ?" . self::areaType($f, $p2) . self::demo($f) . '
                       GROUP BY r.booking_date ORDER BY d', $p2);
        $cTotal = array_sum(array_map(fn($r) => (float) $r['amt'], $collected));
        $bTotal = array_sum(array_map(fn($r) => (float) $r['value'], $booked));
        $bPaid = array_sum(array_map(fn($r) => (float) $r['paid'], $booked));
        return [
            'title' => 'Revenue & collections',
            'description' => 'Two different figures, never mixed: (1) Cash collected = VERIFIED payments, by the date staff verified them. (2) Booked revenue = full price of CONFIRMED or COMPLETED reservations, by stay date; the outstanding balance is what those guests still owe. Pending, rejected and unverified payments are never counted. Default period: this month.',
            'tables' => [
                ['Cash collected (verified payments)', ['Verified on', 'Payments', 'Amount'], array_map(fn($r) => [fmt_date($r['d']), (int) $r['n'], money($r['amt'])], $collected)],
                ['Booked revenue (confirmed/completed stays)', ['Stay date', 'Reservations', 'Booking value', 'Verified paid', 'Outstanding'], array_map(fn($r) => [fmt_date($r['d']), (int) $r['n'], money($r['value']), money($r['paid']), money((float) $r['value'] - (float) $r['paid'])], $booked)],
            ],
            'summary' => [['Cash collected', money($cTotal)], ['Booked revenue', money($bTotal)], ['Outstanding balance on those stays', money($bTotal - $bPaid)]],
        ];
    }

    // ------------------------------------------------------------------
    public static function customers(array $f): array
    {
        $p = [$f['from'], $f['to']];
        $where = 'DATE(c.created_at) BETWEEN ? AND ?' . self::demo($f, 'c');
        if (in_array($f['status'] ?? '', ['ACTIVE', 'INACTIVE'], true)) {
            $where .= ' AND c.status = ?';
            $p[] = $f['status'];
        }
        $rows = q_all("SELECT c.customer_id, c.full_name, c.email, c.contact_number, c.address, c.status, c.created_at,
                              COUNT(r.reservation_id) AS total,
                              SUM(r.status = 'COMPLETED') AS completed,
                              SUM(r.status IN ('CANCELLED','DECLINED')) AS cancelled,
                              COALESCE(SUM(r.amount_paid), 0) AS paid,
                              MAX(r.booking_date) AS last_booking
                         FROM customers c JOIN reservations r ON r.customer_id = c.customer_id
                        WHERE $where GROUP BY c.customer_id ORDER BY c.full_name", $p);
        $detail = array_map(fn($r) => ['C' . str_pad((string) $r['customer_id'], 4, '0', STR_PAD_LEFT), $r['full_name'], $r['email'], $r['contact_number'], $r['address'], $r['status'], (int) $r['total'], (int) $r['completed'], (int) $r['cancelled'], money($r['paid']), fmt_date($r['last_booking']), fmt_date($r['created_at'])], $rows);
        $active = count(array_filter($rows, fn($r) => (int) $r['total'] > 0));
        return [
            'title' => 'Customer list & activity',
            'description' => 'Customers registered in the period (by record creation date) with their reservation counts and verified payments. Only customers linked to at least one reservation are listed. Contains personal data: handle exports according to the resort\'s privacy practice. Default period: all time.',
            'tables' => [['Customers', ['ID', 'Name', 'Email', 'Contact', 'Address', 'Status', 'Reservations', 'Completed', 'Cancelled', 'Verified paid', 'Last booking date', 'Registered'], $detail]],
            'summary' => [['Customers', count($rows)], ['With at least one reservation', $active]],
        ];
    }

    private static function pct(int $a, int $b): string
    {
        return $b > 0 ? number_format($a * 100 / $b, 1) . '%' : '—';
    }
}
