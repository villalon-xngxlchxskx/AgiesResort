<?php
require __DIR__ . '/../app/bootstrap.php';
$me = require_perm('reservations.view');
ReservationService::expireHolds();

$f = [
    'q' => search_term(), 'status' => get('status'), 'area_id' => int_in('area_id'), 'type' => get('type'),
    'payment_status' => get('payment_status'), 'from' => get('from'), 'to' => get('to'),
    'review' => get('review'), 'mismatch' => get('mismatch'), 'demo' => get('demo'), 'sort' => get('sort', 'date_desc'),
];
$where = ['1=1'];
$p = [];
if ($f['q'] !== '') {
    $where[] = '(r.reservation_code LIKE ? OR r.guest_name LIKE ? OR c.full_name LIKE ? OR c.email LIKE ? OR r.guest_contact LIKE ?)';
    $like = '%' . addcslashes($f['q'], '%_\\') . '%';
    array_push($p, $like, $like, $like, $like, $like);
}
if ($f['status'] === 'ACTIVE_PENDING') {
    $where[] = "r.status IN ('PENDING','PAYMENT PENDING')";
} elseif (in_array($f['status'], ReservationService::ALL, true)) {
    $where[] = 'r.status = ?';
    $p[] = $f['status'];
}
if ($f['area_id']) { $where[] = 'r.area_id = ?'; $p[] = $f['area_id']; }
if (in_array($f['type'], ['DAYTIME', 'OVERNIGHT'], true)) { $where[] = 'r.booking_type = ?'; $p[] = $f['type']; }
if (in_array($f['payment_status'], ['UNPAID', 'PENDING VERIFICATION', 'PARTIAL', 'PAID'], true)) { $where[] = 'r.payment_status = ?'; $p[] = $f['payment_status']; }
if (valid_date($f['from'])) { $where[] = 'r.booking_date >= ?'; $p[] = $f['from']; }
if (valid_date($f['to'])) { $where[] = 'r.booking_date <= ?'; $p[] = $f['to']; }
if ($f['review'] === '1') { $where[] = 'r.needs_review = 1'; }
if ($f['mismatch'] === '1') {
    $where[] = "ABS(r.amount_paid - (SELECT COALESCE(SUM(x.amount_paid),0) FROM payments x WHERE x.reservation_id = r.reservation_id AND x.payment_status = 'VERIFIED')) > 0.004";
}
if ($f['demo'] === 'hide') { $where[] = 'r.is_demo = 0'; }
if ($f['demo'] === 'only') { $where[] = 'r.is_demo = 1'; }
$order = match ($f['sort']) {
    'date_asc' => 'r.booking_date ASC, r.start_at ASC',
    'created' => 'r.created_at DESC, r.reservation_id DESC',
    default => 'r.booking_date DESC, r.start_at DESC',
};
$whereSql = implode(' AND ', $where);
$from = "FROM reservations r JOIN customers c ON c.customer_id = r.customer_id JOIN resort_areas a ON a.area_id = r.area_id WHERE $whereSql";

