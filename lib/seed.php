<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/config.php';
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/auth.php';

function xp_seed_if_empty(): void
{
    if (!is_dir(XP_DATA)) {
        mkdir(XP_DATA, 0777, true);
    }
    $files = ['users','admins','offers','operators','orders','invoices','wallet','gateways','settings','pages','banners','categories'];
    foreach ($files as $f) {
        $p = xp_json_path($f);
        if (!is_file($p)) {
            file_put_contents($p, $f === 'settings' ? '{}' : '[]');
        }
    }

    if (!xp_read('admins')) {
        xp_write('admins', [[
            'id' => 'a_root',
            'username' => 'admin',
            'name' => 'সুপার অ্যাডমিন',
            'password' => password_hash('admin123', PASSWORD_DEFAULT),
            'created_at' => xp_now(),
        ]]);
    }

    if (!xp_read('settings')) {
        xp_write('settings', [
            'site_name' => 'XP Telecom',
            'tagline' => 'বাংলাদেশের সকল অপারেটর অফার ও রিচার্জ',
            'phone' => '01700000000',
            'whatsapp' => '01700000000',
            'notice' => 'স্বাগতম! অফার কেনার আগে নম্বরটি ভালো করে চেক করুন।',
            'auto_approve_payments' => true,
            'auto_process_orders' => true,
            'gateway_secret' => 'xp-secret-change-me',
            'min_topup' => 20,
            'theme' => 'ocean',
        ]);
    }

    if (!xp_read('operators')) {
        xp_write('operators', [
            ['code' => 'gp', 'name' => 'Grameenphone', 'color' => '#00a651', 'recharge_rate' => 99.5, 'active' => true, 'prefixes' => ['013','017']],
            ['code' => 'robi', 'name' => 'Robi', 'color' => '#ed1c24', 'recharge_rate' => 99.6, 'active' => true, 'prefixes' => ['018']],
            ['code' => 'airtel', 'name' => 'Airtel', 'color' => '#ed1c24', 'recharge_rate' => 99.6, 'active' => true, 'prefixes' => ['016']],
            ['code' => 'bl', 'name' => 'Banglalink', 'color' => '#f26522', 'recharge_rate' => 99.4, 'active' => true, 'prefixes' => ['014','019']],
            ['code' => 'tt', 'name' => 'Teletalk', 'color' => '#0072bc', 'recharge_rate' => 100, 'active' => true, 'prefixes' => ['015']],
        ]);
    }

    if (!xp_read('categories')) {
        xp_write('categories', [
            ['id' => 'regular', 'name' => 'রেগুলার অফার', 'icon' => '⚡'],
            ['id' => 'drive', 'name' => 'ড্রাইভ অফার', 'icon' => '🚗'],
            ['id' => 'internet', 'name' => 'ইন্টারনেট', 'icon' => '📶'],
            ['id' => 'minutes', 'name' => 'মিনিট', 'icon' => '📞'],
            ['id' => 'combo', 'name' => 'কম্বো', 'icon' => '🎁'],
            ['id' => 'recharge', 'name' => 'রিচার্জ', 'icon' => '💰'],
        ]);
    }

    if (!xp_read('gateways')) {
        xp_write('gateways', [
            ['code' => 'wallet', 'name' => 'এক্সপি ওয়ালেট', 'merchant' => '', 'enabled' => true, 'auto' => true, 'instructions' => 'ইনস্ট্যান্ট কাটা হবে আপনার ওয়ালেট থেকে।'],
            ['code' => 'bkash', 'name' => 'বিকাশ', 'merchant' => '01711111111', 'enabled' => true, 'auto' => true, 'instructions' => 'Send Money করুন। রেফারেন্সে পে-কোড দিন। তারপর TrxID জমা দিন।'],
            ['code' => 'nagad', 'name' => 'নগদ', 'merchant' => '01712222222', 'enabled' => true, 'auto' => true, 'instructions' => 'Send Money করুন। রেফারেন্সে পে-কোড দিন।'],
            ['code' => 'rocket', 'name' => 'রকেট', 'merchant' => '01713333333', 'enabled' => true, 'auto' => true, 'instructions' => 'Send Money করুন। রেফারেন্সে পে-কোড দিন।'],
            ['code' => 'upay', 'name' => 'উপায়', 'merchant' => '01714444444', 'enabled' => true, 'auto' => true, 'instructions' => 'Send Money করে TrxID দিন।'],
            ['code' => 'ucash', 'name' => 'ইউক্যাশ', 'merchant' => '01715555555', 'enabled' => true, 'auto' => true, 'instructions' => 'Send Money করে TrxID দিন।'],
            ['code' => 'bank', 'name' => 'ব্যাংক', 'merchant' => 'XP Telecom Ltd — 1234567890 — City Bank', 'enabled' => true, 'auto' => false, 'instructions' => 'ব্যাংক ট্রান্সফার করে রেফারেন্স নম্বর দিন।'],
        ]);
    }

    if (!xp_read('banners')) {
        xp_write('banners', [
            ['id' => 'b1', 'title' => 'সব অপারেটর এক ছাদের নিচে', 'text' => 'জিপি, রবি, এয়ারটেল, বাংলালিংক, টেলিটক', 'active' => true],
            ['id' => 'b2', 'title' => 'ড্রাইভ অফার স্টক লাইভ', 'text' => 'কম দামে হাই স্পিড প্যাক', 'active' => true],
        ]);
    }

    if (!xp_read('offers')) {
        $offers = [];
        $pack = function ($op, $cat, $title, $desc, $face, $price, $validity, $stock = 99) {
            return [
                'id' => xp_id('of_'),
                'operator' => $op,
                'category' => $cat,
                'title' => $title,
                'description' => $desc,
                'face_value' => $face,
                'price' => $price,
                'validity' => $validity,
                'stock' => $stock,
                'sold' => 0,
                'featured' => true,
                'active' => true,
                'created_at' => xp_now(),
            ];
        };
        $offers[] = $pack('gp', 'regular', 'জিপি ৫ জিবি', '৫ জিবি ডাটা, ৭ দিন', 69, 62, '৭ দিন');
        $offers[] = $pack('gp', 'drive', 'জিপি ড্রাইভ ১৫ জিবি', 'ড্রাইভ অফার, ৩০ দিন', 299, 249, '৩০ দিন');
        $offers[] = $pack('gp', 'combo', 'জিপি কম্বো ১৯৯', '১০ জিবি + ১০০ মিনিট', 199, 175, '৩০ দিন');
        $offers[] = $pack('gp', 'minutes', 'জিপি ১০০ মিনিট', 'যেকোনো নম্বরে', 58, 52, '৭ দিন');
        $offers[] = $pack('robi', 'regular', 'রবি ৮ জিবি', '৮ জিবি, ৩০ দিন', 198, 175, '৩০ দিন');
        $offers[] = $pack('robi', 'drive', 'রবি ড্রাইভ ২৫ জিবি', 'ড্রাইভ স্পেশাল', 399, 329, '৩০ দিন');
        $offers[] = $pack('airtel', 'internet', 'এয়ারটেল ১০ জিবি', '৪জি ডাটা', 179, 159, '৩০ দিন');
        $offers[] = $pack('bl', 'regular', 'বিএল ৬ জিবি', '৬ জিবি বান্ডেল', 129, 115, '১৫ দিন');
        $offers[] = $pack('bl', 'drive', 'বিএল ড্রাইভ ৪০ জিবি', 'ড্রাইভ মেগা', 499, 419, '৩০ দিন');
        $offers[] = $pack('tt', 'internet', 'টেলিটক ৫ জিবি', 'সরকারি নেটওয়ার্ক', 99, 92, '৩০ দিন');
        $offers[] = $pack('gp', 'internet', 'জিপি নাইট ১০ জিবি', '১২টা-৬টা', 48, 42, '৭ দিন');
        xp_write('offers', $offers);
    }
}
