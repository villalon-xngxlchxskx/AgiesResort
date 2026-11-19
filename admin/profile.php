<?php
require __DIR__ . '/../app/bootstrap.php';
$me = require_login();
$full = q_one('SELECT * FROM admins WHERE admin_id = ?', [$me['admin_id']]);
$canChange = can('profile.password'); // Super Admin only; Administrators view only

if (is_post()) {
    csrf_check();
    if (!$canChange) {
        http_response_code(403);
        flash('error', 'Administrators cannot change their own profile or password. Ask the Super Admin.');
        redirect('admin/profile.php');
    }
    $current = (string) ($_POST['current_password'] ?? '');
    $new = (string) ($_POST['new_password'] ?? '');
    if (!password_verify($current, $full['password_hash'])) {
        flash('error', 'Your current password is incorrect.');
    } elseif ($new !== (string) ($_POST['confirm_password'] ?? '')) {
        flash('error', 'The new passwords do not match.');
    } elseif ($p = password_problem($new, $me['username'])) {
        flash('error', $p);
    } elseif (password_verify($new, $full['password_hash'])) {
        flash('error', 'Choose a password different from your current one.');
    } else {
        q('UPDATE admins SET password_hash = ?, must_change_password = 0 WHERE admin_id = ?', [password_hash($new, PASSWORD_DEFAULT), $me['admin_id']]);
        session_regenerate_id(true);
        log_activity('Authentication', 'Password change', $me['username'] . ' changed their own password');
        flash('success', 'Password changed.');
        redirect('admin/accounts.php');
    }
    redirect('admin/profile.php');
}
$pageTitle = 'My profile';
$active = 'profile';
require __DIR__ . '/../app/views/admin_header.php';
?>
<h1>My profile</h1>
<div class="two-col">
  <section class="panel">
    <h2>Account details</h2>
    <dl class="kv">
      <dt>Full name</dt><dd><?= e($full['full_name']) ?></dd>
      <dt>Username</dt><dd><?= e($full['username']) ?></dd>
      <dt>Role</dt><dd><?= e($full['role'] === 'ADMIN' ? 'Administrator' : 'Super Admin') ?></dd>
      <dt>Email</dt><dd><?= e($full['email'] ?: '—') ?></dd>
      <dt>Contact number</dt><dd><?= e($full['contact_number'] ?: '—') ?></dd>
      <dt>Resort office</dt><dd><?= e(content('contact_address', '—')) ?></dd>
      <dt>Last sign-in</dt><dd><?= e(fmt_dt($full['last_login'])) ?><?= $full['last_login_ip'] ? ' (IP ' . e($full['last_login_ip']) . ')' : '' ?></dd>
      <dt>Account created</dt><dd><?= e(fmt_dt($full['created_at'])) ?></dd>
    </dl>
    <?php if (!$canChange): ?><p class="flash flash-info" style="margin-top:1rem">Your profile is view-only. To change your details or password, ask the Super Admin.</p><?php endif; ?>
  </section>
  <?php if ($canChange): ?>
  <section class="panel">
    <h2>Change my password</h2>
    <?php if ((int) $full['must_change_password'] === 1): ?><p class="flash flash-warn">Please set a new password before continuing.</p><?php endif; ?>
    <form method="post"><?= csrf_field() ?>
      <div class="field"><label for="current_password">Current password</label><input type="password" id="current_password" name="current_password" maxlength="200" autocomplete="current-password" required></div>
      <div class="field"><label for="new_password">New password <span class="hint">10+ characters with a letter and a number</span></label><input type="password" id="new_password" name="new_password" minlength="10" maxlength="200" autocomplete="new-password" required></div>
      <div class="field"><label for="confirm_password">Repeat new password</label><input type="password" id="confirm_password" name="confirm_password" minlength="10" maxlength="200" autocomplete="new-password" required></div>
      <button class="btn" type="submit">Save new password</button>
    </form>
  </section>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/../app/views/admin_footer.php'; ?>
