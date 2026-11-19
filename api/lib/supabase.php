<?php
// KAMTEAM // Supabase client — serverless-compatible (cookie sessions for Vercel)

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/terms.php';

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
        $r = supabase_request('GET', '/rest/v1/profiles?select=id&deleted_at=is.null&referral_code=eq.' . urlencode(strtoupper(trim($meta['ref']))));
        $referrer = $r['body'][0]['id'] ?? null;
        if (!$referrer) {
            return ['error' => 'That referral code does not exist. Check it, or leave it blank.', 'profile' => null];
        }
    }

    // Client = permanent account that buys service packages.
    // Affiliate = refers clients; picks Basic/Standard after approval.
    $role = in_array($meta['role'] ?? '', ['client', 'affiliate'], true)
        ? $meta['role'] : 'affiliate';

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

// Client and admin accounts are permanent. Only an affiliate's term
// expires; it can still sign in during the grace period to renew or
// upgrade, and is terminated once the grace period has passed (§4.3, §6.1).
function check_account_status(?array $profile): bool
{
    if (!$profile) return false;
    if ($profile['status'] !== 'accepted') return false;
    if (!empty($profile['deleted_at'])) return false;
    if (($profile['role'] ?? '') === 'affiliate' && affiliate_state($profile) === 'terminated') return false;
    return true;
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
        'admin'     => '/dashboard-admin.php',
        'staff'     => '/work.php',
        'client'    => '/dashboard-client.php',
        'affiliate' => '/dashboard-affiliate.php',
    ][$role] ?? '/dashboard-affiliate.php';
}

function redirect_by_role(?string $welcome = null): void
{
    $profile = current_profile();

    if (is_walled($profile)) {
        header('Location: /pending.php');
        exit;
    }

    header('Location: ' . dashboard_url($profile['role'] ?? 'affiliate') . ($welcome ? '?welcome=1' : ''));
    exit;
}

// True when the user still has to accept the current manual version.
function terms_outdated(?array $profile): bool
{
    return $profile && !is_team($profile)
        && ($profile['terms_version'] ?? '') !== TERMS_VERSION;
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
        flash('error', $role === 'affiliate' && affiliate_state($profile) === 'terminated'
            ? 'Your Growth Partner account expired and its grace period has ended. Contact support.'
            : 'Your account is inactive. Contact support.');
        supabase_sign_out_local();
        header('Location: /index.php');
        exit;
    }

    // Every member must accept the current program manual before continuing.
    if (terms_outdated($profile)) {
        header('Location: /terms.php');
        exit;
    }

    if ($role === 'affiliate') remind_basic_expiry($profile);

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

