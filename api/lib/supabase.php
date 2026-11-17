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
        && strpos(SUPABASE_ANON_KEY, 'YOUR-ANON') === false;
}

function supabase_request(string $method, string $path, ?array $body = null, ?string $accessToken = null, array $extraHeaders = []): array
{
    $headers = array_merge([
        'apikey: ' . SUPABASE_ANON_KEY,
        'Authorization: Bearer ' . ($accessToken ?: SUPABASE_ANON_KEY),
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

function supabase_sign_up(string $email, string $password, array $meta, string $redirectTo): array
{
    return supabase_request(
        'POST',
        '/auth/v1/signup?redirect_to=' . urlencode($redirectTo),
        ['email' => $email, 'password' => $password, 'data' => $meta]
    );
}

function supabase_sign_in(string $email, string $password): array
{
    return supabase_request('POST', '/auth/v1/token?grant_type=password', [
        'email'    => $email,
        'password' => $password,
    ]);
}

function supabase_store_session(array $auth): void
{
    $session = &_session();
    $session['sb'] = [
        'access_token'  => $auth['access_token'],
        'refresh_token' => $auth['refresh_token'],
        'expires_at'    => time() + (int) ($auth['expires_in'] ?? 3600),
        'user_id'       => $auth['user']['id'] ?? null,
        'email'         => $auth['user']['email'] ?? null,
    ];
    _save_session();
}

function supabase_access_token(): ?string
{
    $session = &_session();
    if (empty($session['sb']['access_token'])) {
        return null;
    }
    if (($session['sb']['expires_at'] ?? 0) - 60 > time()) {
        return $session['sb']['access_token'];
    }

    $res = supabase_request('POST', '/auth/v1/token?grant_type=refresh_token', [
        'refresh_token' => $session['sb']['refresh_token'] ?? '',
    ]);
    if ($res['error'] || empty($res['body']['access_token'])) {
        supabase_sign_out_local();
        return null;
    }
    supabase_store_session($res['body']);
    return $session['sb']['access_token'];
}

function supabase_sign_out(): void
{
    $session = &_session();
    if (!empty($session['sb']['access_token'])) {
        supabase_request('POST', '/auth/v1/logout', [], $session['sb']['access_token']);
    }
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

function redirect_by_role(?string $welcome = null): void
{
    $profile = current_profile();
    $role    = $profile['role'] ?? 'referral';
    $qs      = $welcome ? '?welcome=1' : '';

    $map = [
        'admin'           => '/dashboard-admin.php',
        'client_basic'    => '/dashboard-client-basic.php',
        'client_standard' => '/dashboard-client-standard.php',
        'referral'        => '/dashboard-referral.php',
    ];

    header('Location: ' . ($map[$role] ?? '/dashboard-referral.php') . $qs);
    exit;
}

function require_role(string|array $allowed): array
{
    $token   = require_login();
    $profile = current_profile($token);
    $role    = $profile['role'] ?? '';
    $allowed = (array) $allowed;

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
