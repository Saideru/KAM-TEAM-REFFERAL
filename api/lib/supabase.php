<?php
// KAMTEAM // Supabase client — serverless-compatible (cookie sessions for Vercel)

require_once __DIR__ . '/../config.php';

// =====================================================================
// Cookie-based session (replaces $_SESSION for serverless/Vercel)
// =====================================================================

function _enc_key(): string { return hash('sha256', SESSION_SECRET, true); }
function _enc_iv(): string  { return substr(hash('sha256', 'iv:' . SESSION_SECRET), 0, 16); }

function _encrypt(string $data): string
{
    return base64_encode(openssl_encrypt($data, 'aes-256-cbc', _enc_key(), OPENSSL_RAW_DATA, _enc_iv()));
}

function _decrypt(string $data): ?string
{
    $raw = openssl_decrypt(base64_decode($data), 'aes-256-cbc', _enc_key(), OPENSSL_RAW_DATA, _enc_iv());
    return $raw === false ? null : $raw;
}

function &_session(): array
{
    static $store = null;
    if ($store === null) {
        $cookie = $_COOKIE['kt_session'] ?? '';
        if ($cookie !== '') {
            $json = _decrypt($cookie);
            $store = $json ? (json_decode($json, true) ?: []) : [];
        } else {
            $store = [];
        }
    }
    return $store;
}

function _save_session(): void
{
    $data = &_session();
    $encrypted = _encrypt(json_encode($data));
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
           || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    setcookie('kt_session', $encrypted, [
        'expires'  => time() + 86400 * 30,
        'path'     => '/',
        'httponly'  => true,
        'samesite' => 'Lax',
        'secure'   => $secure,
    ]);
}

function _clear_session(): void
{
    $store = &_session();
    $store = [];
    setcookie('kt_session', '', ['expires' => 1, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
}

// =====================================================================
// Supabase HTTP client
// =====================================================================

function supabase_configured(): bool
{
    return strpos(SUPABASE_URL, 'YOUR-PROJECT-REF') === false
        && SUPABASE_SERVICE_KEY !== '';
}

function supabase_request(string $method, string $path, ?array $body = null, ?string $accessToken = null, array $extraHeaders = []): array
{
    $key     = $accessToken ?: (SUPABASE_SERVICE_KEY ?: SUPABASE_ANON_KEY);
    $headers = array_merge([
        'apikey: ' . $key,
        'Authorization: Bearer ' . $key,
        'Content-Type: application/json',
        'Accept: application/json',
    ], $extraHeaders);

    $ch = curl_init(rtrim(SUPABASE_URL, '/') . $path);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 15,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }

    $raw    = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err    = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        return ['status' => 0, 'body' => null, 'error' => 'Could not reach Supabase: ' . $err];
    }

    $decoded = json_decode($raw, true);
    $error   = null;
    if ($status >= 400) {
        $error = $decoded['msg']
            ?? $decoded['error_description']
            ?? $decoded['message']
            ?? $decoded['error']
            ?? ('Request failed (HTTP ' . $status . ')');
    }
    return ['status' => $status, 'body' => $decoded, 'error' => $error];
}

// =====================================================================
// Auth
// =====================================================================

// Creates a profile row with a bcrypt password hash.
// Returns ['error' => string|null, 'profile' => array|null].
function supabase_sign_up(string $username, string $password, array $meta): array
{
    $username = strtolower(trim($username));
    $email    = strtolower(trim($meta['email'] ?? ''));

    $exists = supabase_request('GET', '/rest/v1/profiles?select=id&username=eq.' . urlencode($username));
    if (!empty($exists['body'])) {
        return ['error' => 'That username is already taken.', 'profile' => null];
    }

    // A typo'd code is reported rather than ignored, otherwise the referrer
    // silently never gets credited and nobody notices.
    $referrer = null;
    if (!empty($meta['ref'])) {
        $r = supabase_request('GET', '/rest/v1/profiles?select=id&deleted_at=is.null&referral_code=eq.' . urlencode(strtoupper(trim($meta['ref']))));
        $referrer = $r['body'][0]['id'] ?? null;
        if (!$referrer) {
            return ['error' => 'That referral code does not exist. Check it, or leave it blank.', 'profile' => null];
        }
    }

    // Standard can never be self-assigned at sign-up: it requires a paid,
    // admin-approved activation (see approve_activation()).
    $role = in_array($meta['role'] ?? '', ['client_basic', 'referral'], true)
        ? $meta['role'] : 'referral';

    // Every new account waits behind the approval wall until an admin accepts
    // it; the lifecycle clock starts at approval (see approve_application()).
    $res = supabase_request('POST', '/rest/v1/profiles', [
        'full_name'     => $meta['full_name'] ?: $username,
        'username'      => $username,
        'email'         => $email ?: null,
        'password_hash' => password_hash($password, PASSWORD_BCRYPT),
        'phone'         => $meta['phone']   ?: null,
        'company'       => $meta['company'] ?: null,
        'referred_by'   => $referrer,
        'role'          => $role,
        'status'        => 'pending',
    ], null, ['Prefer: return=representation']);

    if ($res['error']) {
        return ['error' => $res['error'], 'profile' => null];
    }
    $new = $res['body'][0] ?? null;
    if ($new) {
        notify(SUPABASE_SERVICE_KEY, 'New account application',
            ($new['full_name'] ?? $username) . ' applied as ' . role_name($role) . '.', null, 'admin', '/dashboard-admin.php#approvals');
    }
    return ['error' => null, 'profile' => $new];
}