// Audit-log categories for the admin log viewer. An action belongs to the
// first category whose exact names or prefixes match it; anything else
// falls under "other". New actions only need a matching prefix or name.
const LOG_CATEGORIES = [
    'auth'     => ['label' => 'Login & terms',             'actions' => ['login_success', 'login_failed', 'logout', 'terms_accepted'], 'prefixes' => []],
    'tasks'    => ['label' => 'Client task log',           'actions' => [], 'prefixes' => ['task_']],
    'reports'  => ['label' => 'Weekly / monthly reports',  'actions' => [], 'prefixes' => ['report_']],
    'accounts' => ['label' => 'Accounts & approvals',      'actions' => ['staff_created', 'approve_application', 'reject_application', 'role_change', 'status_change', 'soft_delete', 'restore', 'edit_user', 'edit_business'], 'prefixes' => []],
    'payments' => ['label' => 'Payments & commissions',    'actions' => ['plan_requested', 'payment_submitted', 'payment_cancelled', 'deposit_submitted', 'approve_activation', 'reject_activation', 'approve_deposit', 'reject_deposit', 'referral_commission', 'update_transaction', 'commission_rate', 'payment_settings'], 'prefixes' => []],
    'kyc'      => ['label' => 'KYC',                       'actions' => [], 'prefixes' => ['kyc_']],
    'packages' => ['label' => 'Packages',                  'actions' => ['create_package'], 'prefixes' => ['schedule_package', 'cancel_package', 'apply_package']],
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

// none (no plan yet) | active | grace | terminated
function affiliate_state(array $p): string
{
    if (empty($p['affiliate_tier']) || empty($p['lifecycle_expires_at'])) return 'none';
    $exp = strtotime($p['lifecycle_expires_at']);
    if ($exp >= time()) return 'active';
    $grace = AFFILIATE_GRACE_DAYS[$p['affiliate_tier']] ?? 7;
    return time() <= $exp + $grace * 86400 ? 'grace' : 'terminated';
}

function affiliate_grace_ends(array $p): ?int
{
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

    $link = '/dashboard-affiliate.php?notice=' . $kind . '-' . date('Ymd', $exp) . '#plans';
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
    return ['basic' => 'Basic', 'standard' => 'Standard'][$tier ?? ''] ?? 'No plan';
}

// Puts an affiliate on Basic or Standard and sets their term.
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
        'Approved ' . $pkgName . ' ($' . number_format((float) $txn['amount'], 2) . ') ref ' . ($txn['payment_ref'] ?? '—')
        . ($owner ? ' → roulette: ' . $owner['full_name'] : ''));

    if ($isDeposit) {
        notify($token, 'Deposit confirmed — ' . $pkgName,
            'Your 50% advance deposit is confirmed and setup work can begin. Progress reports will appear in your dashboard.'
            . ($owner ? ' Your KAM Team contact is ' . $owner['full_name'] . '.' : ''), $txn['user_id']);
        pay_referral_commission($token, $txn);
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
    if ($referrer['role'] === 'affiliate' && affiliate_state($referrer) === 'terminated') return;

    // Qualified Client: +3 months on the affiliate's current term.
    if ($referrer['role'] === 'affiliate' && !empty($referrer['lifecycle_expires_at'])) {
        $base = max(strtotime($referrer['lifecycle_expires_at']), time());
        supabase_request('PATCH', '/rest/v1/profiles?id=eq.' . urlencode($refId),
            ['lifecycle_expires_at' => date('c', strtotime('+3 months', $base))], $token, ['Prefer: return=minimal']);
    }

    $commission = round($amount * $pct / 100, 2);
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
        . ($referrer['role'] === 'affiliate' ? ' and 3 more months on your Growth Partner term.' : '.'), $refId);
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
    return ['admin' => 'Admin', 'staff' => 'Staff', 'client' => 'Client', 'affiliate' => 'Growth Partner'][$role] ?? $role;
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

    $res = supabase_request('POST', '/rest/v1/profiles', [
        'full_name'     => mb_substr($name, 0, 120),
        'username'      => $username,
        'email'         => $email ?: null,
        'password_hash' => password_hash($pw, PASSWORD_BCRYPT),
        'phone'         => mb_substr(trim((string) ($in['phone'] ?? '')), 0, 40) ?: null,
        'role'          => $role,
        'status'        => 'accepted',
    ], $token, ['Prefer: return=representation']);
    if ($res['error'] || empty($res['body'][0])) return $res['error'] ?: 'Could not create the account.';

    $new = $res['body'][0];
    audit_log($token, 'staff_created', 'profile', $new['id'],
        role_name($role) . ' account for ' . $new['full_name'] . ' (@' . $username . ')');
    notify($token, 'Welcome to KAM TEAM', 'Your ' . role_name($role) . ' account is ready.', $new['id'], 'all', dashboard_url($role));
    return null;
}

