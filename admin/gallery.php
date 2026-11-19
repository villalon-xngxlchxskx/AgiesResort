<?php
require __DIR__ . '/../app/bootstrap.php';
$me = require_perm('gallery.manage');
$areas = q_all('SELECT area_id, area_name FROM resort_areas ORDER BY sort_order, area_id');
$areaIds = array_map(fn($a) => (int) $a['area_id'], $areas);

/** Gallery photo belongs to an area (any area) or to "around the resort" (area 0). */
function gallery_category(int $areaId): string
{
    return in_array($areaId, [1, 2, 3], true) ? 'AREA ' . $areaId : 'GENERAL';
}

if (is_post()) {
    csrf_check();
    $action = post('action');
    $areaId = int_in('area_id');
    if ($areaId && !in_array($areaId, $areaIds, true)) $areaId = 0;
    try {
        if (in_array($action, ['upload', 'update'], true) && mb_strlen(post('caption')) > 160) throw new UserError('Caption is too long (160 max).');
        if ($action === 'upload') {
            $files = uploaded_files('images');
            if (!$files) throw new UserError('Choose one or more photos to upload.');
            $ok = 0; $failed = [];
            $sort = (int) q_val('SELECT COALESCE(MAX(sort_order),0) FROM gallery');
            foreach ($files as $file) {
                try {
                    $path = store_uploaded_image($file);
                    q('INSERT INTO gallery (area_id, image_path, caption, category, status, sort_order, uploaded_by) VALUES (?,?,?,?,?,?,?)',
                      [$areaId ?: null, $path, post('caption') ?: null, gallery_category($areaId), 'ACTIVE', ++$sort, $me['admin_id']]);
                    $ok++;
                } catch (UserError $e) {
                    $failed[] = mb_substr((string) $file['name'], 0, 60) . ': ' . $e->getMessage();
                }
            }
            if ($ok) {
                log_activity('Gallery', 'Upload', "$ok photo(s) uploaded");
                flash('success', "$ok photo(s) uploaded and shown on the website.");
            }
            foreach ($failed as $f) flash('error', $f);
        } elseif ($action === 'update') {
            $sort = preg_match('/^\d{1,5}$/', post('sort_order')) ? (int) post('sort_order') : 0;
            q('UPDATE gallery SET caption = ?, category = ?, area_id = ?, sort_order = ? WHERE image_id = ?', [post('caption') ?: null, gallery_category($areaId), $areaId ?: null, $sort, int_in('image_id')]);
            log_activity('Gallery', 'Update', 'Photo #' . int_in('image_id') . ' updated');
            flash('success', 'Photo details saved.');
        } elseif ($action === 'toggle') {
            q("UPDATE gallery SET status = IF(status = 'ACTIVE', 'INACTIVE', 'ACTIVE') WHERE image_id = ?", [int_in('image_id')]);
            $st = q_val('SELECT status FROM gallery WHERE image_id = ?', [int_in('image_id')]);
            log_activity('Gallery', 'Toggle', 'Photo #' . int_in('image_id') . " set to $st");
            flash('success', $st === 'ACTIVE' ? 'Photo is now shown on the website.' : 'Photo hidden from the website.');
        } elseif ($action === 'delete') {
            $g = q_one('SELECT * FROM gallery WHERE image_id = ?', [int_in('image_id')]);
            if ($g) {
                q('DELETE FROM gallery WHERE image_id = ?', [$g['image_id']]);
                $inUse = (int) q_val('SELECT COUNT(*) FROM resort_areas WHERE main_image = ?', [$g['image_path']])
                       + (int) q_val('SELECT COUNT(*) FROM website_content WHERE content_value = ?', [$g['image_path']])
                       + (int) q_val('SELECT COUNT(*) FROM packages WHERE image = ?', [$g['image_path']]);
                if (!$inUse) delete_uploaded_file($g['image_path']);
                log_activity('Gallery', 'Delete', 'Photo #' . $g['image_id'] . ' deleted');
                flash('success', 'Photo deleted.');
            }
        }
    } catch (UserError $e) {
        flash('error', $e->getMessage());
    }
    redirect('admin/gallery.php');
}
$rows = q_all('SELECT g.*, a.full_name AS by_name FROM gallery g LEFT JOIN admins a ON a.admin_id = g.uploaded_by ORDER BY g.sort_order, g.image_id');
$areaName = [];
foreach ($areas as $a) $areaName[(int) $a['area_id']] = $a['area_name'];
$maxMb = round(upload_max_bytes() / 1048576, 1);
$postMb = post_max_bytes() ? round(post_max_bytes() / 1048576, 1) : 0;
$liveCount = count(array_filter($rows, fn($g) => $g['status'] === 'ACTIVE'));
$pageTitle = 'Gallery';
$active = 'gallery';
require __DIR__ . '/../app/views/admin_header.php';
?>
<div class="cal-head"><div><h1>Gallery</h1>
  <p class="muted" style="margin:0"><?= count($rows) ?> photo(s), <?= $liveCount ?> live on the website. No limit on the number of photos; each can be up to <?= $maxMb ?> MB<?= $postMb ? " ($postMb MB per upload batch)" : '' ?>.</p></div>
  <a class="btn btn-ghost no-print" href="<?= e(url('gallery.php')) ?>" target="_blank" rel="noopener">View public gallery ↗</a>