// Verifies the password against the stored bcrypt hash.
// Returns ['error' => string|null, 'profile' => array|null].
function supabase_sign_in(string $username, string $password): array
{
    $username = strtolower(trim($username));
    $res      = supabase_request('GET', '/rest/v1/profiles?select=*&username=eq.' . urlencode($username));

    if ($res['error']) {
        return ['error' => 'Could not reach the database.', 'profile' => null];
    }

    $profile = $res['body'][0] ?? null;
    if (!$profile || !password_verify($password, $profile['password_hash'] ?? '')) {
        return ['error' => 'Invalid credentials.', 'profile' => null];
    }
    if (!empty($profile['deleted_at'])) {
        return ['error' => 'Invalid credentials.', 'profile' => null];
    }

    return ['error' => null, 'profile' => $profile];
}

function supabase_store_session(array $profile): void
{
    $session = &_session();
    $session['sb'] = [
        'user_id'  => $profile['id'],
        'username' => $profile['username'],
    ];
    _save_session();
}

// Returns the service-role key when a user is signed in, so existing
// callers that pass $token around keep working unchanged.
function supabase_access_token(): ?string
{
    $session = &_session();
    return empty($session['sb']['user_id']) ? null : SUPABASE_SERVICE_KEY;
}

function supabase_sign_out(): void
{
    supabase_sign_out_local();
}

function supabase_sign_out_local(): void
{
    _clear_session();
}

// =====================================================================
// Guards
// =====================================================================

function require_login(): string
{
    $token = supabase_access_token();
    if (!$token) {
        header('Location: /index.php');
        exit;
    }
    return $token;
}

function current_profile(?string $token = null): ?array
{
    static $cached = null;
    if ($cached !== null) return $cached ?: null;

    $token  = $token ?: supabase_access_token();
    $session = &_session();
    $userId = $session['sb']['user_id'] ?? null;
    if (!$token || !$userId) { $cached = false; return null; }

    $res = supabase_request('GET', '/rest/v1/profiles?select=*&id=eq.' . urlencode($userId), null, $token);
    $cached = $res['body'][0] ?? false;
    if ($cached) unset($cached['password_hash']);
    return $cached ?: null;
}

function check_account_status(?array $profile): bool
{
    if (!$profile) return false;
    if ($profile['status'] !== 'accepted') return false;
    if (!empty($profile['deleted_at'])) return false;

    if ($profile['lifecycle_expires_at'] && $profile['role'] !== 'admin') {
        if (strtotime($profile['lifecycle_expires_at']) < time()) {
            return false;
        }
    }
    return true;
}

function is_walled(?array $profile): bool
{
    return $profile && ($profile['role'] ?? '') !== 'admin'
        && in_array($profile['status'] ?? '', ['pending', 'rejected'], true);
}

function dashboard_url(string $role): string
{
    return [
        'admin'           => '/dashboard-admin.php',
        'client_basic'    => '/dashboard-client-basic.php',
        'client_standard' => '/dashboard-client-standard.php',
        'referral'        => '/dashboard-referral.php',
    ][$role] ?? '/dashboard-referral.php';
}

function redirect_by_role(?string $welcome = null): void
{
    $profile = current_profile();

    if (is_walled($profile)) {
        header('Location: /pending.php');
        exit;
    }

    header('Location: ' . dashboard_url($profile['role'] ?? 'referral') . ($welcome ? '?welcome=1' : ''));
    exit;
}

