<?php
require __DIR__ . '/../app/bootstrap.php';
$me = require_perm('catalog.manage');

function money_in(string $k, float $max = 9999999): ?float
{
    $v = str_replace([',', '₱', ' '], '', post($k));
    return preg_match('/^\d{1,7}(\.\d{1,2})?$/', $v) && (float) $v <= $max ? round((float) $v, 2) : null;
}
function int_post(string $k, int $min, int $max): ?int
{
    $v = post($k);
    return preg_match('/^\d{1,5}$/', $v) && (int) $v >= $min && (int) $v <= $max ? (int) $v : null;
}

if (is_post()) {
    csrf_check();
    $action = post('action');
    try {
        if ($action === 'update_area') {
            $aid = int_in('area_id');
            $area = q_one('SELECT * FROM resort_areas WHERE area_id = ?', [$aid]) ?? throw new UserError('Area not found.');
            $name = post('area_name');
            $cap = int_post('capacity', 1, 999);
            $sort = int_post('sort_order', 0, 999) ?? 0;
            $status = post('status') === 'INACTIVE' ? 'INACTIVE' : 'ACTIVE';
            if (!str_len_ok($name, 2, 80)) throw new UserError('Area name must be 2-80 characters.');
            if ($cap === null) throw new UserError('Capacity must be a whole number from 1 to 999.');
            $maxPkg = (int) q_val('SELECT COALESCE(MAX(max_guests),0) FROM packages WHERE area_id = ?', [$aid]);
            if ($cap < $maxPkg) throw new UserError("Capacity cannot be lower than a package's guest limit ($maxPkg). Lower the package limit first.");
            if (mb_strlen(post('description')) > 2000) throw new UserError('Description is too long.');
            $img = $area['main_image'];
            if (has_upload('main_image')) {
                $img = store_uploaded_image($_FILES['main_image']);
            }
            try {
                q('UPDATE resort_areas SET area_name = ?, description = ?, capacity = ?, status = ?, sort_order = ?, main_image = ? WHERE area_id = ?',
                  [$name, post('description') ?: null, $cap, $status, $sort, $img, $aid]);
            } catch (PDOException $e) {
                if ($img !== $area['main_image']) delete_uploaded_file($img);
                if (is_duplicate_key($e)) throw new UserError('Another area already has that name.');
                throw $e;
            }
            if ($img !== $area['main_image']) delete_image_if_unused($area['main_image']); // kept if a gallery photo still uses it
            log_activity('Areas', 'Update', "Updated {$name}");
            $future = (int) q_val("SELECT COUNT(*) FROM reservations WHERE area_id = ? AND status IN ('PENDING','PAYMENT PENDING','CONFIRMED') AND booking_date >= CURDATE()", [$aid]);
            flash('success', 'Area saved.' . ($status === 'INACTIVE' && $future ? " It is hidden from booking; $future existing future reservation(s) are unchanged." : ''));
        } elseif ($action === 'update_package') {
            $pid = int_in('package_id');
            $pkg = q_one('SELECT p.*, a.capacity FROM packages p JOIN resort_areas a ON a.area_id = p.area_id WHERE p.package_id = ?', [$pid]) ?? throw new UserError('Package not found.');
            $d = [
                'package_name' => post('package_name'), 'rate' => money_in('rate'), 'max_guests' => int_post('max_guests', 1, 999),
                'additional_guest_fee' => money_in('additional_guest_fee', 100000), 'max_extra_guests' => int_post('max_extra_guests', 0, 200),
                'down_payment' => money_in('down_payment'), 'description' => post('description'),
                'status' => post('status') === 'INACTIVE' ? 'INACTIVE' : 'ACTIVE',
            ];
            if (!str_len_ok($d['package_name'], 2, 120)) throw new UserError('Package name must be 2-120 characters.');
            foreach (['rate' => 'Rate', 'max_guests' => 'Guest limit', 'additional_guest_fee' => 'Extra guest fee', 'max_extra_guests' => 'Extra guests allowed', 'down_payment' => 'Down payment'] as $k => $label) {
                if ($d[$k] === null) throw new UserError("$label must be a valid number.");
            }
            if ($d['max_guests'] > (int) $pkg['capacity']) throw new UserError("Guest limit cannot exceed the area's capacity ({$pkg['capacity']}).");
            if ($d['down_payment'] > $d['rate']) throw new UserError('Down payment cannot be more than the rate.');
            if (mb_strlen($d['description']) > 255) throw new UserError('Description is too long (255 max).');
            q('UPDATE packages SET package_name = ?, rate = ?, max_guests = ?, additional_guest_fee = ?, max_extra_guests = ?, down_payment = ?, description = ?, status = ? WHERE package_id = ?',
              [$d['package_name'], $d['rate'], $d['max_guests'], $d['additional_guest_fee'], $d['max_extra_guests'], $d['down_payment'], $d['description'] ?: null, $d['status'], $pid]);
            $changes = [];
            foreach (['rate', 'max_guests', 'additional_guest_fee', 'max_extra_guests', 'down_payment', 'status'] as $k) {
                if ((string) $pkg[$k] !== (string) $d[$k] && (float) $pkg[$k] != (float) $d[$k]) $changes[] = "$k {$pkg[$k]} -> {$d[$k]}";
            }
            log_activity('Packages', 'Update', $d['package_name'] . ($changes ? ': ' . implode(', ', $changes) : ''));
            flash('success', 'Package saved. Existing reservations keep the price they were booked at.');
        } elseif ($action === 'add_inclusion') {
            $pid = int_in('package_id');
            $text = post('inclusion_text');
            if (!str_len_ok($text, 2, 255)) throw new UserError('Inclusion text must be 2-255 characters.');
            if (!q_val('SELECT package_id FROM packages WHERE package_id = ?', [$pid])) throw new UserError('Package not found.');
            $next = (int) q_val('SELECT COALESCE(MAX(sort_order),0) + 1 FROM package_inclusions WHERE package_id = ?', [$pid]);
            q('INSERT INTO package_inclusions (package_id, inclusion_text, sort_order) VALUES (?,?,?)', [$pid, $text, $next]);
            log_activity('Packages', 'Inclusion add', "Package #$pid: $text");
            flash('success', 'Inclusion added.');
        } elseif ($action === 'update_inclusion') {
            $iid = int_in('inclusion_id');
            $text = post('inclusion_text');
            $sort = int_post('sort_order', 0, 999) ?? 0;
            if (!str_len_ok($text, 2, 255)) throw new UserError('Inclusion text must be 2-255 characters.');
            q('UPDATE package_inclusions SET inclusion_text = ?, sort_order = ? WHERE inclusion_id = ?', [$text, $sort, $iid]);
            log_activity('Packages', 'Inclusion edit', "Inclusion #$iid: $text");
            flash('success', 'Inclusion saved.');
        } elseif ($action === 'delete_inclusion') {
            $iid = int_in('inclusion_id');
            $text = q_val('SELECT inclusion_text FROM package_inclusions WHERE inclusion_id = ?', [$iid]);
            q('DELETE FROM package_inclusions WHERE inclusion_id = ?', [$iid]);
            log_activity('Packages', 'Inclusion delete', "Inclusion #$iid: $text");
            flash('success', 'Inclusion removed.');
        }
    } catch (UserError $e) {
        flash('error', $e->getMessage());
    }
    redirect('admin/areas.php#' . (preg_replace('/[^a-z0-9-]/', '', post('anchor')) ?: 'top'));
}

