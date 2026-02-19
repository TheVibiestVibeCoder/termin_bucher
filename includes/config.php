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
define('ADMIN_PASSWORD', $_ENV['ADMIN_PASSWORD'] ?? 'change_me');
define('MAIL_FROM',      $_ENV['MAIL_FROM']      ?? 'workshops@disinfoconsulting.eu');
define('MAIL_FROM_NAME', $_ENV['MAIL_FROM_NAME']  ?? 'Disinfo Consulting Workshops');
define('SITE_URL',       rtrim($_ENV['SITE_URL']  ?? '', '/'));
define('SITE_NAME',      $_ENV['SITE_NAME']       ?? 'Disinfo Consulting – Workshops');
define('DB_PATH',        __DIR__ . '/../' . ($_ENV['DB_PATH'] ?? 'data/bookings.db'));

// ── Database ────────────────────────────────────────────────────────────────
$dbDir = dirname(DB_PATH);
if (!is_dir($dbDir)) {
    mkdir($dbDir, 0750, true);
}

$db = new SQLite3(DB_PATH);
$db->busyTimeout(5000);
$db->exec('PRAGMA journal_mode = WAL');
$db->exec('PRAGMA foreign_keys = ON');

require __DIR__ . '/db.php';

// ── Session ─────────────────────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly'  => true,
        'samesite'  => 'Lax',
    ]);
    session_start();
}

// ── Helpers (always needed) ─────────────────────────────────────────────────
require __DIR__ . '/functions.php';
