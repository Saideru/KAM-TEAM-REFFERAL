<?php
require_once __DIR__ . '/lib/supabase.php';
require_once __DIR__ . '/lib/payouts.php';
require_once __DIR__ . '/lib/logo.php';
require_once __DIR__ . '/lib/work.php';
require_once __DIR__ . '/lib/ui.php';
require_once __DIR__ . '/lib/chat.php';
require_once __DIR__ . '/lib/projects.php';
require_once __DIR__ . '/lib/contract.php';
require_once __DIR__ . '/lib/intake.php';

// The Client side of a member account — permanent. Buys service packages
// (50% advance deposit before setup starts), follows work progress and reads
// delivered reports. The Growth Partner side lives on /dashboard-affiliate.php.
['token' => $token, 'profile' => $profile] = require_role('client');
if (isset($_GET['view']) && !is_client_only($profile)) { header('Location: /dashboard-affiliate.php'); exit; }
// Affiliate-only members haven't opened the Client side yet. Send them to
// the partner dashboard, where "Activate Client account" lives.
if (affiliate_only($profile)) { header('Location: /dashboard-affiliate.php'); exit; }
$userId = $profile['id'];
$errors = [];
$notice = flash();
$csrf   = csrf_token();

// Print / PDF receipt for one of this client's own payments.
if (isset($_GET['receipt'])) receipt_send($token, (string) $_GET['receipt'], $userId);

$pkgRes   = supabase_request('GET', '/rest/v1/packages?select=*&kind=eq.service&active=eq.true&order=price.asc', null, $token);
$packages = is_array($pkgRes['body']) && !$pkgRes['error'] ? $pkgRes['body'] : [];
$deposit  = fn(array $pk) => round((float) $pk['price'] * 0.5, 2);

$txnRes = supabase_request('GET', '/rest/v1/transactions?select=*,packages!transactions_package_id_fkey(name,price,monthly_price)&type=eq.deposit&user_id=eq.' . urlencode($userId) . '&order=created_at.desc', null, $token);
$deps   = is_array($txnRes['body']) && !$txnRes['error'] ? $txnRes['body'] : [];
$pendingDeps = array_values(array_filter($deps, fn($t) => $t['status'] === 'pending'));
$activeDeps  = array_values(array_filter($deps, fn($t) => $t['status'] === 'completed'));
$pendingPkg  = array_flip(array_column($pendingDeps, 'package_id'));

// Every agreement this client has, fetched once: the package cards, the
// deposit gate and the agreements table all read from it.
$myContracts   = contracts_for_client($token, $userId);
$contractOf    = contracts_by_package($myContracts);
$signedFor     = fn(string $pkgId): bool => (($contractOf[$pkgId]['status'] ?? '') === 'approved');

$pay      = get_settings($token, ['payment_qr', 'payment_account', 'payment_instructions']);
$payReady = $pay['payment_qr'] !== '';

// Messages: a client reaches the admins, and the staff an admin assigned
// to their account (api/lib/chat.php).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check() && ($chatRes = chat_action($token, $profile))) {
    if ($chatRes[0] === null) { header('Location: /dashboard-client.php?peer=' . urlencode((string) ($_POST['peer_id'] ?? '')) . '#messages'); exit; }
    $errors[] = $chatRes[0];
}

// The service agreement: the client completes it, e-signs it and sends it
// to an admin. Only a counter-signed agreement unlocks the deposit.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check() && ($conRes = contract_client_action($token, $profile))) {
    if ($conRes[0] === null) { flash('success', $conRes[1]); header('Location: /dashboard-client.php#packages'); exit; }
    $errors[] = $conRes[0];
}

