<?php
require_once __DIR__ . '/lib/supabase.php';
require_once __DIR__ . '/lib/logo.php';
require_once __DIR__ . '/lib/ui.php';

// Reachable before a plan is chosen: Standard can't be paid for without
// approved identity documents.
define('PLAN_GATE_EXEMPT', true);

['token' => $token, 'profile' => $profile] = require_role('client');
$userId = $profile['id'];
$errors = [];
$notice = flash();
$csrf   = csrf_token();

$kyc = kyc_submission($token, $userId, true);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check()) {
    $legal  = trim((string) ($_POST['legal_name'] ?? ''));
    $idType = (string) ($_POST['id_type'] ?? '');
    $idNum  = trim((string) ($_POST['id_number'] ?? ''));
    $dob    = trim((string) ($_POST['dob'] ?? ''));
    $addr   = trim((string) ($_POST['address'] ?? ''));

    if ($legal === '')                             $errors[] = 'Enter your full legal name as printed on your ID.';
    if (!isset(KYC_ID_TYPES[$idType]))             $errors[] = 'Choose which ID you are submitting.';
    if ($idNum === '')                             $errors[] = 'Enter the ID number.';
    if ($dob !== '' && !strtotime($dob))           $errors[] = 'Enter a valid date of birth.';

    $images = [];
    foreach (['id_front' => 'Front of ID', 'id_back' => 'Back of ID', 'selfie' => 'Selfie with ID'] as $field => $label) {
        $uri = image_data_uri($_FILES[$field] ?? null, 1500 * 1024, $err);
        if ($err) $errors[] = $label . ': ' . $err;
        if ($uri) $images[$field] = $uri;
    }

    // First submission must include the front of the ID; a resubmission can
    // keep the images already on file.
    if (empty($images['id_front']) && empty($kyc['id_front'])) {
        $errors[] = 'Upload a photo of the front of your ID.';
    }

    if (!$errors) {
        $err = submit_kyc($token, $userId, [
            'legal_name' => mb_substr($legal, 0, 200),
            'dob'        => $dob,
            'address'    => mb_substr($addr, 0, 500),
            'id_type'    => $idType,
            'id_number'  => mb_substr($idNum, 0, 100),
        ] + $images);

        if ($err) {
            $errors[] = $err;
        } else {
            flash('success', 'Documents submitted. An admin will review them shortly.');
            header('Location: /kyc.php'); exit;
        }
    }
}

$status  = $kyc['status'] ?? 'not_submitted';
$locked  = $status === 'pending' || $status === 'approved';
$dashUrl = dashboard_url($profile['role']);

$badge = [
    'not_submitted' => ['Not verified', 'pending'],
    'pending'       => ['In review',    'pending'],
    'approved'      => ['Verified',     'active'],
    'rejected'      => ['Rejected',     'failed'],
][$status];
?>
<?= ui_head([
    'title'       => 'Identity verification // KAM Team',
    'description' => 'Submit a government ID so your Growth Partner account can be verified.',
    'csrf'        => $csrf,
    'idle'        => true,
]) ?>
<?= ui_topbar([
    'csrf'  => $csrf,
    'links' => [['label' => 'Dashboard', 'href' => $dashUrl]],
]) ?>

