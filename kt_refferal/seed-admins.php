<?php
// KAMTEAM // Admin Seeder
// Run once: php seed-admins.php  (or visit http://localhost/kt_refferal/seed-admins.php)
// Creates the 3 hardcoded admin accounts: Michael, Azi, Siedel
// Password: Numbers 2020

require_once __DIR__ . '/lib/supabase.php';

$admins = [
    ['email' => 'michael@kam-team.com', 'full_name' => 'Michael',  'password' => 'Numbers 2020'],
    ['email' => 'azi@kam-team.com',     'full_name' => 'Azi',      'password' => 'Numbers 2020'],
    ['email' => 'siedel@kam-team.com',   'full_name' => 'Siedel',   'password' => 'Numbers 2020'],
];

if (php_sapi_name() !== 'cli') {
    header('Content-Type: text/plain');
}

if (!supabase_configured()) {
    echo "ERROR: Configure Supabase credentials in config.php first.\n";
    exit(1);
}

echo "KAMTEAM // Admin Seeder\n";
echo str_repeat('=', 50) . "\n\n";

foreach ($admins as $admin) {
    echo "Creating {$admin['full_name']} ({$admin['email']})... ";

    $res = supabase_sign_up($admin['email'], $admin['password'], [
        'full_name' => $admin['full_name'],
        'role'      => 'referral',
    ], APP_URL . '/index.php?confirmed=1');

    if ($res['error']) {
        echo "SKIP: {$res['error']}\n";
        continue;
    }

    if (!empty($res['body']['access_token'])) {
        // Auto-confirmed — promote to admin immediately
        $token = $res['body']['access_token'];
        $uid   = $res['body']['user']['id'] ?? null;
        if ($uid) {
            $patch = supabase_request('PATCH', '/rest/v1/profiles?id=eq.' . urlencode($uid), [
                'role'   => 'admin',
                'status' => 'accepted',
            ], $token, ['Prefer: return=minimal']);

            if ($patch['error']) {
                echo "CREATED but role update failed: {$patch['error']}\n";
                echo "  Run manually: UPDATE profiles SET role='admin', status='accepted' WHERE email='{$admin['email']}';\n";
            } else {
                echo "OK (admin, accepted)\n";
            }
        }
    } else {
        echo "CREATED (needs email confirmation)\n";
        echo "  After confirming, run: UPDATE profiles SET role='admin', status='accepted' WHERE email='{$admin['email']}';\n";
    }
}

echo "\n" . str_repeat('=', 50) . "\n";
echo "Done. If email confirmation is enabled, confirm each account first,\n";
echo "then run this SQL in Supabase SQL Editor:\n\n";
echo "UPDATE public.profiles SET role = 'admin', status = 'accepted'\n";
echo "WHERE email IN ('michael@kam-team.com', 'azi@kam-team.com', 'siedel@kam-team.com');\n";
