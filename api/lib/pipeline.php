<?php
// Referral pipeline for referred clients, and the Main Basket.
//
//   Pending → Converted (package) → Approved
//   Pending → Rejected (reason)
//   Pending → Converted (package) → Rejected (reason)
//
// The stage lives on the client's referral row (referrals.stage …, see
// migration 20261203). It moves on its own with the client's deposit
// (submitted → Converted, approved → Approved, rejected → Rejected) and the
// admin can move it by hand from the Referral pipeline tab.
//
// Approved = the 50% deposit is confirmed: pay_referral_commission credits
// the Outright Commission (shows in the partner's Payout balance) and the
// Uni-Level goes to the referrer's upline, or to the Main Basket when that
// upline is an admin's own Growth Partner account (or there is none).

const REFERRAL_STAGES = [
    'pending'   => ['Pending',   'pending'],
    'converted' => ['Converted', 'active'],
    'approved'  => ['Approved',  'completed'],
    'rejected'  => ['Rejected',  'failed'],
];

const REFERRAL_REJECT_REASONS = [
    'Duplicate — already a KAM Team client or in the CRM',
    'Outside the 90-day attribution window',
    'Not a qualified lead',
    'Self-referral or related account',
    'Deposit not received or could not be verified',
    'Client cancelled before work started',
    'Wrong package or amount — needs a new deposit',
];

/** The referral row of a client (direct referrer), or null. */
function referral_row(string $token, string $clientId): ?array
{
    $r = supabase_request('GET', '/rest/v1/referrals?select=*&level=eq.1&referred_id=eq.' . urlencode($clientId) . '&limit=1', null, $token);
    return is_array($r['body']) && !$r['error'] ? ($r['body'][0] ?? null) : null;
}

/**
 * Move a referred client to a stage. Creates the referral row if the client
 * has a referrer but no row yet. Silently does nothing for clients nobody
 * referred. Returns an error or null.
 */
function referral_stage_set(string $token, string $clientId, string $stage, array $extra = []): ?string
{
    if (!isset(REFERRAL_STAGES[$stage])) return 'Unknown stage.';
    $row = referral_row($token, $clientId);
    if (!$row) {
        $who = supabase_request('GET', '/rest/v1/profiles?select=referred_by&id=eq.' . urlencode($clientId), null, $token)['body'][0] ?? null;
        if (empty($who['referred_by'])) return null;
        supabase_request('POST', '/rest/v1/referrals?on_conflict=referrer_id,referred_id',
            ['referrer_id' => $who['referred_by'], 'referred_id' => $clientId, 'status' => 'pending'],
            $token, ['Prefer: resolution=ignore-duplicates,return=minimal']);
        $row = referral_row($token, $clientId);
        if (!$row) return null;
    }
    $patch = array_merge([
        'stage'  => $stage,
        'stage_at' => gmdate('c'),
        'stage_reason' => $stage === 'rejected' ? ($extra['stage_reason'] ?? null) : null,
    ], $extra);
    if ($stage === 'approved') $patch['status'] = 'active';
    $res = supabase_request('PATCH', '/rest/v1/referrals?id=eq.' . urlencode($row['id']), $patch, $token, ['Prefer: return=minimal']);
    return $res['error'];
}

/** Pending › Converted (pkg) › Approved / Rejected, as small step chips. */
function referral_stage_html(array $ref, ?string $pkgName = null): string
{
    $stage = $ref['stage'] ?? 'pending';
    $steps = ['pending'];
    if ($stage === 'converted' || $stage === 'approved' || ($stage === 'rejected' && !empty($ref['stage_package_id']))) $steps[] = 'converted';
    if ($stage === 'approved' || $stage === 'rejected') $steps[] = $stage;

    $h = '<span class="stage-flow">';
    foreach ($steps as $i => $s) {
        [$label, $pill] = REFERRAL_STAGES[$s];
        $cur = $s === $stage;
        if ($s === 'converted' && $pkgName) $label .= ' · ' . $pkgName;
        $h .= ($i ? '<span class="stage-sep" aria-hidden="true">›</span>' : '')
            . '<span class="pill stage-' . e($s) . ' ' . ($cur ? e($pill) : 'stage-done') . '"' . ($cur ? ' aria-current="step"' : '') . '>' . e($label) . '</span>';
    }
    $h .= '</span>';
    if ($stage === 'rejected' && !empty($ref['stage_reason'])) {
        $h .= '<span class="ut-sub stage-reason">Reason: ' . e($ref['stage_reason']) . '</span>';
    }
    return $h;
}

