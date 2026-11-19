<?php
require __DIR__ . '/../app/bootstrap.php';
$me = require_perm('reschedules.manage');
if (is_post()) {
    csrf_check();
    try {
        if (post('action') === 'approve') {
            RescheduleService::approve(int_in('request_id'), (int) $me['admin_id'], post('notes'));
            flash('success', 'Approved. The booking date has been updated.');
        } elseif (post('action') === 'reject') {
            RescheduleService::reject(int_in('request_id'), (int) $me['admin_id'], post('notes'));
            flash('success', 'Request rejected.');
        }
    } catch (UserError $e) {
        flash('error', $e->getMessage());
    }
    redirect('admin/reschedules.php?status=' . urlencode(get('status', 'PENDING')));
}
$status = get('status', 'PENDING');
$p = [];
$w = '1=1';
if (in_array($status, ['PENDING', 'APPROVED', 'REJECTED'], true)) { $w = 'q.approval_status = ?'; $p[] = $status; }
$total = (int) q_val("SELECT COUNT(*) FROM rescheduling_requests q WHERE $w", $p);
$pg = pager($total, 25);
$rows = q_all("SELECT q.*, r.reservation_code, r.guest_name, r.status AS res_status, r.reschedule_count, r.area_id, r.start_time, r.end_time, a.area_name, ad.full_name AS decided_by
                 FROM rescheduling_requests q JOIN reservations r ON r.reservation_id = q.reservation_id JOIN resort_areas a ON a.area_id = r.area_id
            LEFT JOIN admins ad ON ad.admin_id = q.approved_by WHERE $w ORDER BY q.created_at " . ($status === 'PENDING' ? 'ASC' : 'DESC') . " LIMIT {$pg['per']} OFFSET {$pg['offset']}", $p);
$pageTitle = 'Rescheduling';
$active = 'reschedules';
require __DIR__ . '/../app/views/admin_header.php';
?>
<h1>Rescheduling requests</h1>
<p class="muted">Policy: once per reservation, requested at least <?= setting_int('reschedule_min_days', 5) ?> days before the booking date, same area and session, subject to availability and your approval. The date is re-checked when you approve.</p>
<nav class="filters"><?php foreach (['PENDING' => 'Awaiting decision', 'APPROVED' => 'Approved', 'REJECTED' => 'Rejected', 'ALL' => 'All'] as $k => $l): ?>
  <a href="?status=<?= e($k) ?>" aria-current="<?= $status === $k ? 'true' : 'false' ?>"><?= e($l) ?></a><?php endforeach; ?></nav>
<div class="panel table-wrap">
<?php if (!$rows): ?><p>No requests here.</p><?php else: ?>
<table><thead><tr><th>Reservation</th><th>Area / session</th><th>Original date</th><th>Requested date</th><th>Requested on</th><th>Reason</th><th>Status</th><th>Action</th></tr></thead><tbody>
<?php foreach ($rows as $q):
    $free = null;
    if ($q['approval_status'] === 'PENDING') {
        [$s, $en] = ReservationService::interval($q['requested_date'], $q['start_time'], $q['end_time']);
        $free = !ReservationService::conflicts((int) $q['area_id'], $s, $en, (int) $q['reservation_id']);
    } ?>
  <tr>
    <td><a href="<?= e(url('admin/reservation.php?id=' . $q['reservation_id'])) ?>"><?= e($q['reservation_code']) ?></a><br><span class="hint"><?= e($q['guest_name']) ?> · <?= e(strtolower($q['res_status'])) ?> · used <?= (int) $q['reschedule_count'] ?></span></td>
    <td><?= e($q['area_name']) ?><br><span class="hint"><?= e(type_short($q['booking_type'])) ?></span></td>
    <td><?= e(fmt_date($q['original_date'])) ?></td>
    <td><strong><?= e(fmt_date($q['requested_date'])) ?></strong><?php if ($free !== null): ?><br><?= $free ? '<span class="badge badge-ok">Open</span>' : '<span class="badge badge-bad">Taken</span>' ?><?php endif; ?></td>
    <td><?= e(fmt_date($q['request_date'])) ?><br><span class="hint">via <?= e(strtolower($q['requested_via'])) ?></span></td>
    <td><?= e($q['reason']) ?></td>
    <td><?= badge($q['approval_status']) ?><?php if ($q['decided_by']): ?><br><span class="hint"><?= e($q['decided_by']) ?>, <?= e(fmt_dt($q['approval_date'])) ?></span><?php endif; ?><?php if ($q['notes']): ?><br><span class="hint"><?= e($q['notes']) ?></span><?php endif; ?></td>
    <td><?php if ($q['approval_status'] === 'PENDING'): ?>
      <form method="post" data-confirm="Approve and move <?= e($q['reservation_code']) ?> to <?= e(fmt_date($q['requested_date'])) ?>?"><?= csrf_field() ?><input type="hidden" name="action" value="approve"><input type="hidden" name="request_id" value="<?= (int) $q['request_id'] ?>">
        <button class="btn btn-success btn-small" type="submit"<?= $free ? '' : ' disabled title="Requested date is taken"' ?>>Approve</button></form>
      <details class="action"><summary>Reject</summary><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="reject"><input type="hidden" name="request_id" value="<?= (int) $q['request_id'] ?>">
        <label for="n<?= (int) $q['request_id'] ?>">Reason</label><input type="text" id="n<?= (int) $q['request_id'] ?>" name="notes" maxlength="255" required><button class="btn btn-danger btn-small" type="submit" style="margin-top:.4rem">Reject</button></form></details>
    <?php endif; ?></td>
  </tr>
<?php endforeach; ?></tbody></table><?php endif; ?>
<?= pager_links($pg) ?>
</div>
<?php require __DIR__ . '/../app/views/admin_footer.php'; ?>
