<?php
// KAMTEAM // TEMPORARY DIAGNOSTIC — delete after use.
// Reports connection health without printing secrets.
require_once __DIR__ . '/lib/supabase.php';

header('Content-Type: text/plain; charset=utf-8');

function mask(string $v): string
{
    if ($v === '') return '(empty)';
    return substr($v, 0, 12) . '…' . substr($v, -4) . '  [len ' . strlen($v) . ']';
}

echo "KAMTEAM // DIAGNOSTIC\n" . str_repeat('=', 55) . "\n\n";

echo "SUPABASE_URL         : " . (SUPABASE_URL ?: '(empty)') . "\n";
echo "SUPABASE_ANON_KEY    : " . mask(SUPABASE_ANON_KEY) . "\n";
echo "SUPABASE_SERVICE_KEY : " . mask(SUPABASE_SERVICE_KEY) . "\n";
echo "key style            : " . (str_starts_with(SUPABASE_SERVICE_KEY, 'sb_') ? 'NEW (sb_secret_)' : (str_starts_with(SUPABASE_SERVICE_KEY, 'eyJ') ? 'LEGACY (JWT)' : 'unknown/empty')) . "\n";
echo "supabase_configured(): " . (supabase_configured() ? 'yes' : 'NO') . "\n\n";

echo str_repeat('-', 55) . "\nQuery: profiles?username=eq.michael\n" . str_repeat('-', 55) . "\n";

$res = supabase_request('GET', '/rest/v1/profiles?select=id,username,role,status,password_hash&username=eq.michael');

echo "HTTP status : " . $res['status'] . "\n";
echo "error       : " . ($res['error'] ?? '(none)') . "\n";
echo "rows        : " . (is_array($res['body']) ? count($res['body']) : 'not an array') . "\n";

if (!empty($res['body'][0])) {
    $p = $res['body'][0];
    echo "\nFound user  : " . $p['username'] . "\n";
    echo "role/status : {$p['role']} / {$p['status']}\n";
    $hash = (string) ($p['password_hash'] ?? '');
    echo "hash prefix : " . ($hash === '' ? '(EMPTY!)' : substr($hash, 0, 7) . '… [len ' . strlen($hash) . ']') . "\n";
    echo "verify pass : " . (password_verify('Numbers20:20', $hash) ? 'MATCH' : 'NO MATCH') . "\n";
} else {
    echo "\nNo row returned. Raw body:\n";
    echo substr(json_encode($res['body']), 0, 500) . "\n";
}

echo "\n" . str_repeat('=', 55) . "\nDelete api/diag.php when finished.\n";
