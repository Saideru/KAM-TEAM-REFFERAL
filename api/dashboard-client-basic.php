<?php
// Superseded: affiliates use /dashboard-affiliate.php, clients /dashboard-client.php.
require_once __DIR__ . '/lib/supabase.php';
require_login();
redirect_by_role();
