<?php
declare(strict_types=1);

define('XP_ROOT', dirname(__DIR__));
define('XP_DATA', XP_ROOT . '/data');
define('XP_SESSION_NAME', 'XPTEL_SID');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name(XP_SESSION_NAME);
    session_start();
}

date_default_timezone_set('Asia/Dhaka');

function xp_json_path(string $name): string
{
    return XP_DATA . '/' . $name . '.json';
}

function xp_read(string $name, $default = [])
{
    $path = xp_json_path($name);
    if (!is_file($path)) {
        return $default;
    }
    $raw = file_get_contents($path);
    $data = json_decode($raw ?: 'null', true);
    return $data === null ? $default : $data;
}

function xp_write(string $name, $data): bool
{
    $path = xp_json_path($name);
    $tmp = $path . '.tmp';
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if (file_put_contents($tmp, $json, LOCK_EX) === false) {
        return false;
    }
    return rename($tmp, $path);
}

function xp_id(string $prefix = ''): string
{
    return $prefix . bin2hex(random_bytes(6)) . dechex(time());
}

function xp_now(): string
{
    return date('Y-m-d H:i:s');
}

function xp_settings(): array
{
    static $s = null;
    if ($s === null) {
        $s = xp_read('settings', []);
    }
    return $s;
}

function xp_csrf(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function xp_csrf_ok(?string $token): bool
{
    return is_string($token) && isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $token);
}

function xp_user(): ?array
{
    if (empty($_SESSION['uid'])) {
        return null;
    }
    foreach (xp_read('users', []) as $u) {
        if ($u['id'] === $_SESSION['uid']) {
            return $u;
        }
    }
    return null;
}

function xp_admin(): ?array
{
    if (empty($_SESSION['aid'])) {
        return null;
    }
    foreach (xp_read('admins', []) as $a) {
        if ($a['id'] === $_SESSION['aid']) {
            return $a;
        }
    }
    return null;
}

function xp_json_out($data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function xp_money(float $n): string
{
    return number_format($n, 2, '.', ',');
}
