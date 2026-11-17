<?php
require_once __DIR__ . '/lib/supabase.php';
require_once __DIR__ . '/lib/logo.php';

['token' => $token, 'profile' => $profile] = require_role('client_basic');
$session = &_session();
$userId = $session['sb']['user_id'];
$notice = flash();
$csrf   = csrf_token();

$svcRes   = supabase_request('GET', '/rest/v1/client_services?select=*&client_id=eq.' . urlencode($userId) . '&order=started_at.desc', null, $token);
$services = is_array($svcRes['body']) && !$svcRes['error'] ? $svcRes['body'] : [];

$refRes    = supabase_request('GET', '/rest/v1/referrals?select=*,referred:profiles!referrals_referred_id_fkey(full_name)&referrer_id=eq.' . urlencode($userId) . '&order=created_at.desc', null, $token);
$referrals = is_array($refRes['body']) && !$refRes['error'] ? $refRes['body'] : [];

$txnRes = supabase_request('GET', '/rest/v1/transactions?select=*,packages(name)&user_id=eq.' . urlencode($userId) . '&order=created_at.desc', null, $token);
$txns   = is_array($txnRes['body']) && !$txnRes['error'] ? $txnRes['body'] : [];

$active      = count(array_filter($services, fn($s) => $s['status'] === 'active'));
$totalRefs   = count($referrals);
$commissions = array_sum(array_map(fn($t) => $t['type'] === 'commission' && $t['status'] === 'completed' ? (float) $t['amount'] : 0, $txns));

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
  <title>Basic Client // KAM Team</title>
  <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 20 20'><rect fill='%230a0a0f' width='20' height='20' rx='4'/><text x='3' y='15' font-size='13' fill='%2300ff88'>B</text></svg>">
  <link rel="stylesheet" href="/assets/styles.css">
</head>
<body>
<header class="topbar">
  <div class="topbar-inner">
    <?= kam_lockup() ?>
    <div style="display:flex;align-items:center;gap:10px">
      <?= notif_bell($token, $profile, $csrf) ?>
      <span class="role-label client_basic">Basic</span>
      <form method="post" action="/logout.php" style="margin:0"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><button class="btn btn-ghost btn-sm" type="submit">Logout</button></form>
    </div>
  </div>
</header>

<div class="shell">
<?= app_sidebar([
    ['label' => 'Overview',       'href' => '#overview', 'icon' => 'home'],
    ['label' => 'Your business',  'href' => '#business', 'icon' => 'brief'],
    ['label' => 'Referral link',  'href' => '#share',    'icon' => 'link'],
    ['label' => 'Services',       'href' => '#services', 'icon' => 'tool'],
    ['label' => 'Account',        'href' => '#account',  'icon' => 'user'],
    ['label' => 'Verify ID',      'href' => '/kyc.php',  'icon' => 'id'],
], 'Basic client') ?>
<main class="wrap">
  <span class="label"><span class="dot"></span>Basic client // <?= e($profile['referral_code'] ?? '') ?></span>
  <h1><?= isset($_GET['welcome']) ? 'Welcome, ' : '' ?><?= e($firstName) ?>. <span class="accent">Basic tier active.</span></h1>

  <?php if ($notice): ?><div class="alert alert-<?= e($notice['type']) ?>"><?= e($notice['message']) ?></div><?php endif; ?>
  <?= kyc_banner($profile) ?>

  <div id="overview"><?= lifecycle_countdown($profile, 'Account active for // 3-month window') ?></div>

  <section class="grid grid-3">
    <div class="card"><span class="label">01 // Active services</span><div class="big cyan"><?= $active ?></div></div>
    <div class="card"><span class="label">02 // Referrals</span><div class="big"><?= $totalRefs ?></div></div>
    <div class="card"><span class="label">03 // Commissions</span><div class="big green">$<?= number_format($commissions, 2) ?></div></div>
  </section>

  <?= business_card($token, $profile, $csrf) ?>

  <section class="card" id="share" style="margin-bottom:12px">
    <span class="label">Your referral link</span>
    <div class="share" style="margin-top:8px">
      <input id="share-link" type="text" readonly value="<?= e($shareLink) ?>">
      <button class="btn btn-sm" type="button" id="copy-btn">Copy →</button>
    </div>
  </section>

  <div class="cols">
    <section class="card" id="services">
      <h2>Your services</h2>
      <?php if (!$services): ?><p class="empty">No services assigned yet.</p>
      <?php else: ?><div class="svc-list">
        <?php foreach ($services as $s): ?>
          <div class="svc-item">
            <span class="svc-icon"><?= $serviceMeta[$s['service']] ?? '⚙️' ?></span>
            <div class="svc-body">
              <strong><?= e($s['service']) ?></strong> <span class="pill <?= e($s['status']) ?>"><?= e($s['status']) ?></span>
              <?php if ($s['notes']): ?><p style="margin:3px 0 0;font-size:12px;color:var(--text-dim)"><?= e($s['notes']) ?></p><?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div><?php endif; ?>
    </section>

    <section class="card" id="account">
      <h2>Your account</h2>
      <div class="profile-info">
        <div class="profile-row"><span class="label">Name</span><span><?= e($profile['full_name'] ?? '') ?></span></div>
        <div class="profile-row"><span class="label">Username</span><span><?= e($profile['username'] ?? '') ?></span></div>
        <div class="profile-row"><span class="label">Email</span><span><?= e($profile['email'] ?? '—') ?></span></div>
        <div class="profile-row"><span class="label">Phone</span><span><?= e($profile['phone'] ?? '—') ?></span></div>
        <div class="profile-row"><span class="label">Company</span><span><?= e($profile['company'] ?? '—') ?></span></div>
        <div class="profile-row"><span class="label">Code</span><span style="color:var(--cyan)"><?= e($profile['referral_code'] ?? '') ?></span></div>
        <div class="profile-row"><span class="label">Tier</span><span class="role-badge client_basic">Basic</span></div>
        <div class="profile-row"><span class="label">Since</span><span><?= date('M j, Y', strtotime($profile['created_at'] ?? 'now')) ?></span></div>
      </div>
      <?php if ($txns): ?>
        <h2 style="margin-top:18px">Commissions</h2>
        <div class="table-scroll"><table>
          <thead><tr><th>Type</th><th>Amount</th><th>Status</th><th>Date</th></tr></thead>
          <tbody><?php foreach ($txns as $t): ?>
            <tr><td><span class="pill"><?= e($t['type']) ?></span></td><td style="color:var(--green)">$<?= number_format((float) $t['amount'], 2) ?></td><td><span class="pill <?= e($t['status']) ?>"><?= e($t['status']) ?></span></td><td style="font-size:12px"><?= date('M j', strtotime($t['created_at'])) ?></td></tr>
          <?php endforeach; ?></tbody>
        </table></div>
      <?php endif; ?>
    </section>
  </div>
</main>
</div>
<script>
  document.getElementById('copy-btn').addEventListener('click', function () {
    var input = document.getElementById('share-link'), btn = this;
    input.select();
    (navigator.clipboard ? navigator.clipboard.writeText(input.value) : Promise.reject())
      .catch(function () { document.execCommand('copy'); })
      .finally(function () { btn.textContent = 'Copied ✓'; setTimeout(function () { btn.textContent = 'Copy →'; }, 1800); });
  });
</script>
<?= lifecycle_countdown_script() ?>
<?= sidebar_scrollspy_script() ?>
</body>
</html>
