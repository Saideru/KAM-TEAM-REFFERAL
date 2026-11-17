<?php
require_once __DIR__ . '/lib/supabase.php';
require_once __DIR__ . '/lib/logo.php';

if (supabase_configured() && supabase_access_token()) {
    redirect_by_role();
}

$mode   = ($_GET['mode'] ?? '') === 'register' ? 'register' : 'login';
$ref    = strtoupper(preg_replace('/[^A-Za-z0-9-]/', '', (string) ($_GET['ref'] ?? $_POST['ref'] ?? '')));
$errors = [];
$old    = ['email' => '', 'full_name' => '', 'phone' => '', 'company' => '', 'role' => 'referral'];
$notice = flash();

if ($ref !== '' && !isset($_GET['mode'])) {
    $mode = 'register';
}
if (isset($_GET['confirmed'])) {
    $notice = ['type' => 'success', 'message' => 'Email confirmed. Sign in to access your terminal.'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $mode   = $action === 'register' ? 'register' : 'login';
    $email  = trim((string) ($_POST['email'] ?? ''));
    $pass   = (string) ($_POST['password'] ?? '');
    $old['email'] = $email;

    if (!supabase_configured()) {
        $errors[] = 'System not configured. Add Supabase credentials to config.php.';
    } elseif (!csrf_check()) {
        $errors[] = 'Session expired. Please try again.';
    } elseif ($action === 'login') {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $pass === '') {
            $errors[] = 'Invalid credentials.';
        } else {
            $res = supabase_sign_in($email, $pass);
            if ($res['error']) {
                $errors[] = 'Invalid credentials.';
                audit_log(SUPABASE_ANON_KEY, 'login_failed', 'user', null, 'Failed login attempt for ' . $email);
            } else {
                supabase_store_session($res['body']);
                $token = $_SESSION['sb']['access_token'];
                audit_log($token, 'login_success', 'user', $_SESSION['sb']['user_id'], 'User signed in');
                redirect_by_role();
            }
        }
    } elseif ($action === 'register') {
        $old['full_name'] = trim((string) ($_POST['full_name'] ?? ''));
        $old['phone']     = trim((string) ($_POST['phone'] ?? ''));
        $old['company']   = trim((string) ($_POST['company'] ?? ''));
        $old['role']      = in_array($_POST['role'] ?? '', ['client_basic', 'client_standard', 'referral'], true) ? $_POST['role'] : 'referral';
        $confirm          = (string) ($_POST['password_confirm'] ?? '');

        if ($old['full_name'] === '')                   $errors[] = 'Enter your full name.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter a valid email address.';
        if (strlen($pass) < 8)                          $errors[] = 'Password must be at least 8 characters.';
        if ($pass !== $confirm)                         $errors[] = 'Passwords do not match.';
        if (empty($_POST['terms']))                     $errors[] = 'Accept program terms to continue.';

        if (!$errors) {
            $res = supabase_sign_up($email, $pass, [
                'full_name' => $old['full_name'],
                'phone'     => $old['phone'],
                'company'   => $old['company'],
                'ref'       => $ref,
                'role'      => $old['role'],
            ], APP_URL . '/index.php?confirmed=1');

            if ($res['error']) {
                $errors[] = 'Invalid credentials.';
            } elseif (!empty($res['body']['access_token'])) {
                supabase_store_session($res['body']);
                redirect_by_role('1');
            } elseif (isset($res['body']['identities']) && $res['body']['identities'] === []) {
                $errors[] = 'Invalid credentials.';
            } else {
                flash('success', 'Account created. Check ' . $email . ' for confirmation, then sign in.');
                header('Location: index.php');
                exit;
            }
        }
    }
}

$csrf = csrf_token();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>KAMTEAM // Network Marketing Portal</title>
  <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 20 20'><rect fill='%230a0a0f' width='20' height='20' rx='4'/><text x='3' y='15' font-size='13' fill='%2300f0ff'>K</text></svg>">
  <link rel="stylesheet" href="assets/styles.css">
