<?php
// KAMTEAM // Supabase client — serverless-compatible (cookie sessions for Vercel)

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/terms.php';

// =====================================================================
// Cookie-based session (replaces $_SESSION for serverless/Vercel)
// =====================================================================

// The cookie is sealed with AES-256-GCM and a fresh random nonce each time:
// it can't be read, and any edit to it is detected and rejected (the old
// CBC format with a fixed IV had no tamper check). A sign-in is valid for
// SESSION_LIFETIME from login, however the cookie is copied around.
const SESSION_LIFETIME = 86400 * 30;

function _enc_key(): string { return hash_hmac('sha256', 'kt-session-v2', SESSION_SECRET, true); }

function _encrypt(string $data): string
{
    $nonce = random_bytes(12);
    $ct    = openssl_encrypt($data, 'aes-256-gcm', _enc_key(), OPENSSL_RAW_DATA, $nonce, $tag, 'kt_session');
    return 'v2.' . rtrim(strtr(base64_encode($nonce . $tag . $ct), '+/', '-_'), '=');
}

function _decrypt(string $data): ?string
{
    if (!str_starts_with($data, 'v2.')) return null;          // old/unknown format → signed out
    $raw = base64_decode(strtr(substr($data, 3), '-_', '+/'), true);
    if ($raw === false || strlen($raw) < 29) return null;
    $out = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', _enc_key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16), 'kt_session');
    return $out === false ? null : $out;
}

function _cookie_secure(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

function &_session(): array
{
    static $store = null;
    if ($store === null) {
        $cookie = $_COOKIE['kt_session'] ?? '';
        $json   = $cookie !== '' ? _decrypt($cookie) : null;
        $store  = $json ? (json_decode($json, true) ?: []) : [];
        // A sign-in older than SESSION_LIFETIME (or without a login time) is dropped.
        if (!empty($store['sb']) && (int) ($store['sb']['iat'] ?? 0) < time() - SESSION_LIFETIME) unset($store['sb']);
    }
    return $store;
}

function _save_session(): void
{
    $data = &_session();
    setcookie('kt_session', _encrypt(json_encode($data)), [
        'expires'  => time() + SESSION_LIFETIME,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => _cookie_secure(),
    ]);
}

function _clear_session(): void
{
    $store = &_session();
    $store = [];
    setcookie('kt_session', '', ['expires' => 1, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => _cookie_secure()]);
}

// =====================================================================
// Idle timeout — every signed-in account is signed out after IDLE_TIMEOUT
// seconds without activity. The server is the authority (the cookie carries
// the last-activity stamp); assets/js/app.js only mirrors the same clock in
// the browser so the user gets a warning instead of a surprise.
// =====================================================================

const IDLE_TIMEOUT = 300;   // 5 minutes of inactivity
const IDLE_WARNING = 60;    // warn the user this long before the cut-off
const IDLE_TOUCH_GAP = 15;  // don't rewrite the cookie more often than this

function session_last_seen(): int
{
    $s = &_session();
    return (int) ($s['sb']['seen'] ?? $s['sb']['iat'] ?? 0);
}

function session_idle_seconds(): int
{
    $seen = session_last_seen();
    return $seen ? max(0, time() - $seen) : 0;
}

function session_idle_remaining(): int
{
    return max(0, IDLE_TIMEOUT - session_idle_seconds());
}

function session_idle_expired(): bool
{
    $s = &_session();
    return !empty($s['sb']) && session_idle_seconds() > IDLE_TIMEOUT;
}

// Marks the session as active. Automated reloads (?auto=1, e.g. the approval
// wall refreshing itself) must not count as activity, so they are ignored.
function session_touch(bool $force = false): void
{
    $s = &_session();
    if (empty($s['sb'])) return;
    if (!$force && isset($_GET['auto'])) return;
    $now = time();
    if (!$force && $now - session_last_seen() < IDLE_TOUCH_GAP) return;
    $s['sb']['seen'] = $now;
    _save_session();
}

// Ends an idle session and sends the visitor back to the login page.
function end_idle_session(): void
{
    if ($token = supabase_access_token()) {
        $s = &_session();
        audit_log($token, 'logout_idle', 'user', $s['sb']['user_id'] ?? null, 'Signed out after ' . (IDLE_TIMEOUT / 60) . ' minutes of inactivity');
    }
    supabase_sign_out_local();
    flash('warn', 'You were signed out after ' . (IDLE_TIMEOUT / 60) . ' minutes of inactivity. Please sign in again.');
    header('Location: /index.php?timeout=1');
    exit;
}

// Signed-in pages must never be served from the browser cache, so the Back
// button after logout asks the server again (and lands on the login page).
function no_store_headers(): void
{
    if (headers_sent()) return;
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');
    header('Pragma: no-cache');
    header('Expires: 0');
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

    // No account is created until every section of the program terms
    // checklist has been accepted (see lib/terms.php).
    if (terms_missing((array) ($meta['terms'] ?? []))) {
        return ['error' => 'Accept every section of the program terms to continue.', 'profile' => null];
    }

    $exists =supabase_request('GET', '/rest/v1/profiles?select=id&username=eq.' . urlencode($username));
    if (!empty($exists['body'])) {
        return ['error' => 'That username is already taken.', 'profile' => null];
    }

    // A typo'd code is reported rather than ignored, otherwise the referrer
    // silently never gets credited and nobody notices.
    $referrer = null;
    if (!empty($meta['ref'])) {
        $r = supabase_request('GET', '/rest/v1/profiles?select=id,role,affiliate_tier,status,kyc_status&deleted_at=is.null&referral_code=eq.' . urlencode(strtoupper(trim($meta['ref']))));
        $refRow   = $r['body'][0] ?? null;
        $referrer = $refRow['id'] ?? null;
        if (!$referrer) {
            return ['error' => 'That referral code does not exist. Check it, or leave it blank.', 'profile' => null];
        }
        // Referrals can only be sent by a verified account: a code whose
        // owner's ID isn't approved yet is not accepted.
        if (!kyc_approved($refRow)) {
            return ['error' => ($meta['account_mode'] ?? 'client') === 'affiliate'
                ? 'That referral code is not active yet — its owner has not finished ID verification. Ask them to verify their ID, then try again.'
                : 'That referral code is not active yet — its owner has not finished ID verification. Ask them to verify, or leave the code blank.', 'profile' => null];
        }
        // A Growth Partner joins a Growth Partner's downline only. Clients
        // have KT- codes too, but a client's code can't sponsor a partner
        // (Manual §4): only a Growth Partner referral code works here.
        if (($meta['account_mode'] ?? 'client') === 'affiliate' && !is_growth_partner_code_owner($refRow)) {
            return ['error' => 'That is a Client code. A Growth Partner account needs the referral code of a Growth Partner — ask the partner who invited you for theirs.', 'profile' => null];
        }
    }

    // Four account types live in their own rows (see migration
    // 20261128_separate_accounts.sql). Public sign-up creates one of two:
    //
    //   client           buys KAM TEAM services, no affiliate side
    //   growth_partner   refers clients, no service-package side
    //
    // If the member wants the other side later, "Activate …" on their
    // dashboard creates a new linked row of the other type.
    $role = ($meta['account_mode'] ?? 'client') === 'affiliate' ? 'growth_partner' : 'client';
    // account_mode is kept for the legacy dual-side rows — on new rows
    // the role IS the mode, so account_mode is null.
    $mode = null;

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
        'account_mode'  => $mode,
        'status'        => 'pending',
        'business_nature'    => $meta['business_nature'] ?? null,
        'business_struggles' => array_values($meta['business_struggles'] ?? []),
        'business_notes'     => $meta['business_notes'] ?? null,
        'terms_version'      => TERMS_VERSION,
        // assigned_admin stays null until an admin approves the application,
        // so rejected sign-ups don't consume a roulette turn.
    ], null, ['Prefer: return=representation']);

    if ($res['error']) {
        return ['error' => $res['error'], 'profile' => null];
    }
    $new = $res['body'][0] ?? null;
    if ($new) {
        // Record the acceptance. If it can't be stored, undo the sign-up so
        // there is never an account without a consent record.
        $consent = supabase_request('POST', '/rest/v1/consents', terms_consent_record($new['id']), null, ['Prefer: return=minimal']);
        if ($consent['error']) {
            supabase_request('DELETE', '/rest/v1/profiles?id=eq.' . urlencode($new['id']), null, null, ['Prefer: return=minimal']);
            return ['error' => 'Could not record your acceptance of the program terms. Please try again.', 'profile' => null];
        }
        supabase_request('POST', '/rest/v1/system_logs', [
            'actor_id' => $new['id'], 'action' => 'terms_accepted', 'entity_type' => 'profile', 'entity_id' => $new['id'],
            'summary'  => TERMS_PROGRAM . ' v' . TERMS_VERSION . ' — ' . count(TERMS_SECTIONS) . ' sections + e-signature',
            'ip_address' => client_ip(),
        ], null, ['Prefer: return=minimal']);

        $who =($new['full_name'] ?? $username) . ' applied as ' . role_name($role)
             . (!empty($new['business_nature']) ? ' (' . $new['business_nature'] . ')' : '') . '.'
             . (!empty($new['business_struggles']) ? ' Needs help with: ' . implode(', ', array_map('struggle_label', $new['business_struggles'])) . '.' : '');
        notify(SUPABASE_SERVICE_KEY, 'New account application', $who, null, 'admin', '/dashboard-admin.php#approvals');
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
        'iat'      => time(),
        'seen'     => time(),
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
    no_store_headers();
    $token = supabase_access_token();
    if (!$token) {
        header('Location: /index.php');
        exit;
    }
    if (session_idle_expired()) end_idle_session();
    session_touch();
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

// A member account never expires: the Client side is permanent. Only the
// Growth Partner side carries a term (Basic/Standard), and when that lapses
// the member simply stops earning until they choose a plan again.
function check_account_status(?array $profile): bool
{
    if (!$profile) return false;
    if ($profile['status'] !== 'accepted') return false;
    if (!empty($profile['deleted_at'])) return false;
    return true;
}

// A member: one account that is a permanent Client and a Growth Partner.
// A "member" is anyone who signed up through the public form — Client,
// Growth Partner, or a legacy dual-side account (role='client' with an
// affiliate_tier set). Admins and staff are KAM TEAM's own people.
function is_member(?array $profile): bool
{
    return in_array($profile['role'] ?? '', ['client', 'growth_partner'], true);
}

// 'client'    - Client side is primary (the dashboards show both sides).
// 'affiliate' - Growth Partner only; the Client dashboard is dormant until
//               the member opts in from /dashboard-affiliate.php.
// Pre-migration rows and team accounts return 'client'.
function account_mode(?array $profile): string
{
    return ($profile['account_mode'] ?? 'client') === 'affiliate' ? 'affiliate' : 'client';
}

// A plain Client account: role client, no Growth Partner plan, not a
// legacy partner-only row. It has no Growth Partner side at all.
function is_client_only(?array $profile): bool
{
    return ($profile['role'] ?? '') === 'client' && empty($profile['affiliate_tier']) && account_mode($profile) !== 'affiliate';
}

// Whose code can sponsor a new Growth Partner: a Growth Partner account
// (including an admin's own Unlimited one) or a legacy client row that has
// a Growth Partner plan. Plain Client codes can't.
function is_growth_partner_code_owner(?array $p): bool
{
    if (!$p) return false;
    return ($p['role'] ?? '') === 'growth_partner' || !empty($p['affiliate_tier']);
}
function affiliate_only(?array $profile): bool
{
    return is_member($profile) && account_mode($profile) === 'affiliate';
}

// Member clicks "Activate Client account" on their partner dashboard.
// Flips the mode so the Client side opens, nothing else changes.
function activate_client_side(string $token, string $userId): ?string
{
    $res = supabase_request('PATCH', '/rest/v1/profiles?id=eq.' . urlencode($userId),
        ['account_mode' => 'client'], $token, ['Prefer: return=minimal']);
    if ($res['error']) return 'Could not activate the Client account. Please try again.';
    audit_log($token, 'client_side_activated', 'profile', $userId, 'Member activated their Client account');
    return null;
}

// Admins and staff are KAM TEAM's own people: registered by an admin, never
// behind the approval wall and not bound by the affiliate program terms.
function is_team(?array $profile): bool
{
    return in_array($profile['role'] ?? '', ['admin', 'staff'], true);
}

function is_walled(?array $profile): bool
{
    return $profile && !is_team($profile)
        && in_array($profile['status'] ?? '', ['pending', 'rejected'], true);
}

function dashboard_url(string $role): string
{
    return [
        'admin'          => '/dashboard-admin.php',
        'staff'          => '/work.php',
        'client'         => '/dashboard-client.php',
        'growth_partner' => '/dashboard-affiliate.php',
        'affiliate'      => '/dashboard-client.php',   // legacy rows, pre-migration
    ][$role] ?? '/dashboard-client.php';
}

function redirect_by_role(?string $welcome = null): void
{
    $profile = current_profile();

    if (is_walled($profile)) {
        header('Location: /pending.php');
        exit;
    }

    // Affiliate-only members land on the partner dashboard, not the Client one.
    $url = affiliate_only($profile)
        ? '/dashboard-affiliate.php'
        : dashboard_url($profile['role'] ?? 'client');

    header('Location: ' . $url . ($welcome ? '?welcome=1' : ''));
    exit;
}

// True when the user still has to accept the current manual version.
function terms_outdated(?array $profile, ?bool $teamPartner = null): bool
{
    if (!$profile || is_team($profile)) return false;
    // An admin's (or staff member's) own Growth Partner row belongs to KAM
    // TEAM's people, so it is exempt like the team account it is linked to.
    if ($teamPartner ?? is_team_partner($profile)) return false;
    return ($profile['terms_version'] ?? '') !== TERMS_VERSION;
}

// True for a growth_partner row whose linked account is an admin or staff
// member — e.g. admin-gp, linked to the main admin. Looked up once per id.
// Pass the linked row's role when it is already known to skip the lookup.
function is_team_partner(?array $profile, ?string $linkedRole = null): bool
{
    static $roles = [];
    if (($profile['role'] ?? '') !== 'growth_partner' || empty($profile['linked_profile_id'])) return false;

    $id = $profile['linked_profile_id'];
    if ($linkedRole === null) {
        if (!array_key_exists($id, $roles)) {
            $res = supabase_request('GET', '/rest/v1/profiles?select=role&id=eq.' . urlencode($id));
            $roles[$id] = $res['body'][0]['role'] ?? null;
        }
        $linkedRole = $roles[$id];
    }
    return in_array($linkedRole, ['admin', 'staff'], true);
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
        flash('error', 'Your account is inactive. Contact support.');
        supabase_sign_out_local();
        header('Location: /index.php');
        exit;
    }

    // Every member must accept the current program manual before continuing.
    if (terms_outdated($profile)) {
        header('Location: /terms.php');
        exit;
    }

    if ($role === 'admin') ensure_admin_partner($token, $profile);

    if (is_member($profile)) {
        remind_basic_expiry($profile);

        // Plan gate: an approved member picks Basic or Standard before the
        // dashboard opens (Manual §1.3). The plan chooser itself, and the
        // KYC page Standard needs, declare PLAN_GATE_EXEMPT.
        if (!defined('PLAN_GATE_EXEMPT') && needs_plan($token, $profile)) {
            header('Location: /dashboard-affiliate.php');
            exit;
        }
    }

    return ['token' => $token, 'profile' => $profile];
}

