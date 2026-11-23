<?php
// Admin "Client payments": every client whose 50% advance deposit was
// approved, split into two views —
//   50% paid    deposit approved, remaining 50% balance still due
//   Fully paid  remaining balance marked paid by an admin
// Each row shows who approved the deposit and who marked it fully paid;
// both actions are in the audit log under the admin's full name.
// Data: transactions (type deposit, status completed) + balance_* columns
// (migration 20261205).

/** Full name of the signed-in person (the real one, even when switched into a linked account). */
function actor_full_name(string $token): ?string
{
    static $cache = [];
    $id = session_base_user_id();
    if (!$id) return null;
    if (!array_key_exists($id, $cache)) {
        $cache[$id] = supabase_request('GET', '/rest/v1/profiles?select=full_name&id=eq.' . urlencode($id), null, $token)['body'][0]['full_name'] ?? null;
    }
    return $cache[$id];
}

/** POST pay_action=fully_paid. Returns [errorOrNull, message] or null. */
function client_payment_action(string $token, array $profile): ?array
{
    $act = (string) ($_POST['pay_action'] ?? '');
    if ($act !== 'fully_paid') return null;
    $id  = (string) ($_POST['txn_id'] ?? '');
    $ref = trim((string) ($_POST['balance_ref'] ?? ''));
    if (!preg_match('/^[A-Za-z0-9 #:_\-\.]{3,120}$/', $ref)) return ['Enter the payment reference for the remaining 50%.', ''];

    $t = supabase_request('GET', '/rest/v1/transactions?select=id,user_id,amount,balance_paid_at,packages!transactions_package_id_fkey(name),profiles!transactions_user_id_fkey(full_name)&type=eq.deposit&status=eq.completed&id=eq.' . urlencode($id), null, $token)['body'][0] ?? null;
    if (!$t) return ['That deposit was not found or is not approved yet.', ''];
    if (!empty($t['balance_paid_at'])) return ['This client is already marked fully paid.', ''];

    // Balance = the other half of the setup fee (the deposit was 50%).
    $bal = round((float) $t['amount'], 2);
    $res = supabase_request('PATCH', '/rest/v1/transactions?balance_paid_at=is.null&id=eq.' . urlencode($id), [
        'balance_paid_at' => gmdate('c'), 'balance_paid_by' => $profile['id'], 'balance_ref' => $ref, 'balance_amount' => $bal,
    ], $token, ['Prefer: return=representation']);
    if ($res['error']) return [str_contains((string) $res['error'], 'balance_') ? 'Run migration 20261205 first (it adds the Fully paid columns).' : $res['error'], ''];
    if (empty($res['body'])) return ['This client is already marked fully paid.', ''];

    $client = $t['profiles']['full_name'] ?? 'client';
    $pkg    = $t['packages']['name'] ?? 'package';
    audit_log($token, 'balance_paid', 'transaction', $id,
        'Marked ' . $client . ' fully paid — ' . $pkg . ' (remaining 50%: $' . number_format($bal, 2) . ', ref ' . $ref . ') by ' . ($profile['full_name'] ?? 'admin'));
    notify($token, 'Fully paid — ' . $pkg, 'Your remaining 50% balance of $' . number_format($bal, 2) . ' is confirmed. Thank you — your ' . $pkg . ' setup is fully paid.', $t['user_id']);
    return [null, $client . ' marked fully paid — ' . $pkg . '.'];
}