// ---------------------------------------------------------------------
// Main Basket — Uni-Level the company keeps
// ---------------------------------------------------------------------

/** Main admin account the basket entries are booked to. */
function basket_owner_id(string $token): ?string
{
    $r = supabase_request('GET', '/rest/v1/profiles?select=id,username&role=eq.admin&deleted_at=is.null&order=created_at.asc', null, $token)['body'] ?? [];
    if (!is_array($r) || !$r) return null;
    foreach ($r as $p) if (($p['username'] ?? '') === 'admin') return $p['id'];
    return $r[0]['id'];
}

/** An admin's own Growth Partner account (Unlimited, linked to an admin). */
function is_house_partner(?array $p): bool
{
    return $p !== null && tier_unlimited($p);
}

function basket_add(string $token, array $txn, float $amount, string $why, ?string $referrerName = null): void
{
    if ($amount <= 0) return;
    $owner = basket_owner_id($token);
    if (!$owner) return;
    $res = supabase_request('POST', '/rest/v1/transactions', [
        'user_id'        => $owner,
        'package_id'     => $txn['package_id'] ?? null,
        'source_user_id' => $txn['user_id'] ?? null,
        'source_txn_id'  => $txn['id'] ?? null,
        'amount'         => round($amount, 2),
        'type'           => 'basket',
        'status'         => 'completed',
        'notes'          => 'Uni-Level to Main Basket — ' . $why . ($referrerName ? ' (Outright earned by ' . $referrerName . ')' : ''),
    ], $token, ['Prefer: return=minimal']);
    if (!$res['error']) {
        audit_log($token, 'basket_credit', 'transaction', $txn['id'] ?? null, 'Main Basket +$' . number_format($amount, 2) . ' — ' . $why);
    }
}

// ---------------------------------------------------------------------
// Admin: Referral pipeline tab
// ---------------------------------------------------------------------

