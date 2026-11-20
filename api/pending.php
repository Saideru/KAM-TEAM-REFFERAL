<?php
require_once __DIR__ . '/lib/supabase.php';
require_once __DIR__ . '/lib/logo.php';

// Approval wall: shown to every account an admin has not approved yet.
$token   = require_login();
$profile = current_profile($token);
if (!$profile) { supabase_sign_out_local(); header('Location: /index.php'); exit; }
if (!is_walled($profile)) { redirect_by_role(); }

$csrf     = csrf_token();
$rejected = $profile['status'] === 'rejected';
$first    = explode(' ', $profile['full_name'])[0];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <?php if (!$rejected): ?><meta http-equiv="refresh" content="60"><?php endif; ?>
  <title>Application <?= $rejected ? 'not approved' : 'under review' ?> // KAM Team</title>
  <link rel="icon" type="image/png" href="/assets/img/kam-team-mark.png">
  <link rel="stylesheet" href="/assets/styles.css?v=20261121a">
</head>
<body>
<header class="topbar">
  <div class="topbar-inner">
    <?= kam_lockup() ?>
    <form method="post" action="/logout.php" style="margin:0"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><button class="btn btn-ghost btn-sm" type="submit">Logout</button></form>
  </div>
</header>

<main class="wrap wall">
  <div class="terminal-header"><span class="dots"><span></span><span></span><span></span></span><span>access_control.sh // approval wall</span></div>
  <div class="terminal-body wall-body">
    <div class="prompt">$ kamteam --status <?= e($profile['username']) ?></div>
    <?php if ($rejected): ?>
      <span class="label" style="color:var(--red)"><span class="dot"></span>Access denied</span>
      <h1><?= e($first) ?>, your application <span class="accent">wasn't approved.</span></h1>
      <?php if (!empty($profile['review_note'])): ?><div class="alert alert-warn">Reason: <?= e($profile['review_note']) ?></div><?php endif; ?>
      <p class="output">If you think this is a mistake, contact the KAM Team admin who invited you.</p>
    <?php else: ?>
      <span class="label"><span class="dot pulse"></span>Awaiting admin approval</span>
      <h1>Thanks, <?= e($first) ?>. <span class="accent">You're in the queue.</span></h1>
      <p class="output">An admin reviews every new <?= e(role_name($profile['role'])) ?> application before the dashboard opens. This page checks again every minute.</p>
    <?php endif; ?>
    <div class="profile-info" style="margin:16px 0">
      <div class="profile-row"><span class="label">Applied as</span><span><?= e(role_name($profile['role'])) ?></span></div>
      <div class="profile-row"><span class="label">Username</span><span><?= e($profile['username']) ?></span></div>
      <div class="profile-row"><span class="label">Submitted</span><span><?= date('M j, Y H:i', strtotime($profile['created_at'])) ?></span></div>
      <div class="profile-row"><span class="label">Status</span><span class="pill <?= $rejected ? 'failed' : 'pending' ?>"><?= $rejected ? 'rejected' : 'pending review' ?></span></div>
    </div>
    <?php if (!$rejected): ?><a class="btn btn-sm" href="/pending.php">Check again →</a><?php endif; ?>
  </div>
</main>
</body>
</html>
