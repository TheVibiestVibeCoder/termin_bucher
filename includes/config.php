<?php
/**
 * Central configuration — loaded by every page.
 * Parses .env, opens SQLite, starts session.
 */

// ── Parse .env ──────────────────────────────────────────────────────────────
$envFile = __DIR__ . '/../.env';
$parseEnvValue = static function (string $rawValue): string {
    $value = trim($rawValue);
    if ($value === '') {
        return '';
    }

    $firstChar = $value[0];
    $isQuoted = ($firstChar === '"' || $firstChar === "'") && str_ends_with($value, $firstChar);
    if (!$isQuoted) {
        return $value;
    }

    $inner = substr($value, 1, -1);
    if ($firstChar === "'") {
        return $inner;
    }

    return strtr($inner, [
        '\\n' => "\n",
        '\\r' => "\r",
        '\\t' => "\t",
        '\\"' => '"',
        '\\\\' => '\\',
    ]);
};

if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if (str_starts_with($line, "\xEF\xBB\xBF")) {
            $line = substr($line, 3);
        }
        if ($line === '' || $line[0] === '#') continue;
        if (str_starts_with($line, 'export ')) {
            $line = trim(substr($line, 7));
        }
        if (strpos($line, '=') === false) continue;
        [$key, $val] = array_map('trim', explode('=', $line, 2));
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
            continue;
        }
        $_ENV[$key] = $parseEnvValue($val);
    }
}

$getEnv = static function (string $key, string $default = ''): string {
    if (array_key_exists($key, $_ENV)) {
        return trim((string) $_ENV[$key]);
    }

    $runtimeValue = getenv($key);
    if ($runtimeValue === false || $runtimeValue === null) {
        return $default;
    }

    return trim((string) $runtimeValue);
};

$inferSiteUrl = static function (): string {
    $hostRaw = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
    if ($hostRaw === '') {
        $hostRaw = trim((string) ($_SERVER['SERVER_NAME'] ?? ''));
    }
    if ($hostRaw === '' || preg_match('/[\s\/\\\\]/', $hostRaw)) {
        return '';
    }

    $hostRaw = strtolower($hostRaw);
    $host = $hostRaw;
    $port = '';

    if (str_starts_with($hostRaw, '[')) {
        $endBracketPos = strpos($hostRaw, ']');
        if ($endBracketPos === false) {
            return '';
        }
        $host = substr($hostRaw, 0, $endBracketPos + 1);
        $rest = substr($hostRaw, $endBracketPos + 1);
        if ($rest !== '') {
            if (!str_starts_with($rest, ':')) {
                return '';
            }
            $port = substr($rest, 1);
        }
    } else {
        $parts = explode(':', $hostRaw);
        if (count($parts) > 2) {
            return '';
        }
        $host = $parts[0];
        $port = $parts[1] ?? '';
    }

    $hostForValidation = trim($host, '[]');
    $hostIsValid = filter_var($hostForValidation, FILTER_VALIDATE_IP) !== false
        || filter_var($hostForValidation, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false
        || $hostForValidation === 'localhost';
    if (!$hostIsValid) {
        return '';
    }

    if ($port !== '') {
        if (!ctype_digit($port)) {
            return '';
        }
        $portInt = (int) $port;
        if ($portInt < 1 || $portInt > 65535) {
            return '';
        }
    }

    $forwardedProto = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    $httpsServer = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
    $isHttps = ($httpsServer !== '' && $httpsServer !== 'off') || $forwardedProto === 'https';
    $scheme = $isHttps ? 'https' : 'http';

    $hostWithPort = $host;
    if ($port !== '') {
        $defaultPort = $isHttps ? 443 : 80;
        if ((int) $port !== $defaultPort) {
            $hostWithPort .= ':' . (int) $port;
        }
    }

    return $scheme . '://' . $hostWithPort;
};

// ── Constants ───────────────────────────────────────────────────────────────
define('ADMIN_PASSWORD', $getEnv('ADMIN_PASSWORD', ''));
define('ADMIN_PASSWORD_HASH', $getEnv('ADMIN_PASSWORD_HASH', ''));
define('MAIL_FROM', $getEnv('MAIL_FROM', 'workshops@disinfoconsulting.eu'));
define('MAIL_FROM_NAME', $getEnv('MAIL_FROM_NAME', 'Disinfo Consulting Workshops'));
$siteUrl = rtrim($getEnv('SITE_URL', ''), '/');
if ($siteUrl === '') {
    $siteUrl = $inferSiteUrl();
}

define('SITE_URL', $siteUrl);
define('SITE_NAME', $getEnv('SITE_NAME', 'Disinfo Consulting - Workshops'));
define('DB_PATH', __DIR__ . '/../' . $getEnv('DB_PATH', 'data/bookings.db'));

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
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Cross-Origin-Resource-Policy: same-origin');
    header('X-Permitted-Cross-Domain-Policies: none');

    $csp = "default-src 'self'; "
        . "base-uri 'self'; "
        . "form-action 'self'; "
        . "frame-ancestors 'self'; "
        . "object-src 'none'; "
        . "script-src 'self' 'unsafe-inline'; "
        . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
        . "font-src 'self' https://fonts.gstatic.com data:; "
        . "img-src 'self' https: data:; "
        . "connect-src 'self'";
    header('Content-Security-Policy: ' . $csp);

    if (str_starts_with(strtolower(SITE_URL), 'https://')) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }

    $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    if ($requestUri !== '' && str_contains($requestUri, '/admin/')) {
        header('X-Robots-Tag: noindex, nofollow');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
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

