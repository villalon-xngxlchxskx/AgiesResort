<?php
require __DIR__ . '/../app/bootstrap.php';
$me = require_perm('reservations.view');
$id = int_in('id');

if (is_post()) {
    csrf_check();
    $action = post('action');
    $adminId = (int) $me['admin_id'];
    try {
        switch ($action) {
            case 'confirm':
                require_perm('reservations.manage');
                ReservationService::transition($id, 'CONFIRMED', $adminId, post('remarks') ?: 'Confirmed by staff');
                flash('success', 'Reservation confirmed.');
                break;
            case 'decline':
                require_perm('reservations.manage');
                ReservationService::transition($id, 'DECLINED', $adminId, post('reason'));
                flash('success', 'Reservation declined. The slot is open again.');
                break;
            case 'cancel':
                require_perm('reservations.manage');
                ReservationService::transition($id, 'CANCELLED', $adminId, post('reason'));
                flash('success', 'Reservation cancelled. The slot is open again; verified payments are kept as non-refundable.');
                break;
            case 'complete':
                require_perm('reservations.manage');
                ReservationService::transition($id, 'COMPLETED', $adminId, post('remarks') ?: 'Stay completed');
                flash('success', 'Marked as completed.');
                break;
            case 'record_payment':
                require_perm('payments.verify');
                tx(function () use ($id, $adminId) {
                    $pid = PaymentService::submit($id, $_POST, 'STAFF', $adminId);
                    if (post('verify_now') === '1') {
                        PaymentService::verify($pid, $adminId, 'Recorded and verified by staff');
                    }
                });
                flash('success', post('verify_now') === '1' ? 'Payment recorded and verified.' : 'Payment recorded; it is waiting for verification.');
                break;
            case 'verify_payment':
                require_perm('payments.verify');
                PaymentService::verify(int_in('payment_id'), $adminId, post('notes'));
                flash('success', 'Payment verified.');
                break;
            case 'reject_payment':
                require_perm('payments.verify');
                PaymentService::reject(int_in('payment_id'), $adminId, post('reason'));
                flash('success', 'Payment rejected.');
                break;
            case 'reverse_payment':
                require_perm('payments.reverse');
                PaymentService::reverse(int_in('payment_id'), $adminId, post('reason'));
                flash('success', 'Payment reversed. Record the correct amount if money was actually received.');
                break;
            case 'reschedule_request':
                require_perm('reschedules.manage');
                RescheduleService::request($id, post('new_date'), post('reason'), 'STAFF', $adminId);
                flash('success', 'Rescheduling request recorded. Approve or reject it below or on the Rescheduling page.');
                break;
            case 'approve_reschedule':
                require_perm('reschedules.manage');
                RescheduleService::approve(int_in('request_id'), $adminId, post('notes'));
                flash('success', 'Reschedule approved and the booking date updated.');
                break;
            case 'reject_reschedule':
                require_perm('reschedules.manage');
                RescheduleService::reject(int_in('request_id'), $adminId, post('notes'));
                flash('success', 'Reschedule request rejected.');
                break;
            case 'save_notes':
                require_perm('reservations.manage');
                $notes = mb_substr(post('admin_notes'), 0, 2000);
                q('UPDATE reservations SET admin_notes = ? WHERE reservation_id = ?', [$notes ?: null, $id]);
                log_activity('Reservations', 'Notes', 'Updated staff notes on reservation #' . $id);
                flash('success', 'Staff notes saved.');
                break;
            case 'clear_review':
                require_perm('reservations.manage');
                $why = post('review_resolution');
                if (!str_len_ok($why, 3, 200)) {
                    throw new UserError('Describe how the issue was resolved (3-200 characters).');
                }
                tx(function () use ($id, $why) {
                    $r = ReservationService::lock($id);
                    PaymentService::recompute($id);
                    q('UPDATE reservations SET needs_review = 0, admin_notes = CONCAT_WS("\n", admin_notes, ?) WHERE reservation_id = ?',
                      ['[Review resolved ' . date('Y-m-d') . '] ' . $r['review_note'] . ' -> ' . $why, $id]);
                    log_activity('Reservations', 'Review resolved', $r['reservation_code'] . ': ' . $why);
                });
                flash('success', 'Review flag cleared.');
                break;
            default:
                flash('error', 'Unknown action.');
        }
    } catch (UserError $e) {
        flash('error', $e->getMessage());
    }
    redirect('admin/reservation.php?id=' . $id);
}

