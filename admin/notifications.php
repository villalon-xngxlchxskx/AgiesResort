<?php
require __DIR__ . '/../app/bootstrap.php';
$me = require_perm('notifications.view');
if (is_post()) {
    csrf_check();
    if (post('action') === 'read_all') {
        $n = q('UPDATE notifications SET is_read = 1 WHERE is_read = 0')->rowCount();
        flash('success', "$n notification(s) marked as read.");
    } elseif (post('action') === 'toggle') {
        q('UPDATE notifications SET is_read = 1 - is_read WHERE notification_id = ?', [int_in('notification_id')]);
    } elseif (post('action') === 'open') {
        $n = q_one('SELECT link FROM notifications WHERE notification_id = ?', [int_in('notification_id')]);
        q('UPDATE notifications SET is_read = 1 WHERE notification_id = ?', [int_in('notification_id')]);
        if ($n && preg_match('#^admin/[a-z\-]+\.php(\?[A-Za-z0-9_=&%+\-]*)?$#', (string) $n['link'])) {
            redirect($n['link']);
        }
    }
    redirect('admin/notifications.php?show=' . urlencode(get('show', 'unread')));
}
$show = get('show', 'unread');
$w = $show === 'all' ? '1=1' : 'is_read = 0';
$total = (int) q_val("SELECT COUNT(*) FROM notifications WHERE $w");
$pg = pager($total, 30);
$rows = q_all("SELECT * FROM notifications WHERE $w ORDER BY created_at DESC, notification_id DESC LIMIT {$pg['per']} OFFSET {$pg['offset']}");
$pageTitle = 'Notifications';
$active = 'notifications';
require __DIR__ . '/../app/views/admin_header.php';
?>
<div class="cal-head"><h1>Notifications</h1>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="read_all"><button class="btn btn-ghost" type="submit">Mark all as read</button></form></div>
<nav class="filters"><a href="?show=unread" aria-current="<?= $show !== 'all' ? 'true' : 'false' ?>">Unread</a><a href="?show=all" aria-current="<?= $show === 'all' ? 'true' : 'false' ?>">All</a></nav>
<div class="panel">
<?php if (!$rows): ?><p>You're all caught up.</p><?php else: ?>
<ul class="timeline">
<?php foreach ($rows as $n): ?>
  <li style="<?= $n['is_read'] ? 'opacity:.7' : '' ?>">
    <strong><?= e($n['title']) ?></strong> <span class="muted"><?= e(fmt_dt($n['created_at'])) ?></span><br><?= e($n['message']) ?>
    <div class="actions" style="margin:.3rem 0">
      <?php if ($n['link']): ?><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="open"><input type="hidden" name="notification_id" value="<?= (int) $n['notification_id'] ?>"><button class="btn btn-small" type="submit">Open</button></form><?php endif; ?>
      <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="notification_id" value="<?= (int) $n['notification_id'] ?>"><button class="btn btn-ghost btn-small" type="submit"><?= $n['is_read'] ? 'Mark unread' : 'Mark read' ?></button></form>
    </div>
  </li>
<?php endforeach; ?></ul><?php endif; ?>
<?= pager_links($pg) ?>
</div>
<?php require __DIR__ . '/../app/views/admin_footer.php'; ?>
