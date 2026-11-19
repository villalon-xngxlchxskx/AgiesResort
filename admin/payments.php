<?php
require __DIR__ . '/../app/bootstrap.php';
$me = require_perm('payments.view');
if (is_post()) {
    csrf_check();
    require_perm('payments.verify');
    try {
        if (post('action') === 'verify') {
            PaymentService::verify(int_in('payment_id'), (int) $me['admin_id'], post('notes'));
            flash('success', 'Payment verified.');
        } elseif (post('action') === 'reject') {
            PaymentService::reject(int_in('payment_id'), (int) $me['admin_id'], post('reason'));
            flash('success', 'Payment rejected.');
        }
    } catch (UserError $e) {
        flash('error', $e->getMessage());
    }
    redirect('admin/payments.php?' . http_build_query(array_intersect_key($_GET, array_flip(['status', 'q', 'page']))));
}
$status = get('status', 'PENDING VERIFICATION');
$qtext = search_term();
$where = ['1=1'];
$p = [];
if (in_array($status, ['PENDING VERIFICATION', 'VERIFIED', 'REJECTED'], true)) { $where[] = 'y.payment_status = ?'; $p[] = $status; }
if ($qtext !== '') {
    $like = '%' . addcslashes($qtext, '%_\\') . '%';
    $where[] = '(r.reservation_code LIKE ? OR r.guest_name LIKE ? OR y.reference_number LIKE ?)';
    array_push($p, $like, $like, $like);
}
$w = implode(' AND ', $where);
$total = (int) q_val("SELECT COUNT(*) FROM payments y JOIN reservations r ON r.reservation_id = y.reservation_id WHERE $w", $p);
$pg = pager($total, 25);
$rows = q_all("SELECT y.*, r.reservation_code, r.guest_name, r.status AS res_status, r.total_amount, r.down_payment, r.booking_date, a.full_name AS decided_by
                 FROM payments y JOIN reservations r ON r.reservation_id = y.reservation_id LEFT JOIN admins a ON a.admin_id = y.verified_by
                WHERE $w ORDER BY y.created_at " . ($status === 'PENDING VERIFICATION' ? 'ASC' : 'DESC') . " LIMIT {$pg['per']} OFFSET {$pg['offset']}", $p);
$pageTitle = 'Payments';
$active = 'payments';
require __DIR__ . '/../app/views/admin_header.php';
?>
<h1>Payments</h1>
<p class="muted">Check each claim against the resort's GCash/bank records or cash on hand before verifying. Verifying the down payment confirms the reservation automatically.</p>
<form class="toolbar panel" method="get">
  <div class="field"><label for="status">Status</label><select id="status" name="status">
    <?php foreach (['PENDING VERIFICATION' => 'Awaiting verification', 'VERIFIED' => 'Verified', 'REJECTED' => 'Rejected', 'ALL' => 'All'] as $k => $l): ?><option value="<?= e($k) ?>"<?= $status === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
  <div class="field grow"><label for="q">Search</label><input type="search" id="q" name="q" maxlength="60" value="<?= e($qtext) ?>" placeholder="Reservation code, guest or reference"></div>
  <button class="btn" type="submit">Apply</button><a class="btn btn-ghost" href="<?= e(url('admin/payments.php')) ?>">Reset</a>
</form>
<div class="panel table-wrap">
<?php if (!$rows): ?><p><?= $status === 'PENDING VERIFICATION' ? 'Nothing waiting for verification.' : 'No payments match.' ?></p><?php else: ?>
<table><thead><tr><th>#</th><th>Reservation</th><th>Type</th><th>Method / reference</th><th class="num">Amount</th><th>Paid on</th><th>Status</th><th>Action</th></tr></thead><tbody>
<?php foreach ($rows as $y): ?>
  <tr>
    <td><?= (int) $y['payment_id'] ?><?= $y['is_demo'] ? ' <span class="badge badge-demo">Demo</span>' : '' ?></td>
    <td><a href="<?= e(url('admin/reservation.php?id=' . $y['reservation_id'])) ?>"><?= e($y['reservation_code']) ?></a><br><span class="hint"><?= e($y['guest_name']) ?> · stay <?= e(fmt_date($y['booking_date'])) ?> · <?= e(strtolower($y['res_status'])) ?></span></td>
    <td><?= e($y['payment_type']) ?><br><span class="hint">DP <?= e(money($y['down_payment'])) ?> of <?= e(money($y['total_amount'])) ?></span></td>
    <td><?= e($y['payment_method']) ?><br><?= e($y['reference_number'] ?? '—') ?><?php if ($y['proof_path']): ?> · <a href="<?= e(url('admin/proof.php?id=' . $y['payment_id'])) ?>" target="_blank" rel="noopener">receipt</a><?php endif; ?></td>
    <td class="num"><?= e(money($y['amount_paid'])) ?></td><td><?= e(fmt_date($y['payment_date'])) ?></td>
    <td><?= badge($y['payment_status']) ?><?php if ($y['decided_by']): ?><br><span class="hint"><?= e($y['decided_by']) ?>, <?= e(fmt_dt($y['verified_at'])) ?></span><?php endif; ?><?php if ($y['rejection_reason']): ?><br><span class="hint"><?= e($y['rejection_reason']) ?></span><?php endif; ?></td>
    <td><?php if ($y['payment_status'] === 'PENDING VERIFICATION' && can('payments.verify')): ?>
      <form method="post" data-confirm="Verify <?= e(money($y['amount_paid'])) ?> for <?= e($y['reservation_code']) ?>?"><?= csrf_field() ?><input type="hidden" name="action" value="verify"><input type="hidden" name="payment_id" value="<?= (int) $y['payment_id'] ?>"><button class="btn btn-success btn-small" type="submit">Verify</button></form>
      <details class="action"><summary>Reject</summary><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="reject"><input type="hidden" name="payment_id" value="<?= (int) $y['payment_id'] ?>">
        <label for="r<?= (int) $y['payment_id'] ?>">Reason</label><input type="text" id="r<?= (int) $y['payment_id'] ?>" name="reason" maxlength="255" required><button class="btn btn-danger btn-small" type="submit" style="margin-top:.4rem">Reject</button></form></details>
    <?php endif; ?></td>
  </tr>
<?php endforeach; ?></tbody></table><?php endif; ?>
<?= pager_links($pg) ?>
</div>
<?php require __DIR__ . '/../app/views/admin_footer.php'; ?>
