<?php
/** @var string $pageTitle  @var string $active */
$me = current_admin();
$unread = $me ? (int) q_val('SELECT COUNT(*) FROM notifications WHERE is_read = 0') : 0;
$pendingPay = $me ? (int) q_val("SELECT COUNT(*) FROM payments WHERE payment_status = 'PENDING VERIFICATION'") : 0;
$pendingRs = $me ? (int) q_val("SELECT COUNT(*) FROM rescheduling_requests WHERE approval_status = 'PENDING'") : 0;
$newInq = $me ? (int) q_val("SELECT COUNT(*) FROM inquiries WHERE status = 'NEW'") : 0;
$items = [
    ['dashboard', 'index.php', 'Dashboard', 'dashboard', 0],
    ['reservations', 'reservations.php', 'Reservations', 'reservations.view', 0],
    ['payments', 'payments.php', 'Payments', 'payments.view', $pendingPay],
    ['reschedules', 'reschedules.php', 'Rescheduling', 'reschedules.manage', $pendingRs],
    ['customers', 'customers.php', 'Customers', 'customers.view', 0],
    ['inquiries', 'inquiries.php', 'Inquiries', 'inquiries.manage', $newInq],
    ['notifications', 'notifications.php', 'Notifications', 'notifications.view', $unread],
    ['reports', 'reports.php', 'Reports', 'reports.view', 0],
    ['catalog', 'areas.php', 'Areas & packages', 'catalog.manage', 0],
    ['gallery', 'gallery.php', 'Gallery', 'gallery.manage', 0],
    ['content', 'content.php', 'Website content', 'content.manage', 0],
    ['accounts', 'accounts.php', 'Administrator accounts', 'accounts.manage', 0],
    ['profile', 'profile.php', 'My profile', 'profile.view', 0],
];
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= e($pageTitle ?? 'Admin') ?> · Management</title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(asset('assets/css/app.css')) ?>">
</head>
<body class="admin">
<a class="skip" href="#main">Skip to content</a>
<?php if ($me): ?>
<header class="admin-top">
  <button class="nav-toggle" type="button" aria-expanded="false" aria-controls="admin-nav">Menu</button>
  <a class="brand" href="<?= e(url($me['role'] === 'SUPER ADMIN' ? 'admin/accounts.php' : 'admin/')) ?>"><span class="brand-mark" aria-hidden="true"></span><?= e((string) setting('resort_name', "Agie's Resort")) ?> <span class="muted">Management System</span></a>
  <div class="top-right">
    <a href="<?= e(url('')) ?>" target="_blank" rel="noopener">View website ↗</a>
    <a href="<?= e(url('admin/profile.php')) ?>"><?= e($me['full_name']) ?> <span class="role"><?= e($me['role'] === 'ADMIN' ? 'ADMINISTRATOR' : $me['role']) ?></span></a>
    <form method="post" action="<?= e(url('admin/logout.php')) ?>" class="inline"><?= csrf_field() ?><button class="linklike signout" type="submit">Sign out</button></form>
  </div>
</header>
<div class="admin-shell">
<nav id="admin-nav" class="admin-nav" aria-label="Management">
  <div class="nav-who"><?= e($me['full_name']) ?><small><?= e($me['role'] === 'ADMIN' ? 'Administrator' : 'Super Admin') ?></small></div>
  <?php foreach ($items as [$key, $href, $label, $perm, $count]): if ($perm !== 'profile.view' && !can($perm)) continue; ?>
    <a href="<?= e(url('admin/' . $href)) ?>"<?= ($active ?? '') === $key ? ' aria-current="page"' : '' ?>><?= e($label) ?><?php if ($count > 0): ?> <span class="count" aria-label="<?= $count ?> waiting"><?= $count ?></span><?php endif; ?></a>
  <?php endforeach; ?>
</nav>
<main id="main" class="admin-main">
<?php if (config('setup_enabled') && $me['role'] === 'SUPER ADMIN'): ?>
  <div class="flash flash-warn">Setup mode is on. After all staff passwords are set, change <code>'setup_enabled'</code> to <code>false</code> in app/config.php.</div>
<?php endif; ?>
<?php else: ?>
<main id="main" class="admin-main solo">
<?php endif; ?>
<?= render_flashes() ?>