function require_role(string|array $allowed): array
{
    $token   = require_login();
    $profile = current_profile($token);
    $role    = $profile['role'] ?? '';
    $allowed = (array) $allowed;

    // Approval wall: unapproved applications can't reach any dashboard.
    if (is_walled($profile)) {
        header('Location: /pending.php');
        exit;
    }

    apply_due_package_changes();

    if (!in_array($role, $allowed, true)) {
        http_response_code(403);
        redirect_by_role();
    }

    if ($role !== 'admin' && !check_account_status($profile)) {
        flash('error', 'Your account is inactive or expired. Contact support.');
        supabase_sign_out_local();
        header('Location: /index.php');
        exit;
    }

    return ['token' => $token, 'profile' => $profile];
}

// =====================================================================
// Audit logging
// =====================================================================

function audit_log(string $token, string $action, ?string $entityType = null, ?string $entityId = null, ?string $summary = null): void
{
    $session = &_session();
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    supabase_request('POST', '/rest/v1/system_logs', [
        'actor_id'    => $session['sb']['user_id'] ?? null,
        'action'      => $action,
        'entity_type' => $entityType,
        'entity_id'   => $entityId,
        'summary'     => $summary,
        'ip_address'  => $ip,
    ], $token, ['Prefer: return=minimal']);
}

// =====================================================================
// Tiers, activation & approvals
// =====================================================================

// Lifecycle length per client role, in months.
const TIER_MONTHS = ['client_basic' => 3, 'client_standard' => 27];

function tier_expiry(string $role): ?string
{
    $months = TIER_MONTHS[$role] ?? 0;
    return $months > 0 ? date('c', strtotime("+{$months} months")) : null;
}

function tier_role(string $tier): string
{
    return $tier === 'standard' ? 'client_standard' : 'client_basic';
}

// Turns a user into a Basic or Standard client and starts their lifecycle.
function activate_account(string $token, string $userId, string $tier): ?string
{
    $role = tier_role($tier);
    $res  = supabase_request('PATCH', '/rest/v1/profiles?id=eq.' . urlencode($userId), [
        'role'                 => $role,
        'status'               => 'accepted',
        'lifecycle_expires_at' => tier_expiry($role),
    ], $token, ['Prefer: return=minimal']);
    if ($res['error']) return $res['error'];

    // Mark the user's own referral link as active now that they converted.
    supabase_request('PATCH', '/rest/v1/referrals?status=eq.pending&referred_id=eq.' . urlencode($userId),
        ['status' => 'active'], $token, ['Prefer: return=minimal']);
    return null;
}

function fetch_activation_txn(string $token, string $txnId): ?array
{
    $res = supabase_request('GET', '/rest/v1/transactions?select=*,packages!transactions_package_id_fkey(name,tier)&type=eq.activation&id=eq.' . urlencode($txnId), null, $token);
    return $res['body'][0] ?? null;
}

// Admin approves a pending paid activation: completes the transaction and
// upgrades the user to the tier of the package they paid for.
function approve_activation(string $token, string $txnId, string $adminId): ?string
{
    $txn = fetch_activation_txn($token, $txnId);
    if (!$txn)                         return 'Transaction not found.';
    if ($txn['status'] !== 'pending')  return 'This request was already reviewed.';

    $tier = $txn['packages']['tier'] ?? 'standard';
    $err  = activate_account($token, $txn['user_id'], $tier);
    if ($err) return $err;

    $res = supabase_request('PATCH', '/rest/v1/transactions?status=eq.pending&id=eq.' . urlencode($txnId), [
        'status' => 'completed', 'reviewed_by' => $adminId, 'reviewed_at' => date('c'),
    ], $token, ['Prefer: return=minimal']);
    if ($res['error']) return $res['error'];

    audit_log($token, 'approve_activation', 'transaction', $txnId,
        'Approved ' . ($txn['packages']['name'] ?? $tier) . ' ($' . number_format((float) $txn['amount'], 2) . ') ref ' . ($txn['payment_ref'] ?? '—'));
    notify($token, ucfirst($tier) . ' account approved',
        'Your ' . ($txn['packages']['name'] ?? ucfirst($tier)) . ' plan is now active. Your countdown has started.', $txn['user_id']);

    pay_referral_commission($token, $txn);
    return null;
}

// =====================================================================
// Referral commission
// =====================================================================

// Percent of an activation paid to whoever referred the buyer.
function commission_pct(string $token): float
{
    $raw = get_settings($token, ['referral_commission_pct'])['referral_commission_pct'];
    return $raw === '' ? 5.0 : max(0.0, min(100.0, (float) $raw));
}

// 5.0 -> "5", 7.5 -> "7.5", 10.0 -> "10"
function pct_label(float $pct): string
{
    return rtrim(rtrim(number_format($pct, 2, '.', ''), '0'), '.');
}