// True until the member has either a Growth Partner plan or a plan request
// waiting for an admin.
function needs_plan(string $token, array $profile): bool
{
    // A Client account never picks a Growth Partner plan — it goes straight
    // to the Client dashboard once approved. Only Growth Partner accounts
    // (and legacy partner-only rows) pass through the plan chooser.
    if (is_client_only($profile)) return false;
    if (!empty($profile['affiliate_tier'])) return false;
    return !plan_request_pending($token, $profile['id']);
}

function plan_request_pending(string $token, string $userId): bool
{
    $res = supabase_request('GET', '/rest/v1/transactions?select=id&limit=1&type=eq.activation&status=eq.pending&user_id=eq.' . urlencode($userId), null, $token);
    return is_array($res['body']) && !$res['error'] && $res['body'] !== [];
}

// =====================================================================
// Audit logging
// =====================================================================

function audit_log(string $token, string $action, ?string $entityType = null, ?string $entityId = null, ?string $summary = null): void
{
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    // The real person: an admin switched into their own Growth Partner
    // account is still logged as the admin. Their full name is saved on the
    // row too (system_logs.actor_name), so the log stays readable even if
    // the account is later renamed or deleted.
    $row = [
        'actor_id'    => session_base_user_id(),
        'actor_name'  => actor_full_name($token),
        'action'      => $action,
        'entity_type' => $entityType,
        'entity_id'   => $entityId,
        'summary'     => $summary,
        'ip_address'  => $ip,
    ];
    $res = supabase_request('POST', '/rest/v1/system_logs', $row, $token, ['Prefer: return=minimal']);
    if ($res['error'] && str_contains((string) $res['error'], 'actor_name')) {
        // Before migration 20261205 the column doesn't exist — never lose the log line.
        unset($row['actor_name']);
        supabase_request('POST', '/rest/v1/system_logs', $row, $token, ['Prefer: return=minimal']);
    }
}

// Audit-log categories for the admin log viewer. An action belongs to the
// first category whose exact names or prefixes match it; anything else
// falls under "other". New actions only need a matching prefix or name.
const LOG_CATEGORIES = [
    'auth'     => ['label' => 'Login & terms',             'actions' => ['login_success', 'login_failed', 'logout', 'terms_accepted'], 'prefixes' => []],
    'tasks'    => ['label' => 'Client task log',           'actions' => ['restore_task', 'purge_task'], 'prefixes' => ['task_']],
    'reports'  => ['label' => 'Weekly / monthly reports',  'actions' => [], 'prefixes' => ['report_']],
    'accounts' => ['label' => 'Accounts & approvals',      'actions' => ['staff_created', 'purge_account', 'partner_account_opened', 'approve_application', 'reject_application', 'role_change', 'status_change', 'soft_delete', 'restore', 'edit_user', 'edit_business'], 'prefixes' => []],
    'payments' => ['label' => 'Payments & commissions',    'actions' => ['plan_requested', 'payment_submitted', 'payment_cancelled', 'deposit_submitted', 'approve_activation', 'reject_activation', 'approve_deposit', 'reject_deposit', 'referral_commission', 'unilevel_commission', 'basket_credit', 'balance_paid', 'update_transaction', 'restore_transaction', 'purge_transaction', 'commission_rate', 'payment_settings'], 'prefixes' => ['payout_', 'referral_']],
    'kyc'      => ['label' => 'KYC',                       'actions' => [], 'prefixes' => ['kyc_']],
    'packages' => ['label' => 'Packages',                  'actions' => ['create_package', 'restore_package', 'purge_package'], 'prefixes' => ['schedule_package', 'cancel_package', 'apply_package']],
    'roulette' => ['label' => 'Roulette',                  'actions' => ['reassign'], 'prefixes' => ['roulette_']],
];

function log_category(string $action): string
{
    foreach (LOG_CATEGORIES as $key => $c) {
        if (in_array($action, $c['actions'], true)) return $key;
        foreach ($c['prefixes'] as $pre) if (str_starts_with($action, $pre)) return $key;
    }
    return 'other';
}

// PostgREST filter selecting one category's rows ('' for all).
function log_category_filter(string $cat): string
{
    if (!isset(LOG_CATEGORIES[$cat])) {
        if ($cat !== 'other') return '';
        $parts = [];
        foreach (LOG_CATEGORIES as $c) {
            foreach ($c['actions'] as $a) $parts[] = 'action.neq.' . $a;
            foreach ($c['prefixes'] as $p) $parts[] = 'action.not.like.' . $p . '*';
        }
        return '&and=(' . implode(',', $parts) . ')';
    }
    $c = LOG_CATEGORIES[$cat];
    $parts = [];
    if ($c['actions']) $parts[] = 'action.in.(' . implode(',', $c['actions']) . ')';
    foreach ($c['prefixes'] as $p) $parts[] = 'action.like.' . $p . '*';
    return '&or=(' . implode(',', $parts) . ')';
}

// =====================================================================
// Tiers, activation & approvals
// =====================================================================

// ---- Affiliate term (Manual §4, §6) ----------------------------------
// Basic:    3 months from activation, +3 months per Qualified Client,
//           no renewal (extend only by referrals or upgrading).
// Standard: US$100 per 2-year term. First payment adds a 3-month
//           activation bonus; each payment adds 2 years; +3 months per
//           Qualified Client. Upgrading from Basic restarts the clock at
//           payment date + 3 months + 2 years (§6.4).
const AFFILIATE_GRACE_DAYS = ['basic' => 7, 'standard' => 30];

// Admins refer clients on a partner account with no term at all.
const TIER_UNLIMITED = 'unlimited';

function tier_unlimited(?array $p): bool
{
    return ($p['affiliate_tier'] ?? '') === TIER_UNLIMITED;
}

// Unlimited belongs to admins alone. Everyone else gets Basic/Standard or
// nothing, so a demoted admin has to choose a real plan like any member.
function partner_tier_for_role(string $role, ?string $current): ?string
{
    // An admin's partner side lives on its own linked growth_partner row
    // (migration 20261128), so the admin row itself carries no tier.
    if ($role === 'admin' || $role === 'staff') return null;
    // A Growth Partner row keeps whatever it has — including Unlimited on
    // an admin's own partner row.
    if ($role === 'growth_partner') return $current;
    return $current === TIER_UNLIMITED ? null : $current;   // legacy dual-side client keeps a real plan
}

// Set once the database refused 'unlimited', i.e. migration 20261125 has
// not been run yet. The admin dashboard turns this into a visible notice.
function partner_tier_blocked(?bool $set = null): bool
{
    static $blocked = false;
    if ($set !== null) $blocked = $set;
    return $blocked;
}

