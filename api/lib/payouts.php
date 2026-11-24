<?php
// Growth Partner payouts (Terms §5): requests (bank transfer, PayPal or
// USDT), the weekly cutoff killswitch, the batch progress bar, the admin/staff sorting queue and
// PDF receipts for commissions and payouts.
//
// A payout is a `transactions` row with type 'payout':
//   pending   = requested, waiting to be sorted
//   completed = paid (payment_ref holds the PayPal / on-chain reference)
//   failed    = rejected; the amount goes back to the partner's balance
//
// Killswitch (Terms §5.4): payouts close automatically every Wednesday at
// 11:59 PM US Eastern (Thursday in the Philippines). They stay closed until an admin re-opens them (password
// confirmed) — normally 2–3 business days later, once the batch is sorted.
// Settings: payout_opened_at, payout_closed_at (ISO times), payout_min.

require_once __DIR__ . '/pdf.php';

const PAYOUT_TZ      = 'America/New_York';
const PAYOUT_TZ_PH   = 'Asia/Manila';
// Terms §5.5: bank transfer (PH, US, CA, AU), PayPal, or a USDT wallet.
const PAYOUT_METHODS = ['bank' => 'Bank transfer', 'paypal' => 'PayPal', 'usdt' => 'USDT'];
const PAYOUT_BANK_COUNTRIES = ['PH' => 'Philippines', 'US' => 'United States', 'CA' => 'Canada', 'AU' => 'Australia'];
const PAYOUT_NETWORKS = [
    'USDT-TRC20' => 'TRC20 (Tron)',
    'USDT-ERC20' => 'ERC20 (Ethereum)',
    'USDT-BEP20' => 'BEP20 (BNB Chain)',
];

/** [last cutoff ≤ now, next cutoff > now] as Unix times — Wednesdays 11:59 PM US Eastern. */
function payout_cutoffs(?int $now = null): array
{
    $tz  = new DateTimeZone(PAYOUT_TZ);
    $d   = (new DateTimeImmutable('@' . ($now ?? time())))->setTimezone($tz);
    $wed = $d->modify('wednesday this week')->setTime(23, 59);
    if ($wed > $d) $wed = $wed->modify('-7 days');
    return [$wed->getTimestamp(), $wed->modify('+7 days')->getTimestamp()];
}

function payout_time_label(int $ts, string $fmt = 'D M j · g:i A'): string
{
    return (new DateTimeImmutable('@' . $ts))->setTimezone(new DateTimeZone(PAYOUT_TZ))->format($fmt) . ' ET';
}

/** "Wed Nov 25 · 11:59 PM ET (Thu 12:59 PM Philippines)" */
function payout_cutoff_label(int $ts): string
{
    $ph = (new DateTimeImmutable('@' . $ts))->setTimezone(new DateTimeZone(PAYOUT_TZ_PH))->format('D g:i A');
    return payout_time_label($ts) . ' (' . $ph . ' Philippines)';
}

/** Open/closed state of the payout window. */
function payout_state(string $token): array
{
    $s = get_settings($token, ['payout_opened_at', 'payout_closed_at', 'payout_min']);
    [$last, $next] = payout_cutoffs();
    $opened = $s['payout_opened_at'] !== '' ? (int) strtotime($s['payout_opened_at']) : 0;
    $closed = $s['payout_closed_at'] !== '' ? (int) strtotime($s['payout_closed_at']) : 0;
    $open   = $opened >= $last && $opened > $closed;
    $reason = null;
    if (!$open) $reason = ($closed > $opened && $closed >= $last) ? 'manual' : 'cutoff';
    $min = $s['payout_min'] === '' ? 100.0 : max(0.0, (float) $s['payout_min']);
    return ['open' => $open, 'reason' => $reason, 'last_cut' => $last, 'next_cut' => $next,
            'opened_at' => $opened, 'closed_at' => $closed, 'min' => $min];
}

function payout_get(string $token, string $query): array
{
    $r = supabase_request('GET', '/rest/v1/transactions?' . $query, null, $token);
    return is_array($r['body']) && !$r['error'] ? $r['body'] : [];
}

/** Earned (completed commissions), paid, in sorting, and available to withdraw. */
function payout_balance(string $token, string $uid): array
{
    $u    = urlencode($uid);
    $coms = payout_get($token, 'select=amount&type=eq.commission&status=eq.completed&user_id=eq.' . $u);
    $pays = payout_get($token, 'select=amount,status&type=eq.payout&status=in.(pending,completed)&user_id=eq.' . $u);
    $earned  = array_sum(array_map(fn($r) => (float) $r['amount'], $coms));
    $paid    = array_sum(array_map(fn($r) => $r['status'] === 'completed' ? (float) $r['amount'] : 0, $pays));
    $pending = array_sum(array_map(fn($r) => $r['status'] === 'pending' ? (float) $r['amount'] : 0, $pays));
    return ['earned' => round($earned, 2), 'paid' => round($paid, 2), 'pending' => round($pending, 2),
            'available' => round(max(0, $earned - $paid - $pending), 2)];
}

/** The batch being sorted: everything still pending plus what was sorted since the last cutoff. */
function payout_batch(string $token, array $state): array
{
    $since = gmdate('Y-m-d\TH:i:s\Z', $state['last_cut']);
    $rows  = payout_get($token, 'select=id,amount,status,notes,payment_ref,created_at,reviewed_at,user_id,owner:profiles!transactions_user_id_fkey(full_name,username,referral_code)'
        . '&type=eq.payout&or=(status.eq.pending,reviewed_at.gte.' . $since . ')&order=created_at.asc');
    $total = 0.0; $done = 0.0; $doneN = 0;
    foreach ($rows as $r) {
        $total += (float) $r['amount'];
        if ($r['status'] !== 'pending') { $done += (float) $r['amount']; $doneN++; }
    }
    return ['rows' => $rows, 'total' => round($total, 2), 'done' => round($done, 2),
            'count' => count($rows), 'done_count' => $doneN,
            'pct' => $total > 0 ? round($done / $total * 100, 1) : 0.0];
}

/** Black bar, filled orange as admin/staff sort the batch. Live-refreshed by app.js. */
function payout_bar_html(array $batch, array $state, string $title = 'Overall Growth Partner Payout'): string
{
    $pct = $batch['count'] && $batch['done_count'] === $batch['count'] ? 100.0 : $batch['pct'];
    $statusPill = $state['open']
        ? '<span class="pill active">Payouts open</span>'
        : '<span class="pill failed">Payouts closed' . ($state['reason'] === 'manual' ? ' by admin' : '') . '</span>';
    return '<section class="card po-card" aria-labelledby="po-bar-h">'
        . '<div class="po-card-head"><h2 id="po-bar-h">' . e($title) . '</h2>'
        . '<span class="po-cutoff">' . $statusPill . ' <em>Next cutoff: ' . e(payout_time_label($state['next_cut'], 'g:i A D · M j')) . '</em></span></div>'
        . '<div class="po-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . $pct . '" aria-label="Payout batch sorted">'
        .   '<span class="po-bar-fill" style="width:' . $pct . '%"></span>'
        .   '<span class="po-bar-text">Total : $' . number_format($batch['total'], 2) . '</span>'
        . '</div>'
        . '<p class="po-bar-sub"><strong>$' . number_format($batch['done'], 2) . '</strong> of $' . number_format($batch['total'], 2)
        . ' sorted · ' . $batch['done_count'] . ' of ' . $batch['count'] . ' payout' . ($batch['count'] === 1 ? '' : 's')
        . ' · batch since ' . e(payout_time_label($state['last_cut'], 'M j, g:i A')) . '</p>'
        . '</section>';
}

