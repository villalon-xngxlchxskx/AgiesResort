<?php
require __DIR__ . '/../app/bootstrap.php';
$me = require_perm('customers.view');
// Business rule: a customer record is created only together with a reservation (staff use
// admin/reservation-new.php; guests use the public booking form). Standalone creation is not offered.
if (get('new') === '1' || post('action') === 'create') {
    flash('info', 'New customers are added by creating their reservation: enter the guest details below and the customer record is created with the booking.');
    redirect('admin/reservation-new.php');
}
$id = int_in('id');
$isNew = get('new') === '1' || post('action') === 'create';
$errors = [];

/** @return array [clean data, errors] */
function customer_input(?int $exceptId): array
{
    $d = [
        'full_name' => preg_replace('/\s+/', ' ', post('full_name')),
        'email' => mb_strtolower(post('email')),
        'contact_number' => normalize_phone(post('contact_number')),
        'address' => post('address'),
        'notes' => post('notes'),
    ];
    $err = [];
    if (!str_len_ok($d['full_name'], 2, 120)) $err[] = 'Enter the full name (2-120 characters).';
    if (!valid_email($d['email'])) $err[] = 'Enter a valid email address.';
    elseif (q_val('SELECT customer_id FROM customers WHERE email = ? AND customer_id <> ?', [$d['email'], $exceptId ?? 0])) $err[] = 'Another customer already uses this email.';
    if ($d['contact_number'] === null) $err[] = 'Enter a valid Philippine contact number.';
    if (mb_strlen($d['address']) > 255) $err[] = 'Address is too long.';
    if (mb_strlen($d['notes']) > 2000) $err[] = 'Notes are too long.';
    return [$d, $err];
}

if (is_post()) {
    csrf_check();
    $action = post('action');
    if ($action === 'create') {
        require_perm('customers.edit');
        [$d, $errors] = customer_input(null);
        if (!$errors) {
            q('INSERT INTO customers (full_name, email, contact_number, address, notes) VALUES (?,?,?,?,?)', [$d['full_name'], $d['email'], $d['contact_number'], $d['address'] ?: null, $d['notes'] ?: null]);
            $newId = (int) db()->lastInsertId();
            log_activity('Customers', 'Create', "Customer #$newId {$d['full_name']} added");
            flash('success', 'Customer added.');
            redirect('admin/customer.php?id=' . $newId);
        }
    } elseif ($action === 'update') {
        require_perm('customers.edit');
        [$d, $errors] = customer_input($id);
        if (!$errors) {
            q('UPDATE customers SET full_name = ?, email = ?, contact_number = ?, address = ?, notes = ? WHERE customer_id = ?',
              [$d['full_name'], $d['email'], $d['contact_number'], $d['address'] ?: null, $d['notes'] ?: null, $id]);
            log_activity('Customers', 'Update', "Customer #$id details updated");
            flash('success', 'Customer details saved.');
            redirect('admin/customer.php?id=' . $id);
        }
    } elseif ($action === 'toggle') {
        require_perm('customers.edit');
        $cur = q_val('SELECT status FROM customers WHERE customer_id = ?', [$id]);
        $new = $cur === 'ACTIVE' ? 'INACTIVE' : 'ACTIVE';
        q('UPDATE customers SET status = ? WHERE customer_id = ?', [$new, $id]);
        log_activity('Customers', 'Status', "Customer #$id set to $new");
        flash('success', $new === 'ACTIVE' ? 'Customer activated.' : 'Customer deactivated. They cannot book online until reactivated; history is kept.');
        redirect('admin/customer.php?id=' . $id);
    } elseif ($action === 'delete') {
        require_perm('customers.delete');
        if ((int) q_val('SELECT COUNT(*) FROM reservations WHERE customer_id = ?', [$id]) > 0) {
            flash('error', 'This customer has reservation history, so the record is kept. Deactivate it instead.');
            redirect('admin/customer.php?id=' . $id);
        }
        $name = q_val('SELECT full_name FROM customers WHERE customer_id = ?', [$id]);
        q('DELETE FROM customers WHERE customer_id = ?', [$id]);
        log_activity('Customers', 'Delete', "Customer #$id $name deleted (no reservations)");
        flash('success', 'Customer deleted.');
        redirect('admin/customers.php');
    }
}

