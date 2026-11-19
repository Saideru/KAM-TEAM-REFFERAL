<?php
require_once __DIR__ . '/lib/supabase.php';

// Lets a signed-in user update the business profile they gave at sign-up.
$token   = require_login();
$profile = current_profile($token);
$back    = $_SERVER['HTTP_REFERER'] ?? '/index.php';
$back    = (parse_url($back, PHP_URL_PATH) ?: '/index.php') . '#business';   // same-site path only

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !$profile || !csrf_check()) {
    header('Location: ' . $back); exit;
}

$nature = in_array($_POST['business_nature'] ?? '', BUSINESS_NATURES, true) ? $_POST['business_nature'] : null;
$res = supabase_request('PATCH', '/rest/v1/profiles?id=eq.' . urlencode($profile['id']), [
    'business_nature'    => $nature,
    'business_struggles' => array_values(array_intersect(array_keys(BUSINESS_STRUGGLES), (array) ($_POST['business_struggles'] ?? []))),
    'business_notes'     => mb_substr(trim((string) ($_POST['business_notes'] ?? '')), 0, 1000) ?: null,
], $token, ['Prefer: return=minimal']);

if ($res['error']) {
    flash('error', 'Could not save your business info.');
} else {
    audit_log($token, 'edit_business', 'profile', $profile['id'], 'User updated business profile');
    if (!empty($profile['assigned_admin'])) {
        notify($token, 'Client updated their business info', $profile['full_name'] . ' changed their business profile.', $profile['assigned_admin'], 'all', '/dashboard-admin.php?edit_user=' . $profile['id'] . '#users');
    }
    flash('success', 'Business info saved.');
}
header('Location: ' . $back);