function payout_closed_notice(array $state): string
{
    if ($state['open']) return '';
    return '<div class="alert alert-warn po-notice"><strong>Payouts are switched off right now.</strong> '
        . 'Payouts turn off automatically every Wednesday at 11:59 PM US Eastern (Thursday in the Philippines) so the team can sort the week\'s payouts. '
        . 'An admin turns them back on, usually within 2–3 business days. The black bar shows the batch being sorted in real time.</div>';
}

/** Method details stored in a payout's notes: "Method: Crypto | Network: USDT-TRC20 | To: T…". */
function payout_parse(?string $notes): array
{
    $out = ['method' => '', 'network' => '', 'to' => '', 'reason' => ''];
    foreach (explode(' | ', (string) $notes) as $part) {
        if (preg_match('/^(Method|Network|To|Rejected): ?(.*)$/', $part, $m)) {
            $out[['Method' => 'method', 'Network' => 'network', 'To' => 'to', 'Rejected' => 'reason'][$m[1]]] = $m[2];
        }
    }
    return $out;
}

function payout_method_label(array $p): string
{
    if ($p['method'] === '') return '—';
    return $p['method'] . ($p['network'] !== '' ? ' · ' . (PAYOUT_NETWORKS[$p['network']] ?? PAYOUT_BANK_COUNTRIES[$p['network']] ?? $p['network']) : '');
}

function payout_mask(string $to): string
{
    if (str_contains($to, '@')) {
        [$a, $b] = explode('@', $to, 2);
        return mb_substr($a, 0, 2) . str_repeat('•', max(1, min(6, mb_strlen($a) - 2))) . '@' . $b;
    }
    return strlen($to) > 12 ? substr($to, 0, 6) . '…' . substr($to, -4) : $to;
}

/**
 * Terms §5.2 — Basic batch rule: a Basic partner can withdraw Uni-Level only
 * after referring at least one new Qualified Client since their last
 * successful withdrawal (Outright is exempt; the counter resets on payout).
 * Returns $bal with 'available' reduced by the locked Uni-Level and 'locked_ul'.
 */
function payout_withdrawable(string $token, array $profile, array $bal): array
{
    $locked = 0.0;
    if (!tier_unlimited($profile) && ($profile['affiliate_tier'] ?? '') === 'basic') {
        $u    = urlencode($profile['id']);
        $last = payout_get($token, 'select=reviewed_at&type=eq.payout&status=eq.completed&user_id=eq.' . $u . '&order=reviewed_at.desc&limit=1')[0]['reviewed_at'] ?? null;
        $since = $last ? '&created_at=gt.' . urlencode(gmdate('Y-m-d\TH:i:s\Z', strtotime($last))) : '';
        $newClients = 0; $ul = 0.0;
        foreach (payout_get($token, 'select=amount,notes&type=eq.commission&status=eq.completed&user_id=eq.' . $u . $since) as $c) {
            if (str_contains((string) $c['notes'], 'Uni-Level')) $ul += (float) $c['amount']; else $newClients++;
        }
        if ($newClients === 0) $locked = $ul;
    }
    $locked = round(min($locked, $bal['available']), 2);
    $bal['locked_ul'] = $locked;
    $bal['available'] = round($bal['available'] - $locked, 2);
    return $bal;
}

/** Why this partner cannot request a payout right now (null = they can). */
function payout_blocker(string $token, array $profile, array $state, array $bal, bool $hasPending): ?string
{
    if (!$state['open'])  return 'Payouts are switched off until an admin re-opens them.';
    if ($hasPending)      return 'You already have a payout being sorted.';
    $unl = tier_unlimited($profile);
    if (!$unl) {
        // §5.3: Standard may withdraw while Active or in its 30-day grace period.
        $st    = affiliate_state($profile);
        $isStd = ($profile['affiliate_tier'] ?? '') === 'standard';
        if (!($st === 'active' || ($isStd && $st === 'grace'))) {
            return $isStd ? 'Payouts need an active Standard term or its 30-day grace period.' : 'Payouts need an active Growth Partner term.';
        }
    }
    if (!$unl && ($profile['kyc_status'] ?? '') !== 'approved') return 'Verify your ID before your first payout.';
    if ($bal['available'] < $state['min']) {
        return 'The minimum payout is $' . number_format($state['min'], 2) . '. You have $' . number_format($bal['available'], 2) . ' available'
            . (!empty($bal['locked_ul']) ? ' ($' . number_format($bal['locked_ul'], 2) . ' Uni-Level unlocks when you refer a new Qualified Client, Terms §5.2)' : '') . '.';
    }
    return null;
}

