<?php
require_once __DIR__ . '/lib/supabase.php';
require_once __DIR__ . '/lib/logo.php';

['token' => $token, 'profile' => $profile] = require_role('referral');
$session = &_session();
$userId = $session['sb']['user_id'];
$errors = [];
$notice = flash();
$csrf   = csrf_token();

$pkgRes   = supabase_request('GET', '/rest/v1/packages?select=*&active=eq.true&order=price.asc', null, $token);
$packages = is_array($pkgRes['body']) && !$pkgRes['error'] ? $pkgRes['body'] : [];

$refRes    = supabase_request('GET', '/rest/v1/referrals?select=*,referred:profiles!referrals_referred_id_fkey(full_name)&referrer_id=eq.' . urlencode($userId) . '&order=created_at.desc', null, $token);
$referrals = is_array($refRes['body']) && !$refRes['error'] ? $refRes['body'] : [];

// The user's activation requests, newest first.
$actRes  = supabase_request('GET', '/rest/v1/transactions?select=*,packages!transactions_package_id_fkey(name,tier)&type=eq.activation&user_id=eq.' . urlencode($userId) . '&order=created_at.desc&limit=5', null, $token);
$actTxns = is_array($actRes['body']) && !$actRes['error'] ? $actRes['body'] : [];
$pending = current(array_filter($actTxns, fn($t) => $t['status'] === 'pending')) ?: null;
$lastRejected = (!$pending && ($actTxns[0]['status'] ?? '') === 'failed' && !empty($actTxns[0]['reviewed_by'])) ? $actTxns[0] : null;

$pay = get_settings($token, ['payment_qr', 'payment_account', 'payment_instructions']);
$payReady = $pay['payment_qr'] !== '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check()) {
    $act = $_POST['action'] ?? '';

    if ($act === 'choose_package') {
        $pkgId = (string) ($_POST['package_id'] ?? '');
        $pkg   = current(array_filter($packages, fn($p) => $p['id'] === $pkgId)) ?: null;

        if (!$pkg) {
            $errors[] = 'That package is no longer available.';
        } elseif ($pkg['tier'] === 'basic') {
            // Basic is free: activate immediately and record a $0 activation.
            $err = activate_account($token, $userId, 'basic');
            if (!$err) {
                supabase_request('POST', '/rest/v1/transactions', [
                    'user_id' => $userId, 'package_id' => $pkg['id'], 'amount' => 0, 'type' => 'activation',
                    'status' => 'completed', 'notes' => 'Free Basic activation',
                ], $token, ['Prefer: return=minimal']);
                audit_log($token, 'tier_activation', 'profile', $userId, 'Activated Basic — ' . $pkg['name']);
                flash('success', 'Basic account activated. Your countdown has started.');
                header('Location: /dashboard-client-basic.php?welcome=1'); exit;
            }
            $errors[] = $err;
        } else {
            // Standard: needs QR payment proof, then an admin must approve it.
            $refNo = trim((string) ($_POST['payment_ref'] ?? ''));
            if ($pending) {
                $errors[] = 'You already have a payment waiting for admin approval.';
            } elseif (!$payReady) {
                $errors[] = 'Payments are not open yet. Please contact an admin.';
            } elseif (!preg_match('/^[A-Za-z0-9 #_\-]{4,64}$/', $refNo)) {
                $errors[] = 'Enter the reference number from your payment receipt (4-64 letters/numbers).';
            } else {
                $res = supabase_request('POST', '/rest/v1/transactions', [
                    'user_id' => $userId, 'package_id' => $pkg['id'], 'amount' => (float) $pkg['price'],
                    'type' => 'activation', 'status' => 'pending', 'payment_ref' => $refNo,
                    'notes' => 'QR payment submitted for ' . $pkg['name'],
                ], $token, ['Prefer: return=minimal']);
                if (!$res['error']) {
                    audit_log($token, 'payment_submitted', 'profile', $userId, $pkg['name'] . ' $' . number_format((float) $pkg['price'], 2) . ' ref ' . $refNo);
                    flash('success', 'Payment submitted. An admin will verify it and activate your Standard account.');
                    header('Location: /dashboard-referral.php'); exit;
                }
                $errors[] = $res['status'] === 409 ? 'You already have a payment waiting for admin approval.' : 'Could not submit your payment. Please try again.';
            }
        }
    }

    if ($act === 'cancel_request' && $pending) {
        $res = supabase_request('PATCH', '/rest/v1/transactions?status=eq.pending&id=eq.' . urlencode($pending['id']) . '&user_id=eq.' . urlencode($userId),
            ['status' => 'failed', 'notes' => 'Cancelled by user'], $token, ['Prefer: return=minimal']);
        if (!$res['error']) {
            audit_log($token, 'payment_cancelled', 'transaction', $pending['id'], 'User cancelled activation request');
            flash('success', 'Request cancelled.');
            header('Location: /dashboard-referral.php'); exit;
        }
        $errors[] = 'Could not cancel the request.';
    }
}