// Every admin has a Growth Partner account that never expires. Admins that
// pre-date this rule are fixed up the next time they load a page, so no one
// has to remember to run the backfill by hand.
// Every admin has a dedicated Growth Partner row (role='growth_partner',
// tier Unlimited, no expiry) linked via profiles.linked_profile_id. This
// helper makes sure the link exists — the row itself is created by the
// 20261128_separate_accounts migration for the main admin, and by this
// helper on sign-in for any other admin without one yet.
function ensure_admin_partner(string $token, array &$profile): void
{
    if (($profile['role'] ?? '') !== 'admin') return;

    // Already linked?
    $linkedId = $profile['linked_profile_id'] ?? null;
    if ($linkedId) {
        $peek = supabase_request('GET',
            '/rest/v1/profiles?select=id,role&id=eq.' . urlencode($linkedId), null, $token);
        if (!empty($peek['body'][0]) && ($peek['body'][0]['role'] ?? '') === 'growth_partner') {
            return;
        }
    }

    // Does one already exist pointing back at this admin?
    $find = supabase_request('GET',
        '/rest/v1/profiles?select=id&role=eq.growth_partner&linked_profile_id=eq.' . urlencode($profile['id']),
        null, $token);
    $gpId = $find['body'][0]['id'] ?? null;

    if (!$gpId) {
        // Build a fresh GP row for this admin. Username is derived from
        // the admin's username to stay human-recognisable; the trigger
        // generates a unique referral_code.
        $username = mb_substr(($profile['username'] ?? 'admin') . '-gp', 0, 20);
        $row = supabase_request('POST', '/rest/v1/profiles', [
            'full_name'         => ($profile['full_name'] ?? 'Admin') . ' — Growth Partner',
            'username'          => $username,
            'email'             => $profile['email']         ?? null,
            'password_hash'     => $profile['password_hash'] ?? null,
            'role'              => 'growth_partner',
            'status'            => 'accepted',
            'affiliate_tier'    => TIER_UNLIMITED,
            'lifecycle_expires_at' => null,
            'linked_profile_id' => $profile['id'],
        ], $token, ['Prefer: return=representation']);
        if ($row['error']) { partner_tier_blocked(true); return; }
        $gpId = $row['body'][0]['id'] ?? null;
        if ($gpId) {
            audit_log($token, 'partner_account_opened', 'profile', $gpId,
                'Linked Growth Partner account opened for admin ' . ($profile['username'] ?? ''));
        }
    }

    if ($gpId && $linkedId !== $gpId) {
        supabase_request('PATCH', '/rest/v1/profiles?id=eq.' . urlencode($profile['id']),
            ['linked_profile_id' => $gpId], $token, ['Prefer: return=minimal']);
        $profile['linked_profile_id'] = $gpId;
    }
}

// Fetches the row this profile is paired with. Returns null when there
// is none. Used by the account switcher and by pages that need to show
// data from the paired side (e.g. admin dashboard surfacing its GP tier).
function linked_profile(string $token, ?array $profile): ?array
{
    if (empty($profile['linked_profile_id'])) return null;
    $res = supabase_request('GET',
        '/rest/v1/profiles?select=*&id=eq.' . urlencode($profile['linked_profile_id']),
        null, $token);
    return $res['body'][0] ?? null;
}

// The session can carry two user_ids: `user_id` is whichever account the
// visitor is currently viewing; `base_user_id` is whoever they actually
// signed in as, so "switch back" always returns there. Both default to
// the signed-in user when no switch has happened.
function session_base_user_id(): ?string
{
    $s = &_session();
    return $s['sb']['base_user_id'] ?? $s['sb']['user_id'] ?? null;
}

// Switches the active profile to $targetId. Allowed only when the target
// is linked to the base user (or IS the base user), so a switch can never
// escape the pair and grant extra access.
function switch_session_to(string $token, string $targetId): ?string
{
    $s = &_session();
    $baseId = session_base_user_id();
    if (!$baseId) return 'Not signed in.';

    if ($targetId !== $baseId) {
        $base   = supabase_request('GET',
            '/rest/v1/profiles?select=id,linked_profile_id&id=eq.' . urlencode($baseId), null, $token);
        $target = supabase_request('GET',
            '/rest/v1/profiles?select=id,linked_profile_id&id=eq.' . urlencode($targetId), null, $token);
        $baseRow   = $base['body'][0]   ?? null;
        $targetRow = $target['body'][0] ?? null;
        if (!$baseRow || !$targetRow) return 'That account could not be found.';

        $linked = ($baseRow['linked_profile_id'] ?? null) === $targetId
               || ($targetRow['linked_profile_id'] ?? null) === $baseId;
        if (!$linked) return 'That account is not linked to yours.';
    }

    $s['sb']['base_user_id'] = $baseId;
    $s['sb']['user_id']      = $targetId;
    _save_session();
    audit_log($token, 'session_switched', 'profile', $targetId,
        'Session switched from ' . $baseId . ' to ' . $targetId);
    return null;
}

// none (no plan yet) | active | grace | terminated
function affiliate_state(array $p): string
{
    if (tier_unlimited($p)) return 'active';          // never expires
    if (empty($p['affiliate_tier']) || empty($p['lifecycle_expires_at'])) return 'none';
    $exp = strtotime($p['lifecycle_expires_at']);
    if ($exp >= time()) return 'active';
    $grace = AFFILIATE_GRACE_DAYS[$p['affiliate_tier']] ?? 7;
    return time() <= $exp + $grace * 86400 ? 'grace' : 'terminated';
}

function affiliate_grace_ends(array $p): ?int
{
    if (tier_unlimited($p)) return null;
    if (empty($p['affiliate_tier']) || empty($p['lifecycle_expires_at'])) return null;
    return strtotime($p['lifecycle_expires_at']) + (AFFILIATE_GRACE_DAYS[$p['affiliate_tier']] ?? 7) * 86400;
}

const EXPIRY_NOTICE_DAYS = 7;

// Basic Growth Partners get a notification once their term has 7 days or
// less left (and again when it lapses into the 7-day grace period): refer a
// client or upgrade to Standard for US$100. Each notice is sent once per
// term — the link carries the expiry date, so an extended term starts over.
function remind_basic_expiry(array $p): void
{
    if (($p['affiliate_tier'] ?? '') !== 'basic' || empty($p['lifecycle_expires_at'])) return;
    $exp   = strtotime($p['lifecycle_expires_at']);
    $state = affiliate_state($p);
    $left  = (int) ceil(($exp - time()) / 86400);

    if ($state === 'active' && $left <= EXPIRY_NOTICE_DAYS) {
        $kind  = 'expiring';
        $title = 'Only ' . $left . ' day' . ($left === 1 ? '' : 's') . ' left on your Basic account';
        $body  = 'Your Basic Growth Partner term ends on ' . date('M j, Y', $exp) . '. To keep it, find a client — when a client you referred pays their deposit you get +3 months — or upgrade to Standard for US$100 (+2 years).';
    } elseif ($state === 'grace') {
        $kind  = 'grace';
        $title = 'Your Basic account has expired — save it by ' . date('M j', affiliate_grace_ends($p));
        $body  = 'Find a client who pays their deposit, or upgrade to Standard for US$100, before ' . date('M j, Y', affiliate_grace_ends($p)) . '. After that the account is terminated and Pending commissions are forfeited.';
    } else {
        return;
    }

    $link = '/dashboard-affiliate.php?view=plan&notice=' . $kind . '-' . date('Ymd', $exp);
    $sent = supabase_request('GET', '/rest/v1/notifications?select=id&limit=1&user_id=eq.' . urlencode($p['id']) . '&link=eq.' . rawurlencode($link), null, SUPABASE_SERVICE_KEY);
    if (!is_array($sent['body']) || $sent['error'] || $sent['body']) return;   // already sent (or can't tell)
    notify(SUPABASE_SERVICE_KEY, $title, $body, $p['id'], 'all', $link);
}

// New expiry after an approved Basic or Standard plan payment.
function affiliate_new_expiry(array $p, string $tier): string
{
    if ($tier === 'standard' && ($p['affiliate_tier'] ?? '') === 'standard' && !empty($p['lifecycle_expires_at'])) {
        // Renewal: +2 years on top of the current expiry (stackable).
        $base = max(strtotime($p['lifecycle_expires_at']), time());
        return date('c', strtotime('+2 years', $base));
    }
    return date('c', strtotime($tier === 'standard' ? '+27 months' : '+3 months'));
}

function tier_label(?string $tier): string
{
    return ['basic' => 'Basic', 'standard' => 'Standard', TIER_UNLIMITED => 'Unlimited'][$tier ?? ''] ?? 'No plan';
}

// Puts a member's Growth Partner side on Basic or Standard, and sets its term.
function activate_account(string $token, string $userId, string $tier): ?string
{
    $p = supabase_request('GET', '/rest/v1/profiles?select=*&id=eq.' . urlencode($userId), null, $token)['body'][0] ?? null;
    if (!$p) return 'User not found.';
    $res  = supabase_request('PATCH', '/rest/v1/profiles?id=eq.' . urlencode($userId), [
        'affiliate_tier'       => $tier,
        'status'               => 'accepted',
        'lifecycle_expires_at' => affiliate_new_expiry($p, $tier),
    ], $token, ['Prefer: return=minimal']);
    if ($res['error']) return $res['error'];

    supabase_request('PATCH', '/rest/v1/referrals?status=eq.pending&referred_id=eq.' . urlencode($userId),
        ['status' => 'active'], $token, ['Prefer: return=minimal']);
    return null;
}

// A pending affiliate plan request (activation) or client deposit.
function fetch_activation_txn(string $token, string $txnId): ?array
{
    $res = supabase_request('GET', '/rest/v1/transactions?select=*,packages!transactions_package_id_fkey(name,tier,kind,price,monthly_price)&type=in.(activation,deposit)&id=eq.' . urlencode($txnId), null, $token);
    return $res['body'][0] ?? null;
}

// Admin approves a pending request:
//  - activation: an affiliate's Basic/Standard plan → sets their term.
//    Never commissionable (§3.2).
//  - deposit:    a client's 50% advance deposit on a service package →
//    work can start; the referrer earns the Outright Commission (§3.1).
function approve_activation(string $token, string $txnId, string $adminId): ?string
{
    $txn = fetch_activation_txn($token, $txnId);
    if (!$txn)                         return 'Transaction not found.';
    if ($txn['status'] !== 'pending')  return 'This request was already reviewed.';
    $isDeposit = $txn['type'] === 'deposit';
    $pkgName   = $txn['packages']['name'] ?? 'package';

    // A client claims a package only with an approved ID.
    if ($isDeposit) {
        $buyer = supabase_request('GET', '/rest/v1/profiles?select=kyc_status,affiliate_tier,role,full_name&id=eq.' . urlencode($txn['user_id']), null, $token)['body'][0] ?? null;
        if (!kyc_approved($buyer)) {
            return ($buyer['full_name'] ?? 'This client') . '\'s ID is not approved yet — approve their KYC first, then the deposit.';
        }
    }

    if (!$isDeposit) {
        $err = activate_account($token, $txn['user_id'], $txn['packages']['tier'] ?? 'standard');
        if ($err) return $err;
    }

    $res = supabase_request('PATCH', '/rest/v1/transactions?status=eq.pending&id=eq.' . urlencode($txnId), [
        'status' => 'completed', 'reviewed_by' => $adminId, 'reviewed_at' => date('c'),
    ], $token, ['Prefer: return=representation']);
    if ($res['error']) return $res['error'];
    if (empty($res['body'])) return 'This request was already reviewed.';

    $owner = roulette_assign($token, $txnId, $txn['user_id']);

    audit_log($token, $isDeposit ? 'approve_deposit' : 'approve_activation', 'transaction', $txnId,
        'Approved ' . $pkgName . ' ($' . number_format((float) $txn['amount'], 2) . ') ref ' . ($txn['payment_ref'] ?? '—') . (($by = actor_full_name($token)) ? ' by ' . $by : '')
        . ($owner ? ' → roulette: ' . $owner['full_name'] : ''));

    if ($isDeposit) {
        notify($token, 'Deposit confirmed — ' . $pkgName,
            'Your 50% advance deposit is confirmed and setup work can begin. Progress reports will appear in your dashboard.'
            . ($owner ? ' Your KAM Team contact is ' . $owner['full_name'] . '.' : ''), $txn['user_id']);
        pay_referral_commission($token, $txn);
        // Referral pipeline: the referred client is now Approved.
        referral_stage_set($token, $txn['user_id'], 'approved', [
            'stage_package_id' => $txn['package_id'] ?? null, 'stage_txn_id' => $txnId, 'stage_by' => $adminId,
        ]);
    } else {
        $tier = $txn['packages']['tier'] ?? 'standard';
        notify($token, ucfirst($tier) . ' Growth Partner plan active',
            'Your ' . $pkgName . ' plan is active. Your countdown has started.'
            . ($owner ? ' Your KAM Team contact is ' . $owner['full_name'] . '.' : ''), $txn['user_id']);
    }
    return null;
}