/** Growth Partner submits a payout request. Returns an error or null. */
function payout_request(string $token, array $profile): ?string
{
    $state = payout_state($token);
    $bal   = payout_withdrawable($token, $profile, payout_balance($token, $profile['id']));
    $hasPending = (bool) payout_get($token, 'select=id&type=eq.payout&status=eq.pending&user_id=eq.' . urlencode($profile['id']));
    if ($why = payout_blocker($token, $profile, $state, $bal, $hasPending)) return $why;

    $amount = round((float) ($_POST['amount'] ?? 0), 2);
    if ($amount < $state['min'] || $amount > $bal['available']) {
        return 'Enter an amount between $' . number_format($state['min'], 2) . ' and $' . number_format($bal['available'], 2) . '.';
    }
    $method = (string) ($_POST['method'] ?? '');
    $clean  = fn(string $k, int $max) => mb_substr(trim(str_replace('|', '/', (string) ($_POST[$k] ?? ''))), 0, $max);
    $net    = '';
    if ($method === 'paypal') {
        $to = strtolower(trim((string) ($_POST['paypal_email'] ?? '')));
        if (!filter_var($to, FILTER_VALIDATE_EMAIL) || strlen($to) > 254) return 'Enter the email address of your PayPal account.';
        $notes = 'Method: PayPal | To: ' . $to;
    } elseif ($method === 'usdt') {
        $net = (string) ($_POST['usdt_network'] ?? '');
        $to  = trim((string) ($_POST['usdt_address'] ?? ''));
        if (!isset(PAYOUT_NETWORKS[$net])) return 'Choose the USDT network for your wallet.';
        if (!preg_match('/^[A-Za-z0-9:]{20,100}$/', $to)) return 'Enter a valid wallet address (letters and numbers only, no spaces).';
        $notes = 'Method: USDT | Network: ' . $net . ' | To: ' . $to;
    } elseif ($method === 'bank') {
        $net    = (string) ($_POST['bank_country'] ?? '');
        $bank   = $clean('bank_name', 80);
        $holder = $clean('bank_holder', 120);
        $acct   = preg_replace('/[\s\-]+/', '', (string) ($_POST['bank_account'] ?? ''));
        $code   = strtoupper($clean('bank_code', 20));
        if (!isset(PAYOUT_BANK_COUNTRIES[$net])) return 'Bank transfers are available for the Philippines, the United States, Canada and Australia.';
        if (mb_strlen($bank) < 2)   return 'Enter your bank\'s name.';
        if (mb_strlen($holder) < 2) return 'Enter the account holder\'s name exactly as the bank has it.';
        if (!preg_match('/^[A-Za-z0-9]{4,34}$/', $acct)) return 'Enter a valid account number (letters and numbers only).';
        if ($code !== '' && !preg_match('/^[A-Z0-9 ]{3,20}$/', $code)) return 'The SWIFT / routing / BSB / transit code may contain letters and numbers only.';
        $notes = 'Method: Bank transfer | Network: ' . $net . ' | To: ' . $bank . ' · ' . $holder . ' · ' . $acct . ($code !== '' ? ' · Code ' . $code : '');
    } else {
        return 'Choose how you want to be paid: bank transfer, PayPal or USDT.';
    }

    $res = supabase_request('POST', '/rest/v1/transactions', [
        'user_id' => $profile['id'], 'amount' => $amount, 'type' => 'payout', 'status' => 'pending', 'notes' => $notes,
    ], $token, ['Prefer: return=minimal']);
    if ($res['error']) return $res['status'] === 409 ? 'You already have a payout being sorted.' : 'Could not submit your payout. Please try again.';

    $label = PAYOUT_METHODS[$method] . ($net !== '' ? ' (' . $net . ')' : '');
    audit_log($token, 'payout_requested', 'profile', $profile['id'], 'Payout request $' . number_format($amount, 2) . ' via ' . $label);
    foreach (['admin', 'staff'] as $aud) {
        notify($token, 'Payout to sort', $profile['full_name'] . ' requested $' . number_format($amount, 2) . ' via ' . $label . '.', null, $aud,
            $aud === 'admin' ? '/dashboard-admin.php#payouts' : '/work.php?view=payouts');
    }
    return null;
}

/**
 * Admin / staff actions, posted with field payout_action:
 *   paid, reject — anyone on the team; open, close — admin only, password confirmed.
 * Returns [errorOrNull, successMessage] or null when nothing was posted.
 */
function payout_team_action(string $token, array $profile): ?array
{
    $act = (string) ($_POST['payout_action'] ?? '');
    if ($act === '') return null;
    $isAdmin = $profile['role'] === 'admin';

    if ($act === 'open' || $act === 'close') {
        if (!$isAdmin) return ['Only an admin can switch payouts on or off.', ''];
        $row = supabase_request('GET', '/rest/v1/profiles?select=password_hash&id=eq.' . urlencode($profile['id']), null, $token)['body'][0] ?? null;
        if (!$row || !password_verify((string) ($_POST['password'] ?? ''), (string) ($row['password_hash'] ?? ''))) {
            audit_log($token, 'payout_toggle_denied', 'settings', null, 'Wrong password when switching payouts ' . ($act === 'open' ? 'on' : 'off'));
            return ['Wrong password — payouts were not changed.', ''];
        }
        $err = set_setting($token, $act === 'open' ? 'payout_opened_at' : 'payout_closed_at', gmdate('c'));
        if ($err) return [$err, ''];
        audit_log($token, $act === 'open' ? 'payout_opened' : 'payout_closed', 'settings', null, 'Payouts switched ' . ($act === 'open' ? 'ON' : 'OFF') . ' by ' . $profile['full_name']);
        if ($act === 'open') notify($token, 'Payouts are open', 'You can request a payout again until the next cutoff: ' . payout_cutoff_label(payout_cutoffs()[1]) . '.', null, 'client', '/dashboard-affiliate.php?view=payout');
        return [null, $act === 'open' ? 'Payouts are ON — they switch off again automatically at the next Wednesday 11:59 PM ET cutoff.' : 'Payouts are OFF.'];
    }

    if ($act === 'paid' || $act === 'reject') {
        $id  = (string) ($_POST['txn_id'] ?? '');
        $txn = payout_get($token, 'select=id,user_id,amount,notes,status&type=eq.payout&id=eq.' . urlencode($id))[0] ?? null;
        if (!$txn || $txn['status'] !== 'pending') return ['That payout was already sorted.', ''];
        if ($act === 'paid') {
            $ref = trim((string) ($_POST['payment_ref'] ?? ''));
            if (!preg_match('/^[A-Za-z0-9 #:_\-\.]{3,120}$/', $ref)) return ['Enter the bank reference, PayPal transaction ID or on-chain transaction hash.', ''];
            // Terms §5.6: Net = Gross − transaction fees − tax withheld.
            $fee = round(max(0.0, (float) ($_POST['fee'] ?? 0)), 2);
            $tax = round(max(0.0, (float) ($_POST['tax'] ?? 0)), 2);
            if ($fee + $tax > (float) $txn['amount']) return ['Fees and tax withheld can\'t be more than the payout.', ''];
            $patch = ['status' => 'completed', 'payment_ref' => $ref, 'payout_fee' => $fee, 'payout_tax' => $tax];
        } else {
            $why = mb_substr(trim((string) ($_POST['reason'] ?? '')), 0, 200);
            if ($why === '') return ['Give a reason so the partner knows what to fix.', ''];
            $patch = ['status' => 'failed', 'notes' => $txn['notes'] . ' | Rejected: ' . str_replace('|', '/', $why)];
        }
        $patch += ['reviewed_by' => $profile['id'], 'reviewed_at' => gmdate('c')];
        $res = supabase_request('PATCH', '/rest/v1/transactions?status=eq.pending&id=eq.' . urlencode($id), $patch, $token, ['Prefer: return=minimal']);
        if ($res['error']) return [str_contains((string) $res['error'], 'payout_') ? 'Run migration 20261208 first (it adds payout fees and tax).' : $res['error'], ''];
        $amt = '$' . number_format((float) $txn['amount'], 2);
        $net = $act === 'paid' ? '$' . number_format((float) $txn['amount'] - $patch['payout_fee'] - $patch['payout_tax'], 2) : '';
        audit_log($token, $act === 'paid' ? 'payout_paid' : 'payout_rejected', 'transaction', $id, ($act === 'paid' ? 'Paid ' : 'Rejected ') . $amt . ' payout' . ($act === 'paid' ? ' — fees $' . number_format($patch['payout_fee'], 2) . ', tax $' . number_format($patch['payout_tax'], 2) . ', net ' . $net . ', ref ' . $patch['payment_ref'] : ''));
        notify($token, $act === 'paid' ? 'Payout sent: ' . $amt : 'Payout not approved',
            $act === 'paid' ? 'Your payout was sent: ' . $amt . ' gross − $' . number_format($patch['payout_fee'], 2) . ' fees − $' . number_format($patch['payout_tax'], 2) . ' tax = ' . $net . ' net (ref ' . $patch['payment_ref'] . '). Download the receipt from Payout → Payout receipts.'
                            : 'Your ' . $amt . ' payout was not approved: ' . trim((string) ($_POST['reason'] ?? '')) . ' The amount is back in your balance.',
            $txn['user_id'], 'all', '/dashboard-affiliate.php?view=payout');
        return [null, $act === 'paid' ? 'Marked paid — ' . $amt . '.' : 'Payout rejected; the amount is back in the partner\'s balance.'];
    }
    return ['Unknown payout action.', ''];
}