// Credits the referrer when a referred user's paid activation is approved.
// Free (zero-amount) activations earn nothing. The unique index on
// (user_id, source_user_id) makes a repeat approval a no-op rather than
// paying the same commission twice.
function pay_referral_commission(string $token, array $txn): void
{
    $amount = (float) $txn['amount'];
    $pct    = commission_pct($token);
    if ($amount <= 0 || $pct <= 0) return;

    $buyerRes = supabase_request('GET', '/rest/v1/profiles?select=id,full_name,username,referred_by&id=eq.' . urlencode($txn['user_id']), null, $token);
    $buyer    = $buyerRes['body'][0] ?? null;
    $referrer = $buyer['referred_by'] ?? null;
    if (!$referrer) return;

    $commission = round($amount * $pct / 100, 2);
    if ($commission <= 0) return;

    $res = supabase_request('POST', '/rest/v1/transactions', [
        'user_id'        => $referrer,
        'package_id'     => $txn['package_id'] ?? null,
        'source_user_id' => $txn['user_id'],
        'amount'         => $commission,
        'type'           => 'commission',
        'status'         => 'completed',
        'notes'          => pct_label($pct) . '% referral commission from ' . ($buyer['full_name'] ?? 'a referral'),
    ], $token, ['Prefer: return=minimal']);
    if ($res['error']) return;

    audit_log($token, 'referral_commission', 'transaction', $txn['id'],
        'Paid $' . number_format($commission, 2) . ' (' . pct_label($pct) . '%) to referrer of ' . ($buyer['username'] ?? $txn['user_id']));
    notify($token, 'You earned $' . number_format($commission, 2),
        ($buyer['full_name'] ?? 'Someone you referred') . ' activated a paid plan, so you earned ' . pct_label($pct) . '% of it.', $referrer);
}

function reject_activation(string $token, string $txnId, string $adminId, string $reason = ''): ?string
{
    $txn = fetch_activation_txn($token, $txnId);
    if (!$txn)                        return 'Transaction not found.';
    if ($txn['status'] !== 'pending') return 'This request was already reviewed.';

    $res = supabase_request('PATCH', '/rest/v1/transactions?status=eq.pending&id=eq.' . urlencode($txnId), [
        'status' => 'failed', 'reviewed_by' => $adminId, 'reviewed_at' => date('c'),
        'notes'  => trim('Rejected by admin. ' . $reason),
    ], $token, ['Prefer: return=minimal']);
    if ($res['error']) return $res['error'];

    audit_log($token, 'reject_activation', 'transaction', $txnId, 'Rejected payment ref ' . ($txn['payment_ref'] ?? '—') . ($reason !== '' ? ' — ' . $reason : ''));
    notify($token, 'Plan request not approved',
        trim(($txn['packages']['name'] ?? 'Your plan') . ' was not approved. ' . $reason) . ' You can submit a new request.', $txn['user_id']);
    return null;
}

// =====================================================================
// Approval wall — account applications
// =====================================================================

function role_name(string $role): string
{
    return ['admin' => 'Admin', 'client_basic' => 'Basic client', 'client_standard' => 'Standard client', 'referral' => 'Referral'][$role] ?? $role;
}

function approve_application(string $token, string $userId): ?string
{
    $res = supabase_request('GET', '/rest/v1/profiles?select=id,role,status&id=eq.' . urlencode($userId), null, $token);
    $p   = $res['body'][0] ?? null;
    if (!$p) return 'User not found.';
    if (!in_array($p['status'], ['pending', 'rejected'], true)) return 'This application was already reviewed.';

    $res = supabase_request('PATCH', '/rest/v1/profiles?id=eq.' . urlencode($userId), [
        'status' => 'accepted', 'review_note' => null,
        'lifecycle_expires_at' => tier_expiry($p['role']),   // clock starts on approval
    ], $token, ['Prefer: return=minimal']);
    if ($res['error']) return $res['error'];

    audit_log($token, 'approve_application', 'profile', $userId, 'Approved ' . role_name($p['role']) . ' application');
    notify($token, 'Application approved', 'Welcome aboard — your ' . role_name($p['role']) . ' account is active.', $userId);
    return null;
}

function reject_application(string $token, string $userId, string $reason): ?string
{
    $res = supabase_request('PATCH', '/rest/v1/profiles?status=in.(pending,rejected)&id=eq.' . urlencode($userId), [
        'status' => 'rejected', 'review_note' => $reason !== '' ? $reason : null,
    ], $token, ['Prefer: return=representation']);
    if ($res['error']) return $res['error'];
    if (empty($res['body'])) return 'This application was already reviewed.';

    audit_log($token, 'reject_application', 'profile', $userId, 'Rejected application' . ($reason !== '' ? ' — ' . $reason : ''));
    return null;
}