</head>
<body>
<main class="auth">

  <!-- 01 // BRAND PANEL -->
  <section class="auth-brand">
    <div class="scan-line"></div>
    <div>
      <?= kam_lockup() ?>
    </div>

    <div>
      <span class="label"><span class="dot"></span>Network Marketing // Portal v3.0</span>
      <h1>Build your network. <span class="accent">Get rewarded.</span></h1>
      <p class="lead">Join the KAMTEAM referral ecosystem. Earn commissions through direct referrals and unilevel structures. Track everything in real-time.</p>

      <ol class="steps">
        <li><span class="label" style="color:var(--cyan)">01 //</span><span>Register and choose your tier — <strong style="color:var(--green)">Basic</strong> or <strong style="color:var(--orange)">Standard</strong>.</span></li>
        <li><span class="label" style="color:var(--cyan)">02 //</span><span>Build your referral network. Standard unlocks <strong>unilevel commissions</strong>.</span></li>
        <li><span class="label" style="color:var(--cyan)">03 //</span><span>Track commissions, lifecycle, and payouts from your dashboard.</span></li>
      </ol>
    </div>

    <div class="stats">
      <div class="stat"><strong>4+</strong><span class="label">Years active</span></div>
      <div class="stat"><strong>50+</strong><span class="label">Operators</span></div>
      <div class="stat"><strong>7</strong><span class="label">Services</span></div>
    </div>
  </section>

  <!-- 02 // AUTH PANEL -->
  <section class="auth-panel">
    <div class="auth-card">

      <div class="tabs" role="tablist">
        <button type="button" class="tab" role="tab" id="tab-login" aria-controls="panel-login" aria-selected="<?= $mode === 'login' ? 'true' : 'false' ?>" data-mode="login">Sign in</button>
        <button type="button" class="tab" role="tab" id="tab-register" aria-controls="panel-register" aria-selected="<?= $mode === 'register' ? 'true' : 'false' ?>" data-mode="register">Register</button>
      </div>

      <?php if (!supabase_configured()): ?>
        <div class="alert alert-warn">[ SYSTEM ] Configure Supabase credentials in config.php</div>
      <?php endif; ?>

      <?php if ($notice): ?>
        <div class="alert alert-<?= e($notice['type']) ?>"><?= e($notice['message']) ?></div>
      <?php endif; ?>

      <?php if ($errors): ?>
        <div class="alert alert-error">
          <?php foreach ($errors as $err): ?><div>> <?= e($err) ?></div><?php endforeach; ?>
        </div>
      <?php endif; ?>

      <!-- Sign in -->
      <div id="panel-login" role="tabpanel" <?= $mode === 'login' ? '' : 'hidden' ?>>
        <span class="label kicker">01 // Authentication</span>
        <h2>Welcome back.</h2>
        <p class="sub">Access your terminal.</p>

        <form method="post" novalidate>
          <input type="hidden" name="action" value="login">
          <input type="hidden" name="csrf" value="<?= e($csrf) ?>">

          <div class="field">
            <label for="login-email">Email</label>
            <input id="login-email" name="email" type="email" autocomplete="email" required value="<?= e($mode === 'login' ? $old['email'] : '') ?>" placeholder="operator@kamteam.com">
          </div>
          <div class="field">
            <label for="login-password">Password</label>
            <div class="pw-wrap">
              <input id="login-password" name="password" type="password" autocomplete="current-password" required placeholder="••••••••">
              <button type="button" class="pw-toggle" aria-label="Show password">Show</button>
            </div>
          </div>

          <button class="btn btn-solid" type="submit">Authenticate <span aria-hidden="true">→</span></button>
        </form>

        <p class="switch">New operator? <button type="button" data-mode="register">Create account</button></p>
      </div>

      <!-- Register -->
      <div id="panel-register" role="tabpanel" <?= $mode === 'register' ? '' : 'hidden' ?>>
        <span class="label kicker">02 // Registration</span>
        <h2>Join KAMTEAM.</h2>
        <p class="sub">Select your account tier and initialize.</p>

        <?php if ($ref !== ''): ?>
          <div class="ref-badge"><span class="dot"></span>Ref: <strong><?= e($ref) ?></strong></div>
        <?php endif; ?>

        <form method="post" novalidate>
          <input type="hidden" name="action" value="register">
          <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <input type="hidden" name="ref" value="<?= e($ref) ?>">

          <div class="field">
            <label>Account tier</label>
            <div class="role-pick" role="radiogroup">
              <label class="role-option <?= $old['role'] === 'referral' ? 'active' : '' ?>">
                <input type="radio" name="role" value="referral" <?= $old['role'] === 'referral' ? 'checked' : '' ?>>
                <span class="role-icon">
                  <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                </span>
                <span class="role-info">
                  <strong>Referral</strong>
                  <small>Start here — choose a tier after sign-up</small>
                </span>
              </label>
              <label class="role-option <?= $old['role'] === 'client_basic' ? 'active' : '' ?>">
                <input type="radio" name="role" value="client_basic" <?= $old['role'] === 'client_basic' ? 'checked' : '' ?>>
                <span class="role-icon">
                  <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>
                </span>
                <span class="role-info">
                  <strong>Client</strong>
                  <small>Track your KAMTEAM services</small>
                </span>
              </label>
            </div>
          </div>

          <div class="field">
            <label for="reg-name">Full name</label>
            <input id="reg-name" name="full_name" type="text" autocomplete="name" required value="<?= e($old['full_name']) ?>" placeholder="John Doe">
          </div>
          <div class="field">
            <label for="reg-email">Email</label>
            <input id="reg-email" name="email" type="email" autocomplete="email" required value="<?= e($mode === 'register' ? $old['email'] : '') ?>" placeholder="operator@kamteam.com">
          </div>
          <div class="row">
            <div class="field">
              <label for="reg-phone">Phone</label>
              <input id="reg-phone" name="phone" type="tel" autocomplete="tel" value="<?= e($old['phone']) ?>">
            </div>
            <div class="field">
              <label for="reg-company">Company</label>
              <input id="reg-company" name="company" type="text" autocomplete="organization" value="<?= e($old['company']) ?>">
            </div>
          </div>
          <div class="row">
            <div class="field">
              <label for="reg-password">Password</label>
              <div class="pw-wrap">
                <input id="reg-password" name="password" type="password" autocomplete="new-password" minlength="8" required placeholder="Min 8 chars">
                <button type="button" class="pw-toggle" aria-label="Show password">Show</button>
              </div>
            </div>
            <div class="field">
              <label for="reg-password2">Confirm</label>
              <input id="reg-password2" name="password_confirm" type="password" autocomplete="new-password" minlength="8" required placeholder="••••••••">
            </div>
          </div>

          <label class="terms">
            <input type="checkbox" name="terms" value="1" required>
            <span>I agree to KAMTEAM program terms and authorize account communication.</span>
          </label>

          <button class="btn btn-solid" type="submit">Initialize account <span aria-hidden="true">→</span></button>
        </form>

        <p class="switch">Already registered? <button type="button" data-mode="login">Sign in</button></p>
      </div>

      <p class="foot">KAMTEAM // Network Marketing // Secure Portal</p>
    </div>
  </section>
