<?php
declare(strict_types=1);

function e(null|string|int|float $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function base_url(string $path = ''): string
{
    $base = (string) config('app.base_url', '');
    if ($base === '') {
        return $path === '' ? '/' : '/' . ltrim($path, '/');
    }

    return $base . ($path === '' ? '' : '/' . ltrim($path, '/'));
}

function redirect(string $path): never
{
    header('Location: ' . base_url($path));
    exit;
}

function request_method(): string
{
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
}

function format_price_eur(int $priceCents): string
{
    $value = number_format($priceCents / 100, 2, ',', '.');
    return $value . ' EUR';
}

function format_datetime_de(string $value): string
{
    try {
        $date = new DateTimeImmutable($value);
    } catch (Throwable) {
        return e($value);
    }

    return $date->format('d.m.Y H:i');
}

function to_datetime_input(string $value): string
{
    try {
        $date = new DateTimeImmutable($value);
    } catch (Throwable) {
        return '';
    }

    return $date->format('Y-m-d\TH:i');
}

function parse_datetime_input(string $value): ?string
{
    if ($value === '') {
        return null;
    }

    $date = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $value);
    if ($date === false) {
        return null;
    }

    return $date->format('Y-m-d H:i:s');
}

function parse_price_to_cents(string $value): ?int
{
    $normalized = str_replace([' ', ','], ['', '.'], trim($value));
    if ($normalized === '' || !is_numeric($normalized)) {
        return null;
    }

    $amount = (float) $normalized;
    if ($amount < 0) {
        return null;
    }

    return (int) round($amount * 100);
}

function flash_set(string $key, string $message): void
{
    $_SESSION['flash'][$key] = $message;
}

function flash_get(string $key): ?string
{
    if (!isset($_SESSION['flash'][$key])) {
        return null;
    }

    $message = (string) $_SESSION['flash'][$key];
    unset($_SESSION['flash'][$key]);
    return $message;
}

function store_form_input(array $data): void
{
    $_SESSION['flash_form'] = $data;
}

function consume_form_input(): array
{
    $data = $_SESSION['flash_form'] ?? [];
    unset($_SESSION['flash_form']);
    return is_array($data) ? $data : [];
}

function old_input(array $input, string $key, string $default = ''): string
{
    $value = $input[$key] ?? $default;
    return is_scalar($value) ? (string) $value : $default;
}

function normalize_text(string $value, int $maxLen = 2000): string
{
    $trimmed = trim($value);
    if (mb_strlen($trimmed) > $maxLen) {
        return mb_substr($trimmed, 0, $maxLen);
    }

    return $trimmed;
}

function selected(string $value, string $expected): string
{
    return $value === $expected ? 'selected' : '';
}