// =====================================================================
// Notifications
// =====================================================================

function notify(string $token, string $title, ?string $body = null, ?string $userId = null, string $audience = 'all', ?string $link = null): void
{
    supabase_request('POST', '/rest/v1/notifications', [
        'user_id' => $userId, 'audience' => $userId ? 'all' : $audience,
        'title' => mb_substr($title, 0, 200), 'body' => $body !== null ? mb_substr($body, 0, 1000) : null, 'link' => $link,
    ], $token, ['Prefer: return=minimal']);
}

// Personal notifications plus broadcasts to everyone or to the user's role.
function fetch_notifications(string $token, array $profile, int $limit = 20): array
{
    $role = $profile['role'] ?? 'referral';
    $or   = 'or=(user_id.eq.' . $profile['id'] . ',and(user_id.is.null,audience.in.(all,' . $role . ')))';
    $res  = supabase_request('GET', '/rest/v1/notifications?select=*&' . $or . '&order=created_at.desc&limit=' . $limit, null, $token);
    return is_array($res['body']) && !$res['error'] ? $res['body'] : [];
}

function notif_bell(string $token, array $profile, string $csrf): string
{
    $items  = fetch_notifications($token, $profile);
    $seen   = strtotime($profile['notif_seen_at'] ?? '1970-01-01');
    $unread = count(array_filter($items, fn($n) => strtotime($n['created_at']) > $seen));

    $list = '';
    foreach ($items as $n) {
        $isNew = strtotime($n['created_at']) > $seen;
        $inner = '<strong>' . e($n['title']) . '</strong>'
            . ($n['body'] ? '<span>' . e($n['body']) . '</span>' : '')
            . '<time>' . e(date('M j, H:i', strtotime($n['created_at']))) . '</time>';
        $list .= $n['link']
            ? '<a class="notif-item' . ($isNew ? ' is-new' : '') . '" href="' . e($n['link']) . '">' . $inner . '</a>'
            : '<div class="notif-item' . ($isNew ? ' is-new' : '') . '">' . $inner . '</div>';
    }
    if ($list === '') $list = '<p class="notif-empty">No notifications yet.</p>';

    return '<details class="notif" data-csrf="' . e($csrf) . '">'
        . '<summary class="notif-btn" aria-label="Notifications' . ($unread ? " ($unread unread)" : '') . '">'
        . '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>'
        . ($unread ? '<span class="notif-count">' . ($unread > 9 ? '9+' : $unread) . '</span>' : '')
        . '</summary>'
        . '<div class="notif-panel"><div class="notif-head">Notifications</div>' . $list . '</div>'
        . '</details>'
        . '<script>(function(){var d=document.currentScript.previousElementSibling;d.addEventListener("toggle",function(){'
        . 'if(!d.open||!d.querySelector(".notif-count"))return;var f=new FormData();f.append("csrf",d.dataset.csrf);'
        . 'fetch("/notifications.php",{method:"POST",body:f,credentials:"same-origin"}).then(function(){var c=d.querySelector(".notif-count");if(c)c.remove();});});'
        . 'document.addEventListener("click",function(e){if(d.open&&!d.contains(e.target))d.open=false;});})();</script>';
}

// =====================================================================
// Scheduled package changes (1-7 day notice, then permanent)
// =====================================================================

const PACKAGE_FIELDS = ['name', 'description', 'price', 'commission_outright', 'commission_unilevel', 'tier', 'active'];

function describe_package_changes(array $old, array $new): string
{
    $label = ['name' => 'Name', 'description' => 'Description', 'price' => 'Price', 'commission_outright' => 'Outright commission',
              'commission_unilevel' => 'Unilevel %', 'tier' => 'Tier', 'active' => 'Availability'];
    $fmt = function (string $k, $v): string {
        if ($k === 'active') return $v ? 'available' : 'hidden';
        if (in_array($k, ['price', 'commission_outright'], true)) return '$' . number_format((float) $v, 2);
        if ($k === 'commission_unilevel') return rtrim(rtrim(number_format((float) $v, 2), '0'), '.') . '%';
        return (string) ($v ?? '—');
    };
    $parts = [];
    foreach ($new as $k => $v) $parts[] = $label[$k] . ': ' . $fmt($k, $old[$k] ?? null) . ' → ' . $fmt($k, $v);
    return implode('; ', $parts);
}

