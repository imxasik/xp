<?php
/**
 * মিরর করা ফাইল সার্ভ করে (data/ ফোল্ডার সরাসরি অ্যাক্সেসযোগ্য নয়)।
 *   file.php?f=path/to/img.jpg          → inline
 *   file.php?f=path/to/img.jpg&dl=1     → download
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/common.php';

$rel = sanitize_rel((string)($_GET['f'] ?? ''));
if ($rel === null) { http_response_code(400); exit('bad request'); }

$path = data_path('files/' . $rel);
$real = realpath($path);
$root = realpath(data_path('files'));
if ($real === false || $root === false || !str_starts_with($real, $root) || !is_file($real)) {
    http_response_code(404); exit('not found');
}

$size  = filesize($real) ?: 0;
$mtime = filemtime($real) ?: time();
$etag  = '"' . md5($rel . $size . $mtime) . '"';

header('Content-Type: ' . mime_of($rel));
header('Content-Length: ' . $size);
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
header('ETag: ' . $etag);
header('Cache-Control: public, max-age=86400, immutable');
header('X-Content-Type-Options: nosniff');
header('Accept-Ranges: bytes');

$disp = !empty($_GET['dl']) ? 'attachment' : 'inline';
header('Content-Disposition: ' . $disp . '; filename="' . rawurlencode(basename($rel)) . '"');

if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) { http_response_code(304); exit; }
if ($_SERVER['REQUEST_METHOD'] === 'HEAD') exit;

while (ob_get_level()) ob_end_clean();
readfile($real);