function client_payments_html(string $token, string $csrf, array $nameById): string
{
    $mode = ($_GET['pay'] ?? '') === 'full' ? 'full' : 'half';
    $rows = supabase_request('GET', '/rest/v1/transactions?select=id,user_id,amount,payment_ref,created_at,reviewed_at,reviewed_by,balance_paid_at,balance_paid_by,balance_ref,balance_amount,'
        . 'packages!transactions_package_id_fkey(name,price,monthly_price),profiles!transactions_user_id_fkey(full_name,username,company,deleted_at)'
        . '&type=eq.deposit&status=eq.completed&order=reviewed_at.desc', null, $token);
    $migrated = !$rows['error'];
    if (!$migrated) {
        // Before migration 20261205: no balance columns yet — show the 50% list only.
        $rows = supabase_request('GET', '/rest/v1/transactions?select=id,user_id,amount,payment_ref,created_at,reviewed_at,reviewed_by,'
            . 'packages!transactions_package_id_fkey(name,price,monthly_price),profiles!transactions_user_id_fkey(full_name,username,company,deleted_at)'
            . '&type=eq.deposit&status=eq.completed&order=reviewed_at.desc', null, $token);
    }
    $all  = array_values(array_filter(is_array($rows['body']) ? $rows['body'] : [], fn($r) => empty($r['profiles']['deleted_at'])));
    $half = array_values(array_filter($all, fn($r) => empty($r['balance_paid_at'])));
    $full = array_values(array_filter($all, fn($r) => !empty($r['balance_paid_at'])));
    usort($full, fn($a, $b) => strcmp((string) $b['balance_paid_at'], (string) $a['balance_paid_at']));
    $sum  = fn(array $list, string $k) => array_sum(array_map(fn($r) => (float) ($r[$k] ?? 0), $list));
    $who  = fn(?string $id) => $id ? ($nameById[$id] ?? 'former team member') : '—';
    $m    = fn($v) => '$' . number_format((float) $v, 2);
    $hid  = '<input type="hidden" name="csrf" value="' . e($csrf) . '">';

    $h = '<div class="card"><div class="net-head"><h2 style="margin:0">Client payments</h2><nav class="seg" aria-label="Payment view">'
       . '<a class="seg-btn' . ($mode === 'half' ? ' is-on' : '') . '" href="?pay=half#clientpay"' . ($mode === 'half' ? ' aria-current="true"' : '') . '>50% paid <span class="seg-n">' . count($half) . '</span></a>'
       . '<a class="seg-btn' . ($mode === 'full' ? ' is-on' : '') . '" href="?pay=full#clientpay"' . ($mode === 'full' ? ' aria-current="true"' : '') . '>Fully paid <span class="seg-n">' . count($full) . '</span></a>'
       . '</nav></div>';

    if (!$migrated) {
        $h .= '<div class="alert alert-warn" style="margin:10px 0 0">Run <code>supabase/migrations/20261205_approvals_full_payment.sql</code> to enable marking clients Fully paid.</div>';
    }

    if ($mode === 'half') {
        $h .= '<p class="hint" style="margin:10px 0 12px">Clients whose 50% advance deposit is approved — setup work can run. The remaining 50% is still due. When it is paid, enter the reference and mark the client <strong>Fully paid</strong>; your name is recorded and logged.</p>'
            . '<div class="downline-levels" style="margin-bottom:12px"><span class="chip">' . count($half) . ' client package' . (count($half) === 1 ? '' : 's') . '</span><span class="chip">' . $m($sum($half, 'amount')) . ' received</span><span class="chip">' . $m($sum($half, 'amount')) . ' balance due</span></div>';
        if (!$half) return $h . '<p class="empty">No clients at 50% right now.</p></div>';
        $h .= '<div class="net-tools" data-net-for="pay-half"><input type="search" class="net-q" placeholder="Search client, company, package or approver…" aria-label="Search 50% paid clients" autocomplete="off"><span class="net-count" aria-live="polite"></span></div>'
            . '<div class="table-scroll" tabindex="0" role="region" aria-label="50% paid clients"><table class="users-table" id="pay-half"><thead><tr>'
            . '<th scope="col">Client</th><th scope="col">Package</th><th scope="col" class="num">Setup fee</th><th scope="col" class="num">50% paid</th><th scope="col">Deposit approved</th><th scope="col" class="num">Balance due</th><th scope="col" class="ut-actions-h">Mark fully paid</th></tr></thead><tbody>';
        foreach ($half as $r) {
            $c = $r['profiles']; $p = $r['packages'] ?? [];
            $q = implode(' ', [$c['full_name'], '@' . $c['username'], $c['company'] ?? '', $p['name'] ?? '', $who($r['reviewed_by'] ?? null)]);
            $h .= '<tr data-q="' . e($q) . '"><td><strong>' . e($c['full_name']) . '</strong><span class="ut-sub">@' . e($c['username']) . (!empty($c['company']) ? ' · ' . e($c['company']) : '') . '</span></td>'
                . '<td>' . e($p['name'] ?? '—') . (!empty($p['monthly_price']) && (float) $p['monthly_price'] > 0 ? '<span class="ut-sub">then ' . $m($p['monthly_price']) . '/mo</span>' : '') . '</td>'
                . '<td class="num">' . $m((float) $r['amount'] * 2) . '</td>'
                . '<td class="num"><strong>' . $m($r['amount']) . '</strong><span class="ut-sub">ref ' . e($r['payment_ref'] ?? '—') . '</span>' . receipt_links('/dashboard-admin.php?receipt=', $r['id']) . '</td>'
                . '<td>' . e($r['reviewed_at'] ? date('M j, Y', strtotime($r['reviewed_at'])) : '—') . '<span class="ut-sub">by <strong>' . e($who($r['reviewed_by'] ?? null)) . '</strong></span></td>'
                . '<td class="num"><strong class="net-total">' . $m($r['amount']) . '</strong></td>'
                . '<td class="ut-actions">' . ($migrated
                    ? '<form method="post" class="pipe-act" onsubmit="return confirm(\'Mark ' . e(addslashes($c['full_name'])) . ' fully paid? This records your name.\')">' . $hid
                      . '<input type="hidden" name="pay_action" value="fully_paid"><input type="hidden" name="txn_id" value="' . e($r['id']) . '">'
                      . '<input name="balance_ref" required minlength="3" maxlength="120" placeholder="Payment ref" aria-label="Reference for the remaining 50%"><button class="btn btn-sm btn-solid" type="submit">Fully paid</button></form>'
                    : '<span class="hint">Needs migration</span>') . '</td></tr>';
        }
        return $h . '</tbody></table></div><p class="empty" id="pay-half-none" hidden>No clients match.</p></div>';
    }

    $h .= '<p class="hint" style="margin:10px 0 12px">Clients whose setup fee is fully paid — the 50% deposit and the remaining 50% balance. Shows who approved each payment.</p>'
        . '<div class="downline-levels" style="margin-bottom:12px"><span class="chip">' . count($full) . ' fully paid</span><span class="chip">' . $m($sum($full, 'amount') + $sum($full, 'balance_amount')) . ' collected</span></div>';
    if (!$full) return $h . '<p class="empty">No clients marked fully paid yet.</p></div>';
    $h .= '<div class="net-tools" data-net-for="pay-full"><input type="search" class="net-q" placeholder="Search client, company, package or approver…" aria-label="Search fully paid clients" autocomplete="off"><span class="net-count" aria-live="polite"></span></div>'
        . '<div class="table-scroll" tabindex="0" role="region" aria-label="Fully paid clients"><table class="users-table" id="pay-full"><thead><tr>'
        . '<th scope="col">Client</th><th scope="col">Package</th><th scope="col" class="num">Total paid</th><th scope="col">50% deposit</th><th scope="col">Remaining 50%</th><th scope="col">Status</th></tr></thead><tbody>';
    foreach ($full as $r) {
        $c = $r['profiles']; $p = $r['packages'] ?? [];
        $q = implode(' ', [$c['full_name'], '@' . $c['username'], $c['company'] ?? '', $p['name'] ?? '', $who($r['reviewed_by'] ?? null), $who($r['balance_paid_by'] ?? null)]);
        $h .= '<tr data-q="' . e($q) . '"><td><strong>' . e($c['full_name']) . '</strong><span class="ut-sub">@' . e($c['username']) . (!empty($c['company']) ? ' · ' . e($c['company']) : '') . '</span></td>'
            . '<td>' . e($p['name'] ?? '—') . '</td>'
            . '<td class="num"><strong class="net-total">' . $m((float) $r['amount'] + (float) ($r['balance_amount'] ?? 0)) . '</strong></td>'
            . '<td>' . $m($r['amount']) . ' · ' . e($r['reviewed_at'] ? date('M j, Y', strtotime($r['reviewed_at'])) : '—') . '<span class="ut-sub">approved by <strong>' . e($who($r['reviewed_by'] ?? null)) . '</strong></span></td>'
            . '<td>' . $m($r['balance_amount'] ?? 0) . ' · ' . e(date('M j, Y', strtotime($r['balance_paid_at']))) . '<span class="ut-sub">marked by <strong>' . e($who($r['balance_paid_by'] ?? null)) . '</strong> · ref ' . e($r['balance_ref'] ?? '—') . '</span></td>'
            . '<td><span class="pill stage-approved completed" style="background:var(--orange);border-color:var(--orange);color:#1A1917">Fully paid</span>' . receipt_links('/dashboard-admin.php?receipt=', $r['id']) . '</td></tr>';
    }
    return $h . '</tbody></table></div><p class="empty" id="pay-full-none" hidden>No clients match.</p></div>';
}