function schedule_package_change(string $token, array $pkg, array $changes, int $days, string $adminId): ?string
{
    if ($days < 1 || $days > 7) return 'Pick a notice period between 1 and 7 days.';
    if (!$changes)              return 'Nothing changed.';

    $effective = date('c', strtotime("+{$days} days"));
    $summary   = describe_package_changes($pkg, $changes);
    $res = supabase_request('POST', '/rest/v1/package_changes', [
        'package_id' => $pkg['id'], 'changes' => $changes, 'summary' => $summary,
        'effective_at' => $effective, 'created_by' => $adminId,
    ], $token, ['Prefer: return=minimal']);
    if ($res['error']) {
        return $res['status'] === 409 ? 'This package already has a scheduled change — cancel it first.' : $res['error'];
    }

    audit_log($token, 'schedule_package_change', 'package', $pkg['id'], $pkg['name'] . ' on ' . date('M j', strtotime($effective)) . ': ' . $summary);
    notify($token, 'Upcoming change: ' . $pkg['name'],
        'Takes effect ' . date('M j, Y', strtotime($effective)) . '. ' . $summary);
    return null;
}

function cancel_package_change(string $token, string $changeId): ?string
{
    $res = supabase_request('PATCH', '/rest/v1/package_changes?status=eq.scheduled&id=eq.' . urlencode($changeId),
        ['status' => 'cancelled'], $token, ['Prefer: return=representation']);
    if ($res['error']) return $res['error'];
    $row = $res['body'][0] ?? null;
    if (!$row) return 'That change was already applied or cancelled.';

    $pkg = supabase_request('GET', '/rest/v1/packages?select=name&id=eq.' . urlencode($row['package_id']), null, $token)['body'][0]['name'] ?? 'a package';
    audit_log($token, 'cancel_package_change', 'package', $row['package_id'], 'Cancelled: ' . ($row['summary'] ?? ''));
    notify($token, 'Change cancelled: ' . $pkg, 'The scheduled change will not happen. ' . ($row['summary'] ?? ''));
    return null;
}

// Applies every scheduled change whose date has passed. Runs on each
// dashboard load, so no cron is needed. Each change is claimed with a
// conditional update first, so it is never applied twice.
function apply_due_package_changes(): void
{
    static $done = false;
    if ($done) return;
    $done  = true;
    $token = SUPABASE_SERVICE_KEY;

    $due = supabase_request('GET', '/rest/v1/package_changes?select=*,packages(name)&status=eq.scheduled&effective_at=lte.' . rawurlencode(date('c')), null, $token);
    foreach ((is_array($due['body']) && !$due['error']) ? $due['body'] : [] as $ch) {
        $claim = supabase_request('PATCH', '/rest/v1/package_changes?status=eq.scheduled&id=eq.' . urlencode($ch['id']),
            ['status' => 'applied', 'applied_at' => date('c')], $token, ['Prefer: return=representation']);
        if (empty($claim['body'])) continue;

        $changes = array_intersect_key((array) $ch['changes'], array_flip(PACKAGE_FIELDS));
        supabase_request('PATCH', '/rest/v1/packages?id=eq.' . urlencode($ch['package_id']), $changes, $token, ['Prefer: return=minimal']);
        supabase_request('POST', '/rest/v1/system_logs', [
            'action' => 'apply_package_change', 'entity_type' => 'package', 'entity_id' => $ch['package_id'],
            'summary' => 'Applied: ' . ($ch['summary'] ?? ''), 'ip_address' => 'scheduler',
        ], $token, ['Prefer: return=minimal']);
        notify($token, 'Now live: ' . ($changes['name'] ?? $ch['packages']['name'] ?? 'package update'), $ch['summary'] ?? null);
    }
}

// =====================================================================
// KYC verification
// =====================================================================

const KYC_ID_TYPES = [
    'national_id'     => 'National ID',
    'passport'        => 'Passport',
    'drivers_license' => "Driver's licence",
    'postal_id'       => 'Postal ID',
    'other'           => 'Other government ID',
];

// Status banner + link to /kyc.php, shown on every member dashboard.
function kyc_banner(array $profile): string
{
    return match ($profile['kyc_status'] ?? 'not_submitted') {
        'approved' => '<div class="card" style="margin-bottom:12px;display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap">'
            . '<span class="label">Identity</span><span class="pill active">Verified</span></div>',
        'pending'  => '<div class="alert alert-warn">Your identity documents are in review. <a href="/kyc.php">View submission →</a></div>',
        'rejected' => '<div class="alert alert-error">Your identity check was not approved. <a href="/kyc.php">Submit again →</a></div>',
        default    => '<div class="alert alert-warn">Your account is not verified yet. <a href="/kyc.php">Verify your identity →</a></div>',
    };
}