// =====================================================================
// Referral commission (Manual §3)
// =====================================================================

// Outright Commission rate, as a % of the client's 50% advance deposit.
function commission_pct(string $token): float
{
    $raw = get_settings($token, ['referral_commission_pct'])['referral_commission_pct'];
    return $raw === '' ? 10.0 : max(0.0, min(100.0, (float) $raw));
}

// Uni-Level (Level 1 sponsor override) rate, as a % of the co-growth
// partner's Outright Commission (Manual §3.2). Direct Level 1 only.
function unilevel_pct(string $token): float
{
    $raw = get_settings($token, ['unilevel_commission_pct'])['unilevel_commission_pct'] ?? '';
    return $raw === '' ? 5.0 : max(0.0, min(100.0, (float) $raw));
}

// The program's commission maths in one place (Manual §3, partner deck p.4):
//   Step A  deposit   = actual invoiced setup fee × 50%
//   Step B  outright  = deposit × 10%            (direct Growth Partner)
//   Step C  unilevel  = outright × 5%            (that partner's direct sponsor)
// Pass the setup fee, or the deposit with $isDeposit = true. Rates default to
// 10% / 5%. Example: setup $1,000 → deposit $500 → outright $50 → unilevel $2.50.
function commission_split(float $amount, bool $isDeposit = false, float $outrightPct = 10.0, float $unilevelPct = 5.0): array
{
    $deposit  = $isDeposit ? $amount : $amount * 0.5;
    $outright = $deposit * $outrightPct / 100;
    $unilevel = $outright * $unilevelPct / 100;
    return [
        'setup'    => round($isDeposit ? $deposit * 2 : $amount, 2),
        'deposit'  => round($deposit, 2),
        'outright' => round($outright, 2),
        'unilevel' => round($unilevel, 2),
    ];
}

// 5.0 -> "5", 7.5 -> "7.5", 10.0 -> "10"
function pct_label(float $pct): string
{
    return rtrim(rtrim(number_format($pct, 2, '.', ''), '0'), '.');
}

// Credits the referrer when a referred client's 50% advance deposit is
// approved. Only deposits are commissionable — never affiliate plan
// payments, the setup balance or monthly fees (§3.3). One commission per
// deposit (unique index on user_id + source_txn_id). A Qualified Client
// also adds 3 months to an affiliate referrer's term (§4.1, §4.2).
function pay_referral_commission(string $token, array $txn): void
{
    if (($txn['type'] ?? '') !== 'deposit') return;
    $amount = (float) $txn['amount'];
    $pct    = commission_pct($token);
    if ($amount <= 0) return;

    $buyer    = supabase_request('GET', '/rest/v1/profiles?select=id,full_name,username,referred_by&id=eq.' . urlencode($txn['user_id']), null, $token)['body'][0] ?? null;
    $refId    = $buyer['referred_by'] ?? null;
    if (!$refId) return;
    $referrer = supabase_request('GET', '/rest/v1/profiles?select=*&id=eq.' . urlencode($refId), null, $token)['body'][0] ?? null;
    if (!$referrer || !empty($referrer['deleted_at'])) return;
    // Only Growth Partners earn (Manual §1.1, §2.2). Clients have a code too,
    // and sign-ups made with it are linked to them, but a client with no
    // Growth Partner side is not paid commission.
    if (($referrer['role'] ?? '') === 'client' && empty($referrer['affiliate_tier'])) return;
    // An expired Growth Partner term past its grace period earns nothing.
    if (affiliate_state($referrer) === 'terminated') return;

    // Qualified Client: +3 months on the referrer's Growth Partner term.
    if (!empty($referrer['lifecycle_expires_at'])) {
        $base = max(strtotime($referrer['lifecycle_expires_at']), time());
        supabase_request('PATCH', '/rest/v1/profiles?id=eq.' . urlencode($refId),
            ['lifecycle_expires_at' => date('c', strtotime('+3 months', $base))], $token, ['Prefer: return=minimal']);
    }

    $ulPct      = unilevel_pct($token);
    $split      = commission_split($amount, true, $pct, $ulPct);
    $commission = $split['outright'];
    if ($commission <= 0) return;

    $res = supabase_request('POST', '/rest/v1/transactions', [
        'user_id'        => $refId,
        'package_id'     => $txn['package_id'] ?? null,
        'source_user_id' => $txn['user_id'],
        'source_txn_id'  => $txn['id'],
        'amount'         => $commission,
        'type'           => 'commission',
        'status'         => 'completed',
        'notes'          => pct_label($pct) . '% Outright Commission on the advance deposit of ' . ($buyer['full_name'] ?? 'a referred client'),
    ], $token, ['Prefer: return=minimal']);
    if ($res['error']) return;

    audit_log($token, 'referral_commission', 'transaction', $txn['id'],
        'Paid $' . number_format($commission, 2) . ' (' . pct_label($pct) . '% of deposit) to referrer of ' . ($buyer['username'] ?? $txn['user_id']));
    notify($token, 'You earned $' . number_format($commission, 2),
        ($buyer['full_name'] ?? 'A client you referred') . ' paid the advance deposit on ' . ($txn['packages']['name'] ?? 'a package')
        . '. You earned ' . pct_label($pct) . '% of it'
        . (!empty($referrer['lifecycle_expires_at']) ? ' and 3 more months on your Growth Partner term.' : '.'), $refId);

    // Step C — Uni-Level: the referrer's direct sponsor earns 5% of this
    // Outright Commission (Manual §3.2). Direct Level 1 only: it is never
    // passed further up. The sponsor must be a Growth Partner whose term
    // has not ended; nothing is paid for joining or for plan payments
    // (this runs only for approved client deposits).
    //
    // Where it goes:
    //   - the referrer's upline, when that upline is an eligible Growth
    //     Partner (an increment on that partner's earnings and Payout);
    //   - the Main Basket, when the referrer IS an admin's own Growth
    //     Partner account, when the upline is one, or when there is no
    //     eligible upline.
    if ($split['unilevel'] <= 0) return;
    $refName = $referrer['full_name'] ?? null;
    if (is_house_partner($referrer)) { basket_add($token, $txn, $split['unilevel'], 'referrer is an admin Growth Partner account', $refName); return; }
    $sponsorId = $referrer['referred_by'] ?? null;
    $sponsor   = $sponsorId ? (supabase_request('GET', '/rest/v1/profiles?select=*&id=eq.' . urlencode($sponsorId), null, $token)['body'][0] ?? null) : null;
    if (!$sponsor || !empty($sponsor['deleted_at'])) { basket_add($token, $txn, $split['unilevel'], 'no upline', $refName); return; }
    if (is_house_partner($sponsor)) { basket_add($token, $txn, $split['unilevel'], 'upline is an admin Growth Partner account', $refName); return; }
    if (($sponsor['role'] ?? '') !== 'growth_partner' && empty($sponsor['affiliate_tier'])) { basket_add($token, $txn, $split['unilevel'], 'upline is not a Growth Partner', $refName); return; }
    if (affiliate_state($sponsor) === 'terminated') { basket_add($token, $txn, $split['unilevel'], "upline's Growth Partner term has ended", $refName); return; }

    $ul = supabase_request('POST', '/rest/v1/transactions', [
        'user_id'        => $sponsorId,
        'package_id'     => $txn['package_id'] ?? null,
        'source_user_id' => $txn['user_id'],
        'source_txn_id'  => $txn['id'],
        'amount'         => $split['unilevel'],
        'type'           => 'commission',
        'status'         => 'completed',
        'notes'          => pct_label($ulPct) . '% Uni-Level (Level 1) on ' . ($referrer['full_name'] ?? 'your co-growth partner') . '\'s $' . number_format($commission, 2) . ' Outright Commission',
    ], $token, ['Prefer: return=minimal']);
    if ($ul['error']) return;

    audit_log($token, 'unilevel_commission', 'transaction', $txn['id'],
        'Paid $' . number_format($split['unilevel'], 2) . ' (' . pct_label($ulPct) . '% of $' . number_format($commission, 2) . ' Outright) to sponsor of ' . ($referrer['username'] ?? $refId));
    notify($token, 'You earned $' . number_format($split['unilevel'], 2) . ' Uni-Level',
        ($referrer['full_name'] ?? 'Your co-growth partner') . '\'s client paid a deposit. You earned ' . pct_label($ulPct) . '% of their $' . number_format($commission, 2) . ' Outright Commission.', $sponsorId);
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

    if ($txn['type'] === 'deposit') {
        referral_stage_set($token, $txn['user_id'], 'rejected', [
            'stage_package_id' => $txn['package_id'] ?? null, 'stage_txn_id' => $txnId, 'stage_by' => $adminId,
            'stage_reason' => $reason !== '' ? $reason : 'Deposit not approved',
        ]);
    }

    audit_log($token, $txn['type'] === 'deposit' ? 'reject_deposit' : 'reject_activation', 'transaction', $txnId, 'Rejected payment ref ' . ($txn['payment_ref'] ?? '—') . ($reason !== '' ? ' — ' . $reason : ''));
    notify($token, $txn['type'] === 'deposit' ? 'Deposit not approved' : 'Plan request not approved',
        trim(($txn['packages']['name'] ?? 'Your plan') . ' was not approved. ' . $reason) . ' You can submit a new request.', $txn['user_id']);
    return null;
}

// =====================================================================
// Approval wall — account applications
// =====================================================================

function role_name(string $role): string
{
    return [
        'admin'          => 'Admin',
        'staff'          => 'Staff',
        'client'         => 'Client',
        'growth_partner' => 'Growth Partner',
        'affiliate'      => 'Client',          // legacy rows, pre-migration
    ][$role] ?? ucwords(str_replace('_', ' ', $role));
}

// Turns "the database hasn't been migrated yet" errors into instructions.
function db_error_message(?string $err): ?string
{
    if ($err && (str_contains($err, 'profiles_role_check') || str_contains($err, 'profiles_affiliate_tier_check'))) {
        return 'The database is not up to date yet. Run the migrations in supabase/migrations (20261122, 20261124 and 20261125) in the Supabase SQL Editor, then try again.';
    }
    return $err;
}

