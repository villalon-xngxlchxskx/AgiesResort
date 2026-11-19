<?php $pageTitle = 'Not allowed'; $active = ''; require __DIR__ . '/admin_header.php'; ?>
<h1>You don't have access to this page</h1>
<p>Your role (<?= e(current_admin()['role'] ?? '') ?>) can't open this part of the system. On the website, the Super Admin can only manage Administrator accounts; other owner tools are in the desktop management system.</p>
<p><a class="btn" href="<?= e(url((current_admin()['role'] ?? '') === 'SUPER ADMIN' ? 'admin/accounts.php' : 'admin/')) ?>">Back</a></p>
<?php require __DIR__ . '/admin_footer.php'; ?>
