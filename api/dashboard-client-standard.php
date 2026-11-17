<?php
require_once __DIR__ . '/lib/supabase.php';
require_once __DIR__ . '/lib/logo.php';

['token' => $token, 'profile' => $profile] = require_role('client_standard');
$session = &_session();
$userId = $session['sb']['user_id'];
$notice = flash();
$csrf   = csrf_token();

$svcRes   = supabase_request('GET', '/rest/v1/client_services?select=*&client_id=eq.' . urlencode($userId) . '&order=started_at.desc', null, $token);
$services = is_array($svcRes['body']) && !$svcRes['error'] ? $svcRes['body'] : [];

$refRes    = supabase_request('GET', '/rest/v1/referrals?select=*,referred:profiles!referrals_referred_id_fkey(full_name,username,role)&referrer_id=eq.' . urlencode($userId) . '&order=created_at.desc', null, $token);
$referrals = is_array($refRes['body']) && !$refRes['error'] ? $refRes['body'] : [];

$txnRes = supabase_request('GET', '/rest/v1/transactions?select=*,packages(name)&user_id=eq.' . urlencode($userId) . '&order=created_at.desc', null, $token);
$txns   = is_array($txnRes['body']) && !$txnRes['error'] ? $txnRes['body'] : [];

$active    = count(array_filter($services, fn($s) => $s['status'] === 'active'));
$totalRefs = count($referrals);
$outright  = array_sum(array_map(fn($t) => $t['type'] === 'commission' && $t['status'] === 'completed' ? (float) $t['amount'] : 0, $txns));

$firstName = explode(' ', $profile['full_name'])[0];
$shareLink = APP_URL . '/index.php?ref=' . urlencode($profile['referral_code'] ?? '');
$lifecycle = format_lifecycle($profile);

$serviceMeta = [
    'S-01 Marketing' => '📣', 'S-02 Virtual Assistance' => '💻', 'S-03 Video Editing' => '🎬',
    'S-04 Accounting' => '📊', 'S-05 Call Center Support' => '📞', 'S-06 Project Management' => '📋', 'S-07 AI Implementation' => '🤖',
];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Standard Client // KAM Team</title>
  <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 20 20'><rect fill='%230a0a0f' width='20' height='20' rx='4'/><text x='3' y='15' font-size='13' fill='%23F2651F'>S</text></svg>">
  <link rel="stylesheet" href="/assets/styles.css">
</head>
<body>
<header class="topbar">
  <div class="topbar-inner">
    <?= kam_lockup() ?>
    <div style="display:flex;align-items:center;gap:10px">
      <span class="role-label client_standard">Standard</span>
      <form method="post" action="/logout.php" style="margin:0"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><button class="btn btn-ghost btn-sm" type="submit">Logout</button></form>
    </div>
  </div>
</header>

