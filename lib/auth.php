<?php
declare(strict_types=1);

/**
 * Password hashing — keep a single format across PHP and the Python
 * fallback server in serve.py so the same data/*.json works in both runtimes.
 *
 * Format: a fixed 64-char sha256 hex string of "xp|" . $password.
 * Verification accepts BOTH this format AND the bcrypt/argon2 format
 * produced by password_hash(), so legacy seeds and Python-written hashes
 * keep working after the change.
 */
function auth_hash(string $password): string
{
    return hash('sha256', 'xp|' . $password);
}

function auth_check_hash(string $password, string $stored): bool
{
    if (!is_string($stored) || $stored === '') {
        return false;
    }
    // Native bcrypt/argon2 hash from password_hash()
    if (preg_match('/^\$(2[axy]|argon2(i|id))\$/', $stored) === 1) {
        return password_verify($password, $stored);
    }
    // Legacy sha256 fallback — accept "xp|<hex>" prefixed values too
    $candidate = hash('sha256', 'xp|' . $password);
    if (hash_equals($candidate, $stored)) {
        return true;
    }
    if (hash_equals($candidate, preg_replace('/^xp\|/', '', $stored))) {
        return true;
    }
    // Some old seeds stored raw sha256 hex (64 chars) without prefix
    if (preg_match('/^[a-f0-9]{64}$/', $stored) === 1 && hash_equals($candidate, $stored)) {
        return true;
    }
    return false;
}

function auth_register(string $name, string $phone, string $password): array
{
    $phone = preg_replace('/\D+/', '', $phone);
    if (!preg_match('/^01[3-9]\d{8}$/', $phone)) {
        return ['ok' => false, 'error' => 'সঠিক বাংলাদেশি মোবাইল নম্বর দিন'];
    }
    if (strlen($password) < 6) {
        return ['ok' => false, 'error' => 'পাসওয়ার্ড কমপক্ষে ৬ অক্ষর'];
    }
    if (store_find('users', fn($u) => $u['phone'] === $phone)) {
        return ['ok' => false, 'error' => 'এই নম্বরে অ্যাকাউন্ট আছে'];
    }
    $user = [
        'id' => xp_id('u_'),
        'name' => trim($name) ?: 'গ্রাহক',
        'phone' => $phone,
        'password' => auth_hash($password),
        'wallet' => 0,
        'role' => 'user',
        'status' => 'active',
        'created_at' => xp_now(),
    ];
    store_push('users', $user);
    $_SESSION['uid'] = $user['id'];
    unset($user['password']);
    return ['ok' => true, 'user' => $user];
}

function auth_login(string $phone, string $password): array
{
    $phone = preg_replace('/\D+/', '', $phone);
    $user = store_find('users', fn($u) => $u['phone'] === $phone);
    if (!$user || !auth_check_hash($password, $user['password'] ?? '')) {
        return ['ok' => false, 'error' => 'নম্বর বা পাসওয়ার্ড ভুল'];
    }
    if (($user['status'] ?? 'active') !== 'active') {
        return ['ok' => false, 'error' => 'অ্যাকাউন্ট নিষ্ক্রিয়'];
    }
    // Upgrade legacy hashes to the canonical format on successful login.
    if (!preg_match('/^[a-f0-9]{64}$/', (string)($user['password'] ?? ''))) {
        store_update('users', $user['id'], fn($u) => array_merge($u, ['password' => auth_hash($password)]));
    }
    $_SESSION['uid'] = $user['id'];
    unset($user['password']);
    return ['ok' => true, 'user' => $user];
}

function admin_login(string $user, string $password): array
{
    $admin = store_find('admins', fn($a) => $a['username'] === $user);
    if (!$admin || !auth_check_hash($password, $admin['password'] ?? '')) {
        return ['ok' => false, 'error' => 'লগইন ব্যর্থ'];
    }
    // Upgrade legacy hashes to the canonical format.
    if (!preg_match('/^[a-f0-9]{64}$/', (string)($admin['password'] ?? ''))) {
        store_update('admins', $admin['id'], fn($a) => array_merge($a, ['password' => auth_hash($password)]));
    }
    $_SESSION['aid'] = $admin['id'];
    unset($admin['password']);
    return ['ok' => true, 'admin' => $admin];
}

function auth_logout(): void
{
    unset($_SESSION['uid'], $_SESSION['aid']);
}
