<?php
require __DIR__ . '/../app/bootstrap.php';
if (current_admin()) {
    redirect('admin/');
}

const REMEMBER_COOKIE = 'agies_staff_user';
$cookiePath = rtrim((string) config('base_url', ''), '/') . '/';
$cookieOpts = fn(int $expires) => ['expires' => $expires, 'path' => $cookiePath, 'secure' => (bool) config('cookie_secure', false), 'httponly' => true, 'samesite' => 'Lax'];

$error = null;
$fieldErrors = [];
$rememberedUser = isset($_COOKIE[REMEMBER_COOKIE]) && is_string($_COOKIE[REMEMBER_COOKIE]) ? mb_substr($_COOKIE[REMEMBER_COOKIE], 0, 50) : '';
$username = $rememberedUser;
$remember = $rememberedUser !== '';

if (is_post()) {
    csrf_check();
    $username = mb_substr(post('username'), 0, 50);
    $password = (string) ($_POST['password'] ?? '');
    $remember = post('remember') === '1';
    if ($username === '') $fieldErrors['username'] = 'Enter your username.';
    if ($password === '') $fieldErrors['password'] = 'Enter your password.';
    if (!$fieldErrors) {
        $error = attempt_login($username, $password);
    }
    if (!$fieldErrors && $error === null) {
        // "Remember me": keeps the username on this computer for 30 days and keeps the session
        // across browser restarts (up to 8 hours). The idle timeout still signs staff out.
        if ($remember) {
            setcookie(REMEMBER_COOKIE, $username, $cookieOpts(time() + 30 * 86400));
            $p = session_get_cookie_params();
            setcookie(session_name(), session_id(), ['expires' => time() + 8 * 3600, 'path' => $p['path'], 'secure' => $p['secure'], 'httponly' => true, 'samesite' => 'Lax']);
        } else {
            setcookie(REMEMBER_COOKIE, '', $cookieOpts(time() - 3600));
        }
        $to = $_SESSION['after_login'] ?? '';
        unset($_SESSION['after_login']);
        $base = rtrim((string) config('base_url', ''), '/') . '/admin/';
        // only follow same-app admin paths
        if (is_string($to) && str_starts_with($to, $base) && !str_contains(substr($to, 1), '//') && !preg_match('#login|logout#', $to)) {
            header('Location: ' . $to);
            exit;
        }
        redirect('admin/');
    }
}
$resort = (string) setting('resort_name', "Agie's Resort");
$flashes = render_flashes();
$failed = $error !== null || $fieldErrors;
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Staff sign in · <?= e($resort) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@500;600&display=swap" rel="stylesheet">
<style><?= is_file(APP_ROOT . '/assets/css/app.css') ? file_get_contents(APP_ROOT . '/assets/css/app.css') : '' ?></style>
<style>
/* Safety net: keeps icons small and the card tidy even if assets/css/app.css fails to load. */
svg{width:18px;height:18px;max-width:24px;max-height:24px;flex:none}
.auth-logo{width:104px;height:104px;display:block;background:none;box-shadow:none;border-radius:0}
.auth-logo img{width:100%;height:100%;object-fit:contain;filter:drop-shadow(0 8px 18px rgba(3,4,94,.35))}
body.auth{font-family:system-ui,Segoe UI,sans-serif}
/* Resort photo, softly faded under the blue gradient (kept subtle on purpose). */
.auth-brand{background:
  radial-gradient(500px 300px at 110% -10%,rgba(255,255,255,.18),transparent 60%),
  linear-gradient(155deg,rgba(2,62,138,.90) 0%,rgba(0,150,199,.84) 60%,rgba(0,180,216,.78) 100%),
  url('<?= e(asset('assets/images/resort-photo.jpg')) ?>') 52% center / cover no-repeat}
</style>
</head>
<body class="auth">
<span class="auth-blob b1" aria-hidden="true"></span><span class="auth-blob b2" aria-hidden="true"></span>
<div class="auth-shell">
  <section class="auth-brand" aria-label="About this system">
    <div>
      <div class="auth-logo"><img src="<?= e(asset('assets/images/agies-logo.png')) ?>" alt="Agie's Resort logo" width="104" height="104"></div>
      <h1><?= e($resort) ?></h1>
      <p class="tag">Management System</p>
      <ul class="auth-points">
        <li>Reservations, payments and rescheduling</li>
        <li>Live website content and photo gallery</li>
        <li>Reports exported to PDF</li>
      </ul>
    </div>
    <p class="fine">Authorized staff only. Every sign-in is logged.</p>
  </section>

  <section class="auth-panel">
    <div class="auth-card<?= $failed ? ' shake' : '' ?>" id="auth-card">
      <h2>Welcome back</h2>
      <p class="sub">Sign in to continue to the management system.</p>
      <?php if ($flashes): ?><div class="auth-flashes"><?= $flashes ?></div><?php endif; ?>
      <?php if ($error): ?>
        <div class="auth-alert" role="alert"><span aria-hidden="true">⚠</span><span><?= e($error) ?></span></div>
      <?php endif; ?>
      <form method="post" action="<?= e(url('admin/login.php')) ?>" id="login-form" novalidate>
        <?= csrf_field() ?>
        <div class="auth-field<?= isset($fieldErrors['username']) ? ' invalid' : '' ?>">
          <label for="username">Username</label>
          <div class="input-wrap">
            <svg class="lead" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/></svg>
            <input type="text" id="username" name="username" maxlength="50" autocomplete="username" value="<?= e($username) ?>" placeholder="Enter your username" required<?= $username === '' ? ' autofocus' : '' ?> aria-describedby="username-error">
          </div>
          <div class="field-error" id="username-error"><?= e($fieldErrors['username'] ?? 'Enter your username.') ?></div>
        </div>
        <div class="auth-field<?= isset($fieldErrors['password']) ? ' invalid' : '' ?>">
          <label for="password">Password <span class="caps" id="caps-warning" aria-live="polite">⚠ Caps Lock is on</span></label>
          <div class="input-wrap">
            <svg class="lead" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>
            <input type="password" id="password" name="password" maxlength="200" autocomplete="current-password" placeholder="Enter your password" required<?= $username !== '' ? ' autofocus' : '' ?> aria-describedby="password-error">
            <button type="button" class="pw-toggle" id="pw-toggle" aria-label="Show password" aria-pressed="false">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>
            </button>
          </div>
          <div class="field-error" id="password-error"><?= e($fieldErrors['password'] ?? 'Enter your password.') ?></div>
        </div>
        <div class="auth-row">
          <label class="switch"><input type="checkbox" name="remember" value="1"<?= $remember ? ' checked' : '' ?>><span class="track" aria-hidden="true"></span>Remember me</label>
          <a href="#" id="forgot-link">Forgot password?</a>
        </div>
        <div class="auth-alert info" id="forgot-help" hidden>Passwords cannot be reset from this page. Administrators: ask the Super Admin to set a new password. Super Admin: use install/setup.php on the server computer.</div>
        <button class="btn btn-signin" type="submit" id="signin-btn"><span class="spin" aria-hidden="true"></span><span class="label">Sign In</span></button>
      </form>
      <p class="auth-foot"><a href="<?= e(url('')) ?>">← Back to the website</a></p>
    </div>
  </section>
</div>
<script src="<?= e(asset('assets/js/app.js')) ?>"></script>
</body>
</html>
