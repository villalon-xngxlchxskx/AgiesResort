<?php
require __DIR__ . '/../app/bootstrap.php';
$me = require_perm('inquiries.manage');
if (is_post()) {
    csrf_check();
    $iid = int_in('inquiry_id');
    $status = post('status');
    if (in_array($status, ['NEW', 'READ', 'REPLIED', 'ARCHIVED'], true) && q_val('SELECT inquiry_id FROM inquiries WHERE inquiry_id = ?', [$iid])) {
        q('UPDATE inquiries SET status = ?, admin_notes = ? WHERE inquiry_id = ?', [$status, mb_substr(post('admin_notes'), 0, 2000) ?: null, $iid]);
        q('UPDATE notifications SET is_read = 1 WHERE inquiry_id = ?', [$iid]);
        log_activity('Inquiries', 'Update', "Inquiry #$iid set to $status");
        flash('success', 'Inquiry updated.');
    } else {
        flash('error', 'Choose a valid status.');
    }
    redirect('admin/inquiries.php?id=' . $iid . '&status=' . urlencode(get('status', 'OPEN')));
}
$filter = get('status', 'OPEN');
$w = match ($filter) { 'OPEN' => "i.status IN ('NEW','READ')", 'REPLIED' => "i.status = 'REPLIED'", 'ARCHIVED' => "i.status = 'ARCHIVED'", default => '1=1' };
$qtext = search_term();
$p = [];
if ($qtext !== '') {
    $like = '%' . addcslashes($qtext, '%_\\') . '%';
    $w .= ' AND (i.full_name LIKE ? OR i.email LIKE ? OR i.subject LIKE ?)';
    array_push($p, $like, $like, $like);
}
$total = (int) q_val("SELECT COUNT(*) FROM inquiries i WHERE $w", $p);
$pg = pager($total, 20);
$rows = q_all("SELECT i.* FROM inquiries i WHERE $w ORDER BY i.created_at DESC LIMIT {$pg['per']} OFFSET {$pg['offset']}", $p);
$open = null;
if ($oid = int_in('id')) {
    $open = q_one('SELECT * FROM inquiries WHERE inquiry_id = ?', [$oid]);
    if ($open && $open['status'] === 'NEW') {
        q("UPDATE inquiries SET status = 'READ' WHERE inquiry_id = ?", [$oid]);
        q('UPDATE notifications SET is_read = 1 WHERE inquiry_id = ?', [$oid]);
        $open['status'] = 'READ';
    }
}
$pageTitle = 'Inquiries';
$active = 'inquiries';
require __DIR__ . '/../app/views/admin_header.php';
?>
<h1>Inquiries</h1>
<form class="toolbar" method="get">
  <nav class="filters" style="margin:0"><?php foreach (['OPEN' => 'Open', 'REPLIED' => 'Replied', 'ARCHIVED' => 'Archived', 'ALL' => 'All'] as $k => $l): ?><a href="?status=<?= e($k) ?>" aria-current="<?= $filter === $k ? 'true' : 'false' ?>"><?= e($l) ?></a><?php endforeach; ?></nav>
  <input type="hidden" name="status" value="<?= e($filter) ?>">
  <div class="field grow"><label for="q" class="sr-only">Search</label><input type="search" id="q" name="q" maxlength="60" value="<?= e($qtext) ?>" placeholder="Name, email or subject"></div>
  <button class="btn btn-small" type="submit">Search</button>
</form>
<div class="two-col">
  <div class="panel table-wrap">
    <?php if (!$rows): ?><p>No inquiries here.</p><?php else: ?>
    <table><thead><tr><th>Received</th><th>From</th><th>Subject</th><th>Status</th></tr></thead><tbody>
    <?php foreach ($rows as $i): ?>
      <tr<?= $open && (int) $open['inquiry_id'] === (int) $i['inquiry_id'] ? ' class="flagged"' : '' ?>><td><?= e(fmt_dt($i['created_at'])) ?></td><td><?= e($i['full_name']) ?></td>
        <td><a href="?<?= e(http_build_query(['status' => $filter, 'q' => $qtext, 'id' => $i['inquiry_id'], 'page' => $pg['page']])) ?>"><?= e($i['subject']) ?></a><?= $i['is_demo'] ? ' <span class="badge badge-demo">Demo</span>' : '' ?></td><td><?= badge($i['status']) ?></td></tr>
    <?php endforeach; ?></tbody></table><?php endif; ?>
    <?= pager_links($pg) ?>
  </div>
  <div class="panel">
    <?php if (!$open): ?><p class="muted">Select an inquiry to read it.</p><?php else: ?>
      <h2><?= e($open['subject']) ?></h2>
      <dl class="kv"><dt>From</dt><dd><?= e($open['full_name']) ?></dd><dt>Email</dt><dd><a href="mailto:<?= e($open['email']) ?>?subject=<?= e(rawurlencode('Re: ' . $open['subject'])) ?>"><?= e($open['email']) ?></a></dd>
        <dt>Contact</dt><dd><?= e($open['contact_number'] ?? '—') ?></dd><dt>Received</dt><dd><?= e(fmt_dt($open['created_at'])) ?></dd></dl>
      <div class="summary-box" style="margin:1rem 0"><?= nl2br(e($open['message'])) ?></div>
      <p class="hint">Reply by email, phone or Messenger, then mark it Replied here.</p>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="inquiry_id" value="<?= (int) $open['inquiry_id'] ?>">
        <div class="field"><label for="admin_notes">Staff notes</label><textarea id="admin_notes" name="admin_notes" maxlength="2000"><?= e($open['admin_notes']) ?></textarea></div>
        <div class="field"><label for="st">Status</label><select id="st" name="status"><?php foreach (['NEW' => 'New', 'READ' => 'Read', 'REPLIED' => 'Replied', 'ARCHIVED' => 'Archived'] as $k => $l): ?><option value="<?= $k ?>"<?= $open['status'] === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
        <button class="btn" type="submit">Save</button></form>
    <?php endif; ?>
  </div>
</div>
<?php require __DIR__ . '/../app/views/admin_footer.php'; ?>
