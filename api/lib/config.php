<?php
// KAMTEAM // Config — reads from Vercel environment variables
// Set these in: Vercel Dashboard → Project → Settings → Environment Variables

define('SUPABASE_URL',    getenv('SUPABASE_URL')    ?: 'https://YOUR-PROJECT-REF.supabase.co');
define('SUPABASE_ANON_KEY', getenv('SUPABASE_ANON_KEY') ?: 'YOUR-ANON-PUBLIC-KEY');
// Server-side only — bypasses RLS. Never expose to the browser.
define('SUPABASE_SERVICE_KEY', getenv('SUPABASE_SERVICE_KEY') ?: '');
// Without APP_URL set, use the domain the site is being served from (e.g.
// kam-team-refferal.vercel.app) instead of a placeholder, so referral links
// point somewhere real. Only a plain host name is accepted.
$__host = (string) ($_SERVER['HTTP_HOST'] ?? '');
define('APP_URL', getenv('APP_URL') ?: (preg_match('/^[A-Za-z0-9.-]+(:\d+)?$/', $__host) ? 'https://' . $__host : 'https://your-project.vercel.app'));
unset($__host);
define('SESSION_SECRET',  getenv('SESSION_SECRET')  ?: 'CHANGE-ME-generate-a-64-char-random-string');
