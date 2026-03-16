<?php
declare(strict_types=1);

function h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function nowUtc(): string
{
    return gmdate('c');
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function consumeFlashes(): array
{
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return is_array($messages) ? $messages : [];
}

function csrfToken(): string
{
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['csrf_token'];
}

function verifyCsrfOrFail(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals(csrfToken(), $token)) {
        http_response_code(422);
        exit('Invalid CSRF token');
    }
}

function isPost(): bool
{
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function redirect(string $path): never
{
    header('Location: ' . $path);
    exit;
}

function randomCode(int $length = 8): string
{
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $res = '';
    for ($i = 0; $i < $length; $i++) {
        $res .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $res;
}

function randomSlug(int $length = 12): string
{
    $chars = 'abcdefghijkmnopqrstuvwxyz23456789';
    $res = '';
    for ($i = 0; $i < $length; $i++) {
        $res .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $res;
}

function formatMoney(int $value): string
{
    return '$' . number_format($value);
}

function formatPoints(float $value): string
{
    return number_format($value, 2);
}

function utcDisplay(string $iso): string
{
    try {
        $dt = new DateTimeImmutable($iso, new DateTimeZone('UTC'));
        return $dt->format('Y-m-d H:i') . ' UTC';
    } catch (Throwable $e) {
        return $iso;
    }
}

function boolFromPost(string $key): bool
{
    return isset($_POST[$key]) && $_POST[$key] !== '0';
}

function toFloat(mixed $value, float $default = 0.0): float
{
    if (is_numeric($value)) {
        return (float)$value;
    }
    return $default;
}
