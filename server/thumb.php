<?php
/**
 * অন-দ্য-ফ্লাই থাম্বনেইল (GD) + ডিস্ক ক্যাশ। মোবাইল ডেটা বাঁচায়।
 * GD না থাকলে অরিজিনাল ফাইলে রিডাইরেক্ট করে।
 *   thumb.php?f=path/img.jpg&w=520
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/common.php';

$rel = sanitize_rel((string)($_GET['f'] ?? ''));
if ($rel === null) { http_response_code(400); exit('bad request'); }

$src = data_path('files/' . $rel);
if (!is_file($src)) { http_response_code(404); exit('not found'); }

$w = (int)($_GET['w'] ?? cfg('thumb_width', 520));
$w = max(120, min(1200, $w));

$fallback = 'file.php?f=' . rawurlencode($rel);
if (!cfg('thumbs_enabled', true) || !function_exists('imagecreatetruecolor') || kind_of($rel) !== 'image') {
    header('Location: ' . $fallback, true, 302);
    exit;
}

ensure_dirs();
$cache = data_path('thumbs/' . sha1($rel) . '_' . $w . '.jpg');

if (!is_file($cache) || filemtime($cache) < (filemtime($src) ?: 0)) {
    $info = @getimagesize($src);
    if (!$info) { header('Location: ' . $fallback, true, 302); exit; }
    [$sw, $sh] = $info;
    if ($sw <= 0 || $sh <= 0) { header('Location: ' . $fallback, true, 302); exit; }

    // খুব বড় ছবি হলে মেমরি বাঁচাতে ছেড়ে দাও
    if ($sw * $sh > 40000000) { header('Location: ' . $fallback, true, 302); exit; }

    // প্রতিটি ফরম্যাটের জন্য আলাদা করে দেখা — কিছু হোস্টে GD আছে কিন্তু JPEG সাপোর্ট নেই
    $loader = match ($info[2]) {
        IMAGETYPE_JPEG => 'imagecreatefromjpeg',
        IMAGETYPE_PNG  => 'imagecreatefrompng',
        IMAGETYPE_GIF  => 'imagecreatefromgif',
        IMAGETYPE_WEBP => 'imagecreatefromwebp',
        IMAGETYPE_BMP  => 'imagecreatefrombmp',
        default        => null,
    };
    if ($loader === null || !function_exists($loader) || !function_exists('imagejpeg')) {
        header('Location: ' . $fallback, true, 302); exit;
    }
    try {
        $img = @$loader($src);
    } catch (Throwable $e) {
        $img = false;
    }
    if (!$img) { header('Location: ' . $fallback, true, 302); exit; }

    if ($sw <= $w) { $tw = $sw; $th = $sh; }
    else           { $tw = $w;  $th = (int)max(1, round($sh * ($w / $sw))); }

    $dst = imagecreatetruecolor($tw, $th);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 12, 16, 24));
    imagecopyresampled($dst, $img, 0, 0, 0, 0, $tw, $th, $sw, $sh);
    imagejpeg($dst, $cache, (int)cfg('thumb_quality', 78));
    imagedestroy($dst);
    imagedestroy($img);
}

if (!is_file($cache)) { header('Location: ' . $fallback, true, 302); exit; }

$etag = '"t' . md5($rel . $w . filemtime($cache)) . '"';
header('Content-Type: image/jpeg');
header('Content-Length: ' . (filesize($cache) ?: 0));
header('Cache-Control: public, max-age=604800');
header('ETag: ' . $etag);
if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) { http_response_code(304); exit; }
while (ob_get_level()) ob_end_clean();
readfile($cache);
