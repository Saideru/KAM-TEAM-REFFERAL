<?php
// KAMTEAM // Admin Seeder — run via browser: https://your-project.vercel.app/seed-admins.php
require_once __DIR__ . '/lib/supabase.php';

header('Content-Type: text/plain; charset=utf-8');

$admins = [
    ['email' => 'michael@kam-team.com', 'full_name' => 'Michael',  'password' => 'Numbers 2020'],
    ['email' => 'azi@kam-team.com',     'full_name' => 'Azi',      'password' => 'Numbers 2020'],
    ['email' => 'siedel@kam-team.com',   'full_name' => 'Siedel',   'password' => 'Numbers 2020'],
];

if (!supabase_configured()) {
    echo "ERROR: Configure Supabase environment variables first.\n";
    exit;
}

echo "KAMTEAM // Admin Seeder\n" . str_repeat('=', 50) . "\n\n";

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
        $token = $res['body']['access_token'];
        $uid   = $res['body']['user']['id'] ?? null;
        if ($uid) {
            $patch = supabase_request('PATCH', '/rest/v1/profiles?id=eq.' . urlencode($uid), [
                'role' => 'admin', 'status' => 'accepted',
            ], $token, ['Prefer: return=minimal']);
            echo $patch['error'] ? "CREATED but promotion failed: {$patch['error']}\n" : "OK (admin, accepted)\n";
        }
    } else {
        echo "CREATED (needs email confirmation)\n";
        echo "  After confirming: UPDATE profiles SET role='admin', status='accepted' WHERE email='{$admin['email']}';\n";
    }
}

echo "\n" . str_repeat('=', 50) . "\n";
echo "If email confirmation is on, run this SQL after confirming:\n\n";
echo "UPDATE public.profiles SET role = 'admin', status = 'accepted'\n";
echo "WHERE email IN ('michael@kam-team.com', 'azi@kam-team.com', 'siedel@kam-team.com');\n";
