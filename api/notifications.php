<?php
require_once __DIR__ . '/lib/supabase.php';

// Marks the signed-in user's notifications as read (called by the bell).
header('Content-Type: application/json');

$token   = supabase_access_token();
$profile = $token ? current_profile($token) : null;
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !$profile || !csrf_check()) {
    http_response_code(403);
    echo json_encode(['ok' => false]);
    exit;
}

supabase_request('PATCH', '/rest/v1/profiles?id=eq.' . urlencode($profile['id']),
    ['notif_seen_at' => date('c')], $token, ['Prefer: return=minimal']);
echo json_encode(['ok' => true]);
