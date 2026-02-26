<?php
/**
 * Central configuration — loaded by every page.
 * Parses .env, opens SQLite, starts session.
 */

// ── Parse .env ──────────────────────────────────────────────────────────────
$envFile = __DIR__ . '/../.env';
if (!file_exists($envFile)) {
    die('Missing .env file. Copy .env.example to .env and configure it.');
}
foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#') continue;
    if (strpos($line, '=') === false) continue;
    [$key, $val] = array_map('trim', explode('=', $line, 2));
    $_ENV[$key] = $val;
}

// ── Constants ───────────────────────────────────────────────────────────────
define('ADMIN_PASSWORD', trim((string) ($_ENV['ADMIN_PASSWORD'] ?? '')));
define('ADMIN_PASSWORD_HASH', trim((string) ($_ENV['ADMIN_PASSWORD_HASH'] ?? '')));
define('MAIL_FROM',      $_ENV['MAIL_FROM']      ?? 'workshops@disinfoconsulting.eu');
define('MAIL_FROM_NAME', $_ENV['MAIL_FROM_NAME']  ?? 'Disinfo Consulting Workshops');
define('SITE_URL',       rtrim($_ENV['SITE_URL']  ?? '', '/'));
define('SITE_NAME',      $_ENV['SITE_NAME']       ?? 'Disinfo Consulting – Workshops');
define('DB_PATH',        __DIR__ . '/../' . ($_ENV['DB_PATH'] ?? 'data/bookings.db'));

// ── Database ────────────────────────────────────────────────────────────────
$dbDir = dirname(DB_PATH);
if (!is_dir($dbDir)) {
    if (!mkdir($dbDir, 0750, true) && !is_dir($dbDir)) {
        die('Failed to create data directory.');
    }
}

$db = new SQLite3(DB_PATH);
$db->busyTimeout(5000);
$db->exec('PRAGMA journal_mode = WAL');
$db->exec('PRAGMA foreign_keys = ON');
@chmod(DB_PATH, 0640);

require __DIR__ . '/db.php';

if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-XSS-Protection: 0');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');

    if (str_starts_with(strtolower(SITE_URL), 'https://')) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }

    if (isset($_SERVER['REQUEST_URI']) && str_contains((string) $_SERVER['REQUEST_URI'], '/admin/')) {
        header('X-Robots-Tag: noindex, nofollow');
    }
}

// ── Session ─────────────────────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    $forwardedProto = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    $httpsServer    = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
    $isHttpsRequest = ($httpsServer !== '' && $httpsServer !== 'off')
        || $forwardedProto === 'https'
        || str_starts_with(strtolower(SITE_URL), 'https://');

    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    if ($isHttpsRequest) {
        ini_set('session.cookie_secure', '1');
    }

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $isHttpsRequest,
        'httponly'  => true,
        'samesite'  => 'Lax',
    ]);
    session_start();
}

// ── Helpers (always needed) ─────────────────────────────────────────────────
require __DIR__ . '/functions.php';