$areas = q_all('SELECT * FROM resort_areas ORDER BY sort_order, area_id');
$packages = ReservationService::packages(false);
$incl = [];
foreach (q_all('SELECT * FROM package_inclusions ORDER BY package_id, sort_order, inclusion_id') as $i) {
    $incl[$i['package_id']][] = $i;
}
$pageTitle = 'Areas & packages';
$active = 'catalog';
require __DIR__ . '/../app/views/admin_header.php';
?>
<h1 id="top">Areas &amp; packages</h1>
<p class="muted">Changes appear on the website immediately. Price changes apply to new bookings only; each reservation keeps the rate it was booked at.
“Extra guests allowed” is 0 (strict capacity) until management confirms a policy for larger groups.</p>
<?php foreach ($areas as $a): $aid = (int) $a['area_id']; ?>
<section class="panel" id="area-<?= $aid ?>">
  <h2><?= e($a['area_name']) ?> <?= badge($a['status']) ?></h2>
  <form method="post" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="action" value="update_area"><input type="hidden" name="area_id" value="<?= $aid ?>"><input type="hidden" name="anchor" value="area-<?= $aid ?>">
    <div class="grid-3">
      <div class="field"><label for="an<?= $aid ?>">Name</label><input type="text" id="an<?= $aid ?>" name="area_name" maxlength="80" value="<?= e($a['area_name']) ?>" required></div>
      <div class="field"><label for="ac<?= $aid ?>">Capacity (guests)</label><input type="number" id="ac<?= $aid ?>" name="capacity" min="1" max="999" value="<?= (int) $a['capacity'] ?>" required></div>
      <div class="field"><label for="as<?= $aid ?>">Status</label><select id="as<?= $aid ?>" name="status"><option value="ACTIVE">Active (bookable)</option><option value="INACTIVE"<?= $a['status'] === 'INACTIVE' ? ' selected' : '' ?>>Inactive (hidden)</option></select></div>
      <div class="field"><label for="ao<?= $aid ?>">Display order</label><input type="number" id="ao<?= $aid ?>" name="sort_order" min="0" max="999" value="<?= (int) $a['sort_order'] ?>"></div>
    </div>
    <div class="field"><label for="ad<?= $aid ?>">Description</label><textarea id="ad<?= $aid ?>" name="description" maxlength="2000"><?= e($a['description']) ?></textarea></div>
    <div class="grid-2">
      <div class="field"><label for="ai<?= $aid ?>">Main photo <span class="hint">JPG/PNG/WebP; leave empty to keep the current one</span></label><input type="file" id="ai<?= $aid ?>" name="main_image" accept="image/jpeg,image/png,image/webp" data-max-bytes="<?= upload_max_bytes() ?>" data-post-max="<?= post_max_bytes() ?>"></div>
      <img src="<?= e(img_url($a['main_image'], "assets/images/area$aid.svg")) ?>" alt="" style="max-height:110px;width:auto;border-radius:6px">
    </div>
    <button class="btn btn-edit btn-small" type="submit">Save area</button>
  </form>

  <?php foreach ($packages as $p): if ((int) $p['area_id'] !== $aid) continue; $pid = (int) $p['package_id']; ?>
  <details class="action" id="pkg-<?= $pid ?>" open>
    <summary><?= e($p['package_name']) ?> — <?= e(type_label($p['booking_type'])) ?> — <?= e(money($p['rate'])) ?> <?= badge($p['status']) ?></summary>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="update_package"><input type="hidden" name="package_id" value="<?= $pid ?>"><input type="hidden" name="anchor" value="pkg-<?= $pid ?>">
      <div class="grid-3">
        <div class="field"><label for="pn<?= $pid ?>">Package name</label><input type="text" id="pn<?= $pid ?>" name="package_name" maxlength="120" value="<?= e($p['package_name']) ?>" required></div>
        <div class="field"><label for="pr<?= $pid ?>">Rate (₱)</label><input type="text" inputmode="decimal" id="pr<?= $pid ?>" name="rate" value="<?= e($p['rate']) ?>" required></div>
        <div class="field"><label for="pd<?= $pid ?>">Down payment (₱)</label><input type="text" inputmode="decimal" id="pd<?= $pid ?>" name="down_payment" value="<?= e($p['down_payment']) ?>" required></div>
        <div class="field"><label for="pg<?= $pid ?>">Guest limit <span class="hint">≤ area capacity <?= (int) $a['capacity'] ?></span></label><input type="number" id="pg<?= $pid ?>" name="max_guests" min="1" max="<?= (int) $a['capacity'] ?>" value="<?= (int) $p['max_guests'] ?>" required></div>
        <div class="field"><label for="px<?= $pid ?>">Extra guests allowed</label><input type="number" id="px<?= $pid ?>" name="max_extra_guests" min="0" max="200" value="<?= (int) $p['max_extra_guests'] ?>" required></div>
        <div class="field"><label for="pf<?= $pid ?>">Fee per extra guest (₱)</label><input type="text" inputmode="decimal" id="pf<?= $pid ?>" name="additional_guest_fee" value="<?= e($p['additional_guest_fee']) ?>" required></div>
        <div class="field"><label for="ps<?= $pid ?>">Status</label><select id="ps<?= $pid ?>" name="status"><option value="ACTIVE">Active</option><option value="INACTIVE"<?= $p['status'] === 'INACTIVE' ? ' selected' : '' ?>>Inactive</option></select></div>
      </div>
      <div class="field"><label for="pde<?= $pid ?>">Short description</label><input type="text" id="pde<?= $pid ?>" name="description" maxlength="255" value="<?= e($p['description']) ?>"></div>
      <p class="hint">Session time is fixed for this package type: <?= e(substr($p['start_time'], 0, 5)) ?>–<?= e(substr($p['end_time'], 0, 5)) ?>.</p>
      <button class="btn btn-edit btn-small" type="submit">Save package</button>
    </form>
    <h3 style="margin-top:1rem">Inclusions</h3>
    <div class="table-wrap"><table><thead><tr><th style="width:5rem">Order</th><th>Text</th><th></th></tr></thead><tbody>
    <?php foreach ($incl[$pid] ?? [] as $i): $iid = (int) $i['inclusion_id']; ?>
      <tr><td colspan="3">
        <form method="post" class="toolbar" style="margin:0"><?= csrf_field() ?><input type="hidden" name="action" value="update_inclusion"><input type="hidden" name="inclusion_id" value="<?= $iid ?>"><input type="hidden" name="anchor" value="pkg-<?= $pid ?>">
          <div class="field" style="min-width:4rem;max-width:5rem"><label class="sr-only" for="io<?= $iid ?>">Order</label><input type="number" id="io<?= $iid ?>" name="sort_order" value="<?= (int) $i['sort_order'] ?>" min="0" max="999"></div>
          <div class="field grow"><label class="sr-only" for="it<?= $iid ?>">Inclusion</label><input type="text" id="it<?= $iid ?>" name="inclusion_text" maxlength="255" value="<?= e($i['inclusion_text']) ?>" required></div>
          <button class="btn btn-small" type="submit">Save</button>
        </form>
        <form method="post" class="inline" data-confirm="Remove this inclusion?"><?= csrf_field() ?><input type="hidden" name="action" value="delete_inclusion"><input type="hidden" name="inclusion_id" value="<?= $iid ?>"><input type="hidden" name="anchor" value="pkg-<?= $pid ?>"><button class="linklike" type="submit">Remove</button></form>
      </td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
    <form method="post" class="toolbar" style="margin-top:.5rem"><?= csrf_field() ?><input type="hidden" name="action" value="add_inclusion"><input type="hidden" name="package_id" value="<?= $pid ?>"><input type="hidden" name="anchor" value="pkg-<?= $pid ?>">
      <div class="field grow"><label for="ni<?= $pid ?>">Add an inclusion</label><input type="text" id="ni<?= $pid ?>" name="inclusion_text" maxlength="255" required></div>
      <button class="btn btn-success btn-small" type="submit">Add</button></form>
  </details>
  <?php endforeach; ?>
</section>
<?php endforeach; ?>
<?php require __DIR__ . '/../app/views/admin_footer.php'; ?>