</div>
<form method="post" enctype="multipart/form-data" class="panel"><?= csrf_field() ?><input type="hidden" name="action" value="upload">
  <h2>Upload photos</h2>
  <div class="dropzone">
    <label for="images">Photos <span class="hint">JPG, PNG or WebP. Select one or many, or drag them here.</span></label>
    <input type="file" id="images" name="images[]" accept="image/jpeg,image/png,image/webp" multiple required data-max-bytes="<?= upload_max_bytes() ?>" data-post-max="<?= post_max_bytes() ?>">
  </div>
  <div class="toolbar" style="margin:1rem 0 0">
    <div class="field"><label for="area_id">Show under</label><select id="area_id" name="area_id"><option value="0">Around the resort</option><?php foreach ($areas as $a): ?><option value="<?= (int) $a['area_id'] ?>"><?= e($a['area_name']) ?></option><?php endforeach; ?></select></div>
    <div class="field grow"><label for="caption">Caption <span class="hint">Optional, applied to all photos in this upload</span></label><input type="text" id="caption" name="caption" maxlength="160"></div>
    <button class="btn btn-success" type="submit">Upload photos</button>
  </div>
</form>
<div class="gallery admin-gallery">
<?php foreach ($rows as $g): $gid = (int) $g['image_id']; $ga = (int) ($g['area_id'] ?? 0); $live = $g['status'] === 'ACTIVE'; ?>
  <figure class="panel<?= $live ? '' : ' is-hidden' ?>" style="padding:.7rem;margin:0">
    <div class="thumb"><img src="<?= e(img_url($g['image_path'])) ?>" alt="<?= e($g['caption'] ?: 'Photo ' . $gid) ?>" loading="lazy"><span class="badge <?= $live ? 'badge-ok' : 'badge-warn' ?>"><?= $live ? 'Live' : 'Hidden' ?></span></div>
    <figcaption>#<?= $gid ?> · <?= e($areaName[$ga] ?? 'Around the resort') ?><?= $g['by_name'] ? ' · ' . e($g['by_name']) : '' ?></figcaption>
    <form method="post" style="margin-top:.5rem"><?= csrf_field() ?><input type="hidden" name="action" value="update"><input type="hidden" name="image_id" value="<?= $gid ?>">
      <label class="sr-only" for="c<?= $gid ?>">Caption</label><input type="text" id="c<?= $gid ?>" name="caption" maxlength="160" value="<?= e($g['caption']) ?>" placeholder="Caption">
      <div class="grid-2" style="margin-top:.4rem;gap:.4rem"><select name="area_id" aria-label="Show under"><option value="0">Around the resort</option><?php foreach ($areas as $a): ?><option value="<?= (int) $a['area_id'] ?>"<?= $ga === (int) $a['area_id'] ? ' selected' : '' ?>><?= e($a['area_name']) ?></option><?php endforeach; ?></select>
        <input type="number" name="sort_order" min="0" max="99999" value="<?= (int) $g['sort_order'] ?>" aria-label="Display order" title="Display order"></div>
      <button class="btn btn-edit btn-small" type="submit" style="margin-top:.45rem">Save details</button></form>
    <div class="actions" style="margin:.45rem 0 0">
      <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="image_id" value="<?= $gid ?>"><button class="btn <?= $live ? 'btn-ghost' : 'btn-success' ?> btn-small" type="submit"><?= $live ? 'Hide from website' : 'Show on website' ?></button></form>
      <form method="post" class="inline" data-confirm="Delete this photo permanently?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="image_id" value="<?= $gid ?>"><button class="btn btn-danger btn-small" type="submit">Delete</button></form>
    </div>
  </figure>
<?php endforeach; ?>
</div>
<?php if (!$rows): ?><div class="panel"><p class="muted" style="margin:0">No photos yet. Upload the first ones above.</p></div><?php endif; ?>
<?php require __DIR__ . '/../app/views/admin_footer.php'; ?>
