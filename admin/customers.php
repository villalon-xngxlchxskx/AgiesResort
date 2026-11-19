<?php
require __DIR__ . '/../app/bootstrap.php';
$me = require_perm('customers.view');
$qtext = search_term();
$status = get('status');
$demo = get('demo');
// Business rule: only customers tied to at least one reservation are listed (no orphan records).
$where = ['EXISTS (SELECT 1 FROM reservations rx WHERE rx.customer_id = c.customer_id)'];
$p = [];
if ($qtext !== '') {
    $like = '%' . addcslashes($qtext, '%_\\') . '%';
    $where[] = '(c.full_name LIKE ? OR c.email LIKE ? OR c.contact_number LIKE ?)';
    array_push($p, $like, $like, $like);
}
if (in_array($status, ['ACTIVE', 'INACTIVE'], true)) { $where[] = 'c.status = ?'; $p[] = $status; }
if ($demo === 'hide') { $where[] = 'c.is_demo = 0'; }
if ($demo === 'only') { $where[] = 'c.is_demo = 1'; }
$w = implode(' AND ', $where);
$sort = get('sort', 'name');
$order = $sort === 'newest' ? 'c.created_at DESC, c.customer_id DESC' : 'c.full_name ASC';

if (get('export') === 'csv') {
    require_perm('customers.export');
    $rows = q_all("SELECT c.customer_id, c.full_name, c.email, c.contact_number, c.address, c.status, c.created_at,
                          (SELECT COUNT(*) FROM reservations r WHERE r.customer_id = c.customer_id) AS reservations
                     FROM customers c WHERE $w ORDER BY $order", $p);
    log_activity('Customers', 'Export', count($rows) . ' customer records exported to CSV');
    send_csv('customers_' . date('Ymd_His') . '.csv', ['ID', 'Name', 'Email', 'Contact', 'Address', 'Status', 'Registered', 'Reservations'], $rows);
}

$total = (int) q_val("SELECT COUNT(*) FROM customers c WHERE $w", $p);
$pg = pager($total, 25);
$rows = q_all("SELECT c.*, (SELECT COUNT(*) FROM reservations r WHERE r.customer_id = c.customer_id) AS res_count,
                      (SELECT MAX(r.booking_date) FROM reservations r WHERE r.customer_id = c.customer_id) AS last_booking
                 FROM customers c WHERE $w ORDER BY $order LIMIT {$pg['per']} OFFSET {$pg['offset']}", $p);
$qs = $_GET;
unset($qs['page']);
$pageTitle = 'Customers';
$active = 'customers';
require __DIR__ . '/../app/views/admin_header.php';
?>
<div class="cal-head"><div><h1>Customers</h1><p class="muted" style="margin:0">Customers are created with their reservations. Every customer listed has at least one booking.</p></div>
  <div class="actions no-print">
    <?php if (can('reservations.create')): ?><a class="btn btn-success" href="<?= e(url('admin/reservation-new.php')) ?>">+ New booking</a><?php endif; ?>
    <?php if (can('customers.export')): ?><a class="btn btn-ghost" href="?<?= e(http_build_query($qs + ['export' => 'csv'])) ?>">Export CSV</a><?php endif; ?>
  </div>
</div>
<form class="toolbar panel" method="get">
  <div class="field grow"><label for="q">Search</label><input type="search" id="q" name="q" maxlength="60" value="<?= e($qtext) ?>" placeholder="Name, email or contact number"></div>
  <div class="field"><label for="status">Status</label><select id="status" name="status"><option value="">All</option><option value="ACTIVE"<?= $status === 'ACTIVE' ? ' selected' : '' ?>>Active</option><option value="INACTIVE"<?= $status === 'INACTIVE' ? ' selected' : '' ?>>Inactive</option></select></div>
  <div class="field"><label for="demo">Demo data</label><select id="demo" name="demo"><option value="">Show</option><option value="hide"<?= $demo === 'hide' ? ' selected' : '' ?>>Hide</option><option value="only"<?= $demo === 'only' ? ' selected' : '' ?>>Only demo</option></select></div>
  <div class="field"><label for="sort">Sort</label><select id="sort" name="sort"><option value="name">Name A–Z</option><option value="newest"<?= $sort === 'newest' ? ' selected' : '' ?>>Newest first</option></select></div>
  <button class="btn" type="submit">Apply</button><a class="btn btn-ghost" href="<?= e(url('admin/customers.php')) ?>">Reset</a>
</form>
<div class="panel table-wrap">
<?php if (!$rows): ?><p>No customers match. <a href="<?= e(url('admin/customers.php')) ?>">Clear filters</a></p><?php else: ?>
<table><thead><tr><th>ID</th><th>Name</th><th>Contact</th><th>Email</th><th>Status</th><th class="num">Reservations</th><th>Last booking</th><th>Registered</th></tr></thead><tbody>
<?php foreach ($rows as $c): ?>
  <tr>
    <td>C<?= str_pad((string) $c['customer_id'], 4, '0', STR_PAD_LEFT) ?></td>
    <td><a href="<?= e(url('admin/customer.php?id=' . $c['customer_id'])) ?>"><?= e($c['full_name']) ?></a><?= $c['is_demo'] ? ' <span class="badge badge-demo">Demo</span>' : '' ?></td>
    <td><?= e($c['contact_number']) ?></td><td><?= e($c['email']) ?></td><td><?= badge($c['status']) ?></td>
    <td class="num"><?= (int) $c['res_count'] ?></td><td><?= e(fmt_date($c['last_booking'])) ?></td><td><?= e(fmt_date($c['created_at'])) ?></td>
  </tr>
<?php endforeach; ?></tbody></table><?php endif; ?>
<?= pager_links($pg) ?>
</div>
<?php require __DIR__ . '/../app/views/admin_footer.php'; ?>
