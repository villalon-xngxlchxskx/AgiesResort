<?php
declare(strict_types=1);

class ValidationError extends UserError
{
    public array $errors;

    public function __construct(array $errors)
    {
        $this->errors = $errors;
        parent::__construct(implode(' ', $errors));
    }
}

final class ReservationService
{
    /** Statuses that occupy a slot (must match the slot_lock generated column). */
    public const ACTIVE = ['PENDING', 'PAYMENT PENDING', 'CONFIRMED'];
    public const ALL = ['PENDING', 'PAYMENT PENDING', 'CONFIRMED', 'COMPLETED', 'CANCELLED', 'DECLINED', 'EXPIRED'];

    /** Allowed manual/automatic status changes. Terminal: COMPLETED, CANCELLED, DECLINED, EXPIRED. */
    public const TRANSITIONS = [
        'PENDING'         => ['PAYMENT PENDING', 'CONFIRMED', 'CANCELLED', 'DECLINED', 'EXPIRED'],
        'PAYMENT PENDING' => ['PENDING', 'CONFIRMED', 'CANCELLED', 'DECLINED'],
        'CONFIRMED'       => ['COMPLETED', 'CANCELLED'],
    ];

    private const CODE_ALPHABET = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

    // ------------------------------------------------------------ catalogue
    public static function packages(bool $activeOnly = true): array
    {
        $rows = q_all(
            'SELECT p.*, a.area_name, a.area_code, a.capacity, a.description AS area_description, a.main_image, a.status AS area_status
               FROM packages p JOIN resort_areas a ON a.area_id = p.area_id'
            . ($activeOnly ? " WHERE p.status = 'ACTIVE' AND a.status = 'ACTIVE'" : '')
            . ' ORDER BY a.sort_order, a.area_id, p.booking_type'
        );
        $incl = [];
        foreach (q_all('SELECT package_id, inclusion_text FROM package_inclusions ORDER BY package_id, sort_order, inclusion_id') as $i) {
            $incl[(int) $i['package_id']][] = $i['inclusion_text'];
        }
        foreach ($rows as &$r) {
            $r['inclusions'] = $incl[(int) $r['package_id']] ?? [];
        }
        return $rows;
    }

    public static function package(int $id): ?array
    {
        foreach (self::packages(false) as $p) {
            if ((int) $p['package_id'] === $id) {
                return $p;
            }
        }
        return null;
    }

    // ------------------------------------------------------------ rules
    /** [earliest, latest] bookable dates (Y-m-d). Staff may book from today. */
    public static function window(bool $staff = false): array
    {
        $min = $staff ? 0 : setting_int('booking_min_days_ahead', 1);
        $max = setting_int('booking_max_days_ahead', 365);
        return [date('Y-m-d', strtotime("+$min day")), date('Y-m-d', strtotime("+$max day"))];
    }

