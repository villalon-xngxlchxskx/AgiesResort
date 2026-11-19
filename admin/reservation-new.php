<?php
require __DIR__ . '/../app/bootstrap.php';
$me = require_perm('reservations.create');
$packages = ReservationService::packages();
[$minDate, $maxDate] = ReservationService::window(true);
$errors = [];
$prefill = [];
if ($cid = int_in('customer_id')) {
    $prefill = q_one('SELECT full_name, email, contact_number, address FROM customers WHERE customer_id = ?', [$cid]) ?? [];
}
if (is_post()) {
    csrf_check();
    try {
        $res = ReservationService::create($_POST, $me);
        flash('success', 'Reservation ' . $res['reservation_code'] . ' created. Record the down payment to confirm it.');
        redirect('admin/reservation.php?id=' . $res['reservation_id']);
    } catch (ValidationError $e) {
        $errors = $e->errors;
    } catch (UserError $e) {
        $errors[] = $e->getMessage();
    }
}
$v = fn(string $k) => old($k, (string) ($prefill[$k] ?? ''));
$pageTitle = 'New reservation';
$active = 'reservations';
require __DIR__ . '/../app/views/admin_header.php';
?>
<p><a href="<?= e(url('admin/reservations.php')) ?>">← Reservations</a></p>
<h1>New reservation</h1>
<p class="muted">For phone, Messenger or walk-in requests. Same availability, capacity and pricing rules as the website. If the email matches an existing customer, the booking is added to that customer.</p>
<?php if ($errors): ?><div class="errors" role="alert"><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<form id="booking-form" method="post" class="panel" novalidate>
  <?= csrf_field() ?>
  <fieldset><legend>Area and session</legend>
    <div class="pkg-pick"><?php foreach ($packages as $p): ?>
      <label><input type="radio" name="package_id" value="<?= (int) $p['package_id'] ?>" <?= (int) old('package_id') === (int) $p['package_id'] ? 'checked' : '' ?> required
        data-rate="<?= e($p['rate']) ?>" data-max="<?= (int) $p['max_guests'] ?>" data-extra="<?= (int) $p['max_extra_guests'] ?>" data-fee="<?= e($p['additional_guest_fee']) ?>" data-dp="<?= e($p['down_payment']) ?>">
        <span><strong><?= e($p['area_name']) ?> · <?= e(type_short($p['booking_type'])) ?></strong><br><?= e(money($p['rate'])) ?> · up to <?= (int) $p['max_guests'] ?></span></label>
    <?php endforeach; ?></div>
  </fieldset>
  <div class="grid-3">
    <div class="field"><label for="booking_date">Date</label><input type="date" id="booking_date" name="booking_date" min="<?= e($minDate) ?>" max="<?= e($maxDate) ?>" value="<?= e(old('booking_date')) ?>" required></div>
    <div class="field"><label for="num_guests">Guests</label><input type="number" id="num_guests" name="num_guests" min="1" value="<?= e(old('num_guests')) ?>" required></div>
  </div>
  <p id="estimate" class="summary-box" aria-live="polite"></p>
  <div class="grid-2">
    <div class="field"><label for="full_name">Guest name</label><input type="text" id="full_name" name="full_name" maxlength="120" value="<?= e($v('full_name')) ?>" required></div>
    <div class="field"><label for="contact_number">Contact number</label><input type="tel" id="contact_number" name="contact_number" maxlength="20" value="<?= e($v('contact_number')) ?>" required></div>
    <div class="field"><label for="email">Email</label><input type="email" id="email" name="email" maxlength="120" value="<?= e($v('email')) ?>" required></div>
    <div class="field"><label for="address">Address</label><input type="text" id="address" name="address" maxlength="255" value="<?= e($v('address')) ?>"></div>
  </div>
  <div class="field"><label for="notes">Guest notes</label><textarea id="notes" name="notes" maxlength="1000"><?= e(old('notes')) ?></textarea></div>
  <button class="btn btn-success" type="submit">Create reservation</button>
  <a class="btn btn-ghost" href="<?= e(url('admin/reservations.php')) ?>">Cancel</a>
</form>
<?php require __DIR__ . '/../app/views/admin_footer.php'; ?>