/** Handles POST pipe_action. Returns [errorOrNull, message] or null. */
function pipeline_admin_action(string $token, array $profile): ?array
{
    $act = (string) ($_POST['pipe_action'] ?? '');
    if ($act === '') return null;
    $clientId = (string) ($_POST['client_id'] ?? '');
    $client = supabase_request('GET', '/rest/v1/profiles?select=id,full_name,referred_by&id=eq.' . urlencode($clientId), null, $token)['body'][0] ?? null;
    if (!$client || empty($client['referred_by'])) return ['That client has no referrer.', ''];
    $ref = referral_row($token, $clientId) ?? ['stage' => 'pending'];
    $by  = ['stage_by' => $profile['id']];

    // Pending deposit on file for the converted package, if any.
    $pendingDep = function (?string $pkgId) use ($token, $clientId): ?array {
        $q = 'select=id,package_id&type=eq.deposit&status=eq.pending&user_id=eq.' . urlencode($clientId) . ($pkgId ? '&package_id=eq.' . urlencode($pkgId) : '') . '&order=created_at.desc&limit=1';
        return supabase_request('GET', '/rest/v1/transactions?' . $q, null, $token)['body'][0] ?? null;
    };

    if ($act === 'convert') {
        $pkg = supabase_request('GET', '/rest/v1/packages?select=id,name&kind=eq.service&id=eq.' . urlencode((string) ($_POST['package_id'] ?? '')), null, $token)['body'][0] ?? null;
        if (!$pkg) return ['Choose the package the client is buying.', ''];
        $err = referral_stage_set($token, $clientId, 'converted', $by + ['stage_package_id' => $pkg['id']]);
        if ($err) return [$err, ''];
        audit_log($token, 'referral_converted', 'profile', $clientId, $client['full_name'] . ' converted — ' . $pkg['name']);
        notify($token, 'Your client converted', $client['full_name'] . ' chose ' . $pkg['name'] . '. Your commission is credited once the admin approves their deposit.', $client['referred_by'], 'all', '/dashboard-affiliate.php?view=clients');
        return [null, $client['full_name'] . ' marked Converted — ' . $pkg['name'] . '.'];
    }

    if ($act === 'approve') {
        if (($ref['stage'] ?? '') !== 'converted' || empty($ref['stage_package_id'])) return ['Mark the client Converted (with a package) before approving.', ''];
        $dep = $pendingDep($ref['stage_package_id']);
        if (!$dep) {
            // No deposit submitted online: record the 50% deposit now, then
            // approve it through the normal flow (commission, roulette, notices).
            $pkg = supabase_request('GET', '/rest/v1/packages?select=id,name,price&id=eq.' . urlencode($ref['stage_package_id']), null, $token)['body'][0] ?? null;
            if (!$pkg) return ['That package no longer exists.', ''];
            $ins = supabase_request('POST', '/rest/v1/transactions', [
                'user_id' => $clientId, 'package_id' => $pkg['id'], 'amount' => round((float) $pkg['price'] * 0.5, 2),
                'type' => 'deposit', 'status' => 'pending', 'payment_ref' => 'ADMIN-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8)),
                'notes' => '50% advance deposit — ' . $pkg['name'] . ' (recorded by admin)',
            ], $token, ['Prefer: return=representation']);
            if ($ins['error'] || empty($ins['body'][0]['id'])) return [$ins['status'] === 409 ? 'A deposit for this package is already waiting in Approvals.' : 'Could not record the deposit.', ''];
            $dep = $ins['body'][0];
        }
        $err = approve_activation($token, $dep['id'], $profile['id']);   // → stage Approved + commissions
        return $err ? [$err, ''] : [null, $client['full_name'] . ' approved — commission credited to the referrer.'];
    }

    if ($act === 'reject') {
        $reason = trim((string) ($_POST['reason_pick'] ?? ''));
        $note   = mb_substr(trim((string) ($_POST['reason_note'] ?? '')), 0, 200);
        if ($reason === '__other') $reason = '';
        if ($reason !== '' && !in_array($reason, REFERRAL_REJECT_REASONS, true)) $reason = '';
        $full = trim($reason . ($reason !== '' && $note !== '' ? ' — ' : '') . $note);
        if ($full === '') return ['Pick a reason or type one.', ''];
        if (($ref['stage'] ?? '') === 'approved') return ['This client is already approved — commission was paid.', ''];
        // A deposit waiting in Approvals is rejected with it.
        if (($dep = $pendingDep($ref['stage_package_id'] ?? null))) {
            $err = reject_activation($token, $dep['id'], $profile['id'], $full);   // → stage Rejected
            if ($err) return [$err, ''];
        } else {
            $err = referral_stage_set($token, $clientId, 'rejected', $by + ['stage_reason' => $full]);
            if ($err) return [$err, ''];
        }
        audit_log($token, 'referral_rejected', 'profile', $clientId, $client['full_name'] . ' rejected — ' . $full);
        notify($token, 'Referral not approved', $client['full_name'] . ' was not approved: ' . $full, $client['referred_by'], 'all', '/dashboard-affiliate.php?view=clients');
        return [null, $client['full_name'] . ' rejected.'];
    }

    if ($act === 'reopen') {
        $err = referral_stage_set($token, $clientId, 'pending', $by + ['stage_package_id' => null, 'stage_txn_id' => null]);
        if ($err) return [$err, ''];
        audit_log($token, 'referral_reopened', 'profile', $clientId, $client['full_name'] . ' moved back to Pending');
        return [null, $client['full_name'] . ' is Pending again.'];
    }
    return ['Unknown action.', ''];
}