/** Admin / staff panel: switch, live bar and the queue to sort. */
function payout_queue_html(string $token, array $profile, string $csrf, string $receiptBase): string
{
    $state = payout_state($token);
    $batch = payout_batch($token, $state);
    $isAdmin = $profile['role'] === 'admin';
    $hid = '<input type="hidden" name="csrf" value="' . e($csrf) . '">';

    $h = payout_bar_html($batch, $state, 'Payout batch');
    $h .= '<section class="card po-switch" style="margin-bottom:12px"><div class="po-switch-row">'
        . '<div><span class="label">Payout killswitch</span><h2 style="margin:4px 0 2px">' . ($state['open'] ? 'Payouts are <span class="po-on">ON</span>' : 'Payouts are <span class="po-off">OFF</span>') . '</h2>'
        . '<p class="hint" style="margin:0">Switches off automatically every Wednesday at 11:59 PM US Eastern (next: ' . e(payout_cutoff_label($state['next_cut'])) . '). '
        . ($state['open'] ? 'Opened ' . e(payout_time_label($state['opened_at'], 'M j, g:i A')) . '.' : 'Sort the batch below, then switch payouts back on — usually within 2–3 business days.') . '</p></div>';
    if ($isAdmin) {
        $on = !$state['open'];
        $h .= '<form method="post" class="po-toggle" autocomplete="off">' . $hid
            . '<input type="hidden" name="payout_action" value="' . ($on ? 'open' : 'close') . '">'
            . '<label class="sr-only" for="po-pw">Your admin password</label>'
            . '<input id="po-pw" type="password" name="password" required placeholder="Admin password" autocomplete="current-password">'
            . '<button class="btn ' . ($on ? 'btn-solid' : 'btn-danger') . '" type="submit">' . ($on ? 'Turn payouts ON' : 'Turn payouts OFF') . '</button></form>';
    } else {
        $h .= '<p class="hint" style="margin:0">Only an admin can switch payouts back on.</p>';
    }
    $h .= '</div></section>';

    $h .= '<section class="card"><h2>Payouts to sort</h2>';
    if (!$batch['rows']) return $h . '<p class="empty">Nothing in this batch.</p></section>';
    $h .= '<div class="table-scroll" tabindex="0" role="region" aria-label="Payout queue"><table class="users-table po-table"><thead><tr>'
        . '<th scope="col">Growth Partner</th><th scope="col" class="num">Amount</th><th scope="col">Send to</th><th scope="col">Requested</th><th scope="col">Status</th><th scope="col" class="ut-actions-h">Action</th></tr></thead><tbody>';
    foreach (array_reverse($batch['rows']) as $r) {
        $p = payout_parse($r['notes'] ?? '');
        $h .= '<tr><td><strong>' . e($r['owner']['full_name'] ?? '—') . '</strong><span class="ut-sub">@' . e($r['owner']['username'] ?? '') . ($r['owner']['referral_code'] ?? '' ? ' · ' . e($r['owner']['referral_code']) : '') . '</span></td>'
            . '<td class="num"><strong>$' . number_format((float) $r['amount'], 2) . '</strong></td>'
            . '<td><span class="pill">' . e(payout_method_label($p)) . '</span><span class="ut-sub po-addr">' . e($p['to']) . '</span></td>'
            . '<td style="font-size:12px">' . e(date('M j, H:i', strtotime($r['created_at']))) . '</td>'
            . '<td>' . payout_status_pill($r['status']) . '</td><td class="ut-actions">';
        if ($r['status'] === 'pending') {
            $h .= '<form method="post" class="inline-form po-act">' . $hid . '<input type="hidden" name="payout_action" value="paid"><input type="hidden" name="txn_id" value="' . e($r['id']) . '">'
                . '<input name="payment_ref" required minlength="3" maxlength="120" placeholder="Txn ID / hash / bank ref" aria-label="Transaction reference">'
                . '<input name="fee" type="number" min="0" step="0.01" placeholder="Fees $" aria-label="Transaction fees deducted (US$)" class="po-num">'
                . '<input name="tax" type="number" min="0" step="0.01" placeholder="Tax withheld $" aria-label="Tax withheld (US$)" class="po-num">'
                . '<button class="btn btn-sm btn-solid" type="submit">Mark paid</button></form>'
                . '<form method="post" class="inline-form po-act">' . $hid . '<input type="hidden" name="payout_action" value="reject"><input type="hidden" name="txn_id" value="' . e($r['id']) . '">'
                . '<input name="reason" required maxlength="200" placeholder="Reason" aria-label="Reason for rejecting"><button class="btn btn-sm btn-ghost" type="submit">Reject</button></form>';
        } else {
            $h .= receipt_links($receiptBase, $r['id']);
        }
        $h .= '</td></tr>';
    }
    return $h . '</tbody></table></div></section>';
}

function payout_status_pill(string $s): string
{
    return match ($s) {
        'pending'   => '<span class="pill pending">Sorting</span>',
        'completed' => '<span class="pill active">Paid</span>',
        'failed'    => '<span class="pill failed">Rejected</span>',
        default     => '<span class="pill">' . e($s) . '</span>',
    };
}

