<?php
require_once __DIR__ . '/lib/supabase.php';
require_once __DIR__ . '/lib/logo.php';
require_once __DIR__ . '/lib/ui.php';

if (supabase_configured() && supabase_access_token()) {
    redirect_by_role();
}

$mode   = ($_GET['mode'] ?? '') === 'register' ? 'register' : 'login';
// The form prints "KT-" as a fixed prefix, so the user types only the
// number. Accept either form ("000001" or a pasted "KT-000001") and always
// rebuild the full code.
$refSuffix = preg_replace('/^KT-?/', '', strtoupper(preg_replace('/[^A-Za-z0-9-]/', '', (string) ($_GET['ref'] ?? $_POST['ref'] ?? ''))));
$refSuffix = str_replace('-', '', $refSuffix);
$ref    = $refSuffix === '' ? '' : 'KT-' . $refSuffix;
$errors = [];
// A visitor landing with ?ref=… was pointed here by a Growth Partner. The
// Growth Partner tile is pre-selected, unless the link says as=client (the
// client sign-up link on the partner's Referral link page).
$refAs  = ($_GET['as'] ?? '') === 'client' ? 'client' : 'affiliate';
$old    = ['username' => '', 'email' => '', 'full_name' => '', 'phone' => '', 'company' => '', 'business_nature' => '', 'business_struggles' => [], 'business_notes' => '', 'terms' => [], 'account_mode' => $ref !== '' ? $refAs : 'client'];
$notice = flash();

// Fallback copy when the idle watchdog sent the visitor here and the flash
// cookie did not survive (private mode, blocked cookies).
if (!$notice && isset($_GET['timeout'])) {
    $notice = ['type' => 'warn', 'message' => 'You were signed out after ' . (IDLE_TIMEOUT / 60) . ' minutes of inactivity. Please sign in again.'];
}