$c = $id ? q_one('SELECT * FROM customers WHERE customer_id = ?', [$id]) : null;
if (!$isNew && !$c) {
    http_response_code(404);
    $pageTitle = 'Not found'; $active = 'customers';
    require __DIR__ . '/../app/views/admin_header.php';
    echo '<h1>Customer not found</h1><p><a href="' . e(url('admin/customers.php')) . '">Back to customers</a></p>';
    require __DIR__ . '/../app/views/admin_footer.php';
    exit;
}
$editing = $isNew || get('edit') === '1' || ($errors && post('action') === 'update');
if ($editing) {
    require_perm('customers.edit');
}
$val = fn(string $k) => is_post() ? old($k) : (string) ($c[$k] ?? '');
$pageTitle = $isNew ? 'Add customer' : $c['full_name'];
$active = 'customers';
require __DIR__ . '/../app/views/admin_header.php';
?>
<p class="no-print"><a href="<?= e(url('admin/customers.php')) ?>">← Customers</a></p>
<?php if ($errors): ?><div class="errors" role="alert"><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

<?php if ($editing): ?>
  <h1><?= $isNew ? 'Add customer' : 'Edit ' . e($c['full_name']) ?></h1>
  <form method="post" class="panel" action="<?= e(url('admin/customer.php' . ($isNew ? '' : '?id=' . $id))) ?>" novalidate>
    <?= csrf_field() ?><input type="hidden" name="action" value="<?= $isNew ? 'create' : 'update' ?>">
    <div class="grid-2">
      <div class="field"><label for="full_name">Full name *</label><input type="text" id="full_name" name="full_name" maxlength="120" value="<?= e($val('full_name')) ?>" required></div>
      <div class="field"><label for="email">Email *</label><input type="email" id="email" name="email" maxlength="120" value="<?= e($val('email')) ?>" required></div>
      <div class="field"><label for="contact_number">Contact number *</label><input type="tel" id="contact_number" name="contact_number" maxlength="20" value="<?= e($val('contact_number')) ?>" required></div>
      <div class="field"><label for="address">Address</label><input type="text" id="address" name="address" maxlength="255" value="<?= e($val('address')) ?>"></div>
    </div>
    <div class="field"><label for="notes">Staff notes <span class="hint">Internal only</span></label><textarea id="notes" name="notes" maxlength="2000"><?= e($val('notes')) ?></textarea></div>
    <button class="btn btn-success" type="submit"><?= $isNew ? 'Add customer' : 'Save changes' ?></button>
    <a class="btn btn-ghost" href="<?= e(url($isNew ? 'admin/customers.php' : 'admin/customer.php?id=' . $id)) ?>">Cancel</a>
  </form>
