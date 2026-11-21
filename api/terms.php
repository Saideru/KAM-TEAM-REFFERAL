<?php
require_once __DIR__ . '/lib/supabase.php';
require_once __DIR__ . '/lib/logo.php';
require_once __DIR__ . '/lib/ui.php';

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
<?= ui_head([
    'title'       => 'Program terms // KAM Team',
    'description' => 'Read and accept the current KAM Growth Partnership Program manual.',
    'csrf'        => $csrf,
    'idle'        => true,
]) ?>
<?= ui_topbar(['csrf' => $csrf]) ?>

<main class="wrap wall" id="main" tabindex="-1">
  <?= ui_terminal_head('terms.sh // program manual v' . TERMS_VERSION) ?>
  <div class="terminal-body wall-body">
    <span class="label"><span class="dot pulse"></span>Action required</span>
    <h1><?= e($first) ?>, <span class="accent">please review the program terms.</span></h1>
    <p class="output">The <?= e(TERMS_PROGRAM) ?> manual is now version <?= e(TERMS_VERSION) ?><?= !empty($profile['terms_version']) ? ' (you accepted v' . e($profile['terms_version']) . ')' : '' ?>. Accept each section below to continue to your dashboard.</p>
    <?= ui_alerts(null, $errors) ?>
    <form method="post" style="margin-top:16px">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <?= terms_checklist($checked, 'Program terms', 'terms-submit') ?>
      <button class="btn btn-solid" type="submit" id="terms-submit">I agree — continue <span aria-hidden="true">→</span></button>
    </form>
  </div>
</main>
<?= ui_foot() ?>
