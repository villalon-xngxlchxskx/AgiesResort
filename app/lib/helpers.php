<?php
declare(strict_types=1);

// ---------------------------------------------------------------- output
function e($v): string
{
    return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $path = ''): string
{
    return rtrim((string) config('base_url', ''), '/') . '/' . ltrim($path, '/');
}

/** URL of a bundled css/js file with a version stamp, so browsers never keep serving an old copy. */
function asset(string $path): string
{
    $f = APP_ROOT . '/' . ltrim($path, '/');
    return url($path) . (is_file($f) ? '?v=' . filemtime($f) : '');
}

/** Image path from the DB -> URL, falling back to a bundled placeholder when the file is missing. */
function img_url(?string $path, string $fallback = 'assets/images/general.svg'): string
{
    $path = (string) $path;
    if ($path !== '' && !str_contains($path, '..') && preg_match('#^assets/[A-Za-z0-9_./-]+$#', $path) && is_file(APP_ROOT . '/' . $path)) {
        return url($path);
    }
    return url($fallback);
}

function redirect(string $path): never
{
    header('Location: ' . (preg_match('#^https?://#', $path) ? $path : url($path)));
    exit;
}

function money($n): string
{
    return '₱' . number_format((float) $n, 2);
}

function fmt_date(?string $d, string $fmt = 'M j, Y'): string
{
    if (!$d) {
        return '—';
    }
    $t = strtotime($d);
    return $t ? date($fmt, $t) : '—';
}

function fmt_dt(?string $d): string
{
    return fmt_date($d, 'M j, Y g:i A');
}

function type_label(string $type): string
{
    return $type === 'OVERNIGHT' ? 'Overnight (6:00 PM – 7:00 AM next day)' : 'Daytime (8:00 AM – 5:00 PM)';
}

function type_short(string $type): string
{
    return $type === 'OVERNIGHT' ? 'Overnight' : 'Daytime';
}

function badge(string $status): string
{
    $map = [
        'PENDING' => 'warn', 'PAYMENT PENDING' => 'info', 'CONFIRMED' => 'ok', 'COMPLETED' => 'done',
        'CANCELLED' => 'bad', 'DECLINED' => 'bad', 'EXPIRED' => 'muted',
        'UNPAID' => 'warn', 'PENDING VERIFICATION' => 'info', 'PARTIAL' => 'ok', 'PAID' => 'done',
        'VERIFIED' => 'ok', 'REJECTED' => 'bad', 'APPROVED' => 'ok',
        'ACTIVE' => 'ok', 'INACTIVE' => 'muted', 'NEW' => 'warn', 'READ' => 'info', 'REPLIED' => 'ok', 'ARCHIVED' => 'muted',
    ];
    $labels = ['PARTIAL' => 'Down payment verified', 'PAYMENT PENDING' => 'Payment submitted'];
    $cls = $map[$status] ?? 'muted';
    $text = $labels[$status] ?? ucwords(strtolower($status));
    return '<span class="badge badge-' . $cls . '">' . e($text) . '</span>';
}