    /** Start/end datetimes; overnight end rolls to the next calendar day. */
    public static function interval(string $date, string $startTime, string $endTime): array
    {
        $start = new DateTimeImmutable("$date $startTime");
        $end = new DateTimeImmutable("$date $endTime");
        if ($end <= $start) {
            $end = $end->modify('+1 day');
        }
        return [$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')];
    }

    /** Server-side price. Never uses amounts sent by the browser. */
    public static function quote(array $pkg, int $guests): array
    {
        $max = (int) $pkg['max_guests'];
        $extraAllowed = (int) $pkg['max_extra_guests'];
        if ($guests < 1) {
            throw new UserError('Enter the number of guests (at least 1).');
        }
        if ($guests > $max + $extraAllowed) {
            $msg = "{$pkg['package_name']} allows up to $max guests";
            $msg .= $extraAllowed > 0 ? ", plus up to $extraAllowed extra guests at " . money($pkg['additional_guest_fee']) . ' each.' : '.';
            throw new UserError($msg . ' For a bigger group, send us an inquiry first.');
        }
        $extra = max(0, $guests - $max);
        $fee = round($extra * (float) $pkg['additional_guest_fee'], 2);
        $total = round((float) $pkg['rate'] + $fee, 2);
        return [
            'base_rate' => (float) $pkg['rate'], 'extra_guests' => $extra, 'additional_fee' => $fee,
            'total' => $total, 'down_payment' => min((float) $pkg['down_payment'], $total),
        ];
    }

    // ------------------------------------------------------------ holds
    /** Releases unpaid PENDING holds that ran out (or whose start time passed). Safe to call often. */
    public static function expireHolds(): int
    {
        return (int) tx(function () {
            $ids = q_all("SELECT reservation_id FROM reservations
                           WHERE status = 'PENDING' AND ((hold_expires_at IS NOT NULL AND hold_expires_at < NOW()) OR start_at < NOW())
                           FOR UPDATE");
            foreach ($ids as $r) {
                q("UPDATE reservations SET status = 'EXPIRED', cancelled_at = NOW(), cancel_reason = 'Hold expired without down payment', hold_expires_at = NULL
                    WHERE reservation_id = ? AND status = 'PENDING'", [$r['reservation_id']]);
                q("INSERT INTO reservation_status_history (reservation_id, old_status, new_status, remarks) VALUES (?, 'PENDING', 'EXPIRED', 'Hold expired without down payment')", [$r['reservation_id']]);
            }
            return count($ids);
        });
    }

    /** Active reservations overlapping [start, end) in the area. */
    public static function conflicts(int $areaId, string $startAt, string $endAt, ?int $excludeId = null, bool $lock = false): array
    {
        $sql = "SELECT reservation_id, reservation_code, status FROM reservations
                 WHERE area_id = ? AND status IN ('PENDING','PAYMENT PENDING','CONFIRMED')
                   AND start_at < ? AND end_at > ?";
        $params = [$areaId, $endAt, $startAt];
        if ($excludeId) {
            $sql .= ' AND reservation_id <> ?';
            $params[] = $excludeId;
        }
        return q_all($sql . ($lock ? ' FOR UPDATE' : ''), $params);
    }

    /**
     * Slot map for a month: [Y-m-d][area_id][DAYTIME|OVERNIGHT] => available|held|booked|closed
     */
    public static function availabilityMonth(int $year, int $month, bool $staff = false): array
    {
        self::expireHolds();
        $first = sprintf('%04d-%02d-01', $year, $month);
        $last = date('Y-m-t', strtotime($first));
        [$min, $max] = self::window($staff);
        $taken = [];
        foreach (q_all("SELECT area_id, booking_date, booking_type, status FROM reservations
                         WHERE status IN ('PENDING','PAYMENT PENDING','CONFIRMED') AND booking_date BETWEEN ? AND ?", [$first, $last]) as $r) {
            $taken[$r['booking_date']][$r['area_id']][$r['booking_type']] = $r['status'] === 'CONFIRMED' ? 'booked' : 'held';
        }
        $pk = [];
        foreach (self::packages(true) as $p) {
            $pk[(int) $p['area_id']][$p['booking_type']] = true;
        }
        $areas = q_all("SELECT area_id FROM resort_areas WHERE status = 'ACTIVE' ORDER BY sort_order, area_id");
        $map = [];
        for ($d = $first; $d <= $last; $d = date('Y-m-d', strtotime("$d +1 day"))) {
            foreach ($areas as $a) {
                $aid = (int) $a['area_id'];
                foreach (['DAYTIME', 'OVERNIGHT'] as $t) {
                    if (isset($taken[$d][$aid][$t])) {
                        $map[$d][$aid][$t] = $taken[$d][$aid][$t];
                    } elseif ($d < $min || $d > $max || empty($pk[$aid][$t])) {
                        $map[$d][$aid][$t] = 'closed';
                    } else {
                        $map[$d][$aid][$t] = 'available';
                    }
                }
            }
        }
        return $map;
    }

    // ------------------------------------------------------------ create
    /**
     * Creates a reservation + history + notification atomically.
     * $admin = null for public website submissions.
     * @return array{reservation_id:int, reservation_code:string, total:float, down_payment:float, hold_expires_at:?string}
     */
    public static function create(array $in, ?array $admin = null): array
    {
        $staff = $admin !== null;
        $errors = [];
        $packageId = (int) ($in['package_id'] ?? 0);
        $date = trim((string) ($in['booking_date'] ?? ''));
        $guestsRaw = trim((string) ($in['num_guests'] ?? ''));
        $name = preg_replace('/\s+/', ' ', trim((string) ($in['full_name'] ?? '')));
        $email = mb_strtolower(trim((string) ($in['email'] ?? '')));
        $phone = normalize_phone((string) ($in['contact_number'] ?? ''));
        $address = trim((string) ($in['address'] ?? ''));
        $notes = trim((string) ($in['notes'] ?? ''));

        if ($packageId <= 0) {
            $errors[] = 'Choose an area and package.';
        }
        [$min, $max] = self::window($staff);
        if (!valid_date($date)) {
            $errors[] = 'Choose a valid date.';
        } elseif ($date < $min || $date > $max) {
            $errors[] = 'Choose a date between ' . fmt_date($min) . ' and ' . fmt_date($max) . '.';
        }
        if (!preg_match('/^\d{1,4}$/', $guestsRaw)) {
            $errors[] = 'Enter the number of guests as a whole number.';
        }
        if (!str_len_ok($name, 2, 120) || !preg_match("/^[\p{L}\p{M} .'\-ñÑ]+$/u", $name)) {
            $errors[] = 'Enter your full name (letters, spaces, . \' - only).';
        }
        if (!valid_email($email)) {
            $errors[] = 'Enter a valid email address.';
        }
        if ($phone === null) {
            $errors[] = 'Enter a valid Philippine contact number, e.g. 09171234567.';
        }
        if (mb_strlen($address) > 255) {
            $errors[] = 'Address is too long (255 characters max).';
        }
        if (mb_strlen($notes) > 1000) {
            $errors[] = 'Notes are too long (1000 characters max).';
        }
        if (!$staff && empty($in['agree'])) {
            $errors[] = 'Please confirm that you have read the reservation policies.';
        }
        if ($errors) {
            throw new ValidationError($errors);
        }
        $guests = (int) $guestsRaw;

        self::expireHolds();

        return tx(function () use ($packageId, $date, $guests, $name, $email, $phone, $address, $notes, $admin, $staff) {
            // Locking the package row serialises concurrent bookings for this area + session.
            $pkg = q_one("SELECT p.*, a.area_name, a.status AS area_status FROM packages p
                            JOIN resort_areas a ON a.area_id = p.area_id WHERE p.package_id = ? FOR UPDATE", [$packageId]);
            if (!$pkg || $pkg['status'] !== 'ACTIVE' || $pkg['area_status'] !== 'ACTIVE') {
                throw new UserError('That package is not available for booking.');
            }
            $quote = self::quote($pkg, $guests);
            [$startAt, $endAt] = self::interval($date, $pkg['start_time'], $pkg['end_time']);
            if (strtotime($startAt) <= time()) {
                throw new UserError('That session has already started. Choose a later date.');
            }
            if (self::conflicts((int) $pkg['area_id'], $startAt, $endAt, null, true)) {
                throw new UserError($pkg['area_name'] . ' is no longer available for ' . type_short($pkg['booking_type']) . ' on ' . fmt_date($date) . '. Please choose another date or area.');
            }

            $cust = q_one('SELECT customer_id, status FROM customers WHERE email = ? FOR UPDATE', [$email]);
            if (!$cust) {
                q('INSERT INTO customers (full_name, contact_number, email, address) VALUES (?,?,?,?)', [$name, $phone, $email, $address ?: null]);
                $customerId = (int) db()->lastInsertId();
            } else {
                if ($cust['status'] !== 'ACTIVE' && !$staff) {
                    throw new UserError('We could not accept this request online. Please contact the resort directly.');
                }
                // Existing customer: stored profile is NOT overwritten from a public form;
                // the details typed for this booking are kept on the reservation itself.
                $customerId = (int) $cust['customer_id'];
            }

            $holdHours = max(1, setting_int('pending_hold_hours', 48));
            $hold = date('Y-m-d H:i:s', min(time() + $holdHours * 3600, strtotime($startAt)));
            $id = 0;
            $code = '';
            for ($attempt = 0; $attempt < 5 && !$id; $attempt++) {
                $code = self::newCode();
                try {
                    q('INSERT INTO reservations
                        (reservation_code, customer_id, area_id, package_id, package_name, booking_type, booking_date, start_time, end_time,
                         start_at, end_at, num_guests, base_rate, extra_guests, additional_fee, total_amount, down_payment,
                         status, hold_expires_at, guest_name, guest_contact, notes, source, created_by)
                       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', [
                        $code, $customerId, $pkg['area_id'], $pkg['package_id'], $pkg['package_name'], $pkg['booking_type'], $date,
                        $pkg['start_time'], $pkg['end_time'], $startAt, $endAt, $guests, $quote['base_rate'], $quote['extra_guests'],
                        $quote['additional_fee'], $quote['total'], $quote['down_payment'], 'PENDING', $hold, $name, $phone,
                        $notes ?: null, $staff ? 'STAFF' : 'WEBSITE', $admin['admin_id'] ?? null,
                    ]);
                    $id = (int) db()->lastInsertId();
                } catch (PDOException $e) {
                    if (is_duplicate_key($e, 'uq_slot_lock')) {
                        throw new UserError($pkg['area_name'] . ' was just booked for that session. Please choose another date or area.');
                    }
                    if (!is_duplicate_key($e, 'uq_reservation_code')) {
                        throw $e;
                    }
                }
            }
            if (!$id) {
                throw new RuntimeException('Could not generate a unique reservation code.');
            }
            q('INSERT INTO reservation_status_history (reservation_id, old_status, new_status, changed_by, remarks) VALUES (?,?,?,?,?)', [
                $id, null, 'PENDING', $admin['admin_id'] ?? null,
                $staff ? 'Created by staff (' . $admin['username'] . ')' : 'Reservation submitted from website',
            ]);
            notify('reservation', 'New reservation', "$code - {$pkg['area_name']}, " . type_short($pkg['booking_type']) . ', ' . fmt_date($date) . " by $name", $id, null, "admin/reservation.php?id=$id");
            if ($staff) {
                log_activity('Reservations', 'Create', "$code created for $name");
            }
            return ['reservation_id' => $id, 'reservation_code' => $code, 'total' => $quote['total'], 'down_payment' => $quote['down_payment'], 'hold_expires_at' => $hold];
        });
    }

    private static function newCode(): string
    {
        $s = 'AGR-';
        for ($i = 0; $i < 8; $i++) {
            $s .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
        }
        return $s;
    }

    // ------------------------------------------------------------ read
    public static function find(int $id): ?array
    {
        return q_one('SELECT r.*, c.full_name AS customer_name, c.email AS customer_email, c.contact_number AS customer_contact,
                             c.address AS customer_address, c.status AS customer_status, a.area_name,
                             cb.full_name AS cancelled_by_name, cr.full_name AS created_by_name
                        FROM reservations r
                        JOIN customers c ON c.customer_id = r.customer_id
                        JOIN resort_areas a ON a.area_id = r.area_id
                   LEFT JOIN admins cb ON cb.admin_id = r.cancelled_by
                   LEFT JOIN admins cr ON cr.admin_id = r.created_by
                       WHERE r.reservation_id = ?', [$id]);
    }

    /** Must be called inside tx(). */
    public static function lock(int $id): array
    {
        $r = q_one('SELECT * FROM reservations WHERE reservation_id = ? FOR UPDATE', [$id]);
        if (!$r) {
            throw new UserError('Reservation not found.');
        }
        return $r;
    }

    // ------------------------------------------------------------ status changes
    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    public static function transition(int $id, string $to, ?int $adminId, string $remarks = ''): void
    {
        $remarks = mb_substr(trim($remarks), 0, 255);
        tx(function () use ($id, $to, $adminId, $remarks) {
            $r = self::lock($id);
            $from = $r['status'];
            if (!self::canTransition($from, $to)) {
                throw new UserError('A reservation that is ' . strtolower($from) . ' cannot be changed to ' . strtolower($to) . '.');
            }
            $set = ['status = ?'];
            $vals = [$to];
            switch ($to) {
                case 'CONFIRMED':
                    if ((float) $r['amount_paid'] + 0.004 < (float) $r['down_payment']) {
                        throw new UserError('Verify the down payment of ' . money($r['down_payment']) . ' before confirming (no down payment, no confirmed reservation).');
                    }
                    if (self::conflicts((int) $r['area_id'], $r['start_at'], $r['end_at'], $id, true)) {
                        throw new UserError('Another active reservation overlaps this one. Resolve it before confirming.');
                    }
                    $set[] = 'confirmed_at = NOW()';
                    $set[] = 'hold_expires_at = NULL';
                    break;
                case 'COMPLETED':
                    if ($r['booking_date'] > date('Y-m-d')) {
                        throw new UserError('A stay can be marked completed only on or after its booking date.');
                    }
                    $set[] = 'completed_at = NOW()';
                    break;
                case 'CANCELLED':
                case 'DECLINED':
                    if (mb_strlen($remarks) < 3) {
                        throw new UserError('Enter a reason (at least 3 characters).');
                    }
                    $set[] = 'cancelled_at = NOW()';
                    $set[] = 'cancelled_by = ?';
                    $vals[] = $adminId;
                    $set[] = 'cancel_reason = ?';
                    $vals[] = $remarks;
                    $set[] = 'hold_expires_at = NULL';
                    break;
                case 'PENDING':
                    $hours = max(1, setting_int('pending_hold_hours', 48));
                    $set[] = 'hold_expires_at = ?';
                    $vals[] = date('Y-m-d H:i:s', min(time() + $hours * 3600, strtotime($r['start_at'])));
                    break;
                case 'PAYMENT PENDING':
                    $set[] = 'hold_expires_at = NULL';
                    break;
            }
            $vals[] = $id;
            q('UPDATE reservations SET ' . implode(', ', $set) . ' WHERE reservation_id = ?', $vals);
            q('INSERT INTO reservation_status_history (reservation_id, old_status, new_status, changed_by, remarks) VALUES (?,?,?,?,?)',
              [$id, $from, $to, $adminId, $remarks ?: null]);
            if ($adminId) {
                log_activity('Reservations', ucfirst(strtolower($to)), $r['reservation_code'] . ": $from -> $to" . ($remarks ? " ($remarks)" : ''));
            }
        });
    }

    public static function history(int $id): array
    {
        return q_all('SELECT h.*, a.full_name AS by_name FROM reservation_status_history h
                   LEFT JOIN admins a ON a.admin_id = h.changed_by WHERE h.reservation_id = ? ORDER BY h.changed_at, h.history_id', [$id]);
    }
}