ReservationService::expireHolds();
$r = ReservationService::find($id);
if (!$r) {
    http_response_code(404);
    $pageTitle = 'Not found';
    $active = 'reservations';
    require __DIR__ . '/../app/views/admin_header.php';
    echo '<h1>Reservation not found</h1><p><a href="' . e(url('admin/reservations.php')) . '">Back to reservations</a></p>';
    require __DIR__ . '/../app/views/admin_footer.php';
    exit;
}
$payments = PaymentService::forReservation($id);
$sums = PaymentService::sums($id);
$remaining = round((float) $r['total_amount'] - $sums['verified'] - $sums['pending'], 2);
$history = ReservationService::history($id);
$requests = RescheduleService::forReservation($id);
$rsWhy = RescheduleService::ineligibility($r);
$st = $r['status'];
$can = fn(string $to) => ReservationService::canTransition($st, $to);
$pageTitle = $r['reservation_code'];
$active = 'reservations';
require __DIR__ . '/../app/views/admin_header.php';
?>
<p class="no-print"><a href="<?= e(url('admin/reservations.php')) ?>">← Reservations</a></p>
<div class="cal-head">
  <h1><?= e($r['reservation_code']) ?> <?= badge($st) ?> <?= badge($r['payment_status']) ?><?= $r['is_demo'] ? ' <span class="badge badge-demo">Demo data</span>' : '' ?></h1>
  <button class="btn btn-ghost no-print" type="button" data-print>Print</button>
</div>
<?php if ($r['needs_review']): ?>
  <div class="flash flash-warn"><strong>Needs review:</strong> <?= e($r['review_note']) ?>
    <?php if (can('reservations.manage')): ?>
    <form method="post" class="toolbar" style="margin-top:.6rem"><?= csrf_field() ?><input type="hidden" name="action" value="clear_review">
      <div class="field grow"><label for="review_resolution">How was it resolved?</label><input type="text" id="review_resolution" name="review_resolution" maxlength="200" required></div>
      <button class="btn btn-small" type="submit">Clear flag</button></form>
    <?php endif; ?>
  </div>
<?php endif; ?>

