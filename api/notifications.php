<?php
// Per-session endpoint. Two jobs, deliberately one file: Vercel counts every
// api/*.php as a Serverless Function and the plan allows 12.
//
//   action=ping (default, POST+CSRF)  the user is active → extend the session
//   action=ping (GET)                 how long is left, without extending it
//   action=seen (POST+CSRF)           mark this user's notifications as read
//
// Reachable as /session.php and /notifications.php (see vercel.json).
require_once __DIR__ . '/lib/supabase.php';

no_store_headers();
header('Content-Type: application/json; charset=utf-8');

$reply = function (array $body, int $code = 200): void {
    http_response_code($code);
    echo json_encode($body);
    exit;
};

$action = (string) ($_POST['action'] ?? $_GET['action'] ?? '');
if ($action === '' && str_contains((string) ($_SERVER['REQUEST_URI'] ?? ''), 'notifications')) {
    $action = 'seen';          // the bell posts without naming an action
}

$token = supabase_access_token();
if (!$token) {
    $reply(['state' => 'signed_out', 'ok' => false], 401);
}

if (session_idle_expired()) {
    supabase_sign_out_local();
    $reply(['state' => 'timed_out', 'ok' => false], 401);
}

$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
if ($isPost && !csrf_check()) {
    $reply(['state' => 'invalid_csrf', 'ok' => false], 403);
}

if ($action === 'seen') {
    $profile = current_profile($token);
    if (!$isPost || !$profile) $reply(['ok' => false], 403);

    supabase_request('PATCH', '/rest/v1/profiles?id=eq.' . urlencode($profile['id']),
        ['notif_seen_at' => date('c')], $token, ['Prefer: return=minimal']);
    session_touch(true);       // opening the bell is activity
    $reply(['ok' => true, 'remaining' => session_idle_remaining()]);
}

// action=ping
if ($isPost) session_touch(true);

$reply([
    'state'     => 'active',
    'ok'        => true,
    'remaining' => session_idle_remaining(),
    'timeout'   => IDLE_TIMEOUT,
    'warning'   => IDLE_WARNING,
]);
