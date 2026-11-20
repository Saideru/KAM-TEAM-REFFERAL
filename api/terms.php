<?php
require_once __DIR__ . '/lib/supabase.php';
require_once __DIR__ . '/lib/logo.php';

// Members who have not accepted the current program manual land here from
// require_role() and cannot reach their dashboard until they accept it.
$token   = require_login();
$profile = current_profile($token);
if (!$profile) { supabase_sign_out_local(); header('Location: /index.php'); exit; }
if (is_walled($profile)) { header('Location: /pending.php'); exit; }
if (!terms_outdated($profile)) { redirect_by_role(); }

$csrf    = csrf_token();
$errors  = [];
$checked = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check()) {
    $checked = terms_posted();
    foreach (terms_missing($checked) as $item) $errors[] = 'Read and accept ' . $item . '.';
    if (!$errors) {
        $res = supabase_request('POST', '/rest/v1/consents', terms_consent_record($profile['id']), $token, ['Prefer: return=minimal']);
        if ($res['error']) {
            $errors[] = 'Could not record your acceptance. Please try again.';
        } else {
            supabase_request('PATCH', '/rest/v1/profiles?id=eq.' . urlencode($profile['id']), ['terms_version' => TERMS_VERSION], $token, ['Prefer: return=minimal']);
            audit_log($token, 'terms_accepted', 'profile', $profile['id'],
                TERMS_PROGRAM . ' v' . TERMS_VERSION . ' — ' . count(TERMS_SECTIONS) . ' sections + e-signature'
                . (!empty($profile['terms_version']) ? ' (was v' . $profile['terms_version'] . ')' : ' (existing account)'));
            flash('success', 'Thank you — your acceptance of Manual v' . TERMS_VERSION . ' is recorded.');
            redirect_by_role();
        }
    }
}
$first = explode(' ', $profile['full_name'])[0];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Program terms // KAM Team</title>
  <link rel="icon" type="image/png" href="/assets/img/kam-team-mark.png">
  <link rel="stylesheet" href="/assets/styles.css?v=20261121e">
</head>
<body>
<header class="topbar">
  <div class="topbar-inner">
    <?= kam_lockup() ?>
    <form method="post" action="/logout.php" style="margin:0"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><button class="btn btn-ghost btn-sm" type="submit">Logout</button></form>
  </div>
</header>

<main class="wrap wall">
  <div class="terminal-header"><span class="dots"><span></span><span></span><span></span></span><span>terms.sh // program manual v<?= e(TERMS_VERSION) ?></span></div>
  <div class="terminal-body wall-body">
    <span class="label"><span class="dot pulse"></span>Action required</span>
    <h1><?= e($first) ?>, <span class="accent">please review the program terms.</span></h1>
    <p class="output">The <?= e(TERMS_PROGRAM) ?> manual is now version <?= e(TERMS_VERSION) ?><?= !empty($profile['terms_version']) ? ' (you accepted v' . e($profile['terms_version']) . ')' : '' ?>. Accept each section below to continue to your dashboard.</p>
    <?php if ($errors): ?><div class="alert alert-error"><?php foreach ($errors as $err): ?><div>> <?= e($err) ?></div><?php endforeach; ?></div><?php endif; ?>
    <form method="post" style="margin-top:16px">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <?= terms_checklist($checked, 'Program terms') ?>
      <button class="btn btn-solid" type="submit" id="terms-submit">I agree — continue <span aria-hidden="true">→</span></button>
    </form>
  </div>
</main>
<?= terms_checklist_script('terms-submit') ?>
</body>
</html>
