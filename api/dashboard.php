<?php
require_once __DIR__ . '/lib/supabase.php';
require_login();
redirect_by_role(isset($_GET['welcome']) ? '1' : null);