</main>

<script>
  (function () {
    var panels = { login: document.getElementById('panel-login'), register: document.getElementById('panel-register') };
    var tabs = document.querySelectorAll('.tab');

    function show(mode) {
      Object.keys(panels).forEach(function (k) { panels[k].hidden = k !== mode; });
      tabs.forEach(function (t) { t.setAttribute('aria-selected', t.dataset.mode === mode ? 'true' : 'false'); });
      var first = panels[mode].querySelector('input:not([type=hidden]):not([type=radio]):not([type=checkbox])');
      if (first) first.focus();
    }

    document.querySelectorAll('[data-mode]').forEach(function (el) {
      el.addEventListener('click', function () { show(el.dataset.mode); });
    });

    document.querySelectorAll('.pw-toggle').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var input = btn.parentElement.querySelector('input');
        var visible = input.type === 'text';
        input.type = visible ? 'password' : 'text';
        btn.textContent = visible ? 'Show' : 'Hide';
      });
    });

    document.querySelectorAll('.role-option input[type=radio]').forEach(function (radio) {
      radio.addEventListener('change', function () {
        document.querySelectorAll('.role-option').forEach(function (opt) { opt.classList.remove('active'); });
        if (radio.checked) radio.closest('.role-option').classList.add('active');
      });
    });
  })();
</script>
</body>
</html>
