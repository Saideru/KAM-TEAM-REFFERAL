<?php
require_once __DIR__ . '/lib/supabase.php';

// Sign-out is POST-only and CSRF-checked, so no link or image can trigger it.
// reason=timeout comes from the idle watchdog in assets/js/app.js.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check()) {
    $idle = ($_POST['reason'] ?? '') === 'timeout';

    if ($t = supabase_access_token()) {
        $session = &_session();
        audit_log($t, $idle ? 'logout_idle' : 'logout', 'user', $session['sb']['user_id'] ?? null,
            $idle ? 'Signed out after ' . (IDLE_TIMEOUT / 60) . ' minutes of inactivity' : 'User signed out');
    }

    supabase_sign_out();
    $idle
        ? flash('warn', 'You were signed out after ' . (IDLE_TIMEOUT / 60) . ' minutes of inactivity. Please sign in again.')
        : flash('success', 'You\'re signed out.');
}

header('Location: /index.php' . (($_POST['reason'] ?? '') === 'timeout' ? '?timeout=1' : ''));
exit;