$firstName = explode(' ', $profile['full_name'])[0];
$shareLink = APP_URL . '/index.php?ref=' . urlencode($profile['referral_code'] ?? '');
$totalRefs = count($referrals);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Choose Your Path // KAM Team</title>
  <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 20 20'><rect fill='%230a0a0f' width='20' height='20' rx='4'/><text x='3' y='15' font-size='13' fill='%23ffcc00'>R</text></svg>">
  <link rel="stylesheet" href="/assets/styles.css">
</head>
<body>
<header class="topbar">
  <div class="topbar-inner">
    <?= kam_lockup() ?>
    <div style="display:flex;align-items:center;gap:10px">
      <span class="role-label referral">Referral</span>
      <form method="post" action="/logout.php" style="margin:0"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><button class="btn btn-ghost btn-sm" type="submit">Logout</button></form>
    </div>
  </div>
</header>

<main class="wrap">
  <span class="label"><span class="dot" style="background:var(--yellow)"></span>Onboarding // <?= e($profile['referral_code'] ?? '') ?></span>
  <h1><?= isset($_GET['welcome']) ? 'Welcome, ' : '' ?><?= e($firstName) ?>. <span class="accent" style="color:var(--yellow)">Choose your path.</span></h1>

  <?php if ($notice): ?><div class="alert alert-<?= e($notice['type']) ?>"><?= e($notice['message']) ?></div><?php endif; ?>
  <?php if ($errors): ?><div class="alert alert-error"><?php foreach ($errors as $err): ?><div>> <?= e($err) ?></div><?php endforeach; ?></div><?php endif; ?>

  <section>
    <div class="terminal-header"><span class="dots"><span></span><span></span><span></span></span><span>tier_selector.sh // Choose your account tier</span></div>
    <div class="terminal-body">
      <div class="prompt">$ kamteam --select-tier</div>

      <?php if ($pending): ?>
        <div class="pending-box">
          <span class="label" style="color:var(--rust)"><span class="dot"></span>Awaiting admin approval</span>
          <h3><?= e($pending['packages']['name'] ?? 'Standard') ?> — $<?= number_format((float) $pending['amount'], 2) ?></h3>
          <div class="profile-info">
            <div class="profile-row"><span class="label">Reference</span><span><?= e($pending['payment_ref'] ?? '—') ?></span></div>
            <div class="profile-row"><span class="label">Submitted</span><span><?= date('M j, Y H:i', strtotime($pending['created_at'])) ?></span></div>
            <div class="profile-row"><span class="label">Status</span><span class="pill pending">pending</span></div>
          </div>
          <p class="output" style="margin:12px 0">An admin is verifying your payment. Your account switches to <strong><?= e(ucfirst($pending['packages']['tier'] ?? 'standard')) ?></strong> automatically once approved.</p>
          <form method="post" onsubmit="return confirm('Cancel this payment request?')"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="cancel_request">
            <button class="btn btn-ghost btn-sm" type="submit">Cancel request</button>
          </form>
        </div>
      <?php else: ?>
        <div class="output" style="margin-bottom:16px">Pick a plan. Basic is free and starts now. Standard is activated once an admin confirms your payment.</div>

        <?php if ($lastRejected): ?>
          <div class="alert alert-warn">Your last payment (ref <?= e($lastRejected['payment_ref'] ?? '—') ?>) was not approved. <?= e(preg_replace('/^Rejected by admin\.\s*/', '', (string) ($lastRejected['notes'] ?? ''))) ?> You can submit a new one below.</div>
        <?php endif; ?>

        <?php if (!$packages): ?><p class="empty">No plans available yet. Please check back soon.</p><?php endif; ?>

        <div class="tier-cards">
          <?php foreach ($packages as $i => $pk): $std = $pk['tier'] === 'standard'; ?>
          <div class="tier-card <?= $std ? 'highlight' : '' ?>">
            <span class="label" style="color:var(--<?= $std ? 'orange' : 'green' ?>)"><?= $std ? 'Standard' : 'Basic' ?> plan</span>
            <h3 class="tier-name"><?= e($pk['name']) ?></h3>
            <div class="tier-price <?= $std ? 'orange' : '' ?>" style="<?= $std ? '' : 'color:var(--green)' ?>"><?= $std ? '$' . number_format((float) $pk['price'], 2) : 'Free' ?></div>
            <?php if (!empty($pk['description'])): ?><p class="tier-desc"><?= e($pk['description']) ?></p><?php endif; ?>
            <ul class="tier-features">
              <?php if ($std): ?>
                <li>27-month base lifecycle</li>
                <li>+3 months per referral (unlimited)</li>
                <li>Full unilevel commission tree<?= (float) $pk['commission_unilevel'] > 0 ? ' — ' . e($pk['commission_unilevel']) . '%' : '' ?></li>
              <?php else: ?>
                <li>3-month active window</li>
                <li>Direct referral tracking</li>
              <?php endif; ?>
              <li>Outright commission: $<?= number_format((float) $pk['commission_outright'], 2) ?></li>
            </ul>

            <?php if (!$std): ?>
              <form method="post"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="choose_package"><input type="hidden" name="package_id" value="<?= e($pk['id']) ?>">
                <button class="btn" type="submit" style="border-color:var(--green);color:var(--green)">Activate free →</button>
              </form>
            <?php else: ?>
              <details class="paywall">
                <summary class="btn btn-solid">Pay $<?= number_format((float) $pk['price'], 2) ?> via QR →</summary>
                <div class="paywall-body">
                  <?php if (!$payReady): ?>
                    <p class="alert alert-warn" style="margin:0">Payments aren't open yet — an admin still needs to add the payment QR code.</p>
                  <?php else: ?>
                    <span class="label">Step 1 // Scan &amp; pay exactly $<?= number_format((float) $pk['price'], 2) ?></span>
                    <img class="paywall-qr" src="<?= e($pay['payment_qr']) ?>" alt="Payment QR code" width="220" height="220">
                    <?php if ($pay['payment_account'] !== ''): ?><p class="paywall-acct"><?= e($pay['payment_account']) ?></p><?php endif; ?>
                    <?php if ($pay['payment_instructions'] !== ''): ?><p class="paywall-note"><?= nl2br(e($pay['payment_instructions'])) ?></p><?php endif; ?>
                    <span class="label" style="display:block;margin-top:14px">Step 2 // Enter your receipt reference</span>
                    <form method="post"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="choose_package"><input type="hidden" name="package_id" value="<?= e($pk['id']) ?>">
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
      <?php endif; ?>
    </div>
  </section>

  <section class="card" style="margin:20px 0 12px">
    <span class="label">Start building your network — share your referral link</span>
    <div class="share" style="margin-top:8px">
      <input id="share-link" type="text" readonly value="<?= e($shareLink) ?>">
      <button class="btn btn-sm" type="button" id="copy-btn">Copy →</button>
    </div>
  </section>

  <?php if ($referrals): ?>
  <section class="card">
    <h2>Your referrals (<?= $totalRefs ?>)</h2>
    <div class="table-scroll"><table>
      <thead><tr><th>Name</th><th>Status</th><th>Joined</th></tr></thead>
      <tbody><?php foreach ($referrals as $r): ?>
        <tr><td><strong><?= e($r['referred']['full_name'] ?? '—') ?></strong></td><td><span class="pill <?= e($r['status']) ?>"><?= e($r['status']) ?></span></td><td style="font-size:12px"><?= date('M j, Y', strtotime($r['created_at'])) ?></td></tr>
      <?php endforeach; ?></tbody>
    </table></div>
  </section>
  <?php endif; ?>

</main>
<script>
  document.getElementById('copy-btn').addEventListener('click', function () {
    var input = document.getElementById('share-link'), btn = this;
    input.select();
    (navigator.clipboard ? navigator.clipboard.writeText(input.value) : Promise.reject())
      .catch(function () { document.execCommand('copy'); })
      .finally(function () { btn.textContent = 'Copied ✓'; setTimeout(function () { btn.textContent = 'Copy →'; }, 1800); });
  });
</script>
</body>
</html>