function pipeline_admin_html(string $token, string $csrf): string
{
    $refs = supabase_request('GET', '/rest/v1/referrals?select=id,stage,stage_reason,stage_at,stage_package_id,created_at,'
        . 'client:profiles!referrals_referred_id_fkey(id,full_name,username,role,status,deleted_at),'
        . 'referrer:profiles!referrals_referrer_id_fkey(id,full_name,username,referral_code,affiliate_tier),'
        . 'package:packages!referrals_stage_package_id_fkey(name,price),by:profiles!referrals_stage_by_fkey(full_name)&level=eq.1&order=created_at.desc', null, $token)['body'] ?? [];
    $refs = array_values(array_filter(is_array($refs) ? $refs : [], fn($r) => ($r['client']['role'] ?? '') === 'client' && empty($r['client']['deleted_at'])));
    $pkgs = supabase_request('GET', '/rest/v1/packages?select=id,name,price&kind=eq.service&active=eq.true&order=price.asc', null, $token)['body'] ?? [];
    $pkgs = is_array($pkgs) ? $pkgs : [];

    $count = array_fill_keys(array_keys(REFERRAL_STAGES), 0);
    foreach ($refs as $r) $count[$r['stage'] ?? 'pending']++;

    $hid = '<input type="hidden" name="csrf" value="' . e($csrf) . '">';
    $h  = '<div class="card"><h2>Referral pipeline</h2>'
        . '<p class="hint" style="margin:0 0 10px">Every referred client: <strong>Pending → Converted (package) → Approved</strong>, or <strong>Rejected</strong> with a reason at any step. Approving confirms the 50% deposit: the referrer\'s Outright Commission lands in their Payout balance and the Uni-Level goes to their upline — or to the Main Basket when the upline is an admin\'s own Growth Partner account. Deposits clients submit online move them to Converted on their own.</p>'
        . '<div class="downline-levels" style="margin-bottom:12px">';
    foreach (REFERRAL_STAGES as $k => [$label, $pill]) $h .= '<span class="pill ' . e($pill) . '">' . e($label) . ' ' . $count[$k] . '</span> ';
    $h .= '</div>';
    if (!$refs) return $h . '<p class="empty">No referred clients yet.</p></div>';

    $reasonOpts = '<option value="">Reason…</option>';
    foreach (REFERRAL_REJECT_REASONS as $r) $reasonOpts .= '<option>' . e($r) . '</option>';
    $reasonOpts .= '<option value="__other">Other (type below)</option>';
    $pkgOpts = '<option value="">Package…</option>';
    foreach ($pkgs as $p) $pkgOpts .= '<option value="' . e($p['id']) . '">' . e($p['name']) . ' — $' . number_format((float) $p['price'], 0) . '</option>';

    $h .= '<div class="table-scroll" tabindex="0" role="region" aria-label="Referral pipeline"><table class="users-table pipe-table"><thead><tr>'
        . '<th scope="col">Client</th><th scope="col">Referred by</th><th scope="col">Stage</th><th scope="col">Updated</th><th scope="col" class="ut-actions-h">Admin</th></tr></thead><tbody>';
    foreach ($refs as $r) {
        $c = $r['client']; $g = $r['referrer'] ?? [];
        $stage = $r['stage'] ?? 'pending';
        $base = $hid . '<input type="hidden" name="client_id" value="' . e($c['id']) . '">';
        $act = '';
        if ($stage === 'pending' || $stage === 'rejected') {
            $act .= '<form method="post" class="inline-form pipe-act">' . $base . '<input type="hidden" name="pipe_action" value="convert">'
                  . '<select name="package_id" required aria-label="Package">' . $pkgOpts . '</select><button class="btn btn-sm" type="submit">Converted</button></form>';
        }
        if ($stage === 'converted') {
            $dep = round((float) ($r['package']['price'] ?? 0) * 0.5, 2);
            $act .= '<form method="post" class="inline-form pipe-act" onsubmit="return confirm(\'Approve ' . e(addslashes($c['full_name'])) . ' — confirms the $' . number_format($dep, 2) . ' deposit and pays the commission?\')">' . $base
                  . '<input type="hidden" name="pipe_action" value="approve"><button class="btn btn-sm btn-solid" type="submit">Approve</button></form>';
        }
        if ($stage === 'pending' || $stage === 'converted') {
            $act .= '<form method="post" class="pipe-act pipe-reject">' . $base . '<input type="hidden" name="pipe_action" value="reject">'
                  . '<select name="reason_pick" aria-label="Reason">' . $reasonOpts . '</select>'
                  . '<input name="reason_note" maxlength="200" placeholder="Note (optional)" aria-label="Reason note">'
                  . '<button class="btn btn-sm btn-ghost" type="submit">Reject</button></form>';
        }
        if ($stage === 'rejected') {
            $act .= '<form method="post" class="inline-form pipe-act">' . $base . '<input type="hidden" name="pipe_action" value="reopen"><button class="btn btn-sm btn-ghost" type="submit">Back to Pending</button></form>';
        }
        if ($stage === 'approved') $act = '<span class="hint">Commission paid</span>';

        $h .= '<tr><td><strong>' . e($c['full_name']) . '</strong><span class="ut-sub">@' . e($c['username']) . '</span></td>'
            . '<td><strong>' . e($g['full_name'] ?? '—') . '</strong><span class="ut-sub">' . e($g['referral_code'] ?? '') . (is_house_partner($g) ? ' · admin account' : '') . '</span></td>'
            . '<td>' . referral_stage_html($r, $r['package']['name'] ?? null) . '</td>'
            . '<td style="font-size:12px">' . date('M j, Y', strtotime($r['stage_at'] ?? $r['created_at'])) . (!empty($r['by']['full_name']) ? '<span class="ut-sub">by <strong>' . e($r['by']['full_name']) . '</strong></span>' : '') . '</td>'
            . '<td class="ut-actions">' . $act . '</td></tr>';
    }
    return $h . '</tbody></table></div></div>' . basket_html($token);
}

