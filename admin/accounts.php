<?php
// WEBSITE Super Admin page: add Administrator accounts and set Administrator passwords.
// Deleting/deactivating accounts, roles, backups and settings are in the desktop system.
require __DIR__ . '/../app/bootstrap.php';
$me = require_perm('accounts.manage');

if (is_post()) {
    csrf_check();
    $action = post('action');
    try {
        if ($action === 'create') {
            $u = mb_strtolower(post('username'));
            $name = post('full_name');
            $email = mb_strtolower(post('email'));
            $contact = post('contact_number');
            $pw = (string) ($_POST['password'] ?? '');
            if (!preg_match('/^[a-z0-9._-]{3,50}$/', $u)) throw new UserError('Username: 3-50 lowercase letters, numbers, dot, dash or underscore.');
            if (!str_len_ok($name, 2, 100)) throw new UserError('Enter the full name (2-100 characters).');
            if ($email !== '' && !valid_email($email)) throw new UserError('Enter a valid email or leave it empty.');
            if ($contact !== '' && normalize_phone($contact) === null) throw new UserError('Enter a valid contact number or leave it empty.');
            if ($pw !== (string) ($_POST['password2'] ?? '')) throw new UserError('The passwords do not match.');
            if ($prob = password_problem($pw, $u)) throw new UserError('Password: ' . $prob);
            try {
                // Role is always ADMIN here: the website never creates Super Admin accounts.
                q("INSERT INTO admins (full_name, username, password_hash, email, contact_number, role, status, must_change_password) VALUES (?,?,?,?,?,'ADMIN','ACTIVE',0)",
                  [$name, $u, password_hash($pw, PASSWORD_DEFAULT), $email ?: null, $contact !== '' ? normalize_phone($contact) : null]);
            } catch (PDOException $e) {
                if (is_duplicate_key($e)) throw new UserError('That username or email is already used.');
                throw $e;
            }
            log_activity('Accounts', 'Create', "Administrator account $u created (website)");
            flash('success', "Administrator $u created. Give them the password privately.");
        } elseif ($action === 'password') {
            $id = int_in('admin_id');
            $t = q_one("SELECT admin_id, username, role FROM admins WHERE admin_id = ? AND role = 'ADMIN'", [$id]);
            if (!$t) throw new UserError('Administrator account not found.');
            $pw = (string) ($_POST['password'] ?? '');
            if ($pw !== (string) ($_POST['password2'] ?? '')) throw new UserError('The passwords do not match.');
            if ($prob = password_problem($pw, $t['username'])) throw new UserError('Password: ' . $prob);
            q('UPDATE admins SET password_hash = ?, must_change_password = 0 WHERE admin_id = ?', [password_hash($pw, PASSWORD_DEFAULT), $id]);
            q("DELETE FROM login_attempts WHERE scope IN ('staff','desktop') AND identifier = ?", [mb_strtolower($t['username'])]);
            log_activity('Accounts', 'Password change', "Password changed for administrator {$t['username']} (website)");
            flash('success', "Password changed for {$t['username']}. Any lockout was cleared.");
        }
    } catch (UserError $e) {
        flash('error', $e->getMessage());
    }
    redirect('admin/accounts.php');
}
$rows = q_all("SELECT admin_id, full_name, username, email, contact_number, status, last_login FROM admins WHERE role = 'ADMIN' ORDER BY full_name");
$pageTitle = 'Administrator accounts';
$active = 'accounts';
require __DIR__ . '/../app/views/admin_header.php';
?>
<h1>Good day, <?= e(explode(' ', $me['full_name'])[0]) ?></h1>
<p class="muted">On the website you can add Administrator accounts and change Administrator passwords. Deactivating or deleting accounts, backups and system settings are done in the desktop management system.</p>

<section class="panel table-wrap">
  <h2>Administrators</h2>
  <?php if (!$rows): ?><p>No administrator accounts yet. Add one below.</p><?php else: ?>
  <table><thead><tr><th>Name</th><th>Username</th><th>Contact</th><th>Status</th><th>Last sign-in</th><th>Change password</th></tr></thead><tbody>
  <?php foreach ($rows as $a): $aid = (int) $a['admin_id']; ?>
    <tr><td><?= e($a['full_name']) ?></td><td><?= e($a['username']) ?></td><td><?= e($a['email'] ?? '') ?><br><span class="hint"><?= e($a['contact_number'] ?? '') ?></span></td>
      <td><?= badge($a['status']) ?></td><td><?= e(fmt_dt($a['last_login'])) ?></td>
      <td><details class="action"><summary>Set new password</summary>
        <form method="post" data-confirm="Change the password for <?= e($a['username']) ?>?"><?= csrf_field() ?><input type="hidden" name="action" value="password"><input type="hidden" name="admin_id" value="<?= $aid ?>">
          <label for="p<?= $aid ?>">New password <span class="hint">10+ characters, a letter and a number</span></label><input type="password" id="p<?= $aid ?>" name="password" minlength="10" maxlength="200" autocomplete="new-password" required>
          <label for="q<?= $aid ?>" style="margin-top:.4rem">Repeat password</label><input type="password" id="q<?= $aid ?>" name="password2" minlength="10" maxlength="200" autocomplete="new-password" required>
          <button class="btn btn-small" type="submit" style="margin-top:.5rem">Save password</button></form></details></td></tr>
  <?php endforeach; ?></tbody></table><?php endif; ?>
</section>

<section class="panel">
  <h2>Add an Administrator</h2>
  <p class="hint">New accounts are always Administrators. Super Admin accounts can only be created in the desktop system.</p>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="create">
    <div class="grid-3">
      <div class="field"><label for="full_name">Full name</label><input type="text" id="full_name" name="full_name" maxlength="100" required></div>
      <div class="field"><label for="username">Username</label><input type="text" id="username" name="username" maxlength="50" pattern="[a-z0-9._\-]{3,50}" required></div>
      <div class="field"><label>Role</label><input type="text" value="Administrator" disabled></div>
      <div class="field"><label for="email">Email</label><input type="email" id="email" name="email" maxlength="120"></div>
      <div class="field"><label for="contact_number">Contact number</label><input type="tel" id="contact_number" name="contact_number" maxlength="20"></div>
      <div class="field"><label for="password">Password</label><input type="password" id="password" name="password" minlength="10" maxlength="200" autocomplete="new-password" required></div>
      <div class="field"><label for="password2">Repeat password</label><input type="password" id="password2" name="password2" minlength="10" maxlength="200" autocomplete="new-password" required></div>
    </div>
    <button class="btn btn-success" type="submit">Create Administrator</button>
  </form>
</section>
<?php require __DIR__ . '/../app/views/admin_footer.php'; ?>