<main class="wrap" id="main" tabindex="-1">
  <span class="label"><span class="dot"></span>Identity verification</span>
  <h1>Verify your <span class="accent">identity</span>.</h1>

  <?= ui_alerts($notice, $errors) ?>

  <div class="card" style="margin-bottom:12px">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
      <span class="label">Status</span>
      <span class="pill <?= e($badge[1]) ?>"><?= e($badge[0]) ?></span>
    </div>
    <?php if ($status === 'approved'): ?>
      <p style="margin:8px 0 0;font-size:13px;color:var(--green)">Your identity is verified. Nothing further is needed.</p>
    <?php elseif ($status === 'pending'): ?>
      <p style="margin:8px 0 0;font-size:13px;color:var(--text-dim)">Submitted <?= e(date('M j, Y H:i', strtotime($kyc['created_at']))) ?>. An admin is reviewing your documents.</p>
    <?php elseif ($status === 'rejected'): ?>
      <p style="margin:8px 0 0;font-size:13px;color:var(--red)"><?= e($kyc['note'] ?: 'Your documents could not be verified.') ?> Correct the details below and submit again.</p>
    <?php else: ?>
      <p style="margin:8px 0 0;font-size:13px;color:var(--text-dim)">Verification is required before you can request a paid Standard plan.</p>
    <?php endif; ?>
  </div>

  <?php if ($locked): ?>
    <section class="card">
      <h2>Submitted details</h2>
      <div class="profile-info">
        <div class="profile-row"><span class="label">Legal name</span><span><?= e($kyc['legal_name']) ?></span></div>
        <div class="profile-row"><span class="label">ID type</span><span><?= e(KYC_ID_TYPES[$kyc['id_type']] ?? $kyc['id_type']) ?></span></div>
        <div class="profile-row"><span class="label">ID number</span><span><?= e($kyc['id_number']) ?></span></div>
        <?php if ($kyc['dob']): ?><div class="profile-row"><span class="label">Date of birth</span><span><?= e(date('M j, Y', strtotime($kyc['dob']))) ?></span></div><?php endif; ?>
        <?php if ($kyc['address']): ?><div class="profile-row"><span class="label">Address</span><span><?= e($kyc['address']) ?></span></div><?php endif; ?>
      </div>
      <?php if ($status === 'pending'): ?>
        <p style="margin:12px 0 0;font-size:12px;color:var(--text-dim)">You can't edit these while they're in review.</p>
      <?php endif; ?>
    </section>
  <?php else: ?>
    <section class="card">
      <h2><?= $status === 'rejected' ? 'Submit again' : 'Submit your documents' ?></h2>
      <p style="font-size:13px;color:var(--text-dim);margin:0 0 14px">Details must match your ID exactly. Images stay private and are only seen by KAM Team admins.</p>

      <form method="post" enctype="multipart/form-data" novalidate>
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">

        <div class="field">
          <label for="k-legal">Full legal name</label>
          <input id="k-legal" name="legal_name" type="text" required maxlength="200" value="<?= e($kyc['legal_name'] ?? $profile['full_name']) ?>" placeholder="As printed on your ID">
        </div>

        <div class="row">
          <div class="field">
            <label for="k-type">ID type</label>
            <select id="k-type" name="id_type" required>
              <option value="">Choose…</option>
              <?php foreach (KYC_ID_TYPES as $v => $l): ?>
                <option value="<?= e($v) ?>" <?= ($kyc['id_type'] ?? '') === $v ? 'selected' : '' ?>><?= e($l) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label for="k-num">ID number</label>
            <input id="k-num" name="id_number" type="text" required maxlength="100" value="<?= e($kyc['id_number'] ?? '') ?>">
          </div>
        </div>

        <div class="row">
          <div class="field">
            <label for="k-dob">Date of birth</label>
            <input id="k-dob" name="dob" type="date" value="<?= e($kyc['dob'] ?? '') ?>">
          </div>
          <div class="field">
            <label for="k-addr">Address</label>
            <input id="k-addr" name="address" type="text" maxlength="500" value="<?= e($kyc['address'] ?? '') ?>">
          </div>
        </div>

        <div class="field">
          <label for="k-front">Front of ID <span style="opacity:.6">(PNG/JPG/WEBP, max 1.5 MB)</span></label>
          <input id="k-front" name="id_front" type="file" accept="image/png,image/jpeg,image/webp">
        </div>
        <div class="row">
          <div class="field">
            <label for="k-back">Back of ID <span style="opacity:.6">(optional)</span></label>
            <input id="k-back" name="id_back" type="file" accept="image/png,image/jpeg,image/webp">
          </div>
          <div class="field">
            <label for="k-selfie">Selfie holding your ID <span style="opacity:.6">(optional)</span></label>
            <input id="k-selfie" name="selfie" type="file" accept="image/png,image/jpeg,image/webp">
          </div>
        </div>
        <?php if ($status === 'rejected' && !empty($kyc['id_front'])): ?>
          <p style="font-size:12px;color:var(--text-dim);margin:0 0 12px">Leave a file empty to keep the image already on file.</p>
        <?php endif; ?>

        <button class="btn btn-solid" type="submit">Submit for review <span aria-hidden="true">&rarr;</span></button>
      </form>
    </section>
  <?php endif; ?>
</main>
<?= ui_foot() ?>
