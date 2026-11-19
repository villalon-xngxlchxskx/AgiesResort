<?php
require __DIR__ . '/../app/bootstrap.php';
$me = require_perm('content.manage');
// The home page banner row must exist for uploads to apply (restores it if it was deleted).
q("INSERT IGNORE INTO website_content (content_key, content_label, content_type, content_value, content_group) VALUES ('hero_banner_image','Hero banner image','image','assets/images/hero.svg','Home page')");
$items = q_all('SELECT * FROM website_content ORDER BY content_group, content_id');
if (is_post()) {
    csrf_check();
    $changed = 0;
    $uploaded = [];
    $replaced = [];
    try {
        tx(function () use ($items, $me, &$changed, &$uploaded, &$replaced) {
            foreach ($items as $it) {
                $key = $it['content_key'];
                if ($it['content_type'] === 'image') {
                    if (has_upload('img_' . $key)) {
                        $path = store_uploaded_image($_FILES['img_' . $key]);
                        $uploaded[] = $path;
                        $replaced[] = (string) $it['content_value'];
                        q('UPDATE website_content SET content_value = ?, updated_by = ? WHERE content_id = ?', [$path, $me['admin_id'], $it['content_id']]);
                        $changed++;
                    }
                    continue;
                }
                if (!array_key_exists('c_' . $key, $_POST)) continue;
                $v = str_replace("\r\n", "\n", (string) $_POST['c_' . $key]);
                $max = $it['content_type'] === 'text' ? 255 : 10000;
                if (mb_strlen($v) > $max) throw new UserError("“{$it['content_label']}” is too long ($max characters max).");
                if ($v !== str_replace("\r\n", "\n", (string) $it['content_value'])) {
                    q('UPDATE website_content SET content_value = ?, updated_by = ? WHERE content_id = ?', [trim($v), $me['admin_id'], $it['content_id']]);
                    $changed++;
                }
            }
        });
        foreach ($replaced as $old) {
            delete_image_if_unused($old); // the previous banner file is no longer needed
        }
        if ($changed) log_activity('Website Content', 'Update', "$changed item(s) updated");
        flash('success', $changed ? 'Website content saved. Changes are live.' : 'Nothing changed.');
    } catch (Throwable $e) {
        foreach ($uploaded as $u) {
            delete_uploaded_file($u);
        }
        if (!$e instanceof UserError) {
            throw $e;
        }
        flash('error', $e->getMessage());
    }
    redirect('admin/content.php');
}
$groups = [];
foreach ($items as $it) $groups[$it['content_group']][] = $it;
$pageTitle = 'Website content';
$active = 'content';
require __DIR__ . '/../app/views/admin_header.php';
?>
<h1>Website content</h1>
<p class="muted">Text is shown exactly as typed (no HTML). Leave a contact field empty to hide it on the website. Resort name and Facebook link are in Settings.</p>
<form method="post" enctype="multipart/form-data"><?= csrf_field() ?>
<?php foreach ($groups as $g => $list): ?>
  <section class="panel"><h2><?= e($g) ?></h2>
  <?php foreach ($list as $it): $k = $it['content_key']; ?>
    <div class="field">
      <label for="c_<?= e($k) ?>"><?= e($it['content_label']) ?><?php if ($it['updated_at']): ?> <span class="hint">updated <?= e(fmt_dt($it['updated_at'])) ?></span><?php endif; ?></label>
      <?php if ($it['content_type'] === 'textarea'): ?>
        <textarea id="c_<?= e($k) ?>" name="c_<?= e($k) ?>" rows="<?= $k === 'policies' ? 12 : 5 ?>"><?= e($it['content_value']) ?></textarea>
      <?php elseif ($it['content_type'] === 'image'): ?>
        <div class="dropzone">
          <img src="<?= e(img_url($it['content_value'], 'assets/images/hero.svg')) ?>" alt="Current image" style="max-height:140px;width:auto;border-radius:10px;margin-bottom:.6rem;box-shadow:var(--shadow-s)">
          <input type="file" id="c_<?= e($k) ?>" name="img_<?= e($k) ?>" accept="image/jpeg,image/png,image/webp" data-max-bytes="<?= upload_max_bytes() ?>" data-post-max="<?= post_max_bytes() ?>">
          <span class="hint">JPG, PNG or WebP up to <?= round(upload_max_bytes() / 1048576, 1) ?> MB. Leave empty to keep the current image. The new image goes live as soon as you save.</span>
        </div>
      <?php else: ?>
        <input type="text" id="c_<?= e($k) ?>" name="c_<?= e($k) ?>" maxlength="255" value="<?= e($it['content_value']) ?>">
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
  </section>
<?php endforeach; ?>
<button class="btn btn-success" type="submit">Save all changes</button> <a class="btn btn-ghost" href="<?= e(url('')) ?>" target="_blank" rel="noopener">View website</a>
</form>
<?php require __DIR__ . '/../app/views/admin_footer.php'; ?>
