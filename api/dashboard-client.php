<?php
require_once __DIR__ . '/lib/supabase.php';
require_once __DIR__ . '/lib/logo.php';
require_once __DIR__ . '/lib/work.php';

// The Client side of a member account — permanent. Buys service packages
// (50% advance deposit before setup starts), follows work progress and reads
// delivered reports. The Growth Partner side lives on /dashboard-affiliate.php.
['token' => $token, 'profile' => $profile] = require_role('client');
if (isset($_GET['view'])) { header('Location: /dashboard-affiliate.php'); exit; }
$userId = $profile['id'];
$errors = [];
$notice = flash();
$csrf   = csrf_token();

$pkgRes   = supabase_request('GET', '/rest/v1/packages?select=*&kind=eq.service&active=eq.true&order=price.asc', null, $token);
$packages = is_array($pkgRes['body']) && !$pkgRes['error'] ? $pkgRes['body'] : [];
$deposit  = fn(array $pk) => round((float) $pk['price'] * 0.5, 2);

$txnRes = supabase_request('GET', '/rest/v1/transactions?select=*,packages!transactions_package_id_fkey(name,price,monthly_price)&type=eq.deposit&user_id=eq.' . urlencode($userId) . '&order=created_at.desc', null, $token);
$deps   = is_array($txnRes['body']) && !$txnRes['error'] ? $txnRes['body'] : [];
$pendingDeps = array_values(array_filter($deps, fn($t) => $t['status'] === 'pending'));
$activeDeps  = array_values(array_filter($deps, fn($t) => $t['status'] === 'completed'));
$pendingPkg  = array_flip(array_column($pendingDeps, 'package_id'));

