<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

$configFile = APP_ROOT . '/app/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    exit('Configuration missing: copy app/config.example.php to app/config.php.');
}
$GLOBALS['APP_CONFIG'] = require $configFile;

function config(string $key, $default = null)
{
    return $GLOBALS['APP_CONFIG'][$key] ?? $default;
}

date_default_timezone_set((string) config('timezone', 'Asia/Manila'));

// Errors are logged, never shown to visitors (no SQL, paths or stack traces).
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', APP_ROOT . '/storage/logs/php-error.log');
error_reporting(E_ALL);

require APP_ROOT . '/app/lib/db.php';
require APP_ROOT . '/app/lib/helpers.php';
require APP_ROOT . '/app/lib/auth.php';
require APP_ROOT . '/app/lib/upload.php';
require APP_ROOT . '/app/lib/pdf.php';
require APP_ROOT . '/app/services/ReservationService.php';
require APP_ROOT . '/app/services/PaymentService.php';
require APP_ROOT . '/app/services/RescheduleService.php';
require APP_ROOT . '/app/services/ReportService.php';

set_exception_handler(function (Throwable $e): void {
    $ref = strtoupper(bin2hex(random_bytes(4)));
    app_log('error', "[$ref] " . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }
    $isDb = $e instanceof PDOException;
    echo '<!doctype html><meta charset="utf-8"><title>Something went wrong</title>'
       . '<div style="font-family:system-ui;max-width:560px;margin:4rem auto;padding:1rem">'
       . '<h1 style="font-size:1.4rem">This page could not be loaded</h1>'
       . '<p>' . ($isDb ? 'The database could not be reached or returned an error.' : 'An unexpected error occurred.')
       . ' Please try again. If it keeps happening, give staff this reference: <strong>' . $ref . '</strong>.</p>'
       . '<p style="color:#666;font-size:.9rem">Staff: details are in storage/logs/app.log.</p></div>';
});

start_session();
