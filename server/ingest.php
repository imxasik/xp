<?php
/**
 * BAF WX Mirror — ingest endpoint
 * বাংলাদেশে চলা agent এখানে নতুন ফাইল push করে।
 *
 *   POST ingest.php?action=upload     (multipart: file, rel, mtime, sha256)
 *   POST ingest.php?action=heartbeat  (json body: stats)
 *   POST ingest.php?action=manifest   (agent-এর কাছে থাকা ফাইল তালিকা → server prune)
 *   GET  ingest.php?action=have       (server-এ কী কী আছে, agent diff করার জন্য)
 *
 * Auth: X-Auth-Ts + X-Auth-Token = HMAC-SHA256(ts, shared_secret)
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/common.php';
require_once __DIR__ . '/lib/maintain.php';

ensure_dirs();
require_agent_auth();

$action = (string)($_GET['action'] ?? $_POST['action'] ?? '');

switch ($action) {

    // ── server-এ ইতিমধ্যে কী আছে (agent diff করবে) ────────────────
    case 'have': {
        $idx = index_load();
        $have = [];
        foreach (($idx['files'] ?? []) as $rel => $f) {
            // psize = সোর্স listing যে সাইজ দেখিয়েছিল (গোল করা হতে পারে)।
            // agent এটার সাথেই তুলনা করবে, আসল বাইট-সাইজের সাথে নয়।
            $have[$rel] = [(int)($f['psize'] ?? $f['size'] ?? 0), (int)($f['mtime'] ?? 0),
                           (string)($f['fp'] ?? '')];
        }
        json_out([
            'ok'          => true,
            'server_time' => time(),
            'count'       => count($have),
            'have'        => $have,
        ]);
    }

    // ── ফাইল আপলোড ───────────────────────────────────────────────
    case 'upload': {
        if (empty($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'] ?? '')) {
            json_out(['ok' => false, 'error' => 'no_file', 'php_err' => $_FILES['file']['error'] ?? null], 400);
        }
        $rel = sanitize_rel((string)($_POST['rel'] ?? ''));
        if ($rel === null) json_out(['ok' => false, 'error' => 'bad_path'], 400);

        $tmp  = $_FILES['file']['tmp_name'];
        $size = (int)($_FILES['file']['size'] ?? 0);
        $max  = (int)cfg('max_upload_mb') * 1024 * 1024;
        if ($size <= 0)   json_out(['ok' => false, 'error' => 'empty_file'], 400);
        if ($size > $max) json_out(['ok' => false, 'error' => 'too_large', 'max_mb' => cfg('max_upload_mb')], 413);

        $claimed = strtolower(trim((string)($_POST['sha256'] ?? '')));
        if ($claimed !== '') {
            $actual = hash_file('sha256', $tmp);
            if (!hash_equals($claimed, (string)$actual)) {
                json_out(['ok' => false, 'error' => 'checksum_mismatch'], 422);
            }
        } else {
            $actual = hash_file('sha256', $tmp);
        }

        $dest = data_path('files/' . $rel);
        $dir  = dirname($dest);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            json_out(['ok' => false, 'error' => 'mkdir_failed'], 500);
        }
        if (!@move_uploaded_file($tmp, $dest)) {
            json_out(['ok' => false, 'error' => 'write_failed'], 500);
        }
        @chmod($dest, 0664);

        $mtime = (int)($_POST['mtime'] ?? 0);
        if ($mtime <= 0 || $mtime > time() + 86400) $mtime = time();
        @touch($dest, $mtime);

        // পুরোনো থাম্ব বাতিল
        foreach (glob(data_path('thumbs/' . sha1($rel) . '_*')) ?: [] as $t) @unlink($t);

        $idx = index_load();
        $isNew = !isset($idx['files'][$rel]);
        $psize = (int)($_POST['psize'] ?? 0);
        $idx['files'][$rel] = [
            'size'  => $size,
            'psize' => $psize > 0 ? $psize : $size,
            'fp'    => substr((string)($_POST['fp'] ?? ''), 0, 60),
            'mtime' => $mtime,
            'ts'    => time(),
            'sha'   => substr((string)$actual, 0, 16),
            'kind'  => kind_of($rel),
            'dir'   => (strpos($rel, '/') === false) ? '' : dirname($rel),
        ];
        run_retention($idx);
        index_save($idx);

        $st = store_read('status.json', []);
        $st['last_upload'] = time();
        $st['last_file']   = $rel;
        store_write('status.json', $st);

        json_out(['ok' => true, 'rel' => $rel, 'new' => $isNew, 'size' => $size]);
    }

    // ── হার্টবিট / স্ট্যাটাস ─────────────────────────────────────
    case 'heartbeat': {
        $raw  = file_get_contents('php://input') ?: '';
        $body = json_decode($raw, true);
        if (!is_array($body)) $body = [];

        $idx = index_load();
        $m   = run_retention($idx);
        if ($m['removed'] > 0) index_save($idx);
        trim_thumb_cache();

        $st = store_read('status.json', []);
        $st['last_sync']     = time();
        $st['agent_version'] = (string)($body['agent_version'] ?? '?');
        $st['agent_host']    = substr((string)($body['host'] ?? '?'), 0, 40);
        $st['source_ok']     = (bool)($body['source_ok'] ?? true);
        $st['scanned']       = (int)($body['scanned'] ?? 0);
        $st['uploaded']      = (int)($body['uploaded'] ?? 0);
        $st['skipped']       = (int)($body['skipped'] ?? 0);
        $st['errors']        = (int)($body['errors'] ?? 0);
        $st['duration']      = (float)($body['duration'] ?? 0);
        $st['agent_ip']      = client_ip();
        store_write('status.json', $st);

        agent_log(sprintf(
            'heartbeat ip=%s scanned=%d up=%d skip=%d err=%d src_ok=%d pruned=%d',
            client_ip(), $st['scanned'], $st['uploaded'], $st['skipped'], $st['errors'],
            $st['source_ok'] ? 1 : 0, $m['removed']
        ));

        json_out(['ok' => true, 'server_time' => time(), 'pruned' => $m['removed'], 'total_files' => count($idx['files'] ?? [])]);
    }

    // ── manifest: source-এ আর নেই এমন ফাইল mirror থেকেও মুছে দেওয়া ─
    case 'manifest': {
        $raw  = file_get_contents('php://input') ?: '';
        $body = json_decode($raw, true);
        $keep = is_array($body['files'] ?? null) ? $body['files'] : null;
        if ($keep === null) json_out(['ok' => false, 'error' => 'bad_body'], 400);
        if (empty($body['authoritative'])) json_out(['ok' => true, 'skipped' => true]);

        $keepSet = array_flip($keep);
        $idx = index_load();
        $removed = 0;
        foreach (array_keys($idx['files'] ?? []) as $rel) {
            if (!isset($keepSet[$rel])) { remove_entry($idx, $rel); $removed++; }
        }
        if ($removed) index_save($idx);
        json_out(['ok' => true, 'removed' => $removed]);
    }

    default:
        json_out(['ok' => false, 'error' => 'unknown_action'], 400);
}
