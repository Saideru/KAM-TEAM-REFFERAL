<?php
require_once __DIR__ . '/lib/supabase.php';
require_once __DIR__ . '/lib/logo.php';
require_once __DIR__ . '/lib/ui.php';

// Approval wall: shown to every account an admin has not approved yet.
$token   = require_login();
$profile = current_profile($token);
if (!$profile) { supabase_sign_out_local(); header('Location: /index.php'); exit; }
if (!is_walled($profile)) { redirect_by_role(); }

$csrf     = csrf_token();
$rejected = $profile['status'] === 'rejected';
$first    = explode(' ', $profile['full_name'])[0];
?>
<?= ui_head([
    'title'       => 'Application ' . ($rejected ? 'not approved' : 'under review') . ' // KAM Team',
    'description' => 'Your KAM Growth Partnership Program application status.',
    'csrf'        => $csrf,
    'idle'        => true,
    // The automatic re-check carries ?auto=1 so it is not mistaken for
    // activity by the inactivity watchdog (see session_touch()).
    'refresh'     => $rejected ? null : '60;url=/pending.php?auto=1',
]) ?>
<?= ui_topbar(['csrf' => $csrf]) ?>

<main class="wrap wall" id="main" tabindex="-1">
  <?= ui_terminal_head('access_control.sh // approval wall') ?>
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
<?= ui_foot() ?>