<?php else:
    $res = q_all('SELECT r.reservation_id, r.reservation_code, r.booking_date, r.booking_type, r.num_guests, r.status, r.payment_status, r.total_amount, r.amount_paid, r.reschedule_count, a.area_name
                    FROM reservations r JOIN resort_areas a ON a.area_id = r.area_id WHERE r.customer_id = ? ORDER BY r.booking_date DESC', [$id]);
    $sum = ['total' => count($res), 'upcoming' => 0, 'completed' => 0, 'ended' => 0, 'paid' => 0.0, 'value' => 0.0, 'resched' => 0];
    foreach ($res as $r) {
        if (in_array($r['status'], ReservationService::ACTIVE, true) && $r['booking_date'] >= date('Y-m-d')) $sum['upcoming']++;
        if ($r['status'] === 'COMPLETED') $sum['completed']++;
        if (in_array($r['status'], ['CANCELLED', 'DECLINED', 'EXPIRED'], true)) $sum['ended']++;
        if (in_array($r['status'], ['CONFIRMED', 'COMPLETED'], true)) $sum['value'] += (float) $r['total_amount'];
        $sum['paid'] += (float) $r['amount_paid'];
        $sum['resched'] += (int) $r['reschedule_count'];
    } ?>
  <div class="cal-head">
    <h1><?= e($c['full_name']) ?> <?= badge($c['status']) ?><?= $c['is_demo'] ? ' <span class="badge badge-demo">Demo data</span>' : '' ?></h1>
    <div class="actions no-print">
      <?php if (can('customers.edit')): ?><a class="btn btn-edit" href="<?= e(url('admin/customer.php?id=' . $id . '&edit=1')) ?>">Edit details</a><?php endif; ?>
      <?php if (can('reservations.create') && $c['status'] === 'ACTIVE'): ?><a class="btn btn-success" href="<?= e(url('admin/reservation-new.php?customer_id=' . $id)) ?>">+ New reservation</a><?php endif; ?>
      <button class="btn btn-ghost" type="button" data-print>Print</button>
    </div>
  </div>
  <div class="two-col">
    <section class="panel">
      <h2>Contact details</h2>
      <dl class="kv">
        <dt>Customer ID</dt><dd>C<?= str_pad((string) $id, 4, '0', STR_PAD_LEFT) ?></dd>
        <dt>Full name</dt><dd><?= e($c['full_name']) ?></dd>
        <dt>Contact number</dt><dd><?= e($c['contact_number']) ?></dd>
        <dt>Email</dt><dd><?= e($c['email']) ?></dd>
        <dt>Address</dt><dd><?= e($c['address'] ?: '—') ?></dd>
        <dt>Registered</dt><dd><?= e(fmt_dt($c['created_at'])) ?></dd>
        <dt>Last updated</dt><dd><?= e(fmt_dt($c['updated_at'])) ?></dd>
      </dl>
      <h2 style="margin-top:1rem">Staff notes</h2>
      <p><?= $c['notes'] ? nl2br(e($c['notes'])) : '<span class="muted">None</span>' ?></p>
    </section>
    <section class="panel">
      <h2>Booking summary</h2>
      <dl class="kv">
        <dt>Reservations</dt><dd><?= $sum['total'] ?></dd>
        <dt>Upcoming (active)</dt><dd><?= $sum['upcoming'] ?></dd>
        <dt>Completed stays</dt><dd><?= $sum['completed'] ?></dd>
        <dt>Cancelled / declined / expired</dt><dd><?= $sum['ended'] ?></dd>
        <dt>Reschedules used</dt><dd><?= $sum['resched'] ?></dd>
        <dt>Confirmed booking value</dt><dd><?= e(money($sum['value'])) ?></dd>
        <dt>Verified payments</dt><dd><?= e(money($sum['paid'])) ?></dd>
      </dl>
      <?php if (can('customers.edit')): ?>
      <form method="post" class="no-print" style="margin-top:1rem" data-confirm="<?= $c['status'] === 'ACTIVE' ? 'Deactivate this customer? They will not be able to book online.' : 'Reactivate this customer?' ?>">
        <?= csrf_field() ?><input type="hidden" name="action" value="toggle"><button class="btn btn-ghost btn-small" type="submit"><?= $c['status'] === 'ACTIVE' ? 'Deactivate customer' : 'Activate customer' ?></button></form>
      <?php endif; ?>
      <?php if (can('customers.delete') && $sum['total'] === 0): ?>
      <form method="post" class="no-print" style="margin-top:.5rem" data-confirm="Permanently delete this customer? This cannot be undone.">
        <?= csrf_field() ?><input type="hidden" name="action" value="delete"><button class="btn btn-danger btn-small" type="submit">Delete customer</button></form>
      <?php endif; ?>
    </section>
  </div>
  <section class="panel table-wrap">
    <h2>Reservation history</h2>
    <?php if (!$res): ?><div class="flash flash-warn">This customer has no reservation. Customers should only exist through a booking: create a reservation for them, or delete this record.</div><?php else: ?>
    <table><thead><tr><th>Code</th><th>Booking date</th><th>Area</th><th>Session</th><th class="num">Guests</th><th>Status</th><th>Payment</th><th class="num">Total</th><th class="num">Verified paid</th></tr></thead><tbody>
    <?php foreach ($res as $r): ?>
      <tr><td><a href="<?= e(url('admin/reservation.php?id=' . $r['reservation_id'])) ?>"><?= e($r['reservation_code']) ?></a></td><td><?= e(fmt_date($r['booking_date'])) ?></td><td><?= e($r['area_name']) ?></td><td><?= e(type_short($r['booking_type'])) ?></td><td class="num"><?= (int) $r['num_guests'] ?></td><td><?= badge($r['status']) ?></td><td><?= badge($r['payment_status']) ?></td><td class="num"><?= e(money($r['total_amount'])) ?></td><td class="num"><?= e(money($r['amount_paid'])) ?></td></tr>
    <?php endforeach; ?></tbody></table><?php endif; ?>
  </section>
<?php endif; ?>
<?php require __DIR__ . '/../app/views/admin_footer.php'; ?>