// ---------------------------------------------------------------- request
function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function input(string $key, string $default = ''): string
{
    $v = $_POST[$key] ?? $_GET[$key] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

function post(string $key, string $default = ''): string
{
    $v = $_POST[$key] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

function get(string $key, string $default = ''): string
{
    $v = $_GET[$key] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

function int_in(string $key, int $default = 0): int
{
    $v = $_POST[$key] ?? $_GET[$key] ?? null;
    return (is_string($v) && preg_match('/^-?\d{1,9}$/', trim($v))) ? (int) $v : $default;
}

/** Search text: trimmed and capped (search boxes are never unlimited). */
function search_term(string $key = 'q', int $max = 60): string
{
    return mb_substr(get($key), 0, $max);
}

/** Normalises a multi-file input (name="x[]") into a list of single-file arrays. */
function uploaded_files(string $field): array
{
    $f = $_FILES[$field] ?? null;
    if (!$f || !is_array($f['name'] ?? null)) {
        return $f && ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE ? [$f] : [];
    }
    $out = [];
    foreach ($f['name'] as $i => $name) {
        if (($f['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
        $out[] = ['name' => $name, 'type' => $f['type'][$i], 'tmp_name' => $f['tmp_name'][$i], 'error' => $f['error'][$i], 'size' => $f['size'][$i]];
    }
    return $out;
}

function client_ip(): string
{
    // REMOTE_ADDR only: X-Forwarded-For is client-controlled unless a trusted proxy is configured.
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
}

// ---------------------------------------------------------------- session / csrf / flash
function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE || PHP_SAPI === 'cli') {
        return;
    }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('AGIESSID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => rtrim((string) config('base_url', ''), '/') . '/',
        'secure'   => (bool) config('cookie_secure', false),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function csrf_token(): string
{
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

/** Converts php.ini sizes such as "40M" to bytes. */
function ini_bytes(string $key): int
{
    $v = trim((string) ini_get($key));
    if ($v === '' || $v === '-1' || $v === '0') {
        return 0;
    }
    $n = (int) $v;
    return match (strtolower(substr($v, -1))) {
        'g' => $n * 1073741824,
        'm' => $n * 1048576,
        'k' => $n * 1024,
        default => $n,
    };
}

/** The whole-request upload limit (post_max_size), 0 = unlimited. */
function post_max_bytes(): int
{
    return ini_bytes('post_max_size');
}

/** Largest single image the server will accept (app setting, capped by php.ini). */
function upload_max_bytes(): int
{
    $limits = array_filter([(int) config('upload_max_bytes', 10 * 1024 * 1024), ini_bytes('upload_max_filesize'), post_max_bytes()]);
    return $limits ? min($limits) : 10 * 1024 * 1024;
}

/** Call at the top of every POST handler. */
function csrf_check(): void
{
    // When an upload is larger than post_max_size, PHP silently drops the whole form ($_POST and
    // $_FILES are empty). Report that clearly instead of a misleading "session expired".
    $len = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if (!$_POST && !$_FILES && $len > 0 && post_max_bytes() > 0 && $len > post_max_bytes()) {
        flash('error', 'The upload was too large (' . round($len / 1048576, 1) . ' MB). The server accepts up to '
            . round(post_max_bytes() / 1048576, 1) . ' MB per upload: send fewer or smaller photos at a time, or raise post_max_size and upload_max_filesize in php.ini (XAMPP Control Panel → Apache → Config → PHP (php.ini)) and restart Apache.');
        $back = $_SERVER['HTTP_REFERER'] ?? '';
        $host = $_SERVER['HTTP_HOST'] ?? '';
        if ($back && parse_url($back, PHP_URL_HOST) === $host) {
            header('Location: ' . $back);
            exit;
        }
        redirect('');
    }
    $sent = $_POST['_csrf'] ?? '';
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        http_response_code(419);
        flash('error', 'Your session expired or the form was submitted twice. Please try again.');
        $back = $_SERVER['HTTP_REFERER'] ?? '';
        $host = $_SERVER['HTTP_HOST'] ?? '';
        if ($back && parse_url($back, PHP_URL_HOST) === $host) {
            header('Location: ' . $back);
            exit;
        }
        redirect('');
    }
}

function flash(string $type, string $msg): void
{
    $_SESSION['_flash'][] = [$type, $msg];
}

function render_flashes(): string
{
    $out = '';
    foreach ($_SESSION['_flash'] ?? [] as [$type, $msg]) {
        $role = $type === 'error' ? 'alert' : 'status';
        $out .= '<div class="flash flash-' . e($type) . '" role="' . $role . '">' . e($msg) . '</div>';
    }
    unset($_SESSION['_flash']);
    return $out;
}

/** Remembers posted values so a failed form can be re-filled. */
function old(string $key, string $default = ''): string
{
    return isset($_POST[$key]) && is_string($_POST[$key]) ? $_POST[$key] : $default;
}

// ---------------------------------------------------------------- settings / content
function setting(string $key, $default = null)
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (q_all('SELECT setting_key, setting_value FROM system_settings') as $r) {
            $cache[$r['setting_key']] = $r['setting_value'];
        }
    }
    return $cache[$key] ?? $default;
}

function setting_int(string $key, int $default): int
{
    $v = setting($key);
    return ($v !== null && preg_match('/^\d+$/', (string) $v)) ? (int) $v : $default;
}

function content(string $key, string $default = ''): string
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (q_all('SELECT content_key, content_value FROM website_content') as $r) {
            $cache[$r['content_key']] = (string) $r['content_value'];
        }
    }
    $v = $cache[$key] ?? '';
    return $v !== '' ? $v : $default;
}

/** Plain text with paragraphs; always escaped. */
function paragraphs(string $text): string
{
    $parts = preg_split('/\R{2,}/', trim($text)) ?: [];
    return implode('', array_map(fn($p) => '<p>' . nl2br(e(trim($p))) . '</p>', $parts));
}

// ---------------------------------------------------------------- validation
function valid_email(string $v): bool
{
    return strlen($v) <= 120 && filter_var($v, FILTER_VALIDATE_EMAIL) !== false;
}

/** Philippine mobile: 09XXXXXXXXX or +639XXXXXXXXX. Landlines: 0 + 8-10 digits. Returns normalized or null. */
function normalize_phone(string $v): ?string
{
    $d = preg_replace('/[\s\-().]/', '', $v);
    if (preg_match('/^\+?63(9\d{9})$/', $d, $m)) {
        return '0' . $m[1];
    }
    if (preg_match('/^09\d{9}$/', $d)) {
        return $d;
    }
    if (preg_match('/^0\d{8,10}$/', $d)) {
        return $d;
    }
    return null;
}

function valid_date(string $v): bool
{
    $d = DateTime::createFromFormat('Y-m-d', $v);
    return $d !== false && $d->format('Y-m-d') === $v;
}

function str_len_ok(string $v, int $min, int $max): bool
{
    $l = mb_strlen($v);
    return $l >= $min && $l <= $max;
}

// ---------------------------------------------------------------- audit / notifications / logs
function log_activity(string $module, string $action, string $description = ''): void
{
    $admin = current_admin();
    q('INSERT INTO activity_logs (admin_id, module, action, description, ip_address) VALUES (?,?,?,?,?)', [
        $admin['admin_id'] ?? null, substr($module, 0, 40), substr($action, 0, 60), mb_substr($description, 0, 255), client_ip(),
    ]);
}

function notify(string $type, string $title, string $message, ?int $reservationId = null, ?int $inquiryId = null, ?string $link = null): void
{
    q('INSERT INTO notifications (type, reservation_id, inquiry_id, title, message, link) VALUES (?,?,?,?,?,?)', [
        $type, $reservationId, $inquiryId, mb_substr($title, 0, 120), mb_substr($message, 0, 255), $link,
    ]);
}

function app_log(string $level, string $msg): void
{
    @file_put_contents(APP_ROOT . '/storage/logs/app.log', '[' . date('Y-m-d H:i:s') . "] $level: $msg\n", FILE_APPEND | LOCK_EX);
}

// ---------------------------------------------------------------- pagination / csv
function pager(int $total, int $perPage = 20): array
{
    $pages = max(1, (int) ceil($total / $perPage));
    $page = min(max(1, int_in('page', 1)), $pages);
    return ['page' => $page, 'pages' => $pages, 'per' => $perPage, 'offset' => ($page - 1) * $perPage, 'total' => $total];
}

function pager_links(array $p): string
{
    if ($p['pages'] <= 1) {
        return '<p class="pager-note">' . $p['total'] . ' record' . ($p['total'] === 1 ? '' : 's') . '</p>';
    }
    $qs = $_GET;
    $link = function (int $n) use ($qs): string {
        $qs['page'] = $n;
        return '?' . http_build_query($qs);
    };
    $h = '<nav class="pager" aria-label="Pagination">';
    $h .= $p['page'] > 1 ? '<a href="' . e($link($p['page'] - 1)) . '">Previous</a>' : '<span class="disabled">Previous</span>';
    $h .= '<span>Page ' . $p['page'] . ' of ' . $p['pages'] . ' (' . $p['total'] . ' records)</span>';
    $h .= $p['page'] < $p['pages'] ? '<a href="' . e($link($p['page'] + 1)) . '">Next</a>' : '<span class="disabled">Next</span>';
    return $h . '</nav>';
}

/** Neutralises spreadsheet formula injection in exported cells. */
function csv_cell($v): string
{
    $s = (string) ($v ?? '');
    return ($s !== '' && in_array($s[0], ['=', '+', '-', '@', "\t", "\r"], true) && !is_numeric($s)) ? "'" . $s : $s;
}

function send_csv(string $filename, array $headers, array $rows): never
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $filename) . '"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM so Excel reads UTF-8 (₱, ñ)
    fputcsv($out, $headers);
    foreach ($rows as $r) {
        fputcsv($out, array_map('csv_cell', array_values($r)));
    }
    fclose($out);
    exit;
}

/** Days between today and $date (negative = past). */
function days_until(string $date): int
{
    $today = new DateTimeImmutable('today');
    $d = new DateTimeImmutable($date);
    return (int) $today->diff($d)->format('%r%a');
}