/** Main Basket total and latest entries (admin). */
function basket_html(string $token): string
{
    $rows = supabase_request('GET', '/rest/v1/transactions?select=amount,notes,created_at,packages(name),source:profiles!transactions_source_user_id_fkey(full_name)&type=eq.basket&status=eq.completed&order=created_at.desc&limit=500', null, $token)['body'] ?? [];
    $rows = is_array($rows) ? $rows : [];
    $total = array_sum(array_map(fn($r) => (float) $r['amount'], $rows));
    $h = '<div class="card basket-card" style="margin-top:12px"><div class="po-card-head"><h2>Main Basket</h2><span class="basket-total">$' . number_format($total, 2) . '</span></div>'
       . '<p class="hint" style="margin:0 0 10px">Uni-Level that isn\'t paid to a partner: when the referrer\'s upline is an admin\'s own Growth Partner account, or the referrer has no eligible upline.</p>';
    if (!$rows) return $h . '<p class="empty">Nothing in the basket yet.</p></div>';
    $h .= '<div class="table-scroll" tabindex="0" role="region" aria-label="Main Basket"><table class="users-table"><thead><tr><th scope="col">Date</th><th scope="col">Client</th><th scope="col">Package</th><th scope="col">Why</th><th scope="col" class="num">Amount</th></tr></thead><tbody>';
    foreach (array_slice($rows, 0, 50) as $r) {
        $h .= '<tr><td style="font-size:12px">' . date('M j, Y', strtotime($r['created_at'])) . '</td><td>' . e($r['source']['full_name'] ?? '—') . '</td><td style="font-size:12px">' . e($r['packages']['name'] ?? '—') . '</td>'
            . '<td style="font-size:12px">' . e(preg_replace('/^Uni-Level to Main Basket — /', '', (string) $r['notes'])) . '</td><td class="num"><strong>$' . number_format((float) $r['amount'], 2) . '</strong></td></tr>';
    }
    return $h . '</tbody></table></div></div>';
}
