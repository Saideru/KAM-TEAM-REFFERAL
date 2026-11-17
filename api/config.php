<?php
// KAMTEAM // Config — reads from Vercel environment variables
// Set these in: Vercel Dashboard → Project → Settings → Environment Variables

define('SUPABASE_URL',    getenv('SUPABASE_URL')    ?: 'https://YOUR-PROJECT-REF.supabase.co');
define('SUPABASE_ANON_KEY', getenv('SUPABASE_ANON_KEY') ?: 'YOUR-ANON-PUBLIC-KEY');
// Server-side only — bypasses RLS. Never expose to the browser.
define('SUPABASE_SERVICE_KEY', getenv('SUPABASE_SERVICE_KEY') ?: '');
define('APP_URL',         getenv('APP_URL')         ?: 'https://your-project.vercel.app');
define('SESSION_SECRET',  getenv('SESSION_SECRET')  ?: 'CHANGE-ME-generate-a-64-char-random-string');