// Validates an uploaded image and returns it as a data URI.
// Returns null and sets $err when the file is missing or unacceptable.
function image_data_uri(?array $file, int $maxBytes, ?string &$err): ?string
{
    $err = null;
    if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) return null;

    $mime = (@getimagesize($file['tmp_name']))['mime'] ?? '';
    if (!in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)) {
        $err = 'Images must be PNG, JPG or WEBP.';
        return null;
    }
    if ($file['size'] > $maxBytes) {
        $err = 'Each image must be under ' . round($maxBytes / 1024) . ' KB.';
        return null;
    }
    return 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($file['tmp_name']));
}

// $withImages is false on dashboards so the data URIs aren't fetched
// on pages that only need the status.
function kyc_submission(string $token, string $userId, bool $withImages = false): ?array
{
    $cols = $withImages ? '*' : 'id,user_id,legal_name,dob,address,id_type,id_number,status,note,reviewed_at,created_at,updated_at';
    $res  = supabase_request('GET', '/rest/v1/kyc_submissions?select=' . $cols . '&user_id=eq.' . urlencode($userId), null, $token);
    return $res['body'][0] ?? null;
}

// Inserts or replaces this user's submission and puts it back in review.
function submit_kyc(string $token, string $userId, array $data): ?string
{
    $row = [
        'user_id'     => $userId,
        'legal_name'  => $data['legal_name'],
        'dob'         => $data['dob'] ?: null,
        'address'     => $data['address'] ?: null,
        'id_type'     => $data['id_type'],
        'id_number'   => $data['id_number'],
        'status'      => 'pending',
        'note'        => null,
        'reviewed_by' => null,
        'reviewed_at' => null,
    ];
    // Only overwrite images the user actually re-uploaded
    foreach (['id_front', 'id_back', 'selfie'] as $k) {
        if (!empty($data[$k])) $row[$k] = $data[$k];
    }

    $res = supabase_request('POST', '/rest/v1/kyc_submissions?on_conflict=user_id', $row, $token,
        ['Prefer: resolution=merge-duplicates,return=minimal']);
    if ($res['error']) return $res['error'];

    audit_log($token, 'kyc_submitted', 'kyc', $userId, 'Submitted KYC for review');
    notify($token, 'New KYC submission', ($data['legal_name'] ?? 'A user') . ' submitted identity documents for review.', null, 'admin');
    return null;
}

function review_kyc(string $token, string $userId, string $adminId, bool $approve, string $note = ''): ?string
{
    $status = $approve ? 'approved' : 'rejected';
    $res = supabase_request('PATCH', '/rest/v1/kyc_submissions?user_id=eq.' . urlencode($userId), [
        'status'      => $status,
        'note'        => $note !== '' ? $note : null,
        'reviewed_by' => $adminId,
        'reviewed_at' => date('c'),
    ], $token, ['Prefer: return=minimal']);
    if ($res['error']) return $res['error'];

    audit_log($token, 'kyc_' . $status, 'kyc', $userId, 'KYC ' . $status . ($note !== '' ? ' — ' . $note : ''));
    notify($token,
        $approve ? 'Identity verified' : 'Identity check not approved',
        $approve
            ? 'Your documents checked out. Your account is now verified.'
            : trim('We could not verify your documents. ' . $note) . ' You can submit again.',
        $userId);
    return null;
}

// =====================================================================
// App settings (payment QR etc.)
// =====================================================================

function get_settings(string $token, array $keys): array
{
    $res = supabase_request('GET', '/rest/v1/app_settings?select=key,value&key=in.(' . implode(',', array_map('rawurlencode', $keys)) . ')', null, $token);
    $out = array_fill_keys($keys, '');
    foreach ((is_array($res['body']) && !$res['error']) ? $res['body'] : [] as $row) {
        $out[$row['key']] = (string) $row['value'];
    }
    return $out;
}

function set_setting(string $token, string $key, ?string $value): ?string
{
    $res = supabase_request('POST', '/rest/v1/app_settings?on_conflict=key',
        ['key' => $key, 'value' => $value, 'updated_at' => date('c')],
        $token, ['Prefer: resolution=merge-duplicates,return=minimal']);
    return $res['error'];
}

// =====================================================================
// Live lifecycle countdown
// =====================================================================