// Roadmap: the client asks for work on their own project, or withdraws
// the ask. Staff answer it on the project board (api/lib/projects.php).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check() && ($roadRes = project_client_action($token, $profile))) {
    if ($roadRes[0] === null) { flash('success', $roadRes[1]); header('Location: /dashboard-client.php#roadmap'); exit; }
    $errors[] = $roadRes[0];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check()) {
    $act = $_POST['action'] ?? '';

    if ($act === 'pay_deposit') {
        $pkg   = current(array_filter($packages, fn($p) => $p['id'] === ($_POST['package_id'] ?? ''))) ?: null;
        $refNo = trim((string) ($_POST['payment_ref'] ?? ''));
        if (!$pkg) {
            $errors[] = 'That package is no longer available.';
        } elseif (isset($pendingPkg[$pkg['id']])) {
            $errors[] = 'You already have a deposit for this package waiting for approval.';
        } elseif (!kyc_approved($profile)) {
            $errors[] = 'Verify your ID before claiming a package — the deposit unlocks once your ID is approved.';
        } elseif (!contract_approved_for($token, $userId, $pkg['id'])) {
            // The gate, enforced on the server as well as in the page: no
            // deposit without an agreement signed by both parties.
            $errors[] = 'Sign the service agreement for this package first — the deposit opens once KAM Team counter-signs it.';
        } elseif (!$payReady) {
            $errors[] = 'Payments are not open yet. Please contact your KAM Team contact.';
        } elseif (!preg_match('/^[A-Za-z0-9 #_\-]{4,64}$/', $refNo)) {
            $errors[] = 'Enter the reference number from your payment receipt (4-64 letters/numbers).';
        } else {
            $amt = $deposit($pkg);
            $res = supabase_request('POST', '/rest/v1/transactions', [
                'user_id' => $userId, 'package_id' => $pkg['id'], 'amount' => $amt, 'type' => 'deposit',
                'status' => 'pending', 'payment_ref' => $refNo, 'notes' => '50% advance deposit — ' . $pkg['name'],
            ], $token, ['Prefer: return=minimal']);
            if (!$res['error']) {
                referral_stage_set($token, $userId, 'converted', ['stage_package_id' => $pkg['id']]);
                audit_log($token, 'deposit_submitted', 'profile', $userId, $pkg['name'] . ' deposit $' . number_format($amt, 2) . ' ref ' . $refNo);
                notify($token, 'Deposit to verify', $profile['full_name'] . ' paid the $' . number_format($amt, 2) . ' advance deposit for ' . $pkg['name'] . ' — ref ' . $refNo . '.', null, 'admin', '/dashboard-admin.php#approvals');
                flash('success', 'Deposit submitted. An admin will verify it, then setup work begins.');
                header('Location: /dashboard-client.php#packages'); exit;
            }
            $errors[] = $res['status'] === 409 ? 'You already have a deposit for this package waiting for approval.' : 'Could not submit your payment. Please try again.';
        }
    }

    if ($act === 'cancel_deposit') {
        $tid = (string) ($_POST['txn_id'] ?? '');
        $res = supabase_request('PATCH', '/rest/v1/transactions?status=eq.pending&type=eq.deposit&id=eq.' . urlencode($tid) . '&user_id=eq.' . urlencode($userId),
            ['status' => 'failed', 'notes' => 'Cancelled by client'], $token, ['Prefer: return=representation']);
        if (!$res['error'] && !empty($res['body'])) {
            audit_log($token, 'payment_cancelled', 'transaction', $tid, 'Client cancelled a deposit');
            flash('success', 'Deposit request cancelled.'); header('Location: /dashboard-client.php#packages'); exit;
        }
        $errors[] = 'Could not cancel that request.';
    }
}

$tasks   = client_tasks($token, $userId);
$miles   = milestones($tasks);
$repRes  = supabase_request('GET', '/rest/v1/client_reports?select=id,report_type,period_start,period_end,version,delivered_at,packages(name)&status=eq.delivered&client_id=eq.' . urlencode($userId) . '&order=delivered_at.desc', null, $token);
$reports = is_array($repRes['body']) && !$repRes['error'] ? $repRes['body'] : [];
$firstName = explode(' ', $profile['full_name'])[0];
$doneTasks = count(array_filter($tasks, fn($t) => $t['status'] === 'completed'));
$openTasks = count(array_filter($tasks, fn($t) => !in_array($t['status'], ['completed', 'cancelled'], true)));
$contact   = !empty($profile['assigned_admin'])
    ? (supabase_request('GET', '/rest/v1/profiles?select=full_name&id=eq.' . urlencode($profile['assigned_admin']), null, $token)['body'][0]['full_name'] ?? null) : null;
