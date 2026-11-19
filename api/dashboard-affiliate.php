<?php
require_once __DIR__ . '/lib/supabase.php';
require_once __DIR__ . '/lib/logo.php';

['token' => $token, 'profile' => $profile] = require_role('affiliate');
$userId = $profile['id'];
$errors = [];
$notice = flash();
$csrf   = csrf_token();
$state  = affiliate_state($profile);          // none | active | grace
$tier   = $profile['affiliate_tier'] ?? null;

// Affiliate plans only — service packages are bought by clients.
$pkgRes   = supabase_request('GET', '/rest/v1/packages?select=*&kind=eq.affiliate&active=eq.true&order=price.asc', null, $token);
$packages = is_array($pkgRes['body']) && !$pkgRes['error'] ? $pkgRes['body'] : [];

$actRes  = supabase_request('GET', '/rest/v1/transactions?select=*,packages!transactions_package_id_fkey(name,tier)&type=eq.activation&user_id=eq.' . urlencode($userId) . '&order=created_at.desc&limit=5', null, $token);
$actTxns = is_array($actRes['body']) && !$actRes['error'] ? $actRes['body'] : [];
$pending = current(array_filter($actTxns, fn($t) => $t['status'] === 'pending')) ?: null;
$lastRejected = (!$pending && ($actTxns[0]['status'] ?? '') === 'failed' && !empty($actTxns[0]['reviewed_by'])) ? $actTxns[0] : null;

$pay      = get_settings($token, ['payment_qr', 'payment_account', 'payment_instructions']);
$payReady = $pay['payment_qr'] !== '';

$chRes    = supabase_request('GET', '/rest/v1/package_changes?select=package_id,summary,effective_at&status=eq.scheduled', null, $token);
$upcoming = [];
foreach ((is_array($chRes['body']) && !$chRes['error']) ? $chRes['body'] : [] as $ch) $upcoming[$ch['package_id']] = $ch;