/** The Growth Partner's Payout / Earnings page. */
function payout_page_html(string $token, array $profile, string $csrf): string
{
    $uid   = urlencode($profile['id']);
    $state = payout_state($token);
    $batch = payout_batch($token, $state);
    $bal   = payout_withdrawable($token, $profile, payout_balance($token, $profile['id']));
    $pays  = payout_get($token, 'select=id,amount,status,notes,payment_ref,created_at,reviewed_at&type=eq.payout&user_id=eq.' . $uid . '&order=created_at.desc&limit=100');
    $coms  = payout_get($token, 'select=id,amount,created_at,notes,packages(name),source:profiles!transactions_source_user_id_fkey(full_name)&type=eq.commission&status=eq.completed&user_id=eq.' . $uid . '&order=created_at.desc&limit=200');
    $hasPending = (bool) array_filter($pays, fn($p) => $p['status'] === 'pending');
    $blocker = payout_blocker($token, $profile, $state, $bal, $hasPending);
    $rcpt  = '/dashboard-affiliate.php?receipt=';
    $money = fn(float $v) => '$' . number_format($v, 2);

    // Paid out (and earned) this week / month / year.
    $now = time();
    $starts = ['This week' => strtotime('monday this week', $now), 'Month' => strtotime(date('Y-m-01', $now)), 'Year' => strtotime(date('Y-01-01', $now))];
    $cards = '';
    foreach ($starts as $label => $from) {
        $paid = array_sum(array_map(fn($p) => $p['status'] === 'completed' && strtotime((string) ($p['reviewed_at'] ?? $p['created_at'])) >= $from ? (float) $p['amount'] : 0, $pays));
        $earn = array_sum(array_map(fn($c) => strtotime($c['created_at']) >= $from ? (float) $c['amount'] : 0, $coms));
        $cards .= '<div class="card po-period"><span class="po-period-h">' . e($label) . '</span><div class="po-period-v">' . $money($paid) . '</div><span class="ut-sub">paid out · earned ' . $money($earn) . '</span></div>';
    }

    $h  = payout_bar_html($batch, $state) . payout_closed_notice($state);

    // Payout Now + period cards.
    $h .= '<div class="po-row">'
        . '<div class="card po-now"><button type="button" class="btn btn-solid po-now-btn"' . ($blocker ? ' disabled aria-describedby="po-why"' : ' onclick="document.getElementById(\'po-dlg\').showModal()"') . '>Payout Now</button>'
        . '<span class="po-avail">Available <strong>' . $money($bal['available']) . '</strong></span>'
        . ($bal['pending'] > 0 ? '<span class="ut-sub">' . $money($bal['pending']) . ' being sorted</span>' : '')
        . (!empty($bal['locked_ul']) && !$blocker ? '<span class="ut-sub">' . $money($bal['locked_ul']) . ' Uni-Level locked until your next Qualified Client (§5.2)</span>' : '')
        . ($blocker ? '<span class="ut-sub po-why" id="po-why">' . e($blocker) . '</span>' : '<span class="ut-sub">Bank, PayPal or USDT · min ' . $money($state['min']) . '</span>')
        . '</div>' . $cards . '</div>';

    // Modal triggers.
    $h .= '<div class="po-links">'
        . '<button type="button" class="btn" onclick="document.getElementById(\'po-ledger\').showModal()">Commission Ledger <span class="tab-count">' . count($coms) . '</span></button>'
        . '<button type="button" class="btn" onclick="document.getElementById(\'po-receipts\').showModal()">Payout Receipts <span class="tab-count">' . count($pays) . '</span></button></div>';

    // Payout Now dialog.
    if (!$blocker) {
        $last = payout_parse($pays[0]['notes'] ?? '');
        $lastM = ['PayPal' => 'paypal', 'USDT' => 'usdt', 'Bank transfer' => 'bank'][$last['method']] ?? 'bank';
        $netOpts = '';
        foreach (PAYOUT_NETWORKS as $k => $v) $netOpts .= '<option value="' . e($k) . '"' . ($last['network'] === $k ? ' selected' : '') . '>' . e($v) . '</option>';
        $ccOpts = '';
        foreach (PAYOUT_BANK_COUNTRIES as $k => $v) $ccOpts .= '<option value="' . e($k) . '"' . ($last['network'] === $k ? ' selected' : '') . '>' . e($v) . '</option>';
        $lastBank = $lastM === 'bank' ? array_pad(explode(' · ', $last['to']), 4, '') : ['', '', '', ''];
        $chk = fn(string $m) => $lastM === $m ? ' checked' : '';
        $h .= '<dialog class="tree-dialog po-dialog" id="po-dlg" aria-labelledby="po-dlg-t"><form method="post" class="po-form">'
            . '<input type="hidden" name="csrf" value="' . e($csrf) . '"><input type="hidden" name="action" value="request_payout">'
            . '<div class="tree-dlg-head"><div><span class="label">Available ' . $money($bal['available']) . '</span><h3 id="po-dlg-t">Request a payout</h3></div>'
            . '<button class="btn btn-sm btn-ghost" type="button" onclick="this.closest(\'dialog\').close()">Close</button></div>'
            . '<div class="tree-dlg-body">'
            . '<div class="field"><label for="po-amt">Amount (US$)</label><input id="po-amt" name="amount" type="number" step="0.01" min="' . $state['min'] . '" max="' . $bal['available'] . '" value="' . number_format($bal['available'], 2, '.', '') . '" required></div>'
            . '<fieldset class="po-methods"><legend>Pay me with</legend>'
            . '<label class="po-method"><input type="radio" name="method" value="bank"' . $chk('bank') . '><span><strong>Bank transfer</strong><small>Philippines, US, Canada or Australia</small></span></label>'
            . '<label class="po-method"><input type="radio" name="method" value="paypal"' . $chk('paypal') . '><span><strong>PayPal</strong><small>To the email on your PayPal account</small></span></label>'
            . '<label class="po-method"><input type="radio" name="method" value="usdt"' . $chk('usdt') . '><span><strong>USDT</strong><small>To your USDT wallet</small></span></label></fieldset>'
            . '<div class="po-bank"><div class="field"><label for="po-cc">Bank country</label><select id="po-cc" name="bank_country">' . $ccOpts . '</select></div>'
            . '<div class="field"><label for="po-bank">Bank name</label><input id="po-bank" name="bank_name" maxlength="80" value="' . e($lastBank[0]) . '" placeholder="e.g. BDO, Chase, RBC, Commonwealth Bank"></div>'
            . '<div class="field"><label for="po-holder">Account holder name</label><input id="po-holder" name="bank_holder" maxlength="120" autocomplete="name" value="' . e($lastBank[1]) . '"></div>'
            . '<div class="field"><label for="po-acct">Account number</label><input id="po-acct" name="bank_account" maxlength="40" autocomplete="off" inputmode="text" value="' . e($lastBank[2]) . '"></div>'
            . '<div class="field"><label for="po-code">SWIFT / routing / BSB / transit code <span class="hint">(if your bank needs one)</span></label><input id="po-code" name="bank_code" maxlength="20" value="' . e(preg_replace('/^Code /', '', $lastBank[3])) . '"></div>'
            . '<p class="hint">Bank fees are deducted from the payout (Terms §5.6). KAM TEAM is not responsible for payments sent to incorrect details.</p></div>'
            . '<div class="po-paypal"><div class="field"><label for="po-pp">PayPal email</label><input id="po-pp" name="paypal_email" type="email" maxlength="254" autocomplete="email" value="' . e($lastM === 'paypal' ? $last['to'] : '') . '" placeholder="you@example.com"></div>'
            . '<p class="hint">PayPal and currency-conversion fees are deducted from the payout.</p></div>'
            . '<div class="po-usdt"><div class="field"><label for="po-net">USDT network</label><select id="po-net" name="usdt_network">' . $netOpts . '</select></div>'
            . '<div class="field"><label for="po-addr">Wallet address</label><input id="po-addr" name="usdt_address" maxlength="100" autocomplete="off" spellcheck="false" value="' . e($lastM === 'usdt' ? $last['to'] : '') . '" placeholder="Paste your USDT wallet address"></div>'
            . '<p class="hint">Double-check the network. USDT sent to the wrong network cannot be recovered. Network fees are deducted.</p></div>'
            . '<button class="btn btn-solid" type="submit">Submit payout request →</button>'
            . '<p class="hint" style="margin-top:10px">Requests are sorted after the cutoff every Wednesday 11:59 PM US Eastern (Thursday in the Philippines), within 2–3 business days. Net payment = gross − transaction fees − any tax withheld. You get a notification and a receipt once it is paid.</p>'
            . '</div></form></dialog>';
    }

    // Commission ledger dialog.
    $h .= '<dialog class="tree-dialog po-dialog po-wide" id="po-ledger" aria-labelledby="po-ledger-t"><div class="tree-dlg-head">'
        . '<div><span class="label">' . count($coms) . ' commission' . (count($coms) === 1 ? '' : 's') . ' · ' . $money($bal['earned']) . ' earned</span><h3 id="po-ledger-t">Commission Ledger</h3></div>'
        . '<div class="po-dlg-acts"><a class="btn btn-sm btn-ghost" href="' . $rcpt . 'sample-commission">Sample PDF</a><form method="dialog"><button class="btn btn-sm btn-ghost" type="submit">Close</button></form></div></div><div class="tree-dlg-body">';
    if (!$coms) {
        $h .= '<p class="empty">No commissions yet. <a href="' . $rcpt . 'sample-commission">Download a sample receipt</a> to see what you\'ll get.</p>';
    } else {
        $h .= '<div class="table-scroll" tabindex="0" role="region" aria-label="Commission ledger"><table class="users-table"><thead><tr><th scope="col">Receipt</th><th scope="col">Type</th><th scope="col">Client</th><th scope="col">Package</th><th scope="col" class="num">Amount</th><th scope="col">Date</th><th scope="col" class="ut-actions-h">PDF</th></tr></thead><tbody>';
        foreach ($coms as $c) {
            $isUl = str_contains((string) ($c['notes'] ?? ''), 'Uni-Level');
            $h .= '<tr><td><span class="ref-code">' . e(receipt_no('commission', $c['id'])) . '</span></td>'
                . '<td><span class="pill ' . ($isUl ? 'completed' : 'active') . '">' . e(commission_label($c['notes'] ?? '')[1]) . '</span></td>'
                . '<td>' . e($c['source']['full_name'] ?? '—') . '</td><td style="font-size:12px">' . e($c['packages']['name'] ?? '—') . '</td>'
                . '<td class="num"><strong>' . $money((float) $c['amount']) . '</strong></td><td style="font-size:12px">' . date('M j, Y', strtotime($c['created_at'])) . '</td>'
                . '<td class="ut-actions"><a class="btn btn-sm" href="' . e($rcpt . $c['id']) . '" download>Download</a></td></tr>';
        }
        $h .= '</tbody></table></div>';
    }
    $h .= '</div></dialog>';

    // Payout receipts dialog.
    $h .= '<dialog class="tree-dialog po-dialog po-wide" id="po-receipts" aria-labelledby="po-receipts-t"><div class="tree-dlg-head">'
        . '<div><span class="label">' . $money($bal['paid']) . ' paid out to date</span><h3 id="po-receipts-t">Payout Receipts</h3></div>'
        . '<div class="po-dlg-acts"><a class="btn btn-sm btn-ghost" href="' . $rcpt . 'sample-payout">Sample PDF</a><form method="dialog"><button class="btn btn-sm btn-ghost" type="submit">Close</button></form></div></div><div class="tree-dlg-body">';
    if (!$pays) {
        $h .= '<p class="empty">No payouts yet. <a href="' . $rcpt . 'sample-payout">Download a sample receipt</a> to see what you\'ll get.</p>';
    } else {
        $h .= '<div class="table-scroll" tabindex="0" role="region" aria-label="Payout receipts"><table class="users-table"><thead><tr><th scope="col">Receipt</th><th scope="col">Method</th><th scope="col">Sent to</th><th scope="col" class="num">Amount</th><th scope="col">Requested</th><th scope="col">Status</th><th scope="col" class="ut-actions-h">PDF</th></tr></thead><tbody>';
        foreach ($pays as $p) {
            $d = payout_parse($p['notes'] ?? '');
            $h .= '<tr><td><span class="ref-code">' . e(receipt_no('payout', $p['id'])) . '</span></td>'
                . '<td><span class="pill">' . e(payout_method_label($d)) . '</span></td><td style="font-size:12px">' . e(payout_mask($d['to'])) . '</td>'
                . '<td class="num"><strong>' . $money((float) $p['amount']) . '</strong></td><td style="font-size:12px">' . date('M j, Y', strtotime($p['created_at'])) . '</td>'
                . '<td>' . payout_status_pill($p['status']) . ($p['status'] === 'failed' && $d['reason'] !== '' ? '<span class="ut-sub">' . e($d['reason']) . '</span>' : '') . '</td>'
                . '<td class="ut-actions"><a class="btn btn-sm" href="' . e($rcpt . $p['id']) . '" download>Download</a></td></tr>';
        }
        $h .= '</tbody></table></div>';
    }
    return $h . '</div></dialog>';
}

