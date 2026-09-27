<?php
/**
 * BAF WX Mirror — shared helpers
 */
declare(strict_types=1);

function cfg(?string $key = null, $default = null)
{
    static $cfg = null;
    if ($cfg === null) {
        $cfg = require __DIR__ . '/../config.php';
        if (!empty($cfg['timezone'])) {
            @date_default_timezone_set($cfg['timezone']);
        }
    }
    if ($key === null) return $cfg;
    return array_key_exists($key, $cfg) ? $cfg[$key] : $default;
}

function data_path(string $sub = ''): string
{
    $base = rtrim((string)cfg('data_dir'), '/');
    return $sub === '' ? $base : $base . '/' . ltrim($sub, '/');
}

function ensure_dirs(): void
{
    foreach (['', 'files', 'thumbs', 'logs'] as $d) {
        $p = data_path($d);
        if (!is_dir($p)) @mkdir($p, 0775, true);
    }
    // ডাইরেক্ট অ্যাক্সেস বন্ধ (Apache / LiteSpeed)
    $ht = data_path('.htaccess');
    if (!is_file($ht)) {
        @file_put_contents($ht, "Require all denied\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\nOptions -Indexes\n");
    }
    $idx = data_path('index.html');
    if (!is_file($idx)) @file_put_contents($idx, '');
}

function json_out($payload, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store, max-age=0');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/** JSON ফাইল লক সহ পড়া */
function store_read(string $name, array $fallback = []): array
{
    $p = data_path($name);
    if (!is_file($p)) return $fallback;
    $fh = @fopen($p, 'rb');
    if (!$fh) return $fallback;
    @flock($fh, LOCK_SH);
    $raw = stream_get_contents($fh);
    @flock($fh, LOCK_UN);
    fclose($fh);
    $d = json_decode((string)$raw, true);
    return is_array($d) ? $d : $fallback;
}

/** অ্যাটমিক রাইট */
function store_write(string $name, array $data): bool
{
    ensure_dirs();
    $p   = data_path($name);
    $tmp = $p . '.tmp' . getmypid();
    $ok  = @file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
    if ($ok === false) { @unlink($tmp); return false; }
    return @rename($tmp, $p);
}

/**
 * আপলোড করা relative path কে নিরাপদ করা।
 * "../", ব্যাকস্ল্যাশ, কন্ট্রোল ক্যারেক্টার সব বাদ।
 */
function sanitize_rel(string $rel): ?string
{
    $rel = str_replace('\\', '/', trim($rel));
    $rel = preg_replace('/[\x00-\x1F\x7F]/u', '', $rel) ?? '';
    $rel = ltrim($rel, '/');
    if ($rel === '' || strlen($rel) > 400) return null;

    $out = [];
    foreach (explode('/', $rel) as $seg) {
        if ($seg === '' || $seg === '.') continue;
        if ($seg === '..') return null;
        $seg = preg_replace('/[^A-Za-z0-9._\- ()\[\]+@,=]/u', '_', $seg) ?? '';
        $seg = trim($seg);
        if ($seg === '' || $seg === '.' || $seg === '..') return null;
        $out[] = $seg;
    }
    if (count($out) === 0 || count($out) > 8) return null;
    return implode('/', $out);
}

function kind_of(string $rel): string
{
    $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
    if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'], true)) return 'image';
    if (in_array($ext, ['pdf'], true))                                      return 'pdf';
    if (in_array($ext, ['txt', 'csv', 'log', 'dat', 'md', 'json', 'xml'], true)) return 'text';
    if (in_array($ext, ['zip', 'rar', '7z', 'gz', 'tar', 'bz2'], true))     return 'archive';
    if (in_array($ext, ['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx'], true)) return 'doc';
    return 'other';
}

function mime_of(string $rel): string
{
    static $map = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
        'gif' => 'image/gif', 'webp' => 'image/webp', 'bmp' => 'image/bmp',
        'pdf' => 'application/pdf', 'txt' => 'text/plain; charset=utf-8',
        'csv' => 'text/csv; charset=utf-8', 'log' => 'text/plain; charset=utf-8',
        'json' => 'application/json', 'xml' => 'application/xml',
        'zip' => 'application/zip', 'gz' => 'application/gzip',
    ];
    $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
    return $map[$ext] ?? 'application/octet-stream';
}

function human_size(int $b): string
{
    $u = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    $v = (float)$b;
    while ($v >= 1024 && $i < 3) { $v /= 1024; $i++; }
    return ($i === 0 ? (string)(int)$v : number_format($v, $v < 10 ? 1 : 0)) . ' ' . $u[$i];
}

function client_ip(): string
{
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $k) {
        if (!empty($_SERVER[$k])) return explode(',', (string)$_SERVER[$k])[0];
    }
    return '-';
}

/** agent থেকে আসা রিকোয়েস্ট যাচাই */
function require_agent_auth(): void
{
    $secret = (string)cfg('shared_secret');
    if ($secret === '' || str_starts_with($secret, 'CHANGE_ME')) {
        json_out(['ok' => false, 'error' => 'server_not_configured'], 500);
    }
    $token = (string)($_SERVER['HTTP_X_AUTH_TOKEN'] ?? $_POST['token'] ?? '');
    $ts    = (string)($_SERVER['HTTP_X_AUTH_TS']    ?? $_POST['ts']    ?? '');

    if ($ts === '' || !ctype_digit($ts) || abs(time() - (int)$ts) > 900) {
        json_out(['ok' => false, 'error' => 'bad_timestamp', 'server_time' => time()], 401);
    }
    $expect = hash_hmac('sha256', $ts, $secret);
    if (!hash_equals($expect, $token)) {
        json_out(['ok' => false, 'error' => 'unauthorized'], 401);
    }
}

function agent_log(string $line): void
{
    ensure_dirs();
    $f = data_path('logs/agent-' . date('Y-m') . '.log');
    @file_put_contents($f, date('Y-m-d H:i:s') . ' ' . $line . "\n", FILE_APPEND | LOCK_EX);
}