$pay      = get_settings($token, ['payment_qr', 'payment_account', 'payment_instructions']);
$payReady = $pay['payment_qr'] !== '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check()) {
    $act = $_POST['action'] ?? '';

    if ($act === 'pay_deposit') {
        $pkg   = current(array_filter($packages, fn($p) => $p['id'] === ($_POST['package_id'] ?? ''))) ?: null;
        $refNo = trim((string) ($_POST['payment_ref'] ?? ''));
        if (!$pkg) {
            $errors[] = 'That package is no longer available.';
        } elseif (isset($pendingPkg[$pkg['id']])) {
            $errors[] = 'You already have a deposit for this package waiting for approval.';
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
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Client // KAM Team</title>
  <link rel="icon" type="image/png" href="/assets/img/kam-team-mark.png">
  <link rel="stylesheet" href="/assets/styles.css?v=20261121b">
</head>
<body>
<header class="topbar">
  <div class="topbar-inner">
    <?= kam_lockup() ?>
    <div style="display:flex;align-items:center;gap:10px">
      <?= notif_bell($token, $profile, $csrf) ?>
      <span class="role-label client">Client</span>
      <form method="post" action="/logout.php" style="margin:0"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><button class="btn btn-ghost btn-sm" type="submit">Logout</button></form>
    </div>
  </div>
</header>

<div class="shell">
<?= app_sidebar([
    ['label' => 'Overview',           'href' => '#overview', 'icon' => 'home'],
    ['label' => 'Service packages',   'href' => '#packages', 'icon' => 'box'],
    ['label' => 'Work progress',      'href' => '#progress', 'icon' => 'check'],
    ['label' => 'Reports',            'href' => '#reports',  'icon' => 'log'],
    ['label' => 'Your business',      'href' => '#business', 'icon' => 'brief'],
    ['label' => 'Growth Partner →',   'href' => '/dashboard-affiliate.php', 'icon' => 'link'],
    ['label' => 'Verify ID',          'href' => '/kyc.php',  'icon' => 'id'],
], 'Client') ?>
<main class="wrap">
  <nav class="account-switch" aria-label="Account">
    <a href="/dashboard-client.php" class="on">Client account</a>
    <a href="/dashboard-affiliate.php">Growth Partner <small><?= e($profile['referral_code'] ?? '') ?><?= $profile['affiliate_tier'] ? ' · ' . e(tier_label($profile['affiliate_tier'])) : '' ?></small></a>
  </nav>

  <?php if ($notice): ?><div class="alert alert-<?= e($notice['type']) ?>"><?= e($notice['message']) ?></div><?php endif; ?>
  <?php if ($errors): ?><div class="alert alert-error"><?php foreach ($errors as $err): ?><div>> <?= e($err) ?></div><?php endforeach; ?></div><?php endif; ?>

  <span class="label"><span class="dot" style="background:var(--orange)"></span>Client // <?= e($profile['company'] ?: $profile['username']) ?></span>
  <h1><?= isset($_GET['welcome']) ? 'Welcome, ' : '' ?><?= e($firstName) ?>. <span class="accent" style="color:var(--rust)">Your KAM Team services.</span></h1>

  <section class="grid" id="overview">
    <div class="card"><span class="label">01 // Active packages</span><div class="big cyan"><?= count($activeDeps) ?></div></div>
    <div class="card"><span class="label">02 // Tasks in progress</span><div class="big"><?= $openTasks ?></div></div>
    <div class="card"><span class="label">03 // Tasks completed</span><div class="big green"><?= $doneTasks ?></div></div>
    <div class="card"><span class="label">04 // Reports</span><div class="big orange"><?= count($reports) ?></div></div>
  </section>
  <?php if ($contact): ?><p class="hint" style="margin:-4px 0 14px">Your KAM Team contact: <strong><?= e($contact) ?></strong></p><?php endif; ?>

  <section id="packages" style="margin-bottom:20px">
    <div class="terminal-header"><span class="dots"><span></span><span></span><span></span></span><span>service_packages.sh // 50% advance deposit to start</span></div>
    <div class="terminal-body">
      <div class="output" style="margin-bottom:14px">Prices in USD. A 50% down payment on the one-time setup fee is required before setup starts; the remaining 50% and the monthly fee are invoiced separately.</div>

      <?php foreach ($pendingDeps as $t): ?>
        <div class="pending-box" style="margin-bottom:12px">
          <span class="label" style="color:var(--rust)"><span class="dot"></span>Deposit awaiting approval</span>
          <h3><?= e($t['packages']['name'] ?? 'Package') ?> — $<?= number_format((float) $t['amount'], 2) ?></h3>
          <p class="hint">Ref <?= e($t['payment_ref'] ?? '—') ?> · submitted <?= date('M j, Y H:i', strtotime($t['created_at'])) ?></p>
          <form method="post" onsubmit="return confirm('Cancel this deposit request?')"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="cancel_deposit"><input type="hidden" name="txn_id" value="<?= e($t['id']) ?>"><button class="btn btn-ghost btn-sm" type="submit">Cancel request</button></form>
        </div>
      <?php endforeach; ?>

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
            <summary class="btn btn-solid">Pay 50% deposit — $<?= number_format($dep, 2) ?> →</summary>
            <div class="paywall-body">
              <?php if (!$payReady): ?>
                <p class="alert alert-warn" style="margin:0">Payments aren't open yet — please contact your KAM Team contact.</p>
              <?php else: ?>
                <span class="label">Step 1 // Scan &amp; pay exactly $<?= number_format($dep, 2) ?></span>
                <img class="paywall-qr" src="<?= e($pay['payment_qr']) ?>" alt="Payment QR code" width="220" height="220">
                <?php if ($pay['payment_account'] !== ''): ?><p class="paywall-acct"><?= e($pay['payment_account']) ?></p><?php endif; ?>
                <?php if ($pay['payment_instructions'] !== ''): ?><p class="paywall-note"><?= nl2br(e($pay['payment_instructions'])) ?></p><?php endif; ?>
                <span class="label" style="display:block;margin-top:14px">Step 2 // Enter your receipt reference</span>
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

  <section class="card" id="progress" style="margin-bottom:12px"><h2>Work progress</h2>
    <?php if ($activeDeps): ?>
      <div class="table-scroll" style="margin-bottom:12px"><table><thead><tr><th>Package</th><th>Deposit paid</th><th>Confirmed</th><th>Monthly</th></tr></thead><tbody>
        <?php foreach ($activeDeps as $t): ?><tr><td><strong><?= e($t['packages']['name'] ?? '—') ?></strong></td><td>$<?= number_format((float) $t['amount'], 2) ?></td><td style="font-size:12px"><?= date('M j, Y', strtotime($t['reviewed_at'] ?? $t['created_at'])) ?></td><td style="font-size:12px"><?= (float) ($t['packages']['monthly_price'] ?? 0) > 0 ? '$' . number_format((float) $t['packages']['monthly_price'], 2) . '/mo' : '—' ?></td></tr><?php endforeach; ?>
      </tbody></table></div>
    <?php endif; ?>
    <?php if (!$miles): ?><p class="empty">Work hasn't started yet. Milestones appear here once your KAM Team contact plans the work.</p>
    <?php else: ?><div class="table-scroll"><table><thead><tr><th>Package › milestone</th><th>Progress</th><th>Status</th></tr></thead><tbody>
      <?php foreach ($miles as $m): ?><tr><td><?= e($m['name']) ?></td><td><div class="lifecycle-bar" style="max-width:180px"><div class="lifecycle-fill ok" style="width:<?= $m['pct'] ?>%"></div></div><span class="hint"><?= $m['done'] ?> of <?= $m['total'] ?> tasks verified complete</span></td><td><?= status_pill($m['status']) ?></td></tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
  </section>

  <section class="card" id="reports" style="margin-bottom:12px"><h2>Reports</h2>
    <?php if (!$reports): ?><p class="empty">No reports yet. Weekly or monthly reports appear here once they are reviewed and approved.</p>
    <?php else: ?><div class="table-scroll"><table><thead><tr><th>Report</th><th>Package</th><th>Period</th><th>Received</th><th></th></tr></thead><tbody>
      <?php foreach ($reports as $r): ?><tr><td><?= e(REPORT_TYPES[$r['report_type']] ?? $r['report_type']) ?> <span class="hint">v<?= (int) $r['version'] ?></span></td><td style="font-size:12px"><?= e($r['packages']['name'] ?? 'All services') ?></td><td style="font-size:12px"><?= date('M j', strtotime($r['period_start'])) ?> – <?= date('M j, Y', strtotime($r['period_end'])) ?></td><td style="font-size:12px"><?= date('M j, Y', strtotime($r['delivered_at'])) ?></td><td><a class="btn btn-sm" href="/report.php?id=<?= e($r['id']) ?>">Open →</a></td></tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
  </section>

  <?= business_card($token, $profile, $csrf) ?>
</main>
</div>
<?= sidebar_scrollspy_script() ?>
</body>
</html>