<div class="two-col">
  <div>
    <section class="panel">
      <h2>Booking</h2>
      <dl class="kv">
        <dt>Area</dt><dd><?= e($r['area_name']) ?></dd>
        <dt>Package</dt><dd><?= e($r['package_name']) ?></dd>
        <dt>Session</dt><dd><?= e(type_label($r['booking_type'])) ?></dd>
        <dt>Date</dt><dd><?= e(fmt_date($r['booking_date'], 'l, F j, Y')) ?> <span class="muted">(<?= e(fmt_dt($r['start_at'])) ?> – <?= e(fmt_dt($r['end_at'])) ?>)</span></dd>
        <dt>Guests</dt><dd><?= (int) $r['num_guests'] ?><?= (int) $r['extra_guests'] ? ' (' . (int) $r['extra_guests'] . ' extra)' : '' ?></dd>
        <dt>Rate (snapshot)</dt><dd><?= e(money($r['base_rate'])) ?><?= (float) $r['additional_fee'] > 0 ? ' + ' . e(money($r['additional_fee'])) . ' extra guests' : '' ?></dd>
        <dt>Total</dt><dd><strong><?= e(money($r['total_amount'])) ?></strong></dd>
        <dt>Down payment</dt><dd><?= e(money($r['down_payment'])) ?></dd>
        <dt>Verified paid</dt><dd><?= e(money($sums['verified'])) ?><?= $sums['pending'] > 0 ? ' · ' . e(money($sums['pending'])) . ' awaiting verification' : '' ?></dd>
        <dt>Balance</dt><dd><?= e(money((float) $r['total_amount'] - $sums['verified'])) ?></dd>
        <?php if ($st === 'PENDING'): ?><dt>Hold until</dt><dd><?= e(fmt_dt($r['hold_expires_at'])) ?></dd><?php endif; ?>
        <dt>Reschedules used</dt><dd><?= (int) $r['reschedule_count'] ?> of <?= setting_int('reschedule_max_count', 1) ?></dd>
        <dt>Source</dt><dd><?= e(ucfirst(strtolower($r['source']))) ?><?= $r['created_by_name'] ? ' by ' . e($r['created_by_name']) : '' ?>, <?= e(fmt_dt($r['created_at'])) ?></dd>
        <?php if ($r['cancelled_at']): ?><dt>Ended</dt><dd><?= e(fmt_dt($r['cancelled_at'])) ?><?= $r['cancelled_by_name'] ? ' by ' . e($r['cancelled_by_name']) : '' ?> — <?= e($r['cancel_reason']) ?></dd><?php endif; ?>
        <?php if ($r['notes']): ?><dt>Guest notes</dt><dd><?= nl2br(e($r['notes'])) ?></dd><?php endif; ?>
      </dl>
    </section>

    <section class="panel">
      <h2>Payments</h2>
      <?php if (!$payments): ?><p class="muted">No payments yet.</p><?php else: ?>
      <div class="table-wrap"><table>
        <thead><tr><th>#</th><th>Type</th><th>Method</th><th>Reference</th><th class="num">Amount</th><th>Paid on</th><th>Status</th><th class="no-print">Action</th></tr></thead>
        <tbody>
        <?php foreach ($payments as $p): ?>
          <tr>
            <td><?= (int) $p['payment_id'] ?></td><td><?= e($p['payment_type']) ?></td><td><?= e($p['payment_method']) ?><br><span class="hint"><?= $p['submitted_by'] === 'STAFF' ? 'recorded by ' . e($p['recorded_by_name'] ?? 'staff') : 'from customer' ?></span></td>
            <td><?= e($p['reference_number'] ?? '—') ?><?php if ($p['proof_path'] && can('payments.view')): ?><br><a href="<?= e(url('admin/proof.php?id=' . $p['payment_id'])) ?>" target="_blank" rel="noopener">View receipt</a><?php endif; ?></td>
            <td class="num"><?= e(money($p['amount_paid'])) ?></td><td><?= e(fmt_date($p['payment_date'])) ?></td>
            <td><?= badge($p['payment_status']) ?><?php if ($p['verified_at']): ?><br><span class="hint"><?= e($p['verified_by_name'] ?? '') ?>, <?= e(fmt_dt($p['verified_at'])) ?></span><?php endif; ?><?php if ($p['rejection_reason']): ?><br><span class="hint"><?= e($p['rejection_reason']) ?></span><?php endif; ?></td>
            <td class="no-print">
              <?php if ($p['payment_status'] === 'PENDING VERIFICATION' && can('payments.verify')): ?>
                <form method="post" data-confirm="Verify <?= e(money($p['amount_paid'])) ?>? Only verify after checking the money arrived."><?= csrf_field() ?>
                  <input type="hidden" name="action" value="verify_payment"><input type="hidden" name="payment_id" value="<?= (int) $p['payment_id'] ?>">
                  <button class="btn btn-success btn-small" type="submit">Verify</button></form>
                <details class="action"><summary>Reject</summary>
                  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="reject_payment"><input type="hidden" name="payment_id" value="<?= (int) $p['payment_id'] ?>">
                    <label for="rr<?= (int) $p['payment_id'] ?>">Reason</label><input type="text" id="rr<?= (int) $p['payment_id'] ?>" name="reason" maxlength="255" required>
                    <button class="btn btn-small btn-danger" type="submit" style="margin-top:.4rem">Reject payment</button></form>
                </details>
              <?php endif; ?>
              <?php if ($p['payment_status'] === 'VERIFIED' && can('payments.reverse')): ?>
                <details class="action"><summary>Reverse</summary>
                  <form method="post" data-confirm="Reverse this verified payment? Use only to correct a recording mistake."><?= csrf_field() ?><input type="hidden" name="action" value="reverse_payment"><input type="hidden" name="payment_id" value="<?= (int) $p['payment_id'] ?>">
                    <label for="rv<?= (int) $p['payment_id'] ?>">Why (e.g. amount typed wrong)</label><input type="text" id="rv<?= (int) $p['payment_id'] ?>" name="reason" maxlength="200" required>
                    <button class="btn btn-small btn-danger" type="submit" style="margin-top:.4rem">Reverse payment</button></form>
                </details>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php endif; ?>
      <?php if (can('payments.verify') && in_array($st, array_merge(ReservationService::ACTIVE, ['COMPLETED']), true) && $remaining > 0): ?>
        <details class="action no-print"><summary>Record a payment received</summary>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="record_payment">
            <div class="grid-3">
              <div class="field"><label for="amount">Amount (₱) <span class="hint">Remaining <?= e(money($remaining)) ?></span></label><input type="text" inputmode="decimal" id="amount" name="amount" required></div>
              <div class="field"><label for="payment_method">Method</label><select id="payment_method" name="payment_method" required><?php foreach (PaymentService::METHODS as $m): ?><option><?= e($m) ?></option><?php endforeach; ?></select></div>
              <div class="field"><label for="reference_number">Reference <span class="hint">Optional for cash</span></label><input type="text" id="reference_number" name="reference_number" maxlength="80"></div>
              <div class="field"><label for="payment_date">Date received</label><input type="date" id="payment_date" name="payment_date" max="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>" required></div>
            </div>
            <div class="field"><label for="pnotes">Notes</label><input type="text" id="pnotes" name="notes" maxlength="255"></div>
            <label class="check"><input type="checkbox" name="verify_now" value="1"> I have the money in hand / confirmed it in the account — verify now</label>
            <button class="btn btn-success" type="submit" style="margin-top:.6rem">Save payment</button>
          </form>
        </details>
      <?php endif; ?>
    </section>

    <section class="panel">
      <h2>Status history</h2>
      <ul class="timeline"><?php foreach ($history as $h): ?>
        <li><strong><?= e($h['old_status'] ? ucwords(strtolower($h['old_status'])) . ' → ' : '') ?><?= e(ucwords(strtolower($h['new_status']))) ?></strong>
          <span class="muted"><?= e(fmt_dt($h['changed_at'])) ?> · <?= e($h['by_name'] ?? 'Website / system') ?></span><?php if ($h['remarks']): ?><br><?= e($h['remarks']) ?><?php endif; ?></li>
      <?php endforeach; ?></ul>
    </section>
  </div>

  <div>
    <section class="panel">
      <h2>Customer</h2>
      <dl class="kv">
        <dt>Name on booking</dt><dd><?= e($r['guest_name']) ?></dd>
        <dt>Contact on booking</dt><dd><?= e($r['guest_contact']) ?></dd>
        <dt>Customer record</dt><dd><a href="<?= e(url('admin/customer.php?id=' . $r['customer_id'])) ?>"><?= e($r['customer_name']) ?></a> <?= $r['customer_status'] !== 'ACTIVE' ? badge($r['customer_status']) : '' ?></dd>
        <dt>Email</dt><dd><?= e($r['customer_email']) ?></dd>
      </dl>
    </section>

    <?php if (can('reservations.manage')): ?>
    <section class="panel no-print">
      <h2>Actions</h2>
      <?php if ($can('CONFIRMED')): ?>
        <form method="post" data-confirm="Confirm this reservation?"><?= csrf_field() ?><input type="hidden" name="action" value="confirm">
          <button class="btn btn-success" type="submit"<?= $sums['verified'] + 0.004 < (float) $r['down_payment'] ? ' disabled title="Verify the down payment first"' : '' ?>>Confirm reservation</button>
          <?php if ($sums['verified'] + 0.004 < (float) $r['down_payment']): ?><p class="hint">Available after the down payment is verified. Verifying it confirms the booking automatically.</p><?php endif; ?>
        </form>
      <?php endif; ?>
      <?php if ($can('COMPLETED')): ?>
        <form method="post" data-confirm="Mark this stay as completed?" style="margin-top:.6rem"><?= csrf_field() ?><input type="hidden" name="action" value="complete">
          <button class="btn btn-ghost" type="submit"<?= $r['booking_date'] > date('Y-m-d') ? ' disabled title="Available from the booking date"' : '' ?>>Mark stay completed</button></form>
      <?php endif; ?>
      <?php if ($can('DECLINED')): ?>
        <details class="action"><summary>Decline request</summary>
          <form method="post" data-confirm="Decline this reservation? This cannot be undone."><?= csrf_field() ?><input type="hidden" name="action" value="decline">
            <label for="dreason">Reason (shown to the guest)</label><input type="text" id="dreason" name="reason" maxlength="255" required>
            <button class="btn btn-danger btn-small" type="submit" style="margin-top:.4rem">Decline</button></form></details>
      <?php endif; ?>
      <?php if ($can('CANCELLED')): ?>
        <details class="action"><summary>Cancel reservation</summary>
          <form method="post" data-confirm="Cancel this reservation? The slot will open again. This cannot be undone."><?= csrf_field() ?><input type="hidden" name="action" value="cancel">
            <label for="creason">Reason (shown to the guest)</label><input type="text" id="creason" name="reason" maxlength="255" required>
            <p class="hint">Verified down payments stay recorded (non-refundable policy).</p>
            <button class="btn btn-danger btn-small" type="submit">Cancel reservation</button></form></details>
      <?php endif; ?>
      <?php if (empty(ReservationService::TRANSITIONS[$st])): ?><p class="muted">This reservation is <?= e(strtolower($st)) ?>; no further status changes are possible.</p><?php endif; ?>
    </section>
    <?php endif; ?>

    <section class="panel">
      <h2>Rescheduling</h2>
      <?php foreach ($requests as $q): ?>
        <div class="slot" style="margin-bottom:.6rem">
          <p><?= badge($q['approval_status']) ?> <?= e(fmt_date($q['original_date'])) ?> → <strong><?= e(fmt_date($q['requested_date'])) ?></strong><br>
          <span class="hint">Requested <?= e(fmt_date($q['request_date'])) ?> via <?= e(strtolower($q['requested_via'])) ?>: <?= e($q['reason']) ?></span>
          <?php if ($q['approval_status'] !== 'PENDING'): ?><br><span class="hint">Decided <?= e(fmt_dt($q['approval_date'])) ?> by <?= e($q['decided_by'] ?? '—') ?><?= $q['notes'] ? ': ' . e($q['notes']) : '' ?></span><?php endif; ?></p>
          <?php if ($q['approval_status'] === 'PENDING' && can('reschedules.manage')): ?>
            <form method="post" class="inline" data-confirm="Approve and move the booking to <?= e(fmt_date($q['requested_date'])) ?>?"><?= csrf_field() ?><input type="hidden" name="action" value="approve_reschedule"><input type="hidden" name="request_id" value="<?= (int) $q['request_id'] ?>"><button class="btn btn-success btn-small" type="submit">Approve</button></form>
            <details class="action"><summary>Reject</summary><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="reject_reschedule"><input type="hidden" name="request_id" value="<?= (int) $q['request_id'] ?>">
              <label for="rn<?= (int) $q['request_id'] ?>">Reason</label><input type="text" id="rn<?= (int) $q['request_id'] ?>" name="notes" maxlength="255" required><button class="btn btn-danger btn-small" type="submit" style="margin-top:.4rem">Reject request</button></form></details>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
      <?php if ($rsWhy): ?><p class="muted"><?= e($rsWhy) ?></p>
      <?php elseif (can('reschedules.manage')): [$mn, $mx] = ReservationService::window(true); ?>
        <details class="action no-print"><summary>Request a new date for the guest</summary>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="reschedule_request">
            <div class="field"><label for="new_date">New date</label><input type="date" id="new_date" name="new_date" min="<?= e($mn) ?>" max="<?= e($mx) ?>" required></div>
            <div class="field"><label for="rsreason">Guest's reason</label><input type="text" id="rsreason" name="reason" maxlength="255" required></div>
            <button class="btn btn-success btn-small" type="submit">Record request</button></form></details>
      <?php endif; ?>
    </section>

    <section class="panel">
      <h2>Staff notes</h2>
      <?php if (can('reservations.manage')): ?>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="save_notes">
          <label for="admin_notes" class="sr-only">Staff notes</label><textarea id="admin_notes" name="admin_notes" maxlength="2000"><?= e($r['admin_notes']) ?></textarea>
          <button class="btn btn-edit btn-small" type="submit" style="margin-top:.4rem">Save notes</button><span class="hint">Never shown to the guest.</span></form>
      <?php else: ?><p><?= nl2br(e($r['admin_notes'] ?? '—')) ?></p><?php endif; ?>
    </section>
  </div>
</div>
<?php require __DIR__ . '/../app/views/admin_footer.php'; ?>