// What each plan card offers right now (Manual §4, §6.4).
//   Basic:    only as a first plan — Basic cannot be renewed.
//   Standard: first plan, upgrade from Basic, or +2-year renewal.
$offer = function (array $pk) use ($tier): ?string {
    if ($pk['tier'] === 'basic')    return $tier === null ? 'Start free' : null;
    if ($tier === 'standard')       return 'Renew — adds 2 years';
    if ($tier === 'basic')          return 'Upgrade to Standard';
    return 'Choose Standard';
};

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check()) {
    $act = $_POST['action'] ?? '';

    if ($act === 'choose_package') {
        $pkgId = (string) ($_POST['package_id'] ?? '');
        $pkg   = current(array_filter($packages, fn($p) => $p['id'] === $pkgId)) ?: null;

        if (!$pkg || !$offer($pkg)) {
            $errors[] = 'That plan is not available for your account.';
        } elseif ($pending) {
            $errors[] = 'You already have a request waiting for admin approval.';
        } elseif ($pkg['tier'] === 'basic') {
            $res = supabase_request('POST', '/rest/v1/transactions', [
                'user_id' => $userId, 'package_id' => $pkg['id'], 'amount' => 0, 'type' => 'activation',
                'status' => 'pending', 'notes' => 'Free Basic Growth Partner plan request',
            ], $token, ['Prefer: return=minimal']);
            if (!$res['error']) {
                audit_log($token, 'plan_requested', 'profile', $userId, 'Requested Basic — ' . $pkg['name']);
                notify($token, 'Basic plan request', $profile['full_name'] . ' asked for ' . $pkg['name'] . ' (free).', null, 'admin', '/dashboard-admin.php#approvals');
                flash('success', 'Request sent. An admin will approve your Basic plan shortly.');
                header('Location: /dashboard-affiliate.php'); exit;
            }
            $errors[] = $res['status'] === 409 ? 'You already have a request waiting for admin approval.' : 'Could not send your request. Please try again.';
        } else {
            $refNo = trim((string) ($_POST['payment_ref'] ?? ''));
            if (!$payReady) {
                $errors[] = 'Payments are not open yet. Please contact an admin.';
            } elseif (($profile['kyc_status'] ?? 'not_submitted') !== 'approved') {
                $errors[] = 'Verify your identity before paying for Standard.';
            } elseif (!preg_match('/^[A-Za-z0-9 #_\-]{4,64}$/', $refNo)) {
                $errors[] = 'Enter the reference number from your payment receipt (4-64 letters/numbers).';
            } else {
                $res = supabase_request('POST', '/rest/v1/transactions', [
                    'user_id' => $userId, 'package_id' => $pkg['id'], 'amount' => (float) $pkg['price'],
                    'type' => 'activation', 'status' => 'pending', 'payment_ref' => $refNo,
                    'notes' => $offer($pkg) . ' — ' . $pkg['name'],
                ], $token, ['Prefer: return=minimal']);
                if (!$res['error']) {
                    audit_log($token, 'payment_submitted', 'profile', $userId, $offer($pkg) . ': ' . $pkg['name'] . ' $' . number_format((float) $pkg['price'], 2) . ' ref ' . $refNo);
                    notify($token, 'Payment to verify', $profile['full_name'] . ' paid $' . number_format((float) $pkg['price'], 2) . ' (' . $offer($pkg) . ') — ref ' . $refNo . '.', null, 'admin', '/dashboard-admin.php#approvals');
                    flash('success', 'Payment submitted. An admin will verify it and update your plan.');
                    header('Location: /dashboard-affiliate.php'); exit;
                }
                $errors[] = $res['status'] === 409 ? 'You already have a payment waiting for admin approval.' : 'Could not submit your payment. Please try again.';
            }
        }
    }

    if ($act === 'cancel_request' && $pending) {
        $res = supabase_request('PATCH', '/rest/v1/transactions?status=eq.pending&id=eq.' . urlencode($pending['id']) . '&user_id=eq.' . urlencode($userId),
            ['status' => 'failed', 'notes' => 'Cancelled by user'], $token, ['Prefer: return=minimal']);
        if (!$res['error']) {
            audit_log($token, 'payment_cancelled', 'transaction', $pending['id'], 'User cancelled plan request');
            flash('success', 'Request cancelled.');
            header('Location: /dashboard-affiliate.php'); exit;
        }
        $errors[] = 'Could not cancel the request.';
    }
}

$firstName  = explode(' ', $profile['full_name'])[0];
$graceEnds  = affiliate_grace_ends($profile);
$offered    = array_values(array_filter($packages, fn($p) => $offer($p) !== null));
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Growth Partner // KAM Team</title>
  <link rel="icon" type="image/png" href="/assets/img/kam-team-mark.png">
  <link rel="stylesheet" href="/assets/styles.css?v=20261120b">
</head>
<body>
<header class="topbar">
  <div class="topbar-inner">
    <?= kam_lockup() ?>
    <div style="display:flex;align-items:center;gap:10px">
      <?= notif_bell($token, $profile, $csrf) ?>
      <span class="role-label affiliate">Growth Partner<?= $tier ? ' · ' . e(tier_label($tier)) : '' ?></span>
      <form method="post" action="/logout.php" style="margin:0"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><button class="btn btn-ghost btn-sm" type="submit">Logout</button></form>
    </div>
  </div>
</header>