function receipt_no(string $kind, string $id): string
{
    $prefix = ['payout' => 'KT-PO-', 'commission' => 'KT-CM-', 'deposit' => 'KT-DP-', 'activation' => 'KT-AC-', 'basket' => 'KT-MB-'][$kind] ?? 'KT-TX-';
    return $prefix . strtoupper(substr(str_replace('-', '', $id), 0, 8));
}

// Print / PDF links for one transaction's receipt.
function receipt_links(string $base, string $id): string
{
    $u = $base . urlencode($id);
    return '<span class="rcpt-links"><a class="btn btn-sm" href="' . e($u) . '&amp;print=1" target="_blank" rel="noopener">Print</a>'
        . '<a class="btn btn-sm btn-ghost" href="' . e($u) . '">PDF</a></span>';
}

/**
 * Streams a PDF receipt for any transaction row and exits.
 * $ownerId: only that partner's rows (null = team access, any row).
 * "sample-commission" / "sample-payout" give an example receipt.
 */
function receipt_send(string $token, string $id, ?string $ownerId): void
{
    $sample = in_array($id, ['sample-commission', 'sample-payout', 'sample-deposit', 'sample-activation', 'sample-basket'], true);
    if ($sample) {
        $kind = substr($id, 7);
        $t = [
            'id' => '5a3f9c21-0000-0000-0000-000000000000', 'type' => $kind, 'status' => 'completed',
            'amount' => $kind === 'payout' ? 486.25 : 50.00, 'payout_fee' => 1.00, 'payout_tax' => 0.00,
            'created_at' => date('c', strtotime('-3 days')), 'reviewed_at' => date('c'),
            'notes' => $kind === 'payout' ? 'Method: USDT | Network: USDT-TRC20 | To: TQ7x9mSampleWalletAddress4fK2' : '10% Outright Commission on the advance deposit of Sample Client Co.',
            'payment_ref' => $kind === 'payout' ? '0x9f3c…sample…a71e' : null,
            'owner' => ['full_name' => 'Sample Partner', 'username' => 'sample', 'referral_code' => 'KT-000000'],
            'source' => ['full_name' => 'Sample Client Co.'], 'packages' => ['name' => 'Google SEO'],
            'deposit' => 500.00,
        ];
        if ($kind === 'deposit')    { $t['amount'] = 500.00; $t['payment_ref'] = 'GC-88412'; $t['notes'] = '50% advance deposit — Google SEO'; $t['owner'] = ['full_name' => 'Sample Client Co.', 'username' => 'sampleclient', 'referral_code' => 'KT-000002']; $t['balance_amount'] = 500.00; $t['balance_paid_at'] = date('c'); $t['balance_ref'] = 'BAL-2231'; }
        if ($kind === 'activation') { $t['amount'] = 100.00; $t['payment_ref'] = 'GC-77120'; $t['notes'] = null; $t['packages'] = ['name' => 'Standard plan']; }
        if ($kind === 'basket')     { $t['amount'] = 2.50; $t['notes'] = '5% Uni-Level to Main Basket'; $t['owner'] = ['full_name' => 'KAM Team', 'username' => 'admin', 'referral_code' => '']; }
    } else {
        $q = 'select=id,type,status,amount,notes,payment_ref,created_at,reviewed_at,user_id,source_txn_id,balance_amount,balance_paid_at,balance_ref,packages(name),'
           . 'owner:profiles!transactions_user_id_fkey(full_name,username,referral_code),source:profiles!transactions_source_user_id_fkey(full_name)'
           . '&id=eq.' . urlencode($id) . ($ownerId ? '&user_id=eq.' . urlencode($ownerId) : '');
        // payout_fee / payout_tax arrive with migration 20261208; fall back without them.
        $t = payout_get($token, str_replace('select=id,', 'select=id,payout_fee,payout_tax,', $q))[0] ?? payout_get($token, $q)[0] ?? null;
        if (!$t) { http_response_code(404); header('Content-Type: text/plain'); echo 'Receipt not found.'; exit; }
        $kind = $t['type'];
        $t['deposit'] = null;
        if ($kind === 'commission' && !empty($t['source_txn_id'])) {
            $src = payout_get($token, 'select=amount&id=eq.' . urlencode($t['source_txn_id']))[0] ?? null;
            if ($src) $t['deposit'] = (float) $src['amount'];
        }
    }

    $isPay = in_array($kind, ['deposit', 'activation'], true);
    $title = ['payout' => 'PAYOUT RECEIPT', 'commission' => 'COMMISSION RECEIPT', 'deposit' => 'DEPOSIT RECEIPT', 'activation' => 'PLAN PAYMENT RECEIPT', 'basket' => 'MAIN BASKET CREDIT'][$kind] ?? 'RECEIPT';
    $pdf   = new MiniPdf();
    $ink   = [26, 25, 23]; $muted = [98, 94, 87]; $orange = [242, 101, 31]; $rust = [184, 66, 15]; $paper = [247, 242, 233];
    $L = 48; $R = MiniPdf::W - 48;
    $no = receipt_no($kind, $t['id']);
    $money = fn($v) => 'US$' . number_format((float) $v, 2);
    $dt = fn($v) => $v ? date('M j, Y · g:i A', strtotime($v)) : '—';

    // Header band.
    $pdf->rect(0, 0, MiniPdf::W, 96, $ink);
    $pdf->rect(0, 96, MiniPdf::W, 4, $orange);
    $pdf->text($L, 44, 'KAM TEAM', 24, true, $paper);
    $pdf->text($L, 66, 'KAM Growth Partnership Program', 10, false, $orange);
    $pdf->text($R, 42, $title, 14, true, $paper, 'right');
    $pdf->text($R, 62, $no, 11, false, $orange, 'right');

    if ($sample) {
        $pdf->rect($L, 116, $R - $L, 26, [255, 236, 222]);
        $pdf->text(MiniPdf::W / 2, 133, 'SAMPLE RECEIPT — for illustration only, not a real transaction', 10, true, $rust, 'center');
    }

    // Parties + meta.
    $y = $sample ? 170 : 140;
    $pdf->text($L, $y, ['payout' => 'PAID TO', 'commission' => 'EARNED BY', 'basket' => 'CREDITED TO'][$kind] ?? 'RECEIVED FROM', 8, true, $muted);
    $pdf->text($L, $y + 18, (string) ($t['owner']['full_name'] ?? '—'), 13, true);
    $pdf->text($L, $y + 34, '@' . ($t['owner']['username'] ?? '') . '   ·   ' . ($t['owner']['referral_code'] ?? ''), 10, false, $muted);

    $meta = [
        ['Receipt no.', $no],
        [$kind === 'payout' ? 'Requested' : ($isPay ? 'Submitted' : 'Date earned'), $dt($t['created_at'])],
        [$kind === 'payout' ? 'Paid on' : ($isPay ? 'Approved' : 'Recorded'), ($kind === 'payout' || $isPay) ? ($t['status'] === 'completed' ? $dt($t['reviewed_at'] ?? null) : '—') : $dt($t['created_at'])],
        ['Status', match ($t['status']) {
            'completed' => $kind === 'payout' ? 'PAID' : ($isPay ? 'APPROVED' : 'CREDITED'),
            'pending'   => $kind === 'payout' ? 'BEING SORTED' : 'PENDING REVIEW',
            'failed'    => 'REJECTED',
            default     => strtoupper($t['status']) }],
    ];
    $my = $y;
    foreach ($meta as [$k, $v]) {
        $pdf->text($R - 170, $my, $k, 9, false, $muted);
        $pdf->text($R, $my, $v, 9, true, $k === 'Status' ? ($t['status'] === 'failed' ? $rust : $ink) : $ink, 'right');
        $my += 16;
    }

    // Line items.
    $y += 84;
    $pdf->rect($L, $y, $R - $L, 24, [239, 232, 219]);
    $pdf->text($L + 10, $y + 16, 'DESCRIPTION', 8, true, $muted);
    $pdf->text($R - 10, $y + 16, 'AMOUNT', 8, true, $muted, 'right');
    $y += 24;
    $rows = [];
    $total = (float) $t['amount'];
    if ($kind === 'commission') {
        $isUl = str_contains((string) $t['notes'], 'Uni-Level');
        [, $typeLabel] = commission_label($t['notes']);
        $rows[] = [$isUl ? $typeLabel . ' — upline share of the advance deposit' : $typeLabel . ' — referrer share of the advance deposit', $money($t['amount']), true];
        $rows[] = ['Client: ' . ($t['source']['full_name'] ?? '—') . '   ·   Package: ' . ($t['packages']['name'] ?? '—'), '', false];
        if (!$isUl && $t['deposit']) {
            $rows[] = ['Setup fee ' . $money($t['deposit'] * 2) . '  >  50% deposit ' . $money($t['deposit']) . '  >  ' . (commission_label($t['notes'])[1]) . ' = ' . $money($t['amount']), '', false];
        }
        if (!empty($t['notes'])) $rows[] = [(string) $t['notes'], '', false];
    } elseif ($kind === 'deposit') {
        $setup = (float) $t['amount'] * 2;
        $rows[] = ['50% advance deposit — ' . ($t['packages']['name'] ?? 'Service package'), $money($t['amount']), true];
        $rows[] = ['Setup fee ' . $money($setup) . '   ·   Remaining balance ' . $money($t['balance_amount'] ?? $t['amount']), '', false];
        if (!empty($t['payment_ref'])) $rows[] = ['Deposit reference: ' . $t['payment_ref'], '', false];
        if (!empty($t['balance_paid_at'])) {
            $rows[] = ['Remaining 50% balance — paid ' . $dt($t['balance_paid_at']), $money($t['balance_amount'] ?? $t['amount']), true];
            if (!empty($t['balance_ref'])) $rows[] = ['Balance reference: ' . $t['balance_ref'] . '   ·   FULLY PAID', '', false];
            $total = (float) $t['amount'] + (float) ($t['balance_amount'] ?? $t['amount']);
        } else {
            $rows[] = ['Remaining balance not yet paid', '', false];
        }
        if (!empty($t['notes']) && !str_starts_with((string) $t['notes'], '50% advance deposit')) $rows[] = [(string) $t['notes'], '', false];
    } elseif ($kind === 'activation') {
        $rows[] = ['Growth Partner plan — ' . ($t['packages']['name'] ?? 'Partner plan'), $money($t['amount']), true];
        if (!empty($t['payment_ref'])) $rows[] = ['Payment reference: ' . $t['payment_ref'], '', false];
        if (!empty($t['notes'])) $rows[] = [(string) $t['notes'], '', false];
    } elseif ($kind === 'basket') {
        $rows[] = ['Uni-Level share to the KAM Main Basket', $money($t['amount']), true];
        $rows[] = ['Client: ' . ($t['source']['full_name'] ?? '—') . '   ·   Package: ' . ($t['packages']['name'] ?? '—'), '', false];
        if (!empty($t['notes'])) $rows[] = [(string) $t['notes'], '', false];
    } else {
        $d = payout_parse($t['notes']);
        $rows[] = ['Growth Partner payout — ' . payout_method_label($d), $money($t['amount']), true];
        $rows[] = ['Sent to: ' . ($sample ? $d['to'] : payout_mask($d['to'])), '', false];
        if (!empty($t['payment_ref'])) $rows[] = ['Reference: ' . $t['payment_ref'], '', false];
        if ($d['reason'] !== '') $rows[] = ['Not approved: ' . $d['reason'], '', false];
        if ($t['status'] === 'completed') {
            // Terms §5.6 statement: gross, fees, tax withheld, net.
            $fee = (float) ($t['payout_fee'] ?? 0);
            $tax = (float) ($t['payout_tax'] ?? 0);
            $rows[] = ['Transaction fees (bank / PayPal / network)', '- ' . $money($fee), false];
            $rows[] = ['Tax withheld', '- ' . $money($tax), false];
            $total = (float) $t['amount'] - $fee - $tax;
        }
    }
    foreach ($rows as [$desc, $amt, $main]) {
        $desc = mb_strlen($desc) > 95 ? mb_substr($desc, 0, 94) . '…' : $desc;
        $pdf->text($L + 10, $y + 18, $desc, $main ? 11 : 9, $main, $main ? $ink : $muted);
        if ($amt !== '') $pdf->text($R - 10, $y + 18, $amt, 11, true, $ink, 'right');
        $y += $main ? 26 : 18;
    }
    $y += 8;
    $pdf->line($L, $y, $R, $y);

    // Total.
    $y += 14;
    $pdf->rect($R - 230, $y, 230, 44, $ink);
    $pdf->text($R - 218, $y + 27, $kind === 'payout' ? ($t['status'] === 'completed' ? 'NET PAID' : 'REQUESTED') : ($isPay ? 'AMOUNT PAID' : 'TOTAL CREDITED'), 9, true, $paper);
    $pdf->text($R - 12, $y + 29, $money($total), 16, true, $orange, 'right');

    // Footer.
    $fy = MiniPdf::H - 96;
    $pdf->line($L, $fy, $R, $fy);
    if ($isPay) {
        $pdf->text($L, $fy + 18, 'This receipt records a payment made to KAM Team. A payment is final once its status reads APPROVED.', 8, false, $muted);
        $pdf->text($L, $fy + 30, 'Monthly fees are billed separately and are not part of this receipt.', 8, false, $muted);
    } else {
        $pdf->text($L, $fy + 18, 'Commission is paid on the 50% advance deposit of a one-time setup fee only — never on monthly fees,', 8, false, $muted);
        $pdf->text($L, $fy + 30, 'the setup balance or plan payments. Outright = 10% of the deposit; the direct upline earns 5% of that Outright (Uni-Level).', 8, false, $muted);
        $pdf->text($L, $fy + 42, 'Cutoff: Wednesdays 11:59 PM US Eastern; paid within 2-3 business days. Net = gross - transaction fees - tax withheld.', 8, false, $muted);
    }
    $pdf->text($L, $fy + 62, 'Generated ' . date('M j, Y g:i A') . '  ·  KAM Team  ·  Keep this receipt for your records.', 8, true, $ink);

    $bytes = $pdf->output(ucwords(strtolower($title)) . ' ' . $no);
    header('Content-Type: application/pdf');
    // ?print=1 opens the PDF in the browser viewer (print from there); otherwise it downloads.
    header('Content-Disposition: ' . (!empty($_GET['print']) ? 'inline' : 'attachment') . '; filename="' . $no . ($sample ? '-SAMPLE' : '') . '.pdf"');
    header('Content-Length: ' . strlen($bytes));
    header('Cache-Control: private, no-store');
    echo $bytes;
    exit;
}
