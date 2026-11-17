<?php
// KAMTEAM // Network Marketing Portal — Supabase client (Auth + PostgREST + Guards)

require_once __DIR__ . '/../config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_start();
}

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

// ---------------------------------------------------------------------
// Auth
// ---------------------------------------------------------------------

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
    session_regenerate_id(true);
    $_SESSION['sb'] = [
        'access_token'  => $auth['access_token'],
        'refresh_token' => $auth['refresh_token'],
        'expires_at'    => time() + (int) ($auth['expires_in'] ?? 3600),
        'user_id'       => $auth['user']['id'] ?? null,
        'email'         => $auth['user']['email'] ?? null,
    ];
}

function supabase_access_token(): ?string
{
    if (empty($_SESSION['sb']['access_token'])) {
        return null;
    }
    if ($_SESSION['sb']['expires_at'] - 60 > time()) {
        return $_SESSION['sb']['access_token'];
    }

    $res = supabase_request('POST', '/auth/v1/token?grant_type=refresh_token', [
        'refresh_token' => $_SESSION['sb']['refresh_token'],
    ]);
    if ($res['error'] || empty($res['body']['access_token'])) {
        supabase_sign_out_local();
        return null;
    }
    supabase_store_session($res['body']);
    return $_SESSION['sb']['access_token'];
}

function supabase_sign_out(): void
{
    if (!empty($_SESSION['sb']['access_token'])) {
        supabase_request('POST', '/auth/v1/logout', [], $_SESSION['sb']['access_token']);
    }
    supabase_sign_out_local();
}

function supabase_sign_out_local(): void
{
    unset($_SESSION['sb']);
}

// ---------------------------------------------------------------------
// Guards — live status + role + lifecycle checks on every request
// ---------------------------------------------------------------------

function require_login(): string
{
    $token = supabase_access_token();
    if (!$token) {
        header('Location: index.php');
        exit;
    }
    return $token;
}

function current_profile(?string $token = null): ?array
{
    static $cached = null;
    if ($cached !== null) return $cached ?: null;

    $token  = $token ?: supabase_access_token();
    $userId = $_SESSION['sb']['user_id'] ?? null;
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
        'admin'           => 'dashboard-admin.php',
        'client_basic'    => 'dashboard-client-basic.php',
        'client_standard' => 'dashboard-client-standard.php',
        'referral'        => 'dashboard-referral.php',
    ];

    header('Location: ' . ($map[$role] ?? 'dashboard-referral.php') . $qs);
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
        header('Location: index.php');
        exit;
    }

    return ['token' => $token, 'profile' => $profile];
}

// ---------------------------------------------------------------------
// Audit logging
// ---------------------------------------------------------------------

function audit_log(string $token, string $action, ?string $entityType = null, ?string $entityId = null, ?string $summary = null): void
{
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    supabase_request('POST', '/rest/v1/system_logs', [
        'actor_id'    => $_SESSION['sb']['user_id'] ?? null,
        'action'      => $action,
        'entity_type' => $entityType,
        'entity_id'   => $entityId,
        'summary'     => $summary,
        'ip_address'  => $ip,
    ], $token, ['Prefer: return=minimal']);
}

// ---------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_check(): bool
{
    return isset($_POST['csrf']) && hash_equals($_SESSION['csrf'] ?? '', (string) $_POST['csrf']);
}

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function flash(?string $type = null, ?string $message = null): ?array
{
    if ($type !== null) {
        $_SESSION['flash'] = ['type' => $type, 'message' => $message];
        return null;
    }
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
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