// Admin-only: registers a staff member (or another admin) directly. The
// account is approved immediately, has no expiry and skips the program
// terms (those bind affiliates and clients, not employees).
// Returns an error message, or null on success.
function create_team_account(string $token, array $in): ?string
{
    $username = strtolower(trim((string) ($in['username'] ?? '')));
    $email    = strtolower(trim((string) ($in['email'] ?? '')));
    $name     = trim((string) ($in['full_name'] ?? ''));
    $pw       = (string) ($in['password'] ?? '');
    $role     = in_array($in['role'] ?? '', ['staff', 'admin'], true) ? $in['role'] : 'staff';

    if ($name === '')                                           return 'Full name is required.';
    if (!preg_match('/^[a-z0-9_]{3,20}$/', $username))          return 'Username must be 3–20 letters, numbers or underscores.';
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) return 'Enter a valid email address.';
    if (strlen($pw) < 8)                                         return 'The temporary password must be at least 8 characters.';
    if ($pw !== (string) ($in['password_confirm'] ?? ''))        return 'The passwords do not match.';

    $taken = supabase_request('GET', '/rest/v1/profiles?select=id&username=eq.' . urlencode($username), null, $token);
    if (!empty($taken['body'])) return 'That username is already taken.';
    if ($email !== '') {
        $taken = supabase_request('GET', '/rest/v1/profiles?select=id&email=eq.' . urlencode($email), null, $token);
        if (!empty($taken['body'])) return 'That email already belongs to an account.';
    }

    $row = [
        'full_name'     => mb_substr($name, 0, 120),
        'username'      => $username,
        'email'         => $email ?: null,
        'password_hash' => password_hash($pw, PASSWORD_BCRYPT),
        'phone'         => mb_substr(trim((string) ($in['phone'] ?? '')), 0, 40) ?: null,
        'role'          => $role,
        'status'        => 'accepted',
    ];
    // An admin also gets a Growth Partner account — same referral code and
    // commission as anyone else, but with no term to renew.
    if ($role === 'admin') $row += ['affiliate_tier' => TIER_UNLIMITED, 'lifecycle_expires_at' => null];

    $res = supabase_request('POST', '/rest/v1/profiles', $row, $token, ['Prefer: return=representation']);
    // Older database without the 'unlimited' tier: still create the admin,
    // and ensure_admin_partner() opens the partner side once it is migrated.
    if ($role === 'admin' && $res['error'] && str_contains((string) $res['error'], 'affiliate_tier')) {
        partner_tier_blocked(true);
        unset($row['affiliate_tier'], $row['lifecycle_expires_at']);
        $res = supabase_request('POST', '/rest/v1/profiles', $row, $token, ['Prefer: return=representation']);
    }
    if ($res['error'] || empty($res['body'][0])) return db_error_message($res['error']) ?: 'Could not create the account.';

    $new = $res['body'][0];
    audit_log($token, 'staff_created', 'profile', $new['id'],
        role_name($role) . ' account for ' . $new['full_name'] . ' (@' . $username . ')'
        . ($role === 'admin' ? ' — with an unlimited Growth Partner account' : ''));
    notify($token, 'Welcome to KAM TEAM',
        'Your ' . role_name($role) . ' account is ready.'
        . ($role === 'admin' ? ' Your referral code is ' . ($new['referral_code'] ?? '—') . ' — it never expires.' : ''),
        $new['id'], 'all', dashboard_url($role));
    return null;
}

