<?php
require_once __DIR__ . '/lib/supabase.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check()) {
    if ($t = supabase_access_token()) {
        $session = &_session();
        audit_log($t, 'logout', 'user', $session['sb']['user_id'] ?? null, 'User signed out');
    }
    supabase_sign_out();
    flash('success', 'You\'re signed out.');
}
header('Location: /index.php');
exit;