if ($ref !== '' && !isset($_GET['mode'])) {
    $mode = 'register';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action   = $_POST['action'] ?? '';
    $mode     = $action === 'register' ? 'register' : 'login';
    $username = strtolower(trim((string) ($_POST['username'] ?? '')));
    $pass     = (string) ($_POST['password'] ?? '');
    $old['username'] = $username;

    if (!supabase_configured()) {
        $errors[] = 'System not configured. Set Supabase env vars.';
    } elseif (!csrf_check()) {
        $errors[] = 'Session expired. Please try again.';
    } elseif ($action === 'login') {
        if ($username === '' || $pass === '') {
            $errors[] = 'Invalid credentials.';
        } else {
            $res = supabase_sign_in($username, $pass);
            if ($res['error']) {
                $errors[] = 'Invalid credentials.';
                supabase_request('POST', '/rest/v1/system_logs', ['action' => 'login_failed', 'entity_type' => 'user',
                    'summary' => 'Failed sign-in for "' . mb_substr($username, 0, 40) . '"', 'ip_address' => client_ip()], null, ['Prefer: return=minimal']);
            } else {
                supabase_store_session($res['profile']);
                audit_log(supabase_access_token(), 'login_success', 'user', $res['profile']['id'], 'User signed in');
                redirect_by_role();
            }
        }
    } elseif ($action === 'register') {
        $old['full_name'] = trim((string) ($_POST['full_name'] ?? ''));
        $old['email']     = trim((string) ($_POST['email'] ?? ''));
        $old['phone']     = trim((string) ($_POST['phone'] ?? ''));
        $old['company']   = trim((string) ($_POST['company'] ?? ''));
        $confirm          = (string) ($_POST['password_confirm'] ?? '');
        $old['account_mode']       = ($_POST['account_mode'] ?? '') === 'affiliate' ? 'affiliate' : 'client';
        $old['business_nature']    = in_array($_POST['business_nature'] ?? '', BUSINESS_NATURES, true) ? $_POST['business_nature'] : '';
        $old['business_struggles'] = array_values(array_intersect(array_keys(BUSINESS_STRUGGLES), (array) ($_POST['business_struggles'] ?? [])));
        $old['business_notes']     = mb_substr(trim((string) ($_POST['business_notes'] ?? '')), 0, 1000);

        // "About your business" belongs to the Client account — it tells
        // KAM TEAM which services fit. A Growth Partner refers clients and
        // does not buy services, so the section is hidden for them and
        // anything posted in it is dropped.
        if ($old['account_mode'] === 'client') {
            if ($old['business_nature'] === '')                    $errors[] = 'Tell us the nature of your business.';
            if (!$old['business_struggles'] && $old['business_notes'] === '') $errors[] = 'Pick at least one struggle, or tell us more about what you need.';
        } else {
            $old['business_nature']    = '';
            $old['business_struggles'] = [];
            $old['business_notes']     = '';
        }

        // Growth Partners must sign up through another partner's code, so
        // every new partner joins an existing downline (Manual §4). For a
        // Client the code is optional: when given, the Client is credited to
        // the Growth Partner who referred them (that's what earns commission).
        if ($old['account_mode'] === 'affiliate' && $ref === '') {
            $errors[] = 'Enter the referral code of the Growth Partner who invited you — it is required for a Growth Partner account.';
        }

        if ($old['full_name'] === '')                     $errors[] = 'Enter your full name.';
        if (!preg_match('/^[a-z0-9_]{3,20}$/', $username)) $errors[] = 'Username must be 3-20 characters: letters, numbers or underscore.';
        if ($old['email'] !== '' && !filter_var($old['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter a valid email address.';
        if (strlen($pass) < 8)                            $errors[] = 'Password must be at least 8 characters.';
        if ($pass !== $confirm)                           $errors[] = 'Passwords do not match.';
        $old['terms'] = terms_posted();
        foreach (terms_missing($old['terms']) as $item) $errors[] = 'Read and accept ' . $item . '.';

        if (!$errors) {
            $res = supabase_sign_up($username, $pass, [
                'full_name' => $old['full_name'],
                'email'     => $old['email'],
                'phone'     => $old['phone'],
                'company'   => $old['company'],
                'ref'       => $ref,
                'business_nature'    => $old['business_nature'] ?: null,
                'business_struggles' => $old['business_struggles'],
                'business_notes'     => $old['business_notes'] ?: null,
                'terms'              => $old['terms'],
                'account_mode'       => $old['account_mode'],
            ]);

            if ($res['error']) {
                $errors[] = $res['error'];
            } else {
                supabase_store_session($res['profile']);
                redirect_by_role('1');
            }
        }
    }
}

$csrf = csrf_token();
?>
<?= ui_head([
    'title'       => 'Sign in // KAM Growth Partnership Program',
    'description' => 'Sign in or register for the KAM Growth Partnership Program: buy KAM Team services as a Client and refer clients as a Growth Partner.',
    'body_class'  => 'auth-page',
]) ?>
<main class="auth" id="main" tabindex="-1">

  <section class="auth-brand">
    <div class="scan-line"></div>
    <div><?= kam_lockup('reversed') ?></div>

    <div>
      <span class="label"><span class="dot"></span>KAM GROWTH PARTNERSHIP PROGRAM</span>
      <h1>Solve business problems with the power of <span class="accent">people + AI.</span></h1>
      <p class="lead">Join the KAM Growth Partnership Program. Buy KAM Team services as a Client, refer clients as a Growth Partner, and manage both from one dashboard.</p>

      <ol class="steps">
        <li><span class="label" style="color:var(--orange)">01 //</span><span>Register as a <strong style="color:var(--white)">Client</strong>, a <strong style="color:var(--orange)">Growth Partner</strong>, or both — your call, any time.</span></li>
        <li><span class="label" style="color:var(--orange)">02 //</span><span>Refer clients. You earn 10% of each client's 50% advance deposit.</span></li>
        <li><span class="label" style="color:var(--orange)">03 //</span><span>Track commissions, lifecycle and payouts from your dashboard.</span></li>
      </ol>
    </div>

    <div class="stats">
      <div class="stat"><strong>4+</strong><span class="label">Years active</span></div>
      <div class="stat"><strong>50+</strong><span class="label">Professionals</span></div>
      <div class="stat"><strong>7</strong><span class="label">Services</span></div>
    </div>
  </section>

  <section class="auth-panel">
    <div class="auth-card">

      <div class="tabs" role="tablist">
        <button type="button" class="tab" role="tab" id="tab-login" aria-controls="panel-login" aria-selected="<?= $mode === 'login' ? 'true' : 'false' ?>" data-mode="login">Sign in</button>
        <button type="button" class="tab" role="tab" id="tab-register" aria-controls="panel-register" aria-selected="<?= $mode === 'register' ? 'true' : 'false' ?>" data-mode="register">Register</button>
      </div>

      <?php if (!supabase_configured()): ?>
        <div class="alert alert-warn">Configuration needed: add Supabase environment variables.</div>
      <?php endif; ?>

      <?= ui_alerts($notice, $errors) ?>

      <div id="panel-login" role="tabpanel" <?= $mode === 'login' ? '' : 'hidden' ?>>
        <span class="label kicker">01 // Authentication</span>
        <h2>Welcome back.</h2>
        <p class="sub">Sign in to your account.</p>

        <form method="post" action="/index.php" novalidate>
          <input type="hidden" name="action" value="login">
          <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <div class="field">
            <label for="login-username">Username</label>
            <input id="login-username" name="username" type="text" autocomplete="username" required value="<?= e($mode === 'login' ? $old['username'] : '') ?>" placeholder="yourname">
          </div>
          <div class="field">
            <label for="login-password">Password</label>
            <div class="pw-wrap">
              <input id="login-password" name="password" type="password" autocomplete="current-password" required placeholder="••••••••">
              <button type="button" class="pw-toggle" aria-label="Show password">Show</button>
            </div>
          </div>
          <button class="btn btn-solid" type="submit">Sign in <span aria-hidden="true">→</span></button>
        </form>
        <p class="switch">New to KAM Team? <button type="button" data-mode="register">Create account</button></p>
      </div>

      <div id="panel-register" role="tabpanel" <?= $mode === 'register' ? '' : 'hidden' ?>>
        <span class="label kicker">02 // Registration</span>
        <h2>Join KAM Team.</h2>
        <p class="sub">Pick the account that fits you now — you can add the other side any time.</p>

        <?php if ($ref !== ''): ?>
          <div class="ref-badge" id="ref-badge"><span class="dot"></span>Ref: <strong><?= e($ref) ?></strong></div>
        <?php endif; ?>

        <form method="post" action="/index.php" novalidate>
          <input type="hidden" name="action" value="register">
          <input type="hidden" name="csrf" value="<?= e($csrf) ?>">

          <fieldset class="biz-fieldset acct-pick" aria-describedby="acct-pick-help">
            <legend><span class="label kicker">What are you here for?</span></legend>
            <div class="acct-grid">
              <label class="acct-tile<?= $old['account_mode'] === 'client' ? ' is-on' : '' ?>">
                <input type="radio" name="account_mode" value="client" <?= $old['account_mode'] !== 'affiliate' ? 'checked' : '' ?>>
                <div class="acct-body">
                  <span class="label" style="color:var(--rust)">Client</span>
                  <strong>I want KAM Team services</strong>
                  <p>Creates a Client account — buy service packages, 50% advance deposit to start. You can add a Growth Partner account later if you ever want to refer clients.</p>
                </div>
              </label>
              <label class="acct-tile<?= $old['account_mode'] === 'affiliate' ? ' is-on' : '' ?>">
                <input type="radio" name="account_mode" value="affiliate" <?= $old['account_mode'] === 'affiliate' ? 'checked' : '' ?>>
                <div class="acct-body">
                  <span class="label" style="color:var(--rust)">Growth Partner</span>
                  <strong>I want to refer clients</strong>
                  <p>Creates a Growth Partner account — pick Basic (free) or Standard ($100) and start referring. Carries a term and commission; does not buy services on its own.</p>
                </div>
              </label>
            </div>
            <p id="acct-pick-help" class="hint" style="margin:8px 2px 0">Admin approves every sign-up before the dashboard opens.</p>
          </fieldset>

          <div class="field"><label for="reg-name">Full name</label><input id="reg-name" name="full_name" type="text" autocomplete="name" required value="<?= e($old['full_name']) ?>" placeholder="John Doe"></div>
          <div class="field"><label for="reg-username">Username</label><input id="reg-username" name="username" type="text" autocomplete="username" required pattern="[A-Za-z0-9_]{3,20}" value="<?= e($mode === 'register' ? $old['username'] : '') ?>" placeholder="3-20 letters, numbers or _"></div>
          <div class="field"><label for="reg-email">Email <span style="opacity:.6">(optional)</span></label><input id="reg-email" name="email" type="email" autocomplete="email" value="<?= e($old['email']) ?>" placeholder="you@example.com"></div>
          <div class="row">
            <div class="field"><label for="reg-phone">Phone</label><input id="reg-phone" name="phone" type="tel" autocomplete="tel" value="<?= e($old['phone']) ?>"></div>
            <div class="field"><label for="reg-company">Company</label><input id="reg-company" name="company" type="text" autocomplete="organization" value="<?= e($old['company']) ?>"></div>
          </div>
          <div class="row">
            <div class="field"><label for="reg-password">Password</label><div class="pw-wrap"><input id="reg-password" name="password" type="password" autocomplete="new-password" minlength="8" required placeholder="Min 8 chars"><button type="button" class="pw-toggle" aria-label="Show password">Show</button></div></div>
            <div class="field"><label for="reg-password2">Confirm</label><input id="reg-password2" name="password_confirm" type="password" autocomplete="new-password" minlength="8" required placeholder="••••••••"></div>
          </div>

          <?php $bizOn = $old['account_mode'] !== 'affiliate'; ?>
          <fieldset class="biz-fieldset" id="biz-fieldset"<?= $bizOn ? '' : ' hidden disabled' ?>>
            <legend><span class="label kicker">03 // About your business</span>So we know how KAM Team can help.</legend>
            <div class="field">
              <label for="reg-nature">What's the nature of your business?</label>
              <select id="reg-nature" name="business_nature" required>
                <option value="">Select one…</option>
                <?php foreach (BUSINESS_NATURES as $bn): ?><option <?= $old['business_nature'] === $bn ? 'selected' : '' ?>><?= e($bn) ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="field">
              <label>What are you struggling with? <span style="opacity:.6">(pick all that apply)</span></label>
              <div class="struggle-pick">
                <?php foreach (BUSINESS_STRUGGLES as $key => [$label]): ?>
                  <label class="struggle-option"><input type="checkbox" name="business_struggles[]" value="<?= e($key) ?>" <?= in_array($key, $old['business_struggles'], true) ? 'checked' : '' ?>><span><?= e($label) ?></span></label>
                <?php endforeach; ?>
              </div>
            </div>
            <div class="field">
              <label for="reg-notes">Tell us more <span style="opacity:.6">(optional)</span></label>
              <textarea id="reg-notes" name="business_notes" rows="3" maxlength="1000" placeholder="e.g. We get inquiries on Facebook but can't reply fast enough."><?= e($old['business_notes']) ?></textarea>
            </div>
          </fieldset>

          <?php
            // Shown for both account types: required for a Growth Partner
            // (every partner joins a downline), optional for a Client (credits
            // the Client to the Growth Partner who referred them).
            $refRequired = $old['account_mode'] === 'affiliate';
          ?>
          <div class="field ref-field" id="ref-field">
            <label for="reg-ref">Referral code <span class="ref-req" id="ref-req" aria-hidden="true"><?= $refRequired ? '(required)' : '(optional)' ?></span></label>
            <div class="ref-wrap">
              <span class="ref-prefix" aria-hidden="true">KT-</span>
              <input id="reg-ref" name="ref" type="text" maxlength="9"
                     value="<?= e($ref === '' ? '' : substr($ref, 3)) ?>" placeholder="000001"
                     autocomplete="off" inputmode="latin" aria-describedby="ref-hint"
                     pattern="(KT-?|kt-?)?[A-Za-z0-9]{3,9}" title="The characters after KT-, e.g. 000001"
                     <?= $refRequired ? 'required aria-required="true"' : '' ?>>
            </div>
            <small id="ref-hint" style="display:block;margin-top:4px;font-size:11px;color:var(--text-dim)">Type only the characters after KT- (e.g. 000001). <span id="ref-why"><?= $refRequired ? 'Enter the code of the Growth Partner who invited you — you join their network. Client codes can\'t be used for a Growth Partner account.' : 'Were you referred by a Growth Partner? Enter their code so they are credited. Leave it blank if not.' ?></span></small>
          </div>

          <?= terms_checklist($old['terms'], '04 // Program terms', 'reg-submit') ?>

          <button class="btn btn-solid" type="submit" id="reg-submit">Create account <span aria-hidden="true">→</span></button>
        </form>
        <p class="switch">Already registered? <button type="button" data-mode="login">Sign in</button></p>
      </div>

      <p class="foot">KAM GROWTH PARTNERSHIP PROGRAM</p>
    </div>
  </section>
</main>

<script>
// Fallback tab switcher: runs inline so the Register tab and the "Create
// account" link work even if /assets/js/app.js is still being cached, is
// blocked, or 404s. app.js's authTabs does the same thing on top of this.
(function () {
  var login = document.getElementById('panel-login');
  var reg   = document.getElementById('panel-register');
  if (!login || !reg) return;
  function show(mode) {
    login.hidden = mode !== 'login';
    reg.hidden   = mode !== 'register';
    Array.prototype.forEach.call(document.querySelectorAll('.tab'), function (t) {
      t.setAttribute('aria-selected', t.dataset.mode === mode ? 'true' : 'false');
    });
  }
  Array.prototype.forEach.call(document.querySelectorAll('[data-mode]'), function (el) {
    el.addEventListener('click', function () { show(el.dataset.mode); });
  });
  // Highlight the selected account-type card, then show only the parts
  // of the form that belong to that account type:
  //   Client          -> "About your business" shown, referral code hidden
  //   Growth Partner  -> referral code shown (required), business hidden
  // A hidden section is also disabled, so its required fields can't
  // block the submit and its values are not sent.
  var refField = document.getElementById('ref-field');
  var refInput = document.getElementById('reg-ref');
  var bizField = document.getElementById('biz-fieldset');
  // KT- is printed in front of the box: uppercase as they type and drop a
  // pasted KT- prefix so the code is never doubled.
  if (refInput) refInput.addEventListener('input', function () {
    var v = refInput.value.toUpperCase().replace(/^KT-?/, '').replace(/[^A-Z0-9]/g, '');
    if (v !== refInput.value) refInput.value = v;
  });
  function syncAcctMode() {
    var checked = document.querySelector('.acct-tile input:checked');
    var affiliate = (checked ? checked.value : 'client') === 'affiliate';
    Array.prototype.forEach.call(document.querySelectorAll('.acct-tile'), function (tile) {
      tile.classList.toggle('is-on', tile.contains(checked));
    });
    if (refField && refInput) {
      refField.hidden = false;
      refInput.disabled = false;
      if (affiliate) { refInput.setAttribute('required', ''); refInput.setAttribute('aria-required', 'true'); }
      else { refInput.removeAttribute('required'); refInput.removeAttribute('aria-required'); }
      var req = document.getElementById('ref-req');
      if (req) req.textContent = affiliate ? '(required)' : '(optional)';
      var why = document.getElementById('ref-why');
      if (why) why.textContent = affiliate
        ? 'Enter the code of the Growth Partner who invited you — you join their network. Client codes can\'t be used for a Growth Partner account.'
        : 'Were you referred by a Growth Partner? Enter their code so they are credited. Leave it blank if not.';
    }
    if (bizField) {
      bizField.hidden = affiliate;
      bizField.disabled = affiliate;
    }
    var badge = document.getElementById('ref-badge');
    if (badge) badge.hidden = false;
  }
  Array.prototype.forEach.call(document.querySelectorAll('.acct-tile input'), function (radio) {
    radio.addEventListener('change', syncAcctMode);
  });
  syncAcctMode();
  Array.prototype.forEach.call(document.querySelectorAll('.pw-toggle'), function (btn) {
    // Wire once only: app.js skips buttons marked here, so one click is not
    // handled twice (which flipped the field straight back to dots).
    if (btn.dataset.pwWired) return;
    btn.dataset.pwWired = '1';
    btn.setAttribute('aria-pressed', 'false');
    btn.addEventListener('click', function () {
      var input = btn.parentElement.querySelector('input');
      if (!input) return;
      var visible = input.type === 'text';
      input.type = visible ? 'password' : 'text';
      btn.textContent = visible ? 'Show' : 'Hide';
      btn.setAttribute('aria-pressed', visible ? 'false' : 'true');
      btn.setAttribute('aria-label', (visible ? 'Show' : 'Hide') + ' password');
    });
  });
})();
</script>
<?= ui_foot() ?>