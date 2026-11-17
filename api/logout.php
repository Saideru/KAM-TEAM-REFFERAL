<?php
require_once __DIR__ . '/lib/supabase.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check()) {
    supabase_sign_out();
    flash('success', 'You\'re signed out.');
}
header('Location: /index.php');
exit;