?>
<?= ui_head([
    'title'       => 'Client // KAM Team',
    'description' => 'Your KAM Team service packages, work progress and reports.',
    'csrf'        => $csrf,
    'idle'        => true,
]) ?>
<?= ui_topbar([
    'csrf'       => $csrf,
    'bell'       => notif_bell($token, $profile, $csrf),
    'help'       => tutorial_html('client', $token),
    'role'       => 'Client',
    'role_class' => 'client',
    // Switch to the Growth Partner side. Only link out: the Client
    // dashboard otherwise shows no partner content at all.
    // Only legacy dual-side rows have one; a plain Client account doesn't.
    'switch'     => is_client_only($profile) ? null : [
        'label' => 'Growth Partner',
        'href'  => '/dashboard-affiliate.php',
        'note'  => $profile['affiliate_tier'] ? tier_label($profile['affiliate_tier']) : 'No plan yet',
    ],
]) ?>

<div class="shell">
<?= app_sidebar(array_merge(
    [['label' => 'Overview', 'href' => '#overview', 'icon' => 'home'],
     ['label' => 'Messages', 'href' => '#messages', 'icon' => 'chat', 'count' => chat_unread_total($token, $profile)],
     ['label' => 'Agreements', 'href' => '#agreement', 'icon' => 'brief'],
     ['label' => 'Intake form', 'href' => '#intake', 'icon' => 'id']],
    side_group('My services', 'box', 'services', [
        ['label' => 'Service packages', 'href' => '#packages', 'icon' => 'box'],
        ['label' => 'Project roadmap',  'href' => '#roadmap',  'icon' => 'tree'],
        ['label' => 'Work progress',    'href' => '#progress', 'icon' => 'check'],
        ['label' => 'Reports',          'href' => '#reports',  'icon' => 'log'],
    ]),
    side_group('Account', 'user', 'account', [
        ['label' => 'Your client code', 'href' => '#client-code', 'icon' => 'link'],
        ['label' => 'Your business',    'href' => '#business',    'icon' => 'brief'],
        ['label' => 'Verify ID',        'href' => '/kyc.php',     'icon' => 'id'],
    ])
), 'Client') ?>
<main class="wrap" id="main" tabindex="-1">
  <?= ui_alerts($notice, $errors) ?>
  <?= kyc_approved($profile) ? '' : kyc_gate_notice($profile, 'packages') ?>

  <?= ui_page_title(
        'Client // ' . e($profile['company'] ?: $profile['username']),
        (isset($_GET['welcome']) ? 'Welcome, ' : '') . $firstName . '.',
        'Your KAM Team services.'
  ) ?>

  <?= ui_stat_grid([
      ['label' => 'Active packages',   'value' => count($activeDeps), 'tone' => 'cyan'],
      ['label' => 'Tasks in progress', 'value' => $openTasks],
      ['label' => 'Tasks completed',   'value' => $doneTasks,         'tone' => 'green'],
      ['label' => 'Reports',           'value' => count($reports),    'tone' => 'orange'],
  ], 'overview') ?>
  <?php if ($contact): ?><p class="hint" style="margin:-4px 0 14px">Your KAM Team contact: <strong><?= e($contact) ?></strong></p><?php endif; ?>

  <section id="packages" style="margin-bottom:20px">
    <?= ui_terminal_head('service_packages.sh // 50% advance deposit to start') ?>
    <div class="terminal-body">
      <div class="output" style="margin-bottom:14px">Prices in USD. A 50% down payment on the one-time setup fee is required before setup starts; the remaining 50% and the monthly fee are invoiced separately.</div>

      <?php foreach ($pendingDeps as $t): ?>
        <div class="pending-box" style="margin-bottom:12px">
          <span class="label" style="color:var(--rust)"><span class="dot"></span>Deposit awaiting approval</span>
          <h3><?= e($t['packages']['name'] ?? 'Package') ?> — $<?= number_format((float) $t['amount'], 2) ?></h3>
          <p class="hint">Ref <?= e($t['payment_ref'] ?? '—') ?> · submitted <?= date('M j, Y H:i', strtotime($t['created_at'])) ?> · Receipt <?= receipt_links('/dashboard-client.php?receipt=', $t['id']) ?></p>
          <form method="post" onsubmit="return confirm('Cancel this deposit request?')"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="cancel_deposit"><input type="hidden" name="txn_id" value="<?= e($t['id']) ?>"><button class="btn btn-ghost btn-sm" type="submit">Cancel request</button></form>
        </div>
      <?php endforeach; ?>

      <?php if ($deps): ?>
        <details class="rcpt-list" style="margin-bottom:14px"><summary class="btn btn-sm">Payment receipts (<?= count($deps) ?>)</summary>
          <div class="table-scroll" style="margin-top:10px"><table>
            <thead><tr><th>Package</th><th>Deposit</th><th>Status</th><th>Date</th><th>Receipt</th></tr></thead>
            <tbody><?php foreach ($deps as $t): ?><tr>
              <td><?= e($t['packages']['name'] ?? 'Package') ?></td>
              <td>$<?= number_format((float) $t['amount'], 2) ?><?= !empty($t['balance_paid_at']) ? '<span class="ut-sub">fully paid</span>' : '' ?></td>
              <td><span class="pill <?= e($t['status']) ?>"><?= e($t['status'] === 'completed' ? 'approved' : $t['status']) ?></span></td>
              <td style="font-size:12px"><?= date('M j, Y', strtotime($t['created_at'])) ?></td>
              <td><?= receipt_links('/dashboard-client.php?receipt=', $t['id']) ?></td>
            </tr><?php endforeach; ?></tbody>
          </table></div>
        </details>
      <?php endif; ?>

      <?php if (!$packages): ?><p class="empty">No packages available yet.</p><?php endif; ?>
      <div class="tier-cards">
        <?php foreach ($packages as $pk): $dep = $deposit($pk); ?>
        <div class="tier-card">
          <span class="label" style="color:var(--orange)">Service package</span>
          <h3 class="tier-name"><?= e($pk['name']) ?></h3>
          <div class="tier-price orange">$<?= number_format((float) $pk['price'], 2) ?></div>
          <p class="tier-desc" style="margin-top:-4px">One-time setup<?= (float) $pk['monthly_price'] > 0 ? ' · then <strong>$' . number_format((float) $pk['monthly_price'], 2) . '/month</strong>' : '' ?></p>
          <?php if (!empty($pk['description'])): ?><p class="tier-desc"><?= e($pk['description']) ?></p><?php endif; ?>
          <?php if (isset($pendingPkg[$pk['id']])): ?>
            <p class="pill pending">Deposit awaiting approval</p>
          <?php else: ?>
          <details class="paywall">
            <summary class="btn btn-solid"><?= $signedFor($pk['id']) ? 'Pay 50% deposit — $' . number_format($dep, 2) : 'Avail this service — sign the agreement' ?> →</summary>
            <div class="paywall-body">
              <?php if (!kyc_approved($profile)): ?>
                <p class="alert alert-warn" style="margin:0">Verify your ID to claim this package — the deposit unlocks once your ID is approved. <a href="/kyc.php">Verify now →</a></p>
              <?php elseif (!$signedFor($pk['id'])): ?>
                <?php $myContract = $contractOf[$pk['id']] ?? null; ?>
                <span class="label">Step 1 // Sign the service agreement</span>
                <p class="hint" style="margin:6px 0 10px">Before any payment, the contract pack for this package is signed by you and counter-signed by KAM Team. Nothing is charged by signing.</p>
                <?= contract_client_form_html($token, $profile, $csrf, $pk, $myContract) ?>
              <?php elseif (!$payReady): ?>
                <p class="alert alert-warn" style="margin:0">Payments aren't open yet — please contact your KAM Team contact.</p>
              <?php else: ?>
                <span class="label">Step 2 // Scan &amp; pay exactly $<?= number_format($dep, 2) ?></span>
                <img class="paywall-qr" src="<?= e($pay['payment_qr']) ?>" alt="Payment QR code" width="220" height="220">
                <?php if ($pay['payment_account'] !== ''): ?><p class="paywall-acct"><?= e($pay['payment_account']) ?></p><?php endif; ?>
                <?php if ($pay['payment_instructions'] !== ''): ?><p class="paywall-note"><?= nl2br(e($pay['payment_instructions'])) ?></p><?php endif; ?>
                <span class="label" style="display:block;margin-top:14px">Step 3 // Enter your receipt reference</span>
                <form method="post"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="pay_deposit"><input type="hidden" name="package_id" value="<?= e($pk['id']) ?>">
                  <div class="field" style="margin:6px 0 10px"><input name="payment_ref" required minlength="4" maxlength="64" pattern="[A-Za-z0-9 #_\-]{4,64}" placeholder="e.g. 1234 567 890123" autocomplete="off"></div>
                  <button class="btn btn-solid" type="submit">I've paid — submit for approval →</button>
                </form>
              <?php endif; ?>
            </div>
          </details>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <section class="card" id="intake" style="margin-bottom:12px"><h2>Your intake form</h2>
    <?= intake_client_html($token, $profile) ?>
  </section>

  <section class="card" id="agreement" style="margin-bottom:12px"><h2>Your service agreements</h2>
    <?php if (!$myContracts): ?>
      <p class="empty">No agreement yet. Pick a service package above — you sign its contract pack there, it goes to an admin for counter-signature, and the deposit opens once it comes back approved.</p>
    <?php else: ?>
      <div class="table-scroll" tabindex="0" role="region" aria-label="Your agreements"><table><thead><tr><th>Reference</th><th>Package</th><th>Setup / deposit</th><th>Status</th><th></th></tr></thead><tbody>
        <?php foreach ($myContracts as $c): ?>
          <tr><td><strong><?= e($c['reference']) ?></strong><br><span class="hint"><?= $c['client_signed_at'] ? 'signed ' . date('M j, Y', strtotime($c['client_signed_at'])) : 'not signed' ?></span></td>
            <td style="font-size:12px"><?= e($c['packages']['name'] ?? '—') ?></td>
            <td style="font-size:12px"><?= e(contract_money((float) $c['setup_fee'])) ?><br><span class="hint"><?= e(contract_money((float) $c['down_payment'])) ?> down</span></td>
            <td><?= contract_status_pill($c['status']) ?><?= $c['status'] === 'declined' && $c['decline_note'] ? '<br><span class="hint">' . e($c['decline_note']) . '</span>' : '' ?></td>
            <td><a class="btn btn-sm" href="/report.php?contract=<?= e($c['id']) ?>">Open →</a></td></tr>
        <?php endforeach; ?>
      </tbody></table></div>
    <?php endif; ?>
  </section>

  <section id="roadmap" style="margin-bottom:20px">
    <?= ui_terminal_head('roadmap.sh // your projects, milestone by milestone') ?>
    <div class="terminal-body">
      <div class="output" style="margin-bottom:14px">A project opens when your 50% advance deposit is confirmed. Every phase, its dates and the work inside it are below as your KAM Team lead moves them — and you can ask for work on any of them.</div>
      <?php $weekGroups = project_week_rows($token, $userId); ?>
      <?php if ($weekGroups['overdue'] || $weekGroups['week'] || $weekGroups['next'] || $weekGroups['done']): ?>
        <span class="label" style="display:block;margin-bottom:8px">This week on your projects</span>
        <?= project_week_html($weekGroups, false, false) ?>
        <div style="height:16px"></div>
      <?php endif; ?>
      <?= project_client_html($token, $profile, $csrf) ?>
    </div>
  </section>

  <section class="card" id="progress" style="margin-bottom:12px"><h2>Work progress</h2>
    <?php if ($activeDeps): ?>
      <div class="table-scroll" tabindex="0" role="region" aria-label="Active packages" style="margin-bottom:12px"><table><thead><tr><th>Package</th><th>Deposit paid</th><th>Confirmed</th><th>Monthly</th></tr></thead><tbody>
        <?php foreach ($activeDeps as $t): ?><tr><td><strong><?= e($t['packages']['name'] ?? '—') ?></strong></td><td>$<?= number_format((float) $t['amount'], 2) ?></td><td style="font-size:12px"><?= date('M j, Y', strtotime($t['reviewed_at'] ?? $t['created_at'])) ?></td><td style="font-size:12px"><?= (float) ($t['packages']['monthly_price'] ?? 0) > 0 ? '$' . number_format((float) $t['packages']['monthly_price'], 2) . '/mo' : '—' ?></td></tr><?php endforeach; ?>
      </tbody></table></div>
    <?php endif; ?>
    <?php if (!$miles): ?><p class="empty">Work hasn't started yet. Milestones appear here once your KAM Team contact plans the work.</p>
    <?php else: ?><div class="table-scroll" tabindex="0" role="region" aria-label="Work milestones"><table><thead><tr><th>Package › milestone</th><th>Progress</th><th>Status</th></tr></thead><tbody>
      <?php foreach ($miles as $m): ?><tr><td><?= e($m['name']) ?></td><td><div class="lifecycle-bar" style="max-width:180px"><div class="lifecycle-fill ok" style="width:<?= $m['pct'] ?>%"></div></div><span class="hint"><?= $m['done'] ?> of <?= $m['total'] ?> tasks verified complete</span></td><td><?= status_pill($m['status']) ?></td></tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
  </section>

  <?php $myCode = (string) ($profile['referral_code'] ?? ''); $mySuffix = preg_replace('/^KT-/', '', $myCode); ?>
  <section class="card share-card" id="client-code" style="margin-bottom:12px">
    <span class="label">Your client code</span>
    <?php if ($myCode !== ''): ?>
      <div class="share share-code" style="margin-top:8px">
        <div class="code-box"><span class="code-prefix" aria-hidden="true">KT-</span><input id="client-code-val" type="text" readonly value="<?= e($mySuffix) ?>" aria-label="Your client code, the characters after KT-"></div>
        <button class="btn btn-sm btn-solid" type="button" data-copy="#client-code-val">Copy code</button>
      </div>
      <p class="hint" style="margin:8px 0 0">Your unique KAM Team code. Quote it when you contact KAM Team about your account. Friends who sign up with it are linked to your account; to <strong>earn commission</strong> on the clients you refer, add a Growth Partner account.</p>
    <?php else: ?>
      <p class="hint" style="margin:8px 0 0">Your code is being created — it appears here shortly.</p>
    <?php endif; ?>
  </section>

  <section id="messages" style="margin-bottom:20px">
    <?= ui_terminal_head('messages.sh // your KAM Team contacts') ?>
    <div class="terminal-body">
      <?= chat_panel_html($token, $profile, $csrf, '/dashboard-client.php', chat_peer_param(), '#messages') ?>
      <p class="hint" style="margin:10px 0 0">You can message the KAM Team admins any time. A staff member appears here once an admin assigns them to your account.</p>
    </div>
  </section>

  <section class="card" id="reports" style="margin-bottom:12px"><h2>Reports</h2>
    <?php if (!$reports): ?><p class="empty">No reports yet. Weekly or monthly reports appear here once they are reviewed and approved.</p>
    <?php else: ?><div class="table-scroll" tabindex="0" role="region" aria-label="Delivered reports"><table><thead><tr><th>Report</th><th>Package</th><th>Period</th><th>Received</th><th></th></tr></thead><tbody>
      <?php foreach ($reports as $r): ?><tr><td><?= e(REPORT_TYPES[$r['report_type']] ?? $r['report_type']) ?> <span class="hint">v<?= (int) $r['version'] ?></span></td><td style="font-size:12px"><?= e($r['packages']['name'] ?? 'All services') ?></td><td style="font-size:12px"><?= date('M j', strtotime($r['period_start'])) ?> – <?= date('M j, Y', strtotime($r['period_end'])) ?></td><td style="font-size:12px"><?= date('M j, Y', strtotime($r['delivered_at'])) ?></td><td><a class="btn btn-sm" href="/report.php?id=<?= e($r['id']) ?>">Open →</a></td></tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
  </section>

  <?= business_card($token, $profile, $csrf) ?>
</main>
</div>
<?= ui_foot() ?>
