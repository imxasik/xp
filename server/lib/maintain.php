<?php
/**
 * রিটেনশন + কোটা ম্যানেজমেন্ট — ফ্রি হোস্টিং-এর ডিস্ক বাঁচাতে।
 */
declare(strict_types=1);

require_once __DIR__ . '/common.php';

function index_load(): array
{
    return store_read('index.json', ['files' => [], 'updated' => 0]);
}

function index_save(array $idx): bool
{
    $idx['updated'] = time();
    $idx['count']   = count($idx['files'] ?? []);
    $idx['bytes']   = array_sum(array_map(fn($f) => (int)($f['size'] ?? 0), $idx['files'] ?? []));
    return store_write('index.json', $idx);
}

function remove_entry(array &$idx, string $rel): void
{
    @unlink(data_path('files/' . $rel));
    foreach (glob(data_path('thumbs/' . sha1($rel) . '_*')) ?: [] as $t) @unlink($t);
    unset($idx['files'][$rel]);
    // খালি ফোল্ডার পরিষ্কার
    $dir = dirname(data_path('files/' . $rel));
    while ($dir !== data_path('files') && str_starts_with($dir, data_path('files'))) {
        if (!@rmdir($dir)) break;
        $dir = dirname($dir);
    }
}

/** @return array{removed:int,freed:int} */
function run_retention(array &$idx): array
{
    $removed = 0; $freed = 0;
    $days = (int)cfg('retention_days', 0);
    $files = $idx['files'] ?? [];

    if ($days > 0) {
        $cut = time() - $days * 86400;
        foreach ($files as $rel => $f) {
            $when = (int)($f['mtime'] ?? $f['ts'] ?? 0);
            if ($when > 0 && $when < $cut) {
                $freed += (int)($f['size'] ?? 0);
                remove_entry($idx, $rel);
                $removed++;
            }
        }
    }

    $maxBytes = (int)cfg('max_total_mb', 0) * 1024 * 1024;
    if ($maxBytes > 0) {
        $files = $idx['files'] ?? [];
        $total = array_sum(array_map(fn($f) => (int)($f['size'] ?? 0), $files));
        if ($total > $maxBytes) {
            uasort($files, fn($a, $b) => (int)($a['mtime'] ?? 0) <=> (int)($b['mtime'] ?? 0));
            foreach ($files as $rel => $f) {
                if ($total <= $maxBytes) break;
                $sz = (int)($f['size'] ?? 0);
                remove_entry($idx, $rel);
                $total -= $sz; $freed += $sz; $removed++;
            }
        }
    }
    return ['removed' => $removed, 'freed' => $freed];
}

/** থাম্ব ক্যাশ খুব বড় হলে ছাঁটাই */
function trim_thumb_cache(int $maxMb = 120): void
{
    $files = glob(data_path('thumbs/*')) ?: [];
    $total = 0; $list = [];
    foreach ($files as $f) {
        $s = @filesize($f) ?: 0;
        $total += $s;
        $list[$f] = @filemtime($f) ?: 0;
    }
    $limit = $maxMb * 1024 * 1024;
    if ($total <= $limit) return;
    asort($list);
    foreach ($list as $f => $_) {
        if ($total <= $limit) break;
        $total -= (@filesize($f) ?: 0);
        @unlink($f);
    }
}
