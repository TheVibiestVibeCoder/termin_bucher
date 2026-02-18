<?php
declare(strict_types=1);

function start_secure_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $isHttps = (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (int) ($_SERVER['SERVER_PORT'] ?? 80) === 443
    );

    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_secure', $isHttps ? '1' : '0');
    ini_set('session.cookie_samesite', 'Lax');

    session_name('workshop_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();

    if (!isset($_SESSION['session_initialized'])) {
        session_regenerate_id(true);
        $_SESSION['session_initialized'] = time();
    }
}

function apply_security_headers(): void
{
    if (headers_sent()) {
        return;
    }

    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Cross-Origin-Resource-Policy: same-origin');
    header('X-Permitted-Cross-Domain-Policies: none');

    $csp = implode(' ', [
        "default-src 'self';",
        "base-uri 'self';",
        "frame-ancestors 'none';",
        "form-action 'self';",
        "img-src 'self' data:;",
        "connect-src 'self';",
        "script-src 'self';",
        "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com;",
        "font-src 'self' https://fonts.gstatic.com;",
    ]);
    header('Content-Security-Policy: ' . $csp);
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return (string) $_SESSION['csrf_token'];
}

function verify_csrf_token(?string $token): bool
{
    if ($token === null || $token === '') {
        return false;
    }

    $stored = $_SESSION['csrf_token'] ?? '';
    return is_string($stored) && hash_equals($stored, $token);
}

function client_ip(): string
{
    $value = trim((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'));
    return $value === '' ? '0.0.0.0' : $value;
}

function is_rate_limited(string $bucket, int $maxRequests, int $windowSeconds): bool
{
    try {
        $pdo = db();
        $ip = client_ip();
        $now = time();
        $cutoff = $now - $windowSeconds;

        $cleanup = $pdo->prepare('DELETE FROM rate_limit_hits WHERE created_at < :cutoff');
        $cleanup->execute([':cutoff' => $cutoff]);

        $check = $pdo->prepare(
            'SELECT COUNT(*) FROM rate_limit_hits WHERE bucket = :bucket AND ip = :ip AND created_at >= :cutoff'
        );
        $check->execute([
            ':bucket' => $bucket,
            ':ip' => $ip,
            ':cutoff' => $cutoff,
        ]);
        $count = (int) $check->fetchColumn();

        if ($count >= $maxRequests) {
            return true;
        }

        $insert = $pdo->prepare(
            'INSERT INTO rate_limit_hits (bucket, ip, created_at) VALUES (:bucket, :ip, :created_at)'
        );
        $insert->execute([
            ':bucket' => $bucket,
            ':ip' => $ip,
            ':created_at' => $now,
        ]);
    } catch (Throwable) {
        return false;
    }

    return false;
}