if (get('export') === 'csv') {
    require_perm('reports.view');
    $rows = q_all("SELECT r.reservation_code, r.booking_date, a.area_name, r.booking_type, r.guest_name, r.guest_contact, c.email, r.num_guests,
                          r.status, r.payment_status, r.total_amount, r.down_payment, r.amount_paid, r.source, r.created_at $from ORDER BY $order", $p);
    log_activity('Reservations', 'Export', count($rows) . ' reservations exported to CSV');
    send_csv('reservations_' . date('Ymd_His') . '.csv',
        ['Code', 'Booking date', 'Area', 'Session', 'Guest', 'Contact', 'Email', 'Guests', 'Status', 'Payment', 'Total', 'Down payment', 'Verified paid', 'Source', 'Created'], $rows);
}

$total = (int) q_val("SELECT COUNT(*) $from", $p);
$pg = pager($total, 25);
$rows = q_all("SELECT r.reservation_id, r.reservation_code, r.booking_date, r.booking_type, r.guest_name, r.guest_contact, r.num_guests,
                      r.status, r.payment_status, r.total_amount, r.amount_paid, r.needs_review, r.is_demo, r.hold_expires_at, a.area_name
               $from ORDER BY $order LIMIT {$pg['per']} OFFSET {$pg['offset']}", $p);
$areas = q_all('SELECT area_id, area_name FROM resort_areas ORDER BY sort_order, area_id');
$qs = $_GET;
unset($qs['page']);
$pageTitle = 'Reservations';
$active = 'reservations';
require __DIR__ . '/../app/views/admin_header.php';
?>
<div class="cal-head"><h1>Reservations</h1>
  <div class="actions no-print">
    <?php if (can('reservations.create')): ?><a class="btn btn-success" href="<?= e(url('admin/reservation-new.php')) ?>">New reservation</a><?php endif; ?>
    <?php if (can('reports.view')): ?><a class="btn btn-ghost" href="?<?= e(http_build_query($qs + ['export' => 'csv'])) ?>">Export CSV</a><?php endif; ?>
    <button class="btn btn-ghost" type="button" data-print>Print</button>
  </div>
</div>
<form class="toolbar panel" method="get" action="">
  <div class="field grow"><label for="q">Search</label><input type="search" id="q" name="q" maxlength="60" value="<?= e($f['q']) ?>" placeholder="Code, name, email or phone"></div>
  <div class="field"><label for="status">Status</label><select id="status" name="status"><option value="">All</option>
    <option value="ACTIVE_PENDING"<?= $f['status'] === 'ACTIVE_PENDING' ? ' selected' : '' ?>>Pending + payment submitted</option>
    <?php foreach (ReservationService::ALL as $s): ?><option value="<?= e($s) ?>"<?= $f['status'] === $s ? ' selected' : '' ?>><?= e(ucwords(strtolower($s))) ?></option><?php endforeach; ?></select></div>
  <div class="field"><label for="payment_status">Payment</label><select id="payment_status" name="payment_status"><option value="">All</option>
    <?php foreach (['UNPAID', 'PENDING VERIFICATION', 'PARTIAL', 'PAID'] as $s): ?><option value="<?= e($s) ?>"<?= $f['payment_status'] === $s ? ' selected' : '' ?>><?= e($s === 'PARTIAL' ? 'Down payment verified' : ucwords(strtolower($s))) ?></option><?php endforeach; ?></select></div>
  <div class="field"><label for="area_id">Area</label><select id="area_id" name="area_id"><option value="">All</option><?php foreach ($areas as $a): ?><option value="<?= (int) $a['area_id'] ?>"<?= $f['area_id'] === (int) $a['area_id'] ? ' selected' : '' ?>><?= e($a['area_name']) ?></option><?php endforeach; ?></select></div>
  <div class="field"><label for="type">Session</label><select id="type" name="type"><option value="">All</option><option value="DAYTIME"<?= $f['type'] === 'DAYTIME' ? ' selected' : '' ?>>Daytime</option><option value="OVERNIGHT"<?= $f['type'] === 'OVERNIGHT' ? ' selected' : '' ?>>Overnight</option></select></div>
  <div class="field"><label for="from">Booking from</label><input type="date" id="from" name="from" value="<?= e($f['from']) ?>"></div>
  <div class="field"><label for="to">to</label><input type="date" id="to" name="to" value="<?= e($f['to']) ?>"></div>
  <div class="field"><label for="demo">Demo data</label><select id="demo" name="demo"><option value="">Show</option><option value="hide"<?= $f['demo'] === 'hide' ? ' selected' : '' ?>>Hide</option><option value="only"<?= $f['demo'] === 'only' ? ' selected' : '' ?>>Only demo</option></select></div>
  <div class="field"><label for="sort">Sort</label><select id="sort" name="sort"><option value="date_desc">Booking date, newest</option><option value="date_asc"<?= $f['sort'] === 'date_asc' ? ' selected' : '' ?>>Booking date, oldest</option><option value="created"<?= $f['sort'] === 'created' ? ' selected' : '' ?>>Recently submitted</option></select></div>
  <?php if ($f['review'] === '1'): ?><input type="hidden" name="review" value="1"><?php endif; ?>
  <?php if ($f['mismatch'] === '1'): ?><input type="hidden" name="mismatch" value="1"><?php endif; ?>
  <button class="btn" type="submit">Apply</button>
  <a class="btn btn-ghost" href="<?= e(url('admin/reservations.php')) ?>">Reset</a>
</form>
<?php if ($f['review'] === '1'): ?><p class="flash flash-warn">Showing reservations flagged for review only.</p><?php endif; ?>
<?php if ($f['mismatch'] === '1'): ?><p class="flash flash-warn">Showing reservations whose cached paid total differs from their verified payments. Opening one and saving any payment action re-syncs it.</p><?php endif; ?>
<div class="panel table-wrap">
<?php if (!$rows): ?><p>No reservations match these filters. <a href="<?= e(url('admin/reservations.php')) ?>">Clear filters</a></p><?php else: ?>
<table>
  <thead><tr><th>Code</th><th>Booking date</th><th>Area</th><th>Session</th><th>Guest</th><th class="num">Guests</th><th>Status</th><th>Payment</th><th class="num">Total</th><th class="num">Verified paid</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr<?= $r['needs_review'] ? ' class="flagged"' : '' ?>>
      <td><a href="<?= e(url('admin/reservation.php?id=' . $r['reservation_id'])) ?>"><?= e($r['reservation_code']) ?></a><?= $r['is_demo'] ? ' <span class="badge badge-demo">Demo</span>' : '' ?><?= $r['needs_review'] ? ' <span class="badge badge-warn">Review</span>' : '' ?></td>
      <td><?= e(fmt_date($r['booking_date'])) ?></td><td><?= e($r['area_name']) ?></td><td><?= e(type_short($r['booking_type'])) ?></td>
      <td><?= e($r['guest_name']) ?><br><span class="hint"><?= e($r['guest_contact']) ?></span></td>
      <td class="num"><?= (int) $r['num_guests'] ?></td>
      <td><?= badge($r['status']) ?><?php if ($r['status'] === 'PENDING' && $r['hold_expires_at']): ?><br><span class="hint">hold until <?= e(fmt_dt($r['hold_expires_at'])) ?></span><?php endif; ?></td>
      <td><?= badge($r['payment_status']) ?></td>
      <td class="num"><?= e(money($r['total_amount'])) ?></td><td class="num"><?= e(money($r['amount_paid'])) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>
<?= pager_links($pg) ?>
</div>
<?php require __DIR__ . '/../app/views/admin_footer.php'; ?>
