<?php
require __DIR__ . '/../app/bootstrap.php';
// POST-only so a link or image on another site cannot sign staff out.
if (is_post()) {
    csrf_check();
    if ($a = current_admin()) {
        log_activity('Authentication', 'Logout', $a['username'] . ' signed out');
    }
    logout_session();
    flash('success', 'You have signed out.');
}
redirect('admin/login.php');
