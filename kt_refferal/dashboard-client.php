<?php
// Legacy redirect — routes to the correct client dashboard
require_once __DIR__ . '/lib/supabase.php';
require_login();
redirect_by_role();
