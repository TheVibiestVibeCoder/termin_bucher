<?php
declare(strict_types=1);

function env(string $name, ?string $default = null): ?string
{
    $value = getenv($name);
    if ($value === false || $value === '') {
        return $default;
    }

    return $value;
}

function app_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $root = dirname(__DIR__);
    $dataDir = $root . DIRECTORY_SEPARATOR . 'data';

    $config = [
        'app' => [
            'name' => env('APP_NAME', 'Disinformation Consulting Workshops'),
            'base_url' => rtrim((string) env('APP_BASE_URL', ''), '/'),
            'timezone' => env('APP_TIMEZONE', 'Europe/Berlin'),
        ],
        'paths' => [
            'root' => $root,
            'data' => $dataDir,
            'db' => $dataDir . DIRECTORY_SEPARATOR . 'workshops.sqlite',
            'logs' => $dataDir . DIRECTORY_SEPARATOR . 'logs',
            'backups' => $dataDir . DIRECTORY_SEPARATOR . 'backups',
            'mail_log' => $dataDir . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'email.log',
        ],
        'admin' => [
            'default_username' => env('ADMIN_USERNAME', 'admin'),
            'default_password' => env('ADMIN_PASSWORD', 'ChangeMe!123'),
        ],
        'mail' => [
            'default_from' => env('MAIL_FROM', 'noreply@disinfoconsulting.eu'),
            'default_reply_to' => env('MAIL_REPLY_TO', 'kontakt@disinfoconsulting.eu'),
        ],
    ];

    return $config;
}

function config(string $key, mixed $default = null): mixed
{
    $parts = explode('.', $key);
    $value = app_config();

    foreach ($parts as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return $default;
        }
        $value = $value[$part];
    }

    return $value;
}