function lifecycle_countdown(array $profile, string $title): string
{
    $lc = format_lifecycle($profile);
    if (empty($profile['lifecycle_expires_at'])) {
        return '<div class="card countdown-card"><span class="label">' . e($title) . '</span><p class="empty" style="margin:8px 0 0">No expiry set on this account.</p></div>';
    }
    $expires = strtotime($profile['lifecycle_expires_at']) * 1000;
    $created = strtotime($profile['created_at']) * 1000;
    $unit = fn(string $k, string $l) => '<div class="cd-unit"><span class="cd-num" data-cd="' . $k . '">--</span><span class="cd-lbl">' . $l . '</span></div>';

    return '<div class="card countdown-card" data-countdown data-expires="' . $expires . '" data-start="' . $created . '" data-now="' . (time() * 1000) . '">'
        . '<div class="cd-head"><span class="label">' . e($title) . '</span><span class="cd-expiry" data-cd="expiry">Expires ' . e(date('M j, Y', strtotime($profile['lifecycle_expires_at']))) . '</span></div>'
        . '<div class="cd-grid">' . $unit('d', 'Days') . $unit('h', 'Hours') . $unit('m', 'Minutes') . $unit('s', 'Seconds') . '</div>'
        . '<div class="cd-total" data-cd="total">' . e($lc['text']) . '</div>'
        . '<div class="lifecycle-bar"><div class="lifecycle-fill ' . e($lc['class']) . '" data-cd="bar" style="width:' . $lc['pct'] . '%"></div></div>'
        . '</div>';
}

function lifecycle_countdown_script(): string
{
    return <<<'JS'
<script>
(function () {
  document.querySelectorAll('[data-countdown]').forEach(function (el) {
    var expires = +el.dataset.expires, start = +el.dataset.start;
    var skew = +el.dataset.now - Date.now();            // trust the server clock
    var q = function (k) { return el.querySelector('[data-cd="' + k + '"]'); };
    var pad = function (n) { return n < 10 ? '0' + n : '' + n; };
    q('expiry').textContent = 'Expires ' + new Date(expires).toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' });
    function tick() {
      var left = Math.max(0, expires - (Date.now() + skew));
      var s = Math.floor(left / 1000);
      var d = Math.floor(s / 86400), h = Math.floor(s % 86400 / 3600), m = Math.floor(s % 3600 / 60);
      q('d').textContent = d; q('h').textContent = pad(h); q('m').textContent = pad(m); q('s').textContent = pad(s % 60);
      var hours = Math.floor(s / 3600);
      q('total').textContent = left > 0
        ? hours.toLocaleString() + ' hours ' + pad(m) + ' minutes remaining'
        : 'Account expired';
      var pct = Math.round(left / Math.max(expires - start, 1) * 100);
      var bar = q('bar');
      bar.style.width = pct + '%';
      bar.className = 'lifecycle-fill ' + (pct < 20 ? 'critical' : pct < 40 ? 'warn' : 'ok');
      el.classList.toggle('is-expired', left === 0);
      if (left > 0) setTimeout(tick, 1000 - (Date.now() % 1000));
    }
    tick();
  });
})();
</script>
JS;
}

// =====================================================================
// Helpers
// =====================================================================

function csrf_token(): string
{
    $session = &_session();
    if (empty($session['csrf'])) {
        $session['csrf'] = bin2hex(random_bytes(32));
        _save_session();
    }
    return $session['csrf'];
}

function csrf_check(): bool
{
    $session = &_session();
    return isset($_POST['csrf']) && hash_equals($session['csrf'] ?? '', (string) $_POST['csrf']);
}

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function flash(?string $type = null, ?string $message = null): ?array
{
    $session = &_session();
    if ($type !== null) {
        $session['flash'] = ['type' => $type, 'message' => $message];
        _save_session();
        return null;
    }
    $f = $session['flash'] ?? null;
    unset($session['flash']);
    _save_session();
    return $f;
}

function format_lifecycle(array $profile): array
{
    if (!$profile['lifecycle_expires_at']) {
        return ['text' => 'No expiry set', 'pct' => 0, 'class' => 'ok'];
    }

    $now     = time();
    $expires = strtotime($profile['lifecycle_expires_at']);
    $created = strtotime($profile['created_at']);
    $total   = max($expires - $created, 1);
    $left    = max($expires - $now, 0);
    $pct     = round(($left / $total) * 100);

    $class = 'ok';
    if ($pct < 20) $class = 'critical';
    elseif ($pct < 40) $class = 'warn';

    $days = max(0, (int) ceil($left / 86400));
    $text = $days > 0 ? $days . ' days remaining' : 'Expired';

    return ['text' => $text, 'pct' => $pct, 'class' => $class, 'days' => $days, 'expires' => date('M j, Y', $expires)];
}