function approve_application(string $token, string $userId): ?string
{
    $res = supabase_request('GET', '/rest/v1/profiles?select=id,role,status&id=eq.' . urlencode($userId), null, $token);
    $p   = $res['body'][0] ?? null;
    if (!$p) return 'User not found.';
    if (!in_array($p['status'], ['pending', 'rejected'], true)) return 'This application was already reviewed.';

    // The Client side is permanent. The Growth Partner term starts when the
    // member's Basic/Standard plan is activated, not here.
    $res = supabase_request('PATCH', '/rest/v1/profiles?id=eq.' . urlencode($userId), [
        'status' => 'accepted', 'review_note' => null,
    ], $token, ['Prefer: return=minimal']);
    if ($res['error']) return $res['error'];

    // Who approved it (migration 20261205; ignored if not run yet).
    $by = actor_full_name($token);
    supabase_request('PATCH', '/rest/v1/profiles?id=eq.' . urlencode($userId), [
        'approved_by' => session_base_user_id(), 'approved_at' => date('c'),
    ], $token, ['Prefer: return=minimal']);

    // Roulette: every new account takes one turn and keeps that admin for good.
    $owner = roulette_claim_account($token, $userId);

    audit_log($token, 'approve_application', 'profile', $userId,
        'Approved ' . role_name($p['role']) . ' application' . ($by ? ' by ' . $by : '') . ($owner ? ' → roulette: ' . $owner['full_name'] : ''));
    notify($token, 'Application approved',
        'Welcome aboard — your ' . role_name($p['role']) . ' account is active.'
        . ($owner ? ' Your KAM Team contact is ' . $owner['full_name'] . '.' : ''), $userId);
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
// Business profile (asked at registration)
// =====================================================================

const BUSINESS_NATURES = [
    'Real estate', 'E-commerce / Retail', 'Food & beverage', 'Health & wellness', 'Professional services',
    'Construction & home services', 'Education & coaching', 'Marketing / Agency', 'Technology / SaaS',
    'Manufacturing', 'Logistics & transport', 'Other',
];

// Struggle key => [label, the KAM Team service that solves it]
const BUSINESS_STRUGGLES = [
    'leads'     => ['Getting enough leads & customers',      'S-01 Marketing'],
    'admin'     => ['Too much admin & repetitive tasks',      'S-02 Virtual Assistance'],
    'content'   => ['Creating videos & social content',       'S-03 Video Editing'],
    'books'     => ['Bookkeeping, invoices & taxes',          'S-04 Accounting'],
    'support'   => ['Answering calls & customer support',     'S-05 Call Center Support'],
    'projects'  => ['Keeping projects & the team on track',   'S-06 Project Management'],
    'ai'        => ['Using AI & automation in the business',  'S-07 AI Implementation'],
];

function struggle_label(string $key): string
{
    return BUSINESS_STRUGGLES[$key][0] ?? $key;
}

// Services that match a user's struggles, for the admin's quick read.
function suggested_services(array $struggles): array
{
    return array_values(array_filter(array_map(fn($k) => BUSINESS_STRUGGLES[$k][1] ?? null, $struggles)));
}

function business_summary(array $p): string
{
    if (empty($p['business_nature']) && empty($p['business_struggles']) && empty($p['business_notes'])) {
        return '<span style="color:var(--text-dim)">—</span>';
    }
    $chips = '';
    foreach (($p['business_struggles'] ?? []) as $k) $chips .= '<span class="chip">' . e(struggle_label($k)) . '</span>';
    return '<div class="biz">'
        . (!empty($p['business_nature']) ? '<strong>' . e($p['business_nature']) . '</strong>' : '')
        . ($chips ? '<div class="chips">' . $chips . '</div>' : '')
        . (!empty($p['business_notes']) ? '<p class="biz-notes">“' . e($p['business_notes']) . '”</p>' : '')
        . '</div>';
}

// The user's own business card (dashboard), with an inline edit form.
function business_card(string $token, array $profile, string $csrf): string
{
    $contact = null;
    if (!empty($profile['assigned_admin'])) {
        $contact = supabase_request('GET', '/rest/v1/profiles?select=full_name,email,phone&id=eq.' . urlencode($profile['assigned_admin']), null, $token)['body'][0] ?? null;
    }
    $opts = '<option value="">Select one…</option>';
    foreach (BUSINESS_NATURES as $bn) $opts .= '<option' . (($profile['business_nature'] ?? '') === $bn ? ' selected' : '') . '>' . e($bn) . '</option>';
    $boxes = '';
    foreach (BUSINESS_STRUGGLES as $key => [$label]) {
        $boxes .= '<label class="struggle-option"><input type="checkbox" name="business_struggles[]" value="' . e($key) . '"'
            . (in_array($key, $profile['business_struggles'] ?? [], true) ? ' checked' : '') . '><span>' . e($label) . '</span></label>';
    }
    return '<section class="card" id="business" style="margin-bottom:12px">'
        . '<h2>Your business</h2>'
        . ($contact ? '<p class="contact-line">Your KAM Team contact: <strong>' . e($contact['full_name']) . '</strong>'
            . (!empty($contact['email']) ? ' · <a href="mailto:' . e($contact['email']) . '">' . e($contact['email']) . '</a>' : '') . '</p>' : '')
        . business_summary($profile)
        . '<details class="biz-self"><summary class="btn btn-sm btn-ghost">Update business info</summary>'
        . '<form method="post" action="/business.php"><input type="hidden" name="csrf" value="' . e($csrf) . '">'
        . '<div class="field"><label>Nature of business</label><select name="business_nature" required>' . $opts . '</select></div>'
        . '<div class="field"><label>What are you struggling with?</label><div class="struggle-pick">' . $boxes . '</div></div>'
        . '<div class="field"><label>Tell us more</label><textarea name="business_notes" rows="3" maxlength="1000">' . e($profile['business_notes'] ?? '') . '</textarea></div>'
        . '<button class="btn btn-sm btn-solid" type="submit">Save →</button></form></details>'
        . '</section>';
}

// =====================================================================
// Affiliate account block (affiliate dashboard + each client's Affiliate tab)
// =====================================================================

// Referral code + link, commission rule, earnings and the people referred.
// Builds the Growth Partner dashboard pieces. $parts picks which ones, so
// each sidebar page renders only its own section:
//   share     the referral code card (Copy copies only the characters after
//             KT-, which is what the sign-up form asks for)
//   stats     Referred / Clients / Commissions / Earned tiles
//   earnings  the commission ledger
//   network   the downline graph
function affiliate_account_html(string $token, array $profile, bool $canRefer = true, array $parts = ['share', 'stats', 'earnings', 'network']): string
{
    $uid  = urlencode($profile['id']);
    $code = (string) ($profile['referral_code'] ?? '');
    $out  = '';

    if (in_array('share', $parts, true)) {
        $pct    = commission_pct($token);
        $suffix = preg_replace('/^KT-/', '', $code);
        $link   = APP_URL . '/index.php?ref=' . urlencode($code);
        $out .= '<section class="card share-card" id="share" style="margin-bottom:12px">'
              . '<span class="label">Your referral code</span>';
        if (!kyc_approved($profile)) {
            $out .= '<div style="margin-top:8px">' . kyc_gate_notice($profile, 'referrals') . '</div>';
        } elseif ($canRefer) {
            $out .= '<div class="share share-code" style="margin-top:8px">'
                  .   '<div class="code-box"><span class="code-prefix" aria-hidden="true">KT-</span>'
                  .   '<input id="share-code" type="text" readonly value="' . e($suffix) . '" aria-label="Referral code, the characters after KT-"></div>'
                  .   '<button class="btn btn-sm btn-solid" type="button" data-copy="#share-code">Copy code</button></div>'
                  . '<p class="hint" style="margin:6px 0 0">Sign-ups type these ' . strlen($suffix) . ' characters after the printed <strong>KT-</strong> on the registration form.</p>'
                  . '<div class="how-refer">'
                  .   '<h3>How a client uses your code</h3>'
                  .   '<ol>'
                  .     '<li>They open the sign-up page and choose <strong>Client — I want KAM Team services</strong>.</li>'
                  .     '<li>In <strong>Referral code</strong> they type <strong>' . e($suffix) . '</strong> after the printed KT-.</li>'
                  .     '<li>Once an admin approves them, they appear on your <a href="/dashboard-affiliate.php?view=clients">Clients referred</a> page.</li>'
                  .     '<li>When they pay the 50% advance deposit on a package, your commission is credited.</li>'
                  .   '</ol>'
                  . '</div>'
                  . '<span class="label" style="display:block;margin-top:16px">Client sign-up link</span>'
                  . '<div class="share share-link" style="margin-top:6px"><input id="share-client-link" type="text" readonly value="' . e($link . '&as=client') . '" aria-label="Client sign-up link">'
                  .   '<button class="btn btn-sm" type="button" data-copy="#share-client-link">Copy link</button></div>'
                  . '<p class="hint" style="margin:6px 0 0">Opens sign-up with <strong>Client</strong> selected and your code already filled in.</p>'
                  . '<span class="label" style="display:block;margin-top:14px">Growth Partner invite link</span>'
                  . '<div class="share share-link" style="margin-top:6px"><input id="share-link" type="text" readonly value="' . e($link) . '" aria-label="Growth Partner invite link">'
                  .   '<button class="btn btn-sm" type="button" data-copy="#share-link">Copy link</button></div>'
                  . '<p class="hint" style="margin:6px 0 0">For people who want to refer clients themselves — they join your downline.</p>';
        } else {
            $out .= '<p class="alert alert-warn" style="margin:8px 0 0">Your Growth Partner term has expired. New referrals are not credited until you renew or upgrade.</p>';
        }
        $out .= '<p style="margin:14px 0 0;font-size:12px;color:var(--text-dim)">Clients who sign up with your code are credited to you. When a client you referred pays the 50% advance deposit on a service package, you earn <strong>' . e(pct_label($pct)) . '% of that deposit</strong>. Monthly fees and the remaining setup balance are not commissionable, and no income is guaranteed. '
              . 'Always disclose that you may earn a commission (e.g. "I earn a commission if you hire KAM TEAM through my link").</p></section>';
    }

    if (in_array('stats', $parts, true) || in_array('earnings', $parts, true)) {
        $refs = supabase_request('GET', '/rest/v1/referrals?select=created_at,status,referred:profiles!referrals_referred_id_fkey(full_name,role)&referrer_id=eq.' . $uid . '&order=created_at.desc', null, $token)['body'] ?? [];
        $coms = supabase_request('GET', '/rest/v1/transactions?select=amount,created_at,notes,packages(name),source:profiles!transactions_source_user_id_fkey(full_name)&type=eq.commission&status=eq.completed&user_id=eq.' . $uid . '&order=created_at.desc&limit=50', null, $token)['body'] ?? [];
        $refs = is_array($refs) ? $refs : [];
        $coms = is_array($coms) ? $coms : [];
        $earned  = array_sum(array_map(fn($c) => (float) $c['amount'], $coms));
        $clients = count(array_filter($refs, fn($r) => ($r['referred']['role'] ?? '') === 'client'));

        if (in_array('stats', $parts, true)) {
            $out .= '<section class="grid">'
                  . '<div class="card"><span class="label">Referred</span><div class="big">' . count($refs) . '</div></div>'
                  . '<div class="card"><span class="label">Clients</span><div class="big cyan">' . $clients . '</div></div>'
                  . '<div class="card"><span class="label">Commissions</span><div class="big">' . count($coms) . '</div></div>'
                  . '<div class="card"><span class="label">Earned</span><div class="big green">$' . number_format($earned, 2) . '</div></div></section>';
        }

        if (in_array('earnings', $parts, true)) {
            $out .= '<section class="card" id="earnings" style="margin-bottom:12px"><h2>Commission ledger</h2>';
            if (!$coms) {
                $out .= '<p class="empty">No commissions yet.</p>';
            } else {
                $out .= '<div class="table-scroll" tabindex="0" role="region" aria-label="Commission ledger"><table><thead><tr><th>Type</th><th>Client</th><th>Package</th><th>Amount</th><th>Date</th></tr></thead><tbody>';
                foreach ($coms as $c) {
                    // Uni-Level rows are the 5% Level 1 override on a co-growth partner's sale.
                    $isUl = str_contains((string) ($c['notes'] ?? ''), 'Uni-Level');
                    $out .= '<tr><td><span class="pill ' . ($isUl ? 'completed' : 'active') . '" title="' . e((string) ($c['notes'] ?? '')) . '">' . ($isUl ? 'Uni-Level 5%' : 'Outright 10%') . '</span></td><td style="font-size:12px">' . e($c['source']['full_name'] ?? '—') . '</td><td style="font-size:12px">' . e($c['packages']['name'] ?? '—') . '</td>'
                          . '<td style="color:var(--green)">$' . number_format((float) $c['amount'], 2) . '</td><td style="font-size:12px">' . date('M j, Y', strtotime($c['created_at'])) . '</td></tr>';
                }
                $out .= '</tbody></table></div>';
            }
            $out .= '</section>';
        }
    }

    if (in_array('network', $parts, true)) $out .= downline_graph_html($token, $profile);

    // The copy buttons' behaviour lives in assets/js/app.js (copyButtons).
    return $out;
}

// The Growth Partner's "Clients referred" page: every Client who signed up
// with this partner's code, their approval status, what they have bought,
// and the commission each has earned the partner.
function clients_referred_html(string $token, array $profile): string
{
    $uid     = urlencode($profile['id']);
    $clients = supabase_request('GET', '/rest/v1/profiles?select=id,full_name,username,status,created_at,company'
        . '&role=eq.client&deleted_at=is.null&referred_by=eq.' . $uid . '&order=created_at.desc', null, $token)['body'] ?? [];
    $clients = is_array($clients) ? $clients : [];

    $out = '<section class="card" id="clients" style="margin-bottom:12px"><h2>Clients referred</h2>'
         . '<p class="hint" style="margin:0 0 12px">Clients who signed up with your code. Each moves <strong>Pending → Converted (package) → Approved</strong>, or is Rejected with a reason — the admin decides. On Approved your Outright Commission is credited to your Payout balance. Monthly fees are not commissionable.</p>';
    if (!$clients) {
        return $out . '<p class="empty">No clients yet. Share your <a href="/dashboard-affiliate.php?view=share">client sign-up link</a> — clients who use your code appear here.</p></section>';
    }

    $ids = implode(',', array_map(fn($c) => urlencode($c['id']), $clients));
    $deps = supabase_request('GET', '/rest/v1/transactions?select=user_id,amount,status&type=eq.deposit&user_id=in.(' . $ids . ')', null, $token)['body'] ?? [];
    $coms = supabase_request('GET', '/rest/v1/transactions?select=source_user_id,amount&type=eq.commission&status=eq.completed&user_id=eq.' . $uid, null, $token)['body'] ?? [];
    $paid = $earned = [];
    foreach (is_array($deps) ? $deps : [] as $d) {
        if (($d['status'] ?? '') === 'completed') $paid[$d['user_id']] = ($paid[$d['user_id']] ?? 0) + 1;
    }
    foreach (is_array($coms) ? $coms : [] as $c) {
        $earned[$c['source_user_id']] = ($earned[$c['source_user_id']] ?? 0) + (float) $c['amount'];
    }
    $total = array_sum($earned);
    $active = count(array_filter($clients, fn($c) => $c['status'] === 'accepted'));
    // Referral pipeline stage per client (Pending > Converted > Approved / Rejected).
    $stages = supabase_request('GET', '/rest/v1/referrals?select=referred_id,stage,stage_reason,stage_package_id,package:packages!referrals_stage_package_id_fkey(name)&level=eq.1&referrer_id=eq.' . $uid, null, $token)['body'] ?? [];
    $stageOf = [];
    foreach (is_array($stages) ? $stages : [] as $st) $stageOf[$st['referred_id']] = $st;

    $out .= '<div class="downline-levels" style="margin-bottom:10px"><span class="chip">' . count($clients) . ' referred</span>'
          . '<span class="chip">' . $active . ' active</span>'
          . '<span class="chip">' . count($paid) . ' paid a deposit</span>'
          . '<span class="chip">$' . number_format($total, 2) . ' earned</span></div>';

    $out .= '<div class="table-scroll" tabindex="0" role="region" aria-label="Clients referred"><table>'
          . '<thead><tr><th scope="col">Client</th><th scope="col">Joined</th><th scope="col">Status</th><th scope="col">Deposits paid</th><th scope="col">Your commission</th></tr></thead><tbody>';
    foreach ($clients as $c) {
        [$label, $pill] = match ($c['status']) {
            'accepted'  => ['Active', 'active'],
            'pending'   => ['Awaiting approval', 'pending'],
            'rejected'  => ['Not approved', 'failed'],
            'suspended' => ['Suspended', 'suspended'],
            default     => [ucfirst((string) $c['status']), 'pending'],
        };
        $out .= '<tr><td><strong>' . e($c['full_name']) . '</strong>'
              . (!empty($c['company']) ? '<span class="ut-sub">' . e($c['company']) . '</span>' : '') . '</td>'
              . '<td style="font-size:12px">' . date('M j, Y', strtotime($c['created_at'])) . '</td>'
              . '<td>' . ($c['status'] === 'accepted'
                    ? referral_stage_html($stageOf[$c['id']] ?? ['stage' => 'pending'], $stageOf[$c['id']]['package']['name'] ?? null)
                    : '<span class="pill ' . e($pill) . '">' . e($label) . '</span>') . '</td>'
              . '<td>' . ($paid[$c['id']] ?? 0) . '</td>'
              . '<td style="color:var(--green)">$' . number_format($earned[$c['id']] ?? 0, 2) . '</td></tr>';
    }
    return $out . '</tbody></table></div></section>';
}

// ---- Downline graph ---------------------------------------------------
// Who signed up with this member's code (level 1), who signed up with
// theirs (level 2), and so on, following profiles.referred_by.
// UNLI (0) = no limit. Set a number to cap the levels or people shown.
const UNLI               = 0;
const DOWNLINE_DEPTH     = UNLI;
const DOWNLINE_MAX_NODES = UNLI;

// Returns ['kids' => parentId => [profile…], 'levels' => level => count,
// 'below' => id => people under them, 'total' => n, 'capped' => bool].
function downline_tree(string $token, string $rootId): array
{
    $kids = []; $levels = []; $total = 0; $capped = false;
    $seen = [$rootId => true];            // guards against a referral loop
    $frontier = [$rootId];
    for ($lvl = 1; $frontier && (DOWNLINE_DEPTH === UNLI || $lvl <= DOWNLINE_DEPTH); $lvl++) {
        $next = [];
        // Batches keep the in.(…) filter well under URL-length limits.
        foreach (array_chunk($frontier, 150) as $batch) {
            $res = supabase_request('GET', '/rest/v1/profiles?select=id,full_name,role,status,affiliate_tier,created_at,referred_by'
                . '&deleted_at=is.null&order=created_at.asc&referred_by=in.(' . implode(',', array_map('urlencode', $batch)) . ')', null, $token);
            foreach (is_array($res['body']) && !$res['error'] ? $res['body'] : [] as $r) {
                if (isset($seen[$r['id']])) continue;
                if (DOWNLINE_MAX_NODES !== UNLI && $total >= DOWNLINE_MAX_NODES) { $capped = true; break 3; }
                $seen[$r['id']] = true;
                $r['level'] = $lvl;
                $kids[$r['referred_by']][] = $r;
                $next[] = $r['id'];
                $levels[$lvl] = ($levels[$lvl] ?? 0) + 1;
                $total++;
            }
        }
        $frontier = $next;
    }

    // People under each member, counted once bottom-up (deepest level first).
    $below = [];
    $byLevel = [];
    foreach ($kids as $list) foreach ($list as $k) $byLevel[$k['level']][] = $k;
    krsort($byLevel);
    foreach ($byLevel as $list) foreach ($list as $k) {
        $below[$k['referred_by']] = ($below[$k['referred_by']] ?? 0) + 1 + ($below[$k['id']] ?? 0);
    }
    return ['kids' => $kids, 'levels' => $levels, 'below' => $below, 'total' => $total, 'capped' => $capped];
}

function downline_graph_html(string $token, array $profile): string
{
    $t = downline_tree($token, $profile['id']);
    $out = '<section class="card" id="network" style="margin-bottom:12px"><h2>Your downline</h2>'
         . '<p class="hint" style="margin:0 0 10px">Everyone who signed up with your code, and everyone who signed up with theirs' . (DOWNLINE_DEPTH === UNLI ? ', all the way down.' : ' — up to ' . DOWNLINE_DEPTH . ' levels.') . '</p>';
    if (!$t['total']) {
        return $out . '<p class="empty">Nobody yet. Share your link — people who sign up with your code appear here, and so do the people they bring in.</p></section>';
    }

    $out .= '<div class="downline-levels"><span class="chip">Total ' . $t['total'] . '</span>';
    foreach ($t['levels'] as $lvl => $n) $out .= '<span class="chip">Level ' . $lvl . ' · ' . $n . '</span>';
    $out .= '</div>';

    $render = function (string $id) use (&$render, $t): string {
        if (empty($t['kids'][$id])) return '';
        $html = '<ul>';
        foreach ($t['kids'][$id] as $k) {
            $role = $k['role'];
            $type = $role === 'client'
                ? 'Client · ' . (!empty($k['affiliate_tier']) ? tier_label($k['affiliate_tier']) . ' partner' : 'no partner plan')
                : role_name($role);
            $n    = $t['below'][$k['id']] ?? 0;
            $html .= '<li><div class="org-node ' . e($role) . ($k['status'] !== 'accepted' ? ' is-pending' : '') . '" title="Joined ' . e(date('M j, Y', strtotime($k['created_at']))) . '">'
                   . '<span class="org-lvl">L' . $k['level'] . '</span>'
                   . '<strong>' . e($k['full_name']) . '</strong>'
                   . '<span class="org-type">' . e($type) . ($k['status'] !== 'accepted' ? ' · ' . e($k['status']) : '') . '</span>'
                   . ($n ? '<span class="org-below">' . $n . ' below</span>' : '')
                   . '</div>' . $render($k['id']) . '</li>';
        }
        return $html . '</ul>';
    };

    $out .= '<div class="org"><div class="org-inner"><ul><li><div class="org-node org-root"><span class="org-lvl">You</span><strong>' . e($profile['full_name']) . '</strong>'
          . '<span class="org-type">' . e($profile['referral_code'] ?? '') . '</span></div>' . $render($profile['id']) . '</li></ul></div></div>'
          . '<p class="hint" style="margin:8px 0 0">Scroll sideways on a small screen. Hover a name to see when they joined.'
          . ($t['capped'] ? ' Showing the first ' . DOWNLINE_MAX_NODES . ' people.' : '') . '</p></section>';
    return $out;
}

// =====================================================================
// Roulette — round-robin assignment of approved packages to admins
// =====================================================================

// Advances the rotation one slot and returns whose turn it was.
function roulette_take_turn(string $token): ?string
{
    $turn = supabase_request('POST', '/rest/v1/rpc/roulette_next', [], $token);
    return is_string($turn['body']) ? $turn['body'] : null;
}

// Gives a newly approved account its permanent admin. One account costs one
// turn: the admin claimed here also receives every package that account buys
// later, so buying a plan never moves it to someone else.
// Returns the admin's profile, or null when nobody is in the rotation.
function roulette_claim_account(string $token, string $userId): ?array
{
    $user = supabase_request('GET', '/rest/v1/profiles?select=full_name,role,assigned_admin,business_nature,business_struggles&id=eq.' . urlencode($userId), null, $token)['body'][0] ?? null;
    if (!$user) return null;

    // Already owned — don't burn another turn on the same account.
    $adminId = $user['assigned_admin'] ?? null;
    $isNew   = $adminId === null;
    if ($isNew) {
        $adminId = roulette_take_turn($token);
        if (!$adminId) return null;
        supabase_request('PATCH', '/rest/v1/profiles?id=eq.' . urlencode($userId), ['assigned_admin' => $adminId], $token, ['Prefer: return=minimal']);
    }

    $admin = supabase_request('GET', '/rest/v1/profiles?select=id,full_name&id=eq.' . urlencode($adminId), null, $token)['body'][0] ?? null;
    if ($isNew) {
        notify($token, 'Roulette: a new account is yours',
            ($user['full_name'] ?? 'A new account') . ' (' . role_name($user['role'] ?? 'client') . ') was added to your list'
            . (!empty($user['business_nature']) ? ' · ' . $user['business_nature'] : '')
            . (!empty($user['business_struggles']) ? '. Needs help with: ' . implode(', ', array_map('struggle_label', $user['business_struggles'])) : '') . '.',
            $adminId, 'all', '/dashboard-admin.php?mine=1#roulette');
    }
    return $admin;
}

// Tags an approved package with its account's admin. Falls back to claiming
// the account when it somehow has no owner yet (e.g. approved before the
// roulette existed), so a package is never left unassigned.
function roulette_assign(string $token, string $txnId, string $userId): ?array
{
    $admin = roulette_claim_account($token, $userId);
    if (!$admin) return null;

    supabase_request('PATCH', '/rest/v1/transactions?id=eq.' . urlencode($txnId), ['assigned_admin' => $admin['id']], $token, ['Prefer: return=minimal']);

    $user = supabase_request('GET', '/rest/v1/profiles?select=full_name&id=eq.' . urlencode($userId), null, $token)['body'][0] ?? [];
    $pkg  = supabase_request('GET', '/rest/v1/transactions?select=amount,packages!transactions_package_id_fkey(name)&id=eq.' . urlencode($txnId), null, $token)['body'][0] ?? [];
    notify($token, 'Roulette: your client bought a package',
        ($user['full_name'] ?? 'A client') . ' activated ' . ($pkg['packages']['name'] ?? 'a plan') . '.',
        $admin['id'], 'all', '/dashboard-admin.php?mine=1#roulette');
    return $admin;
}

function roulette_state(string $token): array
{
    $m = supabase_request('GET', '/rest/v1/roulette_members?select=*,admin:profiles!roulette_members_admin_id_fkey(full_name,username,deleted_at)&order=position.asc', null, $token);
    $next = supabase_request('POST', '/rest/v1/rpc/roulette_peek', [], $token);
    return [
        'members' => is_array($m['body']) && !$m['error'] ? $m['body'] : [],
        'next'    => is_string($next['body']) ? $next['body'] : null,
    ];
}

// =====================================================================
// Sidebar navigation
// =====================================================================

// $items: [['label' => .., 'panel' => 'users'] (admin tab) or ['label' => .., 'href' => '#x' | '/page.php'], optional 'count', 'icon']
function app_sidebar(array $items, string $title, ?string $active = null): string
{
    $icons = [
        'home' => '<path d="M3 11l9-8 9 8"/><path d="M5 10v10h14V10"/>',
        'users' => '<circle cx="9" cy="8" r="4"/><path d="M2 21v-1a6 6 0 0 1 12 0v1"/><path d="M16 4a4 4 0 0 1 0 8"/><path d="M22 21v-1a6 6 0 0 0-4-5.6"/>',
        'check' => '<path d="M20 6L9 17l-5-5"/>',
        'id' => '<rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="12" r="2.5"/><path d="M14 10h4M14 14h4"/>',
        'qr' => '<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><path d="M14 14h3v3h-3zM20 14v.01M14 20h.01M17 20h4v-3"/>',
        'link' => '<path d="M10 13a5 5 0 0 0 7 0l3-3a5 5 0 0 0-7-7l-1 1"/><path d="M14 11a5 5 0 0 0-7 0l-3 3a5 5 0 0 0 7 7l1-1"/>',
        'box' => '<path d="M21 8l-9-5-9 5 9 5 9-5z"/><path d="M3 8v8l9 5 9-5V8"/><path d="M12 13v8"/>',
        'cash' => '<rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="3"/>',
        'tool' => '<path d="M14.7 6.3a4 4 0 0 0-5.4 5.4L3 18l3 3 6.3-6.3a4 4 0 0 0 5.4-5.4l-2.6 2.6-2.4-.6-.6-2.4z"/>',
        'bell' => '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/>',
        'log' => '<path d="M4 4h16v16H4z"/><path d="M8 8h8M8 12h8M8 16h5"/>',
        'wheel' => '<circle cx="12" cy="12" r="9"/><path d="M12 3v18M3 12h18M5.6 5.6l12.8 12.8M18.4 5.6L5.6 18.4"/>',
        'tree' => '<circle cx="12" cy="5" r="2"/><circle cx="5" cy="19" r="2"/><circle cx="19" cy="19" r="2"/><path d="M12 7v5M12 12l-7 5M12 12l7 5"/>',
        'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 21v-1a8 8 0 0 1 16 0v1"/>',
        'trash' => '<path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M6 6l1 14h10l1-14"/><path d="M10 11v6M14 11v6"/>',
        'brief' => '<rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/>',
    ];
    $out = '';
    foreach ($items as $it) {
        $svg   = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($icons[$it['icon'] ?? 'home'] ?? '') . '</svg>';
        // Panel counters are always rendered (hidden at 0) and marked live, so
        // a background refresh can bring a badge in or out without a reload.
        if (isset($it['panel']) && array_key_exists('count', $it)) {
            $count = '<span class="tab-count" data-live="count-' . e($it['panel']) . '"' . (empty($it['count']) ? ' hidden' : '') . '>' . (int) $it['count'] . '</span>';
        } else {
            $count = !empty($it['count']) ? '<span class="tab-count">' . (int) $it['count'] . '</span>' : '';
        }
        $inner = $svg . '<span class="side-label">' . e($it['label']) . '</span>' . $count;
        if (isset($it['panel'])) {
            $on = $active === $it['panel'];
            $out .= '<button type="button" class="side-link admin-tab' . ($on ? ' active' : '') . '"'
                . ($on ? ' aria-current="true"' : '') . ' data-panel="' . e($it['panel']) . '">' . $inner . '</button>';
        } elseif (!empty($it['post'])) {
            // POST form styled as a link — for actions that change server
            // state from the sidebar (e.g. switching to a linked account).
            $params = '';
            foreach ($it['params'] ?? [] as $k => $v) {
                $params .= '<input type="hidden" name="' . e($k) . '" value="' . e((string) $v) . '">';
            }
            $out .= '<form class="side-form" method="post" action="' . e($it['href']) . '">'
                . '<input type="hidden" name="csrf" value="' . e($it['csrf'] ?? '') . '">'
                . $params
                . '<button type="submit" class="side-link">' . $inner . '</button>'
                . '</form>';
        } else {
            $on = $active === $it['href'];
            $out .= '<a class="side-link' . ($on ? ' active' : '') . '"' . ($on ? ' aria-current="page"' : '')
                . ' href="' . e($it['href']) . '">' . $inner . '</a>';
        }
    }
    return '<aside class="sidebar" aria-label="' . e($title) . '"><div class="side-title">' . e($title) . '</div><nav class="side-nav">' . $out . '</nav></aside>';
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
    $role = $profile['role'] ?? 'client';
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
        . '</details>';
}

// =====================================================================
// Scheduled package changes (1-7 day notice, then permanent)
// =====================================================================

const PACKAGE_FIELDS = ['name', 'description', 'price', 'monthly_price', 'commission_outright', 'commission_unilevel', 'tier', 'kind', 'active'];

function describe_package_changes(array $old, array $new): string
{
    $label = ['name' => 'Name', 'description' => 'Description', 'price' => 'One-time price', 'monthly_price' => 'Monthly fee',
              'commission_outright' => 'Outright commission', 'commission_unilevel' => 'Unilevel %', 'tier' => 'Tier', 'kind' => 'Kind', 'active' => 'Availability'];
    $fmt = function (string $k, $v): string {
        if ($k === 'active') return $v ? 'available' : 'hidden';
        if (in_array($k, ['price', 'monthly_price', 'commission_outright'], true)) return '$' . number_format((float) $v, 2);
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
// KYC gate: a Growth Partner needs an approved ID before sending referrals
// (code shown, code accepted at sign-up); a Client needs one before claiming
// a package (paying / an admin approving the deposit). Admins' own
// Unlimited partner accounts and team accounts are exempt.
function kyc_approved(?array $p): bool
{
    if (!$p) return false;
    return ($p['kyc_status'] ?? '') === 'approved' || tier_unlimited($p) || is_team($p);
}

// Notice explaining what is locked until the ID is approved.
//   $what: 'referrals' (Growth Partner) | 'packages' (Client)
function kyc_gate_notice(array $profile, string $what): string
{
    $status = $profile['kyc_status'] ?? 'not_submitted';
    $locked = $what === 'referrals'
        ? 'You can send referrals once your ID is approved — your referral code and links unlock then, and sign-ups with your code are accepted.'
        : 'You can claim a package once your ID is approved — paying the 50% deposit unlocks then.';
    [$cls, $lead, $cta] = match ($status) {
        'pending'  => ['alert-warn',  'Your ID is in review.', '<a href="/kyc.php">View submission →</a>'],
        'rejected' => ['alert-error', 'Your ID check was not approved.', '<a href="/kyc.php">Submit again →</a>'],
        default    => ['alert-warn',  'Verify your ID first.', '<a href="/kyc.php">Verify your ID →</a>'],
    };
    return '<div class="alert ' . $cls . ' kyc-gate"><strong>' . e($lead) . '</strong> ' . e($locked) . ' ' . $cta . '</div>';
}

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

// The "Verify ID" section on the Growth Partner dashboard (#verify): status,
// what was submitted, and the button to /kyc.php where the form lives.
function kyc_section(string $token, array $profile): string
{
    $status = $profile['kyc_status'] ?? 'not_submitted';
    $kyc    = $status !== 'not_submitted' ? kyc_submission($token, $profile['id']) : null;
    [$label, $pill] = [
        'not_submitted' => ['Not verified', 'pending'],
        'pending'       => ['In review',    'pending'],
        'approved'      => ['Verified',     'active'],
        'rejected'      => ['Rejected',     'failed'],
    ][$status] ?? ['Not verified', 'pending'];

    $msg = match ($status) {
        'approved' => 'Your identity is verified. Nothing further is needed.',
        'pending'  => 'Your documents were submitted' . (!empty($kyc['created_at']) ? ' on ' . date('M j, Y', strtotime($kyc['created_at'])) : '') . ' and an admin is reviewing them.',
        'rejected' => ($kyc['note'] ?? '') !== '' ? 'Not approved: ' . $kyc['note'] . ' Correct the details and submit again.' : 'Your documents could not be verified. Correct the details and submit again.',
        default    => 'Verify a government ID to confirm your Growth Partner account (Manual §9.1). It is required before you can buy the Standard plan.',
    };
    $btn = match ($status) {
        'approved' => '<a class="btn btn-sm" href="/kyc.php">View details</a>',
        'pending'  => '<a class="btn btn-sm" href="/kyc.php">View submission</a>',
        'rejected' => '<a class="btn btn-sm btn-solid" href="/kyc.php">Submit again →</a>',
        default    => '<a class="btn btn-sm btn-solid" href="/kyc.php">Verify my ID →</a>',
    };
    $rows = '';
    if ($kyc) {
        $rows = '<div class="profile-info" style="margin-top:12px">'
            . '<div class="profile-row"><span class="label">Legal name</span><span>' . e($kyc['legal_name'] ?? '') . '</span></div>'
            . '<div class="profile-row"><span class="label">ID type</span><span>' . e(KYC_ID_TYPES[$kyc['id_type'] ?? ''] ?? ($kyc['id_type'] ?? '')) . '</span></div>'
            . '</div>';
    }

    return '<section class="card" id="verify" style="margin-top:20px">'
        . '<div style="display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap">'
        .   '<h2 style="margin:0">Verify ID</h2><span class="pill ' . e($pill) . '">' . e($label) . '</span></div>'
        . '<p style="margin:10px 0 0;font-size:13px;color:var(--text-dim)">' . e($msg) . '</p>'
        . $rows
        . '<div style="margin-top:14px">' . $btn . '</div>'
        . '</section>';
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

    $by = actor_full_name($token);
    audit_log($token, 'kyc_' . $status, 'kyc', $userId, 'KYC ' . $status . ($by ? ' by ' . $by : '') . ($note !== '' ? ' — ' . $note : ''));
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
    // A background live refresh (?auto=1) must not use up the message the
    // visible page is about to show.
    if (isset($_GET['auto'])) return null;
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

/**
 * Overview "networth" panel for a Growth Partner: Total Networth (Outright +
 * Uni-Level), Total Outright and Total Uni-Level commission for the chosen
 * period, with a run-rate forecast to the end of that period.
 *   $period 'month' → this calendar month, 'year' → this calendar year.
 * Commission rows are told apart by their notes (Uni-Level rows say so).
 */
function networth_html(string $token, array $profile, string $period = 'month', string $base = '/dashboard-affiliate.php?view=overview'): string
{
    $period = $period === 'year' ? 'year' : 'month';
    $uid  = urlencode($profile['id']);
    $coms = supabase_request('GET', '/rest/v1/transactions?select=amount,created_at,notes&type=eq.commission&status=eq.completed&user_id=eq.' . $uid . '&order=created_at.asc', null, $token)['body'] ?? [];
    $coms = is_array($coms) ? $coms : [];

    $now   = time();
    $start = $period === 'year' ? strtotime(date('Y-01-01 00:00:00', $now)) : strtotime(date('Y-m-01 00:00:00', $now));
    $end   = $period === 'year' ? strtotime(date('Y-01-01 00:00:00', $now) . ' +1 year') : strtotime(date('Y-m-01 00:00:00', $now) . ' +1 month');

    $out = 0.0; $uni = 0.0; $allOut = 0.0; $allUni = 0.0;
    $byMonth = array_fill(1, 12, ['out' => 0.0, 'uni' => 0.0]);
    $thisYear = date('Y', $now);
    foreach ($coms as $c) {
        $amt  = (float) ($c['amount'] ?? 0);
        $ts   = strtotime((string) ($c['created_at'] ?? ''));
        $isUni = str_contains((string) ($c['notes'] ?? ''), 'Uni-Level');
        $isUni ? $allUni += $amt : $allOut += $amt;
        if ($ts && date('Y', $ts) === $thisYear) {
            $byMonth[(int) date('n', $ts)][$isUni ? 'uni' : 'out'] += $amt;
        }
        if ($ts >= $start && $ts < $end) $isUni ? $uni += $amt : $out += $amt;
    }
    $net = $out + $uni;

    // Run-rate forecast: what this period ends at if the pace so far holds.
    $elapsed  = max(1, $now - $start);
    $factor   = ($end - $start) / $elapsed;
    $fcNet    = $net * $factor;
    $fcOut    = $out * $factor;
    $fcUni    = $uni * $factor;
    $label    = $period === 'year' ? date('Y', $now) : date('F Y', $now);
    $endLabel = $period === 'year' ? 'year end' : 'month end';
    $money = fn(float $v) => '$' . number_format($v, 2);

    $btn = fn(string $p, string $text) => '<a class="seg-btn' . ($period === $p ? ' is-on' : '') . '" href="' . e($base . '&period=' . $p) . '"' . ($period === $p ? ' aria-current="true"' : '') . '>' . $text . '</a>';

    $box = fn(string $title, float $val, float $fc, float $all, string $cls = '') =>
        '<div class="nw-box' . $cls . '"><span class="nw-title">' . e($title) . '</span>'
        . '<div class="nw-val">' . $money($val) . '</div>'
        . '<span class="nw-sub">' . e($label) . '</span>'
        . '<span class="nw-fc">Forecast to ' . e($endLabel) . ': <strong>' . $money($fc) . '</strong></span>'
        . '<span class="nw-all">All time: ' . $money($all) . '</span></div>';

    $html = '<section class="card nw-panel" aria-labelledby="nw-h">'
        . '<div class="nw-head"><div><span class="label">Forecast</span><h2 id="nw-h" class="nw-h">Your networth · ' . e($label) . '</h2></div>'
        . '<nav class="seg" aria-label="Forecast period">' . $btn('month', 'Month') . $btn('year', 'Year') . '</nav></div>'
        . '<div class="nw-grid">'
        . $box('Total Networth', $net, $fcNet, $allOut + $allUni, ' nw-main')
        . $box('Total Outright Commission', $out, $fcOut, $allOut)
        . $box('Total Uni-Level Commission', $uni, $fcUni, $allUni)
        . '</div>';

    if ($period === 'year') {
        // Month by month: Outright + Uni-Level = that month's networth.
        $max = max(0.01, ...array_map(fn($m) => $m['out'] + $m['uni'], $byMonth));
        $cur = (int) date('n', $now);
        $html .= '<div class="table-scroll" tabindex="0" role="region" aria-label="Networth by month"><table class="nw-months"><thead><tr><th scope="col">Month</th><th scope="col" class="num">Outright</th><th scope="col" class="num">Uni-Level</th><th scope="col" class="num">Networth</th><th scope="col"><span class="sr-only">Share</span></th></tr></thead><tbody>';
        foreach ($byMonth as $m => $v) {
            $t = $v['out'] + $v['uni'];
            $html .= '<tr' . ($m === $cur ? ' class="is-cur"' : ($m > $cur ? ' class="is-future"' : '')) . '><th scope="row">' . date('M', mktime(0, 0, 0, $m, 1)) . '</th>'
                . '<td class="num">' . $money($v['out']) . '</td><td class="num">' . $money($v['uni']) . '</td><td class="num"><strong>' . $money($t) . '</strong></td>'
                . '<td class="nw-bar-cell"><span class="nw-bar" style="width:' . round($t / $max * 100, 1) . '%"></span></td></tr>';
        }
        $html .= '</tbody></table></div>';
    }

    $html .= '<p class="hint nw-note">Networth = Outright commission (10% of your clients\' deposits) + Uni-Level commission (5% of your direct Co-growth Partners\' Outright). The forecast assumes the pace so far continues; it is an estimate, not guaranteed income.</p></section>';
    return $html;
}

require_once __DIR__ . '/pipeline.php';
require_once __DIR__ . '/clientpay.php';
