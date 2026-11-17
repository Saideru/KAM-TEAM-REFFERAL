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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check()) {
    $act = $_POST['action'] ?? '';

    if ($act === 'activate_basic') {
        $res = supabase_request('PATCH', '/rest/v1/profiles?id=eq.' . urlencode($userId), [
            'role' => 'client_basic', 'status' => 'accepted',
            'lifecycle_expires_at' => date('c', strtotime('+3 months')),
        ], $token, ['Prefer: return=minimal']);
        if (!$res['error']) {
            audit_log($token, 'tier_activation', 'profile', $userId, 'Activated Basic tier');
            flash('success', 'Basic account activated.');
            header('Location: /dashboard-client-basic.php?welcome=1'); exit;
        }
        $errors[] = $res['error'] ?? 'Activation failed.';
    }

    if ($act === 'activate_standard') {
        $txnRes2 = supabase_request('POST', '/rest/v1/transactions', [
            'user_id' => $userId, 'amount' => 100.00, 'type' => 'activation', 'status' => 'pending', 'notes' => 'Standard tier activation fee',
        ], $token, ['Prefer: return=representation']);

        if ($txnRes2['error']) {
            $errors[] = 'Could not process activation.';
        } else {
            $txnId = $txnRes2['body'][0]['id'] ?? null;
            if ($txnId) supabase_request('PATCH', '/rest/v1/transactions?id=eq.' . urlencode($txnId), ['status' => 'completed'], $token, ['Prefer: return=minimal']);

            $res = supabase_request('PATCH', '/rest/v1/profiles?id=eq.' . urlencode($userId), [
                'role' => 'client_standard', 'status' => 'accepted',
                'lifecycle_expires_at' => date('c', strtotime('+27 months')),
            ], $token, ['Prefer: return=minimal']);

            if (!$res['error']) {
                audit_log($token, 'tier_activation', 'profile', $userId, 'Activated Standard ($100)');
                flash('success', 'Standard account activated. Full access unlocked.');
                header('Location: /dashboard-client-standard.php?welcome=1'); exit;
            }
            $errors[] = $res['error'] ?? 'Activation failed.';
        }
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
  <title>Choose Your Path // KAMTEAM</title>
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
      <div class="output" style="margin-bottom:16px">Two paths available. Choose wisely — you can always upgrade later.</div>

      <div class="tier-cards">
        <div class="tier-card">
          <span class="label" style="color:var(--green)">Option A</span>
          <h3 style="margin:8px 0 0;font-family:var(--font-head);font-size:20px">Basic Account</h3>
          <div class="tier-price" style="color:var(--green)">Free</div>
          <ul class="tier-features">
            <li>3-month active window</li>
            <li>Direct referral tracking</li>
            <li>Outright commissions per sale</li>
            <li>Service tracking dashboard</li>
            <li>Auto-purge after expiration</li>
          </ul>
          <form method="post"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="activate_basic">
            <button class="btn" type="submit" style="border-color:var(--green);color:var(--green)">Activate Basic →</button>
          </form>
        </div>

        <div class="tier-card highlight">
          <span class="label" style="color:var(--orange)">Option B // Recommended</span>
          <h3 style="margin:8px 0 0;font-family:var(--font-head);font-size:20px">Standard Account</h3>
          <div class="tier-price orange">$100</div>
          <ul class="tier-features">
            <li>27-month base lifecycle</li>
            <li>+3 months per referral (unlimited)</li>
            <li>Full unilevel commission tree</li>
            <li>Outright + residual commissions</li>
            <li>Priority service access</li>
            <li>Complete analytics dashboard</li>
          </ul>
          <form method="post"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="activate_standard">
            <button class="btn btn-solid" type="submit" style="background:var(--orange);border-color:var(--orange);color:var(--bg)">Pay $100 &amp; Activate →</button>
          </form>
        </div>
      </div>
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

  <?php if ($packages): ?>
  <section class="card" style="margin-top:12px">
    <h2>Available packages</h2>
    <div class="table-scroll"><table>
      <thead><tr><th>Package</th><th>Tier</th><th>Price</th><th>Outright</th><th>Unilevel</th></tr></thead>
      <tbody><?php foreach ($packages as $pk): ?>
        <tr>
          <td><strong><?= e($pk['name']) ?></strong><br><span style="font-size:11px;color:var(--text-dim)"><?= e($pk['description'] ?? '') ?></span></td>
          <td><span class="role-badge <?= $pk['tier'] === 'standard' ? 'client_standard' : 'client_basic' ?>"><?= e($pk['tier']) ?></span></td>
          <td>$<?= number_format((float) $pk['price'], 2) ?></td>
          <td style="color:var(--green)">$<?= number_format((float) $pk['commission_outright'], 2) ?></td>
          <td><?= (float) $pk['commission_unilevel'] > 0 ? e($pk['commission_unilevel']) . '%' : '—' ?></td>
        </tr>
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