<div class="shell">
<?= app_sidebar([
    ['label' => 'Partner plan', 'href' => '#plans',    'icon' => 'box'],
    ['label' => 'Referral link',  'href' => '#share',    'icon' => 'link'],
    ['label' => 'Earnings',       'href' => '#earnings', 'icon' => 'cash'],
    ['label' => 'Your referrals', 'href' => '#network',  'icon' => 'tree'],
    ['label' => 'Your business',  'href' => '#business', 'icon' => 'brief'],
    ['label' => 'Verify ID',      'href' => '/kyc.php',  'icon' => 'id'],
], 'Growth Partner') ?>
<main class="wrap">
  <span class="label"><span class="dot" style="background:var(--yellow)"></span>KAM Growth Partnership Program // <?= e($profile['referral_code'] ?? '') ?></span>
  <h1><?= isset($_GET['welcome']) ? 'Welcome, ' : '' ?><?= e($firstName) ?>. <span class="accent" style="color:var(--yellow)"><?= $tier ? 'Refer clients, earn commission.' : 'Choose your plan.' ?></span></h1>

  <?php if ($notice): ?><div class="alert alert-<?= e($notice['type']) ?>"><?= e($notice['message']) ?></div><?php endif; ?>
  <?= kyc_banner($profile) ?>
  <?php if ($errors): ?><div class="alert alert-error"><?php foreach ($errors as $err): ?><div>> <?= e($err) ?></div><?php endforeach; ?></div><?php endif; ?>

  <?php if ($state === 'grace'): ?>
    <div class="alert alert-error"><strong>Your <?= e(tier_label($tier)) ?> term expired on <?= date('M j, Y', strtotime($profile['lifecycle_expires_at'])) ?>.</strong>
      You have until <strong><?= date('M j, Y', $graceEnds) ?></strong> to save it —
      <?= $tier === 'basic' ? 'refer 1 Qualified Client (+3 months) or upgrade to Standard for US$100.' : 'renew for US$100.' ?>
      After that the account is terminated, Pending commissions are forfeited and your Level 1 Growth Partners roll up to your upline.</div>
  <?php elseif ($state === 'active' && $tier === 'basic' && ($daysLeft = (int) ceil((strtotime($profile['lifecycle_expires_at']) - time()) / 86400)) <= EXPIRY_NOTICE_DAYS): ?>
    <div class="alert alert-warn"><strong>Only <?= $daysLeft ?> day<?= $daysLeft === 1 ? '' : 's' ?> left on your Basic account</strong> (ends <?= date('M j, Y', strtotime($profile['lifecycle_expires_at'])) ?>).
      To keep it, find a client — when a client you referred pays their deposit you get +3 months — or <a href="#plans">upgrade to Standard for US$100</a> (+2 years).</div>
  <?php endif; ?>

  <?php if ($tier): ?>
    <div id="overview"><?= lifecycle_countdown($profile, tier_label($tier) . ' Growth Partner term // +3 months per Qualified Client') ?></div>
  <?php endif; ?>

  <section id="plans" style="margin-bottom:20px">
    <div class="terminal-header"><span class="dots"><span></span><span></span><span></span></span><span>partner_plan.sh // <?= $tier ? 'Your plan: ' . e(tier_label($tier)) : 'Choose your Growth Partner plan' ?></span></div>
    <div class="terminal-body">
      <?php if ($pending): ?>
        <div class="pending-box">
          <span class="label" style="color:var(--rust)"><span class="dot"></span>Awaiting admin approval</span>
          <h3><?= e($pending['packages']['name'] ?? 'Plan') ?> — <?= (float) $pending['amount'] > 0 ? '$' . number_format((float) $pending['amount'], 2) : 'Free' ?></h3>
          <div class="profile-info">
            <?php if (!empty($pending['payment_ref'])): ?><div class="profile-row"><span class="label">Reference</span><span><?= e($pending['payment_ref']) ?></span></div><?php endif; ?>
            <div class="profile-row"><span class="label">Submitted</span><span><?= date('M j, Y H:i', strtotime($pending['created_at'])) ?></span></div>
            <div class="profile-row"><span class="label">Status</span><span class="pill pending">pending</span></div>
          </div>
          <form method="post" onsubmit="return confirm('Cancel this request?')" style="margin-top:12px"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="cancel_request">
            <button class="btn btn-ghost btn-sm" type="submit">Cancel request</button>
          </form>
        </div>
      <?php else: ?>
        <div class="output" style="margin-bottom:16px">
          <?php if (!$tier): ?>Basic is free for 3 months. Standard is US$100 per 2-year term (+3-month activation bonus) with unlimited renewal. An admin approves every plan.
          <?php elseif ($tier === 'basic'): ?>Basic cannot be renewed. Extend it by referring Qualified Clients (+3 months each), or upgrade to Standard: new expiry = payment date + 3 months + 2 years.
          <?php else: ?>Each US$100 payment adds 2 years to your current expiry. Payments stack.<?php endif; ?>
        </div>
        <?php if ($lastRejected): ?>
          <div class="alert alert-warn">Your last plan request<?= !empty($lastRejected['payment_ref']) ? ' (ref ' . e($lastRejected['payment_ref']) . ')' : '' ?> was not approved. <?= e(preg_replace('/^Rejected by admin\.\s*/', '', (string) ($lastRejected['notes'] ?? ''))) ?></div>
        <?php endif; ?>
        <?php if (!$offered): ?><p class="empty">No plans available right now.</p><?php endif; ?>

        <div class="tier-cards">
          <?php foreach ($offered as $pk): $std = $pk['tier'] === 'standard'; $label = $offer($pk); ?>
          <div class="tier-card <?= $std ? 'highlight' : '' ?>">
            <span class="label" style="color:var(--<?= $std ? 'orange' : 'green' ?>)"><?= $std ? 'Standard' : 'Basic' ?> Growth Partner</span>
            <h3 class="tier-name"><?= e($pk['name']) ?></h3>
            <div class="tier-price <?= $std ? 'orange' : '' ?>" style="<?= $std ? '' : 'color:var(--green)' ?>"><?= $std ? '$' . number_format((float) $pk['price'], 2) : 'Free' ?></div>
            <?php if (!empty($pk['description'])): ?><p class="tier-desc"><?= e($pk['description']) ?></p><?php endif; ?>
            <?php if (isset($upcoming[$pk['id']])): $up = $upcoming[$pk['id']]; ?><p class="tier-upcoming">Changing <?= date('M j, Y', strtotime($up['effective_at'])) ?>: <?= e($up['summary']) ?></p><?php endif; ?>
            <ul class="tier-features">
              <?php if ($std): ?>
                <li><?= $tier === 'standard' ? '+2 years on your current expiry' : '2-year term + 3-month activation bonus' ?></li>
                <li>Unlimited renewal · 30-day grace period</li>
                <li>Withdraw any time while active</li>
              <?php else: ?>
                <li>3-month term · 7-day grace period</li>
                <li>No renewal — extend by referrals or upgrade</li>
              <?php endif; ?>
              <li>+3 months per Qualified Client</li>
              <li><?= e(pct_label(commission_pct($token))) ?>% of each referred client's advance deposit</li>
            </ul>

            <?php if (!$std): ?>
              <form method="post"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="choose_package"><input type="hidden" name="package_id" value="<?= e($pk['id']) ?>">
                <button class="btn" type="submit" style="border-color:var(--green);color:var(--green)">Request free plan →</button>
              </form>
            <?php else: ?>
              <details class="paywall">
                <summary class="btn btn-solid"><?= e($label) ?> — pay $<?= number_format((float) $pk['price'], 2) ?> via QR →</summary>
                <div class="paywall-body">
                  <?php if (!$payReady): ?>
                    <p class="alert alert-warn" style="margin:0">Payments aren't open yet — an admin still needs to add the payment QR code.</p>
                  <?php elseif (($profile['kyc_status'] ?? 'not_submitted') !== 'approved'): ?>
                    <p class="alert alert-warn" style="margin:0"><?= ($profile['kyc_status'] ?? '') === 'pending' ? 'Your identity documents are being reviewed. You can pay once they are approved.' : 'Verify your identity before paying for Standard. <a href="/kyc.php">Verify now →</a>' ?></p>
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

  <?= affiliate_account_html($token, $profile, $state !== 'grace') ?>

  <div style="margin-top:20px"><?= business_card($token, $profile, $csrf) ?></div>
</main>
</div>
<?= lifecycle_countdown_script() ?>
<?= sidebar_scrollspy_script() ?>
</body>
</html>
