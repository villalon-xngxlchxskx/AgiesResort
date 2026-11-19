<?php
declare(strict_types=1);

/**
 * Role permissions, enforced server-side on every protected page/action.
 * SUPER ADMIN = resort owner; ADMIN = resort manager.
 */
const ROLE_PERMISSIONS = [
    // On the WEBSITE the Super Admin only manages Administrator accounts
    // (add an Administrator, set Administrator passwords) and their own password.
    // Backups, settings, logs and full account management are in the desktop system.
    'SUPER ADMIN' => ['accounts.manage', 'profile.password'],
    // The Administrator (resort manager) runs the resort. They can VIEW their
    // profile but cannot edit it or change their password (Super Admin does that).
    'ADMIN' => [
        'dashboard', 'reservations.view', 'reservations.manage', 'reservations.create',
        'customers.view', 'customers.edit', 'customers.export', 'customers.delete',
        'payments.view', 'payments.verify', 'payments.reverse',
        'reschedules.manage', 'inquiries.manage', 'gallery.manage', 'content.manage',
        'catalog.manage', 'notifications.view', 'reports.view', 'reports.financial', 'integrity.view',
    ],
];

function current_admin(): ?array
{
    static $loaded = false, $admin = null;
    if ($loaded) {
        return $admin;
    }
    $loaded = true;
    $id = $_SESSION['admin_id'] ?? null;
    if (!$id) {
        return null;
    }
    // Idle timeout
    $timeout = 60 * max(5, setting_int('session_timeout_minutes', 30));
    if (isset($_SESSION['last_activity']) && time() - (int) $_SESSION['last_activity'] > $timeout) {
        logout_session();
        flash('info', 'You were signed out after a period of inactivity.');
        return null;
    }
    $_SESSION['last_activity'] = time();
    // Reload every request so deactivation / role changes take effect immediately.
    $row = q_one('SELECT admin_id, full_name, username, email, role, status, must_change_password FROM admins WHERE admin_id = ?', [$id]);
    if (!$row || $row['status'] !== 'ACTIVE') {
        logout_session();
        return null;
    }
    $admin = $row;
    return $admin;
}

function can(string $perm): bool
{
    $a = current_admin();
    if (!$a) {
        return false;
    }
    $perms = ROLE_PERMISSIONS[$a['role']] ?? [];
    return in_array($perm, $perms, true);
}

/** Gate for admin pages. Use require_perm() for specific actions. */
function require_login(): array
{
    $a = current_admin();
    if (!$a) {
        $_SESSION['after_login'] = $_SERVER['REQUEST_URI'] ?? '';
        redirect('admin/login.php');
    }
    // A forced password change applies only to the Super Admin (Administrators cannot change
    // their own password; the Super Admin sets it for them).
    $self = basename($_SERVER['SCRIPT_NAME'] ?? '');
    if ($a['role'] === 'SUPER ADMIN' && (int) $a['must_change_password'] === 1 && !in_array($self, ['profile.php', 'logout.php'], true)) {
        flash('info', 'Please set a new password before continuing.');
        redirect('admin/profile.php');
    }
    return $a;
}

function require_perm(string $perm): array
{
    $a = require_login();
    if (!can($perm)) {
        http_response_code(403);
        log_activity('Security', 'Denied', 'Permission ' . $perm . ' denied for ' . ($_SERVER['REQUEST_URI'] ?? ''));
        require APP_ROOT . '/app/views/admin_forbidden.php';
        exit;
    }
    return $a;
}

function logout_session(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
    start_session();
}

// ---------------------------------------------------------------- throttling
function throttle_blocked(string $scope, string $identifier, int $max, int $minutes): bool
{
    $since = date('Y-m-d H:i:s', time() - $minutes * 60);
    $byIp = (int) q_val('SELECT COUNT(*) FROM login_attempts WHERE scope = ? AND ip_address = ? AND success = 0 AND attempted_at > ?', [$scope, client_ip(), $since]);
    $byId = (int) q_val('SELECT COUNT(*) FROM login_attempts WHERE scope = ? AND identifier = ? AND success = 0 AND attempted_at > ?', [$scope, $identifier, $since]);
    // IP limit is looser so one person mistyping cannot lock a shared network.
    return $byId >= $max || $byIp >= $max * 3;
}

function throttle_record(string $scope, string $identifier, bool $success): void
{
    q('INSERT INTO login_attempts (scope, identifier, ip_address, success) VALUES (?,?,?,?)', [$scope, mb_substr($identifier, 0, 120), client_ip(), $success ? 1 : 0]);
    if (random_int(1, 50) === 1) {
        q('DELETE FROM login_attempts WHERE attempted_at < NOW() - INTERVAL 30 DAY');
    }
}

// ---------------------------------------------------------------- login
/** @return string|null error message, or null on success */
function attempt_login(string $username, string $password): ?string
{
    $max = max(3, setting_int('login_max_attempts', 5));
    $mins = max(1, setting_int('login_lockout_minutes', 15));
    $ident = mb_strtolower($username);
    if (throttle_blocked('staff', $ident, $max, $mins)) {
        return "Too many failed attempts. Try again in $mins minutes.";
    }
    $row = q_one('SELECT admin_id, username, password_hash, status FROM admins WHERE username = ?', [$username]);
    // Always run a hash comparison to keep timing similar for unknown usernames.
    $hash = $row['password_hash'] ?? password_hash('timing-equaliser', PASSWORD_DEFAULT);
    $ok = password_verify($password, $hash);
    if (!$row || !$ok || $row['status'] !== 'ACTIVE') {
        throttle_record('staff', $ident, false);
        if ($row) {
            q('INSERT INTO activity_logs (admin_id, module, action, description, ip_address) VALUES (?,?,?,?,?)',
              [$row['admin_id'], 'Authentication', 'Failed login', $row['status'] !== 'ACTIVE' ? 'Inactive account' : 'Wrong password', client_ip()]);
        }
        return 'Incorrect username or password.';
    }
    throttle_record('staff', $ident, true);
    if (password_needs_rehash($row['password_hash'], PASSWORD_DEFAULT)) {
        q('UPDATE admins SET password_hash = ? WHERE admin_id = ?', [password_hash($password, PASSWORD_DEFAULT), $row['admin_id']]);
    }
    session_regenerate_id(true);
    $_SESSION['admin_id'] = (int) $row['admin_id'];
    $_SESSION['last_activity'] = time();
    unset($_SESSION['_csrf']);
    q('UPDATE admins SET last_login = NOW(), last_login_ip = ? WHERE admin_id = ?', [client_ip(), $row['admin_id']]);
    q('INSERT INTO activity_logs (admin_id, module, action, description, ip_address) VALUES (?,?,?,?,?)',
      [$row['admin_id'], 'Authentication', 'Login', $row['username'] . ' logged in', client_ip()]);
    return null;
}

/** @return string|null problem with the password, or null if acceptable */
function password_problem(string $pw, string $username = ''): ?string
{
    if (mb_strlen($pw) < 10) {
        return 'Use at least 10 characters.';
    }
    if (mb_strlen($pw) > 200) {
        return 'Use at most 200 characters.';
    }
    if (!preg_match('/[A-Za-z]/', $pw) || !preg_match('/\d/', $pw)) {
        return 'Include at least one letter and one number.';
    }
    if ($username !== '' && stripos($pw, $username) !== false) {
        return 'Do not include your username in the password.';
    }
    if (in_array(strtolower($pw), ['password123', 'admin12345', 'qwerty12345', '1234567890a'], true)) {
        return 'That password is too common.';
    }
    return null;
}
