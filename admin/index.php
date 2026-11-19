<?php
require __DIR__ . '/../app/bootstrap.php';
$me = require_login();
if ($me['role'] === 'SUPER ADMIN') {
    redirect('admin/accounts.php');
}
$me = require_perm('dashboard');
ReservationService::expireHolds();
$today = date('Y-m-d');
$m = [
    'customers'   => (int) q_val('SELECT COUNT(DISTINCT customer_id) FROM reservations'),
    'reservations'=> (int) q_val('SELECT COUNT(*) FROM reservations'),
    'pending'     => (int) q_val("SELECT COUNT(*) FROM reservations WHERE status IN ('PENDING','PAYMENT PENDING')"),
    'confirmed'   => (int) q_val("SELECT COUNT(*) FROM reservations WHERE status = 'CONFIRMED'"),
    'cancelled'   => (int) q_val("SELECT COUNT(*) FROM reservations WHERE status IN ('CANCELLED','DECLINED') AND cancelled_at >= ?", [date('Y-m-01')]),
    'payverify'   => (int) q_val("SELECT COUNT(*) FROM payments WHERE payment_status = 'PENDING VERIFICATION'"),
    'today'       => (int) q_val("SELECT COUNT(*) FROM reservations WHERE booking_date = ? AND status IN ('CONFIRMED','PAYMENT PENDING','PENDING','COMPLETED')", [$today]),
    'reschedules' => (int) q_val("SELECT COUNT(*) FROM rescheduling_requests WHERE approval_status = 'PENDING'"),
    'collected'   => (float) q_val("SELECT COALESCE(SUM(amount_paid),0) FROM payments WHERE payment_status = 'VERIFIED' AND verified_at >= ?", [date('Y-m-01')]),
    'inquiries'   => (int) q_val("SELECT COUNT(*) FROM inquiries WHERE status = 'NEW'"),
];
$todayList = q_all("SELECT r.reservation_id, r.reservation_code, r.guest_name, r.num_guests, r.booking_type, r.status, r.payment_status, a.area_name
                      FROM reservations r JOIN resort_areas a ON a.area_id = r.area_id
                     WHERE r.booking_date = ? AND r.status IN ('PENDING','PAYMENT PENDING','CONFIRMED','COMPLETED') ORDER BY r.start_at", [$today]);
$upcoming = q_all("SELECT r.reservation_id, r.reservation_code, r.guest_name, r.booking_date, r.booking_type, r.status, r.payment_status, a.area_name, r.is_demo
                     FROM reservations r JOIN resort_areas a ON a.area_id = r.area_id
                    WHERE r.booking_date > ? AND r.booking_date <= ? AND r.status IN ('PENDING','PAYMENT PENDING','CONFIRMED')
                 ORDER BY r.start_at LIMIT 10", [$today, date('Y-m-d', strtotime('+14 days'))]);
$activity = q_all("SELECT l.*, a.full_name FROM activity_logs l LEFT JOIN admins a ON a.admin_id = l.admin_id WHERE l.module NOT IN ('Authentication','Setup','Accounts','Security') ORDER BY l.log_id DESC LIMIT 8");
$integrity = [];
if (can('integrity.view')) {
    $integrity['Cached payment total differs from verified payments|reservations.php?mismatch=1'] = (int) q_val("SELECT COUNT(*) FROM (SELECT r.reservation_id FROM reservations r LEFT JOIN payments p ON p.reservation_id = r.reservation_id AND p.payment_status = 'VERIFIED' WHERE r.needs_review = 0 GROUP BY r.reservation_id HAVING ABS(MAX(r.amount_paid) - COALESCE(SUM(p.amount_paid),0)) > 0.004) x");
    $integrity['Reservations flagged for review (from migration)|reservations.php?review=1'] = (int) q_val('SELECT COUNT(*) FROM reservations WHERE needs_review = 1');
    $integrity['Payments still awaiting verification after the stay date|reservations.php?status=PAYMENT+PENDING&to=' . date('Y-m-d', strtotime('-1 day'))] = (int) q_val("SELECT COUNT(*) FROM reservations WHERE status = 'PAYMENT PENDING' AND booking_date < CURDATE()");
}
$pageTitle = 'Dashboard';
$active = 'dashboard';
require __DIR__ . '/../app/views/admin_header.php';
?>
<h1>Good <?= (int) date('G') < 12 ? 'morning' : ((int) date('G') < 18 ? 'afternoon' : 'evening') ?>, <?= e(explode(' ', $me['full_name'])[0]) ?></h1>
<p class="muted"><?= e(date('l, F j, Y')) ?></p>

<div class="metrics">
  <a class="metric" href="<?= e(url('admin/payments.php')) ?>"><b><?= $m['payverify'] ?></b><span>Payments to verify</span></a>
  <a class="metric" href="<?= e(url('admin/reservations.php?status=ACTIVE_PENDING')) ?>"><b><?= $m['pending'] ?></b><span>Pending requests</span></a>
  <a class="metric" href="<?= e(url('admin/reschedules.php')) ?>"><b><?= $m['reschedules'] ?></b><span>Reschedule requests</span></a>
  <a class="metric" href="<?= e(url('admin/reservations.php?from=' . $today . '&to=' . $today)) ?>"><b><?= $m['today'] ?></b><span>Bookings today</span></a>
  <a class="metric" href="<?= e(url('admin/reservations.php?status=CONFIRMED')) ?>"><b><?= $m['confirmed'] ?></b><span>Confirmed (not yet completed)</span></a>
  <a class="metric" href="<?= e(url('admin/reports.php?report=cancellations&from=' . date('Y-m-01') . '&to=' . $today)) ?>"><b><?= $m['cancelled'] ?></b><span>Cancelled this month</span></a>
  <a class="metric" href="<?= e(url('admin/customers.php')) ?>"><b><?= $m['customers'] ?></b><span>Customers</span></a>
  <a class="metric" href="<?= e(url('admin/reservations.php')) ?>"><b><?= $m['reservations'] ?></b><span>All reservations</span></a>
  <?php if (can('reports.financial')): ?><a class="metric" href="<?= e(url('admin/reports.php?report=revenue')) ?>"><b><?= e(money($m['collected'])) ?></b><span>Verified payments this month</span></a><?php endif; ?>
  <a class="metric" href="<?= e(url('admin/inquiries.php')) ?>"><b><?= $m['inquiries'] ?></b><span>New inquiries</span></a>
</div>

<?php foreach ($integrity as $key => $n): if ($n > 0): [$label, $link] = explode('|', $key, 2); ?>
  <div class="flash flash-warn"><?= e($label) ?>: <strong><?= $n ?></strong>. <a href="<?= e(url('admin/' . $link)) ?>">Review</a></div>
<?php endif; endforeach; ?>

<div class="two-col">
  <section class="panel">
    <h2>Today</h2>
    <?php if (!$todayList): ?><p class="muted">No bookings today.</p><?php else: ?>
    <div class="table-wrap"><table><thead><tr><th>Code</th><th>Area</th><th>Session</th><th>Guest</th><th class="num">Guests</th><th>Status</th></tr></thead><tbody>
    <?php foreach ($todayList as $r): ?>
      <tr><td><a href="<?= e(url('admin/reservation.php?id=' . $r['reservation_id'])) ?>"><?= e($r['reservation_code']) ?></a></td><td><?= e($r['area_name']) ?></td><td><?= e(type_short($r['booking_type'])) ?></td><td><?= e($r['guest_name']) ?></td><td class="num"><?= (int) $r['num_guests'] ?></td><td><?= badge($r['status']) ?></td></tr>
    <?php endforeach; ?></tbody></table></div><?php endif; ?>
    <h2 style="margin-top:1.2rem">Next 14 days</h2>
    <?php if (!$upcoming): ?><p class="muted">Nothing booked yet.</p><?php else: ?>
    <div class="table-wrap"><table><thead><tr><th>Date</th><th>Code</th><th>Area</th><th>Session</th><th>Status</th><th>Payment</th></tr></thead><tbody>
    <?php foreach ($upcoming as $r): ?>
      <tr><td><?= e(fmt_date($r['booking_date'], 'D M j')) ?></td><td><a href="<?= e(url('admin/reservation.php?id=' . $r['reservation_id'])) ?>"><?= e($r['reservation_code']) ?></a></td><td><?= e($r['area_name']) ?></td><td><?= e(type_short($r['booking_type'])) ?></td><td><?= badge($r['status']) ?></td><td><?= badge($r['payment_status']) ?></td></tr>
    <?php endforeach; ?></tbody></table></div><?php endif; ?>
  </section>
  <section class="panel">
    <h2>Quick actions</h2>
    <div class="actions">
      <?php if (can('reservations.create')): ?><a class="btn btn-success" href="<?= e(url('admin/reservation-new.php')) ?>">New reservation</a><?php endif; ?>
      <a class="btn btn-ghost" href="<?= e(url('admin/reports.php?report=occupancy')) ?>">Today's occupancy</a>
    </div>
    <?php if ($activity): ?>
      <h2>Recent activity</h2>
      <ul class="timeline"><?php foreach ($activity as $l): ?>
        <li><strong><?= e($l['module']) ?> · <?= e($l['action']) ?></strong> <span class="muted"><?= e(fmt_dt($l['created_at'])) ?></span><br><?= e($l['description']) ?> <span class="muted">— <?= e($l['full_name'] ?? 'System / website') ?></span></li>
      <?php endforeach; ?></ul>
    <?php endif; ?>
  </section>
</div>
<?php require __DIR__ . '/../app/views/admin_footer.php'; ?>
