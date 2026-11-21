<?php
// Session heartbeat — the only job of this endpoint is to answer
// "how long until this session times out?" and, on a POST from a real
// interaction, to mark the session as active again.
//
// GET  /session.php          → status only, never extends the session
// POST /session.php (+csrf)  → the user is active: extend, then report
require_once __DIR__ . '/lib/supabase.php';

no_store_headers();
header('Content-Type: application/json; charset=utf-8');

$reply = function (array $body, int $code = 200): void {
    http_response_code($code);
    echo json_encode($body);
    exit;
};

if (!supabase_access_token()) {
    $reply(['state' => 'signed_out'], 401);
}

if (session_idle_expired()) {
    supabase_sign_out_local();
    $reply(['state' => 'timed_out'], 401);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) $reply(['state' => 'invalid_csrf'], 403);
    session_touch(true);
}

$reply([
    'state'     => 'active',
    'remaining' => session_idle_remaining(),
    'timeout'   => IDLE_TIMEOUT,
    'warning'   => IDLE_WARNING,
]);