<main class="wrap">
  <span class="label"><span class="dot" style="background:var(--orange)"></span>Standard client // <?= e($profile['referral_code'] ?? '') ?></span>
  <h1><?= isset($_GET['welcome']) ? 'Welcome, ' : '' ?><?= e($firstName) ?>. <span class="accent" style="color:var(--rust)">Standard tier — full access.</span></h1>

  <?php if ($notice): ?><div class="alert alert-<?= e($notice['type']) ?>"><?= e($notice['message']) ?></div><?php endif; ?>

  <div class="card" style="margin-bottom:12px">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
      <span class="label">Lifecycle // 27 months base + 3 per referral</span>
      <span style="font-size:12px;color:var(--rust)">+3 months per new referral</span>
    </div>
    <div style="display:flex;justify-content:space-between;align-items:baseline;margin:8px 0 6px">
      <span style="font-size:13px;color:var(--<?= $lifecycle['class'] === 'ok' ? 'green' : ($lifecycle['class'] === 'warn' ? 'yellow' : 'red') ?>)"><?= e($lifecycle['text']) ?></span>
      <span style="font-size:12px;color:var(--text-dim)"><?= isset($lifecycle['expires']) ? 'Expires: ' . e($lifecycle['expires']) : '' ?></span>
    </div>
    <div class="lifecycle-bar"><div class="lifecycle-fill <?= e($lifecycle['class']) ?>" style="width:<?= $lifecycle['pct'] ?>%"></div></div>
  </div>

  <section class="grid">
    <div class="card"><span class="label">01 // Services</span><div class="big cyan"><?= $active ?></div></div>
    <div class="card"><span class="label">02 // Referrals</span><div class="big"><?= $totalRefs ?></div></div>
    <div class="card"><span class="label">03 // Outright</span><div class="big green">$<?= number_format($outright, 2) ?></div></div>
    <div class="card"><span class="label">04 // Total earned</span><div class="big orange">$<?= number_format($outright, 2) ?></div></div>
  </section>

  <section class="card" style="margin-bottom:12px">
    <span class="label">Your referral link — share to extend your lifecycle</span>
    <div class="share" style="margin-top:8px">
      <input id="share-link" type="text" readonly value="<?= e($shareLink) ?>">
      <button class="btn btn-sm btn-orange" type="button" id="copy-btn">Copy →</button>
    </div>
  </section>

  <div class="cols">
    <div>
      <section class="card" style="margin-bottom:12px">
        <h2>Your referral network</h2>
        <?php if (!$referrals): ?><p class="empty">No referrals yet. Share your link.</p>
        <?php else: ?><div class="table-scroll"><table>
          <thead><tr><th>Name</th><th>Tier</th><th>Status</th><th>Joined</th></tr></thead>
          <tbody><?php foreach ($referrals as $r): ?>
            <tr>
              <td><strong><?= e($r['referred']['full_name'] ?? '—') ?></strong><br><span style="font-size:11px;color:var(--text-dim)"><?= e($r['referred']['username'] ?? '') ?></span></td>
              <td><span class="role-badge <?= e($r['referred']['role'] ?? 'referral') ?>"><?= e(str_replace('_', ' ', $r['referred']['role'] ?? '')) ?></span></td>
              <td><span class="pill <?= e($r['status']) ?>"><?= e($r['status']) ?></span></td>
              <td style="font-size:12px"><?= date('M j, Y', strtotime($r['created_at'])) ?></td>
            </tr>
          <?php endforeach; ?></tbody>
        </table></div><?php endif; ?>
      </section>

      <section class="card">
        <h2>Your services</h2>
        <?php if (!$services): ?><p class="empty">No services assigned yet.</p>
        <?php else: ?><div class="svc-list">
          <?php foreach ($services as $s): ?>
            <div class="svc-item">
              <span class="svc-icon"><?= $serviceMeta[$s['service']] ?? '⚙️' ?></span>
              <div class="svc-body"><strong><?= e($s['service']) ?></strong> <span class="pill <?= e($s['status']) ?>"><?= e($s['status']) ?></span>
                <?php if ($s['notes']): ?><p style="margin:3px 0 0;font-size:12px;color:var(--text-dim)"><?= e($s['notes']) ?></p><?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div><?php endif; ?>
      </section>
    </div>

    <div>
      <section class="card" style="margin-bottom:12px">
        <h2>Commission ledger</h2>
        <?php if (!$txns): ?><p class="empty">No transactions yet.</p>
        <?php else: ?><div class="table-scroll"><table>
          <thead><tr><th>Type</th><th>Amount</th><th>Package</th><th>Status</th><th>Date</th></tr></thead>
          <tbody><?php foreach ($txns as $t): ?>
            <tr>
              <td><span class="pill"><?= e($t['type']) ?></span></td>
              <td style="color:<?= $t['type'] === 'payout' ? 'var(--red)' : 'var(--green)' ?>">$<?= number_format((float) $t['amount'], 2) ?></td>
              <td style="font-size:12px"><?= e($t['packages']['name'] ?? '—') ?></td>
              <td><span class="pill <?= e($t['status']) ?>"><?= e($t['status']) ?></span></td>
              <td style="font-size:11px"><?= date('M j', strtotime($t['created_at'])) ?></td>
            </tr>
          <?php endforeach; ?></tbody>
        </table></div><?php endif; ?>
      </section>

      <section class="card">
        <h2>Account</h2>
        <div class="profile-info">
          <div class="profile-row"><span class="label">Name</span><span><?= e($profile['full_name'] ?? '') ?></span></div>
          <div class="profile-row"><span class="label">Username</span><span><?= e($profile['username'] ?? '') ?></span></div>
          <div class="profile-row"><span class="label">Email</span><span><?= e($profile['email'] ?? '—') ?></span></div>
          <div class="profile-row"><span class="label">Phone</span><span><?= e($profile['phone'] ?? '—') ?></span></div>
          <div class="profile-row"><span class="label">Company</span><span><?= e($profile['company'] ?? '—') ?></span></div>
          <div class="profile-row"><span class="label">Code</span><span style="color:var(--rust)"><?= e($profile['referral_code'] ?? '') ?></span></div>
          <div class="profile-row"><span class="label">Tier</span><span class="role-badge client_standard">Standard</span></div>
          <div class="profile-row"><span class="label">Since</span><span><?= date('M j, Y', strtotime($profile['created_at'] ?? 'now')) ?></span></div>
        </div>
      </section>
    </div>
  </div>
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