function approve_application(string $token, string $userId): ?string
{
    $res = supabase_request('GET', '/rest/v1/profiles?select=id,role,status&id=eq.' . urlencode($userId), null, $token);
    $p   = $res['body'][0] ?? null;
    if (!$p) return 'User not found.';
    if (!in_array($p['status'], ['pending', 'rejected'], true)) return 'This application was already reviewed.';

    // Clients are permanent. An affiliate's term starts when their
    // Basic/Standard plan request is approved, not here.
    $res = supabase_request('PATCH', '/rest/v1/profiles?id=eq.' . urlencode($userId), [
        'status' => 'accepted', 'review_note' => null,
    ], $token, ['Prefer: return=minimal']);
    if ($res['error']) return $res['error'];

    // Roulette: every new account takes one turn and keeps that admin for good.
    $owner = roulette_claim_account($token, $userId);

    audit_log($token, 'approve_application', 'profile', $userId,
        'Approved ' . role_name($p['role']) . ' application' . ($owner ? ' → roulette: ' . $owner['full_name'] : ''));
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
function affiliate_account_html(string $token, array $profile, bool $canRefer = true): string
{
    $uid  = urlencode($profile['id']);
    $pct  = commission_pct($token);
    $link = APP_URL . '/index.php?ref=' . urlencode($profile['referral_code'] ?? '');

    $refs = supabase_request('GET', '/rest/v1/referrals?select=created_at,status,referred:profiles!referrals_referred_id_fkey(full_name,role)&referrer_id=eq.' . $uid . '&order=created_at.desc', null, $token)['body'] ?? [];
    $coms = supabase_request('GET', '/rest/v1/transactions?select=amount,created_at,notes,packages(name),source:profiles!transactions_source_user_id_fkey(full_name)&type=eq.commission&status=eq.completed&user_id=eq.' . $uid . '&order=created_at.desc&limit=50', null, $token)['body'] ?? [];
    $refs = is_array($refs) ? $refs : [];
    $coms = is_array($coms) ? $coms : [];
    $earned  = array_sum(array_map(fn($c) => (float) $c['amount'], $coms));
    $clients = count(array_filter($refs, fn($r) => ($r['referred']['role'] ?? '') === 'client'));

    $out  = '<section class="card" id="share" style="margin-bottom:12px">'
          . '<span class="label">Your referral code — ' . e($profile['referral_code'] ?? '') . '</span>';
    if ($canRefer) {
        $out .= '<div class="share" style="margin-top:8px"><input id="share-link" type="text" readonly value="' . e($link) . '">'
              . '<button class="btn btn-sm" type="button" data-copy="share-link">Copy →</button></div>';
    } else {
        $out .= '<p class="alert alert-warn" style="margin:8px 0 0">Your Growth Partner term has expired. New referrals are not credited until you renew or upgrade.</p>';
    }
    $out .= '<p style="margin:10px 0 0;font-size:12px;color:var(--text-dim)">Clients who sign up with your code are credited to you. When a client you referred pays the 50% advance deposit on a service package, you earn <strong>' . e(pct_label($pct)) . '% of that deposit</strong>. Monthly fees and the remaining setup balance are not commissionable, and no income is guaranteed. '
          . 'Always disclose that you may earn a commission (e.g. "I earn a commission if you hire KAM TEAM through my link").</p></section>';

    $out .= '<section class="grid">'
          . '<div class="card"><span class="label">Referred</span><div class="big">' . count($refs) . '</div></div>'
          . '<div class="card"><span class="label">Clients</span><div class="big cyan">' . $clients . '</div></div>'
          . '<div class="card"><span class="label">Commissions</span><div class="big">' . count($coms) . '</div></div>'
          . '<div class="card"><span class="label">Earned</span><div class="big green">$' . number_format($earned, 2) . '</div></div></section>';

    $out .= '<section class="card" id="earnings" style="margin-bottom:12px"><h2>Commission ledger</h2>';
    if (!$coms) {
        $out .= '<p class="empty">No commissions yet.</p>';
    } else {
        $out .= '<div class="table-scroll"><table><thead><tr><th>Client</th><th>Package</th><th>Amount</th><th>Date</th></tr></thead><tbody>';
        foreach ($coms as $c) {
            $out .= '<tr><td style="font-size:12px">' . e($c['source']['full_name'] ?? '—') . '</td><td style="font-size:12px">' . e($c['packages']['name'] ?? '—') . '</td>'
                  . '<td style="color:var(--green)">$' . number_format((float) $c['amount'], 2) . '</td><td style="font-size:12px">' . date('M j, Y', strtotime($c['created_at'])) . '</td></tr>';
        }
        $out .= '</tbody></table></div>';
    }
    $out .= '</section>';

    $out .= '<section class="card" id="network" style="margin-bottom:12px"><h2>People you referred</h2>';
    if (!$refs) {
        $out .= '<p class="empty">Nobody yet. Share your link.</p>';
    } else {
        $out .= '<div class="table-scroll"><table><thead><tr><th>Name</th><th>Account</th><th>Status</th><th>Joined</th></tr></thead><tbody>';
        foreach ($refs as $r) {
            $role = $r['referred']['role'] ?? '';
            $out .= '<tr><td><strong>' . e($r['referred']['full_name'] ?? '—') . '</strong></td><td><span class="role-badge ' . e($role) . '">' . e(role_name($role)) . '</span></td>'
                  . '<td><span class="pill ' . e($r['status']) . '">' . e($r['status']) . '</span></td><td style="font-size:12px">' . date('M j, Y', strtotime($r['created_at'])) . '</td></tr>';
        }
        $out .= '</tbody></table></div>';
    }
    $out .= '</section>';

    return $out . '<script>document.querySelectorAll("[data-copy]").forEach(function(btn){btn.addEventListener("click",function(){'
        . 'var i=document.getElementById(btn.dataset.copy);i.select();(navigator.clipboard?navigator.clipboard.writeText(i.value):Promise.reject())'
        . '.catch(function(){document.execCommand("copy");}).finally(function(){btn.textContent="Copied ✓";setTimeout(function(){btn.textContent="Copy →";},1800);});});});</script>';
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
            ($user['full_name'] ?? 'A new account') . ' (' . role_name($user['role'] ?? 'affiliate') . ') was added to your list'
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
        'brief' => '<rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/>',
    ];
    $out = '';
    foreach ($items as $it) {
        $svg   = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($icons[$it['icon'] ?? 'home'] ?? '') . '</svg>';
        $count = !empty($it['count']) ? '<span class="tab-count">' . (int) $it['count'] . '</span>' : '';
        $inner = $svg . '<span class="side-label">' . e($it['label']) . '</span>' . $count;
        if (isset($it['panel'])) {
            $out .= '<button type="button" class="side-link admin-tab' . ($active === $it['panel'] ? ' active' : '') . '" data-panel="' . e($it['panel']) . '">' . $inner . '</button>';
        } else {
            $out .= '<a class="side-link' . ($active === $it['href'] ? ' active' : '') . '" href="' . e($it['href']) . '">' . $inner . '</a>';
        }
    }
    return '<aside class="sidebar" aria-label="' . e($title) . '"><div class="side-title">' . e($title) . '</div><nav class="side-nav">' . $out . '</nav></aside>';
}

// Highlights the sidebar link for the section currently on screen.
function sidebar_scrollspy_script(): string
{
    return <<<'JS'
<script>
(function () {
  var links = [].slice.call(document.querySelectorAll('.side-nav a[href^="#"]'));
  if (!links.length || !('IntersectionObserver' in window)) return;
  var byId = {};
  links.forEach(function (a) { byId[a.getAttribute('href').slice(1)] = a; });
  var io = new IntersectionObserver(function (entries) {
    entries.forEach(function (en) {
      if (!en.isIntersecting) return;
      links.forEach(function (a) { a.classList.remove('active'); });
      if (byId[en.target.id]) byId[en.target.id].classList.add('active');
    });
  }, { rootMargin: '-30% 0px -60% 0px' });
  Object.keys(byId).forEach(function (id) { var el = document.getElementById(id); if (el) io.observe(el); });
})();
</script>
JS;
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
    $role = $profile['role'] ?? 'affiliate';
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
