<?php
declare(strict_types=1);

function auth_hash(string $password): string
{
    return password_hash($password, PASSWORD_DEFAULT);
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
    if (!$user || !password_verify($password, $user['password'])) {
        return ['ok' => false, 'error' => 'নম্বর বা পাসওয়ার্ড ভুল'];
    }
    if (($user['status'] ?? 'active') !== 'active') {
        return ['ok' => false, 'error' => 'অ্যাকাউন্ট নিষ্ক্রিয়'];
    }
    $_SESSION['uid'] = $user['id'];
    unset($user['password']);
    return ['ok' => true, 'user' => $user];
}

function admin_login(string $user, string $password): array
{
    $admin = store_find('admins', fn($a) => $a['username'] === $user);
    if (!$admin || !password_verify($password, $admin['password'])) {
        return ['ok' => false, 'error' => 'লগইন ব্যর্থ'];
    }
    $_SESSION['aid'] = $admin['id'];
    unset($admin['password']);
    return ['ok' => true, 'admin' => $admin];
}

function auth_logout(): void
{
    unset($_SESSION['uid'], $_SESSION['aid']);
}
