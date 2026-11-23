<?php
require_once __DIR__ . '/lib/supabase.php';
require_once __DIR__ . '/lib/logo.php';
require_once __DIR__ . '/lib/ui.php';
require_once __DIR__ . '/lib/payouts.php';

// The Growth Partner side of a member account: plan, term, referral code,
// downline and earnings. It is also the plan chooser every approved member
// passes through before their Client dashboard opens, so it is exempt from
// that gate (see require_role()).
define('PLAN_GATE_EXEMPT', true);

// Serves three shapes: legacy dual-side members (role='client' with an
// affiliate tier), standalone Growth Partner rows (role='growth_partner'
// from the post-20261128 sign-up flow) and admins viewing their own
// linked Growth Partner row via session switch.
['token' => $token, 'profile' => $profile] = require_role(['client', 'admin', 'growth_partner']);
$userId    = $profile['id'];
$isAdmin   = $profile['role'] === 'admin';
$unlimited = tier_unlimited($profile);   // admins: a partner account with no term
$errors = [];
$notice = flash();
$csrf   = csrf_token();
$state  = affiliate_state($profile);          // none | active | grace | terminated
$tier   = $profile['affiliate_tier'] ?? null;

// A plain Client has no Growth Partner side — send them to their own
// dashboard (unless a plan request they made earlier is still waiting).
if (is_client_only($profile) && !plan_request_pending($token, $userId)) {
    header('Location: /dashboard-client.php' . (isset($_GET['welcome']) ? '?welcome=1' : '')); exit;
}

// PDF receipt for one of this partner's commissions or payouts (or a sample).
if (isset($_GET['receipt'])) receipt_send($token, (string) $_GET['receipt'], $userId);

// Growth Partner plans only — service packages are bought on the Client side.
$pkgRes   = supabase_request('GET', '/rest/v1/packages?select=*&kind=eq.affiliate&active=eq.true&order=price.asc', null, $token);
$packages = is_array($pkgRes['body']) && !$pkgRes['error'] ? $pkgRes['body'] : [];

$actRes  = supabase_request('GET', '/rest/v1/transactions?select=*,packages!transactions_package_id_fkey(name,tier)&type=eq.activation&user_id=eq.' . urlencode($userId) . '&order=created_at.desc&limit=5', null, $token);
$actTxns = is_array($actRes['body']) && !$actRes['error'] ? $actRes['body'] : [];
$pending = current(array_filter($actTxns, fn($t) => $t['status'] === 'pending')) ?: null;
$lastRejected = (!$pending && ($actTxns[0]['status'] ?? '') === 'failed' && !empty($actTxns[0]['reviewed_by'])) ? $actTxns[0] : null;

$pay      = get_settings($token, ['payment_qr', 'payment_account', 'payment_instructions']);
$payReady = $pay['payment_qr'] !== '';

$chRes    = supabase_request('GET', '/rest/v1/package_changes?select=package_id,summary,effective_at&status=eq.scheduled', null, $token);
$upcoming = [];
foreach ((is_array($chRes['body']) && !$chRes['error']) ? $chRes['body'] : [] as $ch) $upcoming[$ch['package_id']] = $ch;

// What each plan card offers right now (Manual §4, §6.4).
//   Basic:    only as a first plan — Basic cannot be renewed.
//   Standard: first plan, upgrade from Basic, or +2-year renewal.
$offer = function (array $pk) use ($tier, $unlimited): ?string {
    if ($unlimited)                 return null;
    if ($pk['tier'] === 'basic')    return $tier === null ? 'Start free' : null;
    if ($tier === 'standard')       return 'Renew — adds 2 years';
    if ($tier === 'basic')          return 'Upgrade to Standard';
    return 'Choose Standard';
};

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check()) {
    $act = $_POST['action'] ?? '';

    if ($act === 'choose_package') {
        $pkgId = (string) ($_POST['package_id'] ?? '');
        $pkg   = current(array_filter($packages, fn($p) => $p['id'] === $pkgId)) ?: null;

        if (!$pkg || !$offer($pkg)) {
            $errors[] = 'That plan is not available for your account.';
        } elseif ($pending) {
            $errors[] = 'You already have a request waiting for admin approval.';
        } elseif ($pkg['tier'] === 'basic') {
            // Basic is free, so it starts at once — the admin already
            // approved the account itself (Manual §1.3, §4.1).
            $err = activate_account($token, $userId, 'basic');
            if (!$err) {
                supabase_request('POST', '/rest/v1/transactions', [
                    'user_id' => $userId, 'package_id' => $pkg['id'], 'amount' => 0, 'type' => 'activation',
                    'status' => 'completed', 'reviewed_at' => date('c'), 'notes' => 'Free Basic Growth Partner plan',
                ], $token, ['Prefer: return=minimal']);
                audit_log($token, 'plan_activated', 'profile', $userId, 'Basic plan started — ' . $pkg['name']);
                notify($token, 'Basic Growth Partner plan active', 'Your 3-month Basic term has started. Refer a client to add 3 more months, or upgrade to Standard any time.', $userId);
                flash('success', 'Basic plan active — your 3-month term starts today.');
                header('Location: ' . (affiliate_only($profile) ? '/dashboard-affiliate.php?welcome=1' : '/dashboard-client.php?welcome=1')); exit;
            }
            $errors[] = db_error_message($err) ?: 'Could not start your Basic plan. Please try again.';
        } else {
            $refNo = trim((string) ($_POST['payment_ref'] ?? ''));
            if (!$payReady) {
                $errors[] = 'Payments are not open yet. Please contact an admin.';
            } elseif (($profile['kyc_status'] ?? 'not_submitted') !== 'approved') {
                $errors[] = 'Verify your identity before paying for Standard.';
            } elseif (!preg_match('/^[A-Za-z0-9 #_\-]{4,64}$/', $refNo)) {
                $errors[] = 'Enter the reference number from your payment receipt (4-64 letters/numbers).';
            } else {
                $res = supabase_request('POST', '/rest/v1/transactions', [
                    'user_id' => $userId, 'package_id' => $pkg['id'], 'amount' => (float) $pkg['price'],
                    'type' => 'activation', 'status' => 'pending', 'payment_ref' => $refNo,
                    'notes' => $offer($pkg) . ' — ' . $pkg['name'],
                ], $token, ['Prefer: return=minimal']);
                if (!$res['error']) {
                    audit_log($token, 'payment_submitted', 'profile', $userId, $offer($pkg) . ': ' . $pkg['name'] . ' $' . number_format((float) $pkg['price'], 2) . ' ref ' . $refNo);
                    notify($token, 'Payment to verify', $profile['full_name'] . ' paid $' . number_format((float) $pkg['price'], 2) . ' (' . $offer($pkg) . ') — ref ' . $refNo . '.', null, 'admin', '/dashboard-admin.php#approvals');
                    flash('success', 'Payment submitted. An admin will verify it and start your Standard term.');
                    // First plan: the dashboard opens now, the term starts on approval.
                    header('Location: ' . ($tier || affiliate_only($profile) ? '/dashboard-affiliate.php' : '/dashboard-client.php')); exit;
                }
                $errors[] = $res['status'] === 409 ? 'You already have a payment waiting for admin approval.' : 'Could not submit your payment. Please try again.';
            }
        }
    }

    // "Activate Client account" — flips an affiliate-only member into a
    // dual-side account. Reversible from the admin dashboard if needed.
    if ($act === 'activate_client' && affiliate_only($profile)) {
        $err = activate_client_side($token, $userId);
        if ($err) {
            $errors[] = $err;
        } else {
            flash('success', 'Client account activated. You can now buy KAM Team services.');
            notify($token, 'Client account activated', 'Your Client dashboard is now open — browse the service packages when you\'re ready.', $userId);
            header('Location: /dashboard-client.php?welcome=1'); exit;
        }
    }

    if ($act === 'request_payout') {
        $err = payout_request($token, $profile);
        if (!$err) {
            flash('success', 'Payout requested. It is sorted after the Thursday 11:59 AM PT cutoff — you will get a notification and a receipt once it is paid.');
            header('Location: /dashboard-affiliate.php?view=payout'); exit;
        }
        $errors[] = $err;
    }

    if ($act === 'cancel_request' && $pending) {
        $res = supabase_request('PATCH', '/rest/v1/transactions?status=eq.pending&id=eq.' . urlencode($pending['id']) . '&user_id=eq.' . urlencode($userId),
            ['status' => 'failed', 'notes' => 'Cancelled by user'], $token, ['Prefer: return=minimal']);
        if (!$res['error']) {
            audit_log($token, 'payment_cancelled', 'transaction', $pending['id'], 'User cancelled plan request');
            flash('success', 'Request cancelled.');
            header('Location: /dashboard-affiliate.php?view=plan'); exit;
        }
        $errors[] = 'Could not cancel the request.';
    }
}

$firstName  = explode(' ', $profile['full_name'])[0];
$graceEnds  = affiliate_grace_ends($profile);
$offered    = array_values(array_filter($packages, fn($p) => $offer($p) !== null));
// No plan and nothing pending: this is the gate, so the rest of the
// account stays closed until a plan is chosen.
$gated      = !$tier && !$pending;
?>
<?= ui_head([
    'title'       => 'Growth Partner // KAM Team',
    'description' => 'Your Growth Partner plan, referral link, downline and commissions.',
    'csrf'        => $csrf,
    'idle'        => true,
]) ?>
<?php
// Admins reach this dashboard by switching session into their dedicated
// Growth Partner row (migration 20261128). So "admin viewing GP" is
// detected by comparing the base session id to the current profile id,
// not by the profile's own role (which is now 'growth_partner').
$switchedFromAdmin = session_base_user_id() !== $profile['id'];

// Topbar switch: the one connective link to the Client side, present only
// for legacy dual-side members (role='client' with an affiliate tier).
// Standalone growth_partner rows and switched-in admins have no Client
// side on this row; affiliate-only legacy members have the "Activate
// Client account" card on the page instead.
$topSwitch = null;
if (!$switchedFromAdmin && $profile['role'] === 'client' && !affiliate_only($profile) && !$gated) {
    $topSwitch = ['label' => 'Client account', 'href' => '/dashboard-client.php', 'note' => 'KAM Team services'];
}

$topLinks = [];
// Switched in from Admin: the same soft toggle (now on) leads back.
$topToggle = $switchedFromAdmin ? ['to' => session_base_user_id(), 'on' => true] : null;
if (!$switchedFromAdmin && $isAdmin) {
    // Legacy path: an admin who never had their referral slot split off
    // still lives on the admin row. Direct link back is fine.
    $topLinks[] = ['label' => '← Admin dashboard', 'href' => '/dashboard-admin.php'];
}
?>
<?= ui_topbar([
    'csrf'       => $csrf,
    'bell'       => notif_bell($token, $profile, $csrf),
    'welcome'    => $profile['full_name'] ?? '',
    'role'       => ($switchedFromAdmin ? 'Admin · Growth Partner' : ($isAdmin ? 'Admin' : 'Growth Partner')) . ($tier ? ' · ' . tier_label($tier) : ''),
    'role_class' => $switchedFromAdmin || $isAdmin ? 'admin' : 'affiliate',
    'links'      => $topLinks,
    'switch'     => $topSwitch,
    'toggle'     => $topToggle,
]) ?>

<div class="shell">
<?php
// Sidebar is purely Growth Partner content — no cross-link to the Client
// dashboard; that lives in the topbar switch above. Affiliate-only members
// also get an "Activate Client account" page.
// "Your business" belongs to the Client side. A Growth Partner account has
// no business details (registration does not ask for them), so only a
// legacy dual-side member (role client) still sees it here.
$hasBusiness = $profile['role'] === 'client';
// Each sidebar item is its own page (?view=…): the server renders only that
// section, so nothing is one long scrolling page with anchors.
$base  = '/dashboard-affiliate.php';
$pages = [
    'overview' => ['label' => 'Overview',      'icon' => 'home', 'title' => 'Overview'],
    'plan'     => ['label' => 'Partner plan',  'icon' => 'box',  'title' => 'Your partner plan'],
    'share'    => ['label' => 'Referral link', 'icon' => 'link', 'title' => 'Your referral code'],
    'clients'  => ['label' => 'Clients referred', 'icon' => 'users', 'title' => 'Clients referred'],
    'payout'   => ['label' => 'Payout',        'icon' => 'cash', 'title' => 'Payout / Earnings'],
    'network'  => ['label' => 'Your downline', 'icon' => 'tree', 'title' => 'Your downline'],
];
if (!$isAdmin && !$switchedFromAdmin) $pages['verify']   = ['label' => 'Verify ID',               'icon' => 'id',    'title' => 'Verify your ID'];
if ($hasBusiness)                     $pages['business'] = ['label' => 'Your business',           'icon' => 'brief', 'title' => 'Your business'];
if (affiliate_only($profile))         $pages['activate'] = ['label' => 'Activate Client account', 'icon' => 'brief', 'title' => 'Client account'];

$view = (string) ($_GET['view'] ?? 'overview');
if ($view === 'earnings') $view = 'payout';   // old links
if ($gated || !isset($pages[$view])) $view = $gated ? 'plan' : 'overview';
$canRefer = !in_array($state, ['grace', 'terminated'], true);

$navOf = fn(string ...$keys): array => array_map(
    fn($k) => isset($pages[$k]) ? ['label' => $pages[$k]['label'], 'href' => $base . '?view=' . $k, 'icon' => $pages[$k]['icon']] : null,
    $keys
);
$navItems = array_merge(
    $navOf('overview'),
    side_group('Referrals', 'users', 'referrals', $navOf('share', 'clients', 'network')),
    $navOf('payout'),
    side_group('Account', 'user', 'account', $navOf('plan', 'verify', 'business', 'activate'))
);
?>
<?= app_sidebar($gated ? [
    ['label' => 'Choose your plan', 'href' => $base . '?view=plan', 'icon' => 'box'],
    ['label' => 'Verify ID',        'href' => '/kyc.php',           'icon' => 'id'],
] : $navItems, 'Growth Partner', $gated ? $base . '?view=plan' : $base . '?view=' . $view) ?>
<main class="wrap" id="main" tabindex="-1">
  <?= ui_page_title(
        'KAM Growth Partnership Program // ' . e($profile['referral_code'] ?? ''),
        ($gated || isset($_GET['welcome']) ? 'Welcome, ' . $firstName . '.' : ($view === 'overview' ? 'Hi, ' . $firstName . '.' : $pages[$view]['title'] . '.')),
        $gated ? 'Choose your plan.' : ($view === 'overview' ? 'Refer clients, earn commission.' : '')
  ) ?>
  <?php if ($gated): ?>
    <?php $onlyAffiliate = affiliate_only($profile); ?>
    <div class="alert alert-warn" style="margin-bottom:12px"><strong>One last step.</strong> Your account is approved. Pick your Growth Partner plan below — <strong>Basic</strong> starts free right away, <strong>Standard</strong> costs US$100 — and your dashboard opens.
      <?= $onlyAffiliate
          ? 'You signed up as a Growth Partner only; you can activate a Client account any time once your plan is set.'
          : 'Your Client account is permanent either way; only the Growth Partner side carries a term.' ?></div>
  <?php endif; ?>

  <?= ui_alerts($notice, $errors) ?>
  <?= $gated ? kyc_banner($profile) : (kyc_approved($profile) || $view === 'verify' ? '' : kyc_gate_notice($profile, 'referrals')) ?>

  <?php if (!$gated && $view === 'activate'): ?>
    <section class="card activate-card" id="activate-client" style="margin-bottom:16px">
      <span class="label"><span class="dot" style="background:var(--orange)"></span>Client account // dormant</span>
      <h2 style="margin:6px 0 4px">Also want KAM Team services?</h2>
      <p class="hint" style="margin:0 0 12px">You signed up as a Growth Partner only. Activate the Client side any time to browse service packages and pay the 50% advance deposit. Your Growth Partner side keeps running as-is.</p>
      <form method="post" style="margin:0">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="action" value="activate_client">
        <button class="btn btn-solid btn-sm" type="submit">Activate Client account <span aria-hidden="true">→</span></button>
      </form>
    </section>
  <?php endif; ?>

  <?php if ($state === 'grace'): ?>
    <div class="alert alert-error"><strong>Your <?= e(tier_label($tier)) ?> term expired on <?= date('M j, Y', strtotime($profile['lifecycle_expires_at'])) ?>.</strong>
      You have until <strong><?= date('M j, Y', $graceEnds) ?></strong> to save it —
      <?= $tier === 'basic' ? 'refer 1 Qualified Client (+3 months) or upgrade to Standard for US$100.' : 'renew for US$100.' ?>
      After that the Growth Partner side ends, Pending commissions are forfeited and your Co-growth Partners roll up to your upline. Your Client account stays open.</div>
  <?php elseif ($state === 'terminated'): ?>
    <div class="alert alert-error"><strong>Your <?= e(tier_label($tier)) ?> Growth Partner term has ended.</strong>
      New referrals are not credited until you take Standard for US$100 (+3 months + 2 years). Your Client account is unaffected.</div>
  <?php elseif ($state === 'active' && $tier === 'basic' && ($daysLeft = (int) ceil((strtotime($profile['lifecycle_expires_at']) - time()) / 86400)) <= EXPIRY_NOTICE_DAYS): ?>
    <div class="alert alert-warn"><strong>Only <?= $daysLeft ?> day<?= $daysLeft === 1 ? '' : 's' ?> left on your Basic account</strong> (ends <?= date('M j, Y', strtotime($profile['lifecycle_expires_at'])) ?>).
      To keep it, find a client — when a client you referred pays their deposit you get +3 months — or <a href="/dashboard-affiliate.php?view=plan">upgrade to Standard for US$100</a> (+2 years).</div>
  <?php endif; ?>

  <?php if ($view === 'overview' || $view === 'plan'): ?>
  <?php if ($unlimited): ?>
    <div class="card countdown-card" id="overview"><span class="label">Unlimited Growth Partner account</span>
      <p style="margin:8px 0 0;font-size:13px;color:var(--text-muted)">Your partner account never expires — there is no plan to buy and nothing to renew. You earn commission on every client you refer, like any Growth Partner.</p></div>
  <?php elseif ($tier): ?>
    <div id="overview"><?= lifecycle_countdown($profile, tier_label($tier) . ' Growth Partner term // +3 months per Qualified Client') ?></div>
  <?php endif; ?>
  <?php endif; ?>

  <?php if (!$unlimited && $view === 'plan'): ?>
  <section id="plans" style="margin-bottom:20px">
    <?= ui_terminal_head('partner_plan.sh // ' . ($tier ? 'Your plan: ' . tier_label($tier) : 'Choose your Growth Partner plan')) ?>
    <div class="terminal-body">
      <?php if ($pending): ?>
        <div class="pending-box">
          <span class="label" style="color:var(--rust)"><span class="dot"></span>Awaiting admin approval</span>
          <h3><?= e($pending['packages']['name'] ?? 'Plan') ?> — <?= (float) $pending['amount'] > 0 ? '$' . number_format((float) $pending['amount'], 2) : 'Free' ?></h3>
          <div class="profile-info">
            <?php if (!empty($pending['payment_ref'])): ?><div class="profile-row"><span class="label">Reference</span><span><?= e($pending['payment_ref']) ?></span></div><?php endif; ?>
            <div class="profile-row"><span class="label">Submitted</span><span><?= date('M j, Y H:i', strtotime($pending['created_at'])) ?></span></div>
            <div class="profile-row"><span class="label">Status</span><span class="pill pending">pending</span></div>
          </div>
          <form method="post" onsubmit="return confirm('Cancel this request?')" style="margin-top:12px"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="cancel_request">
            <button class="btn btn-ghost btn-sm" type="submit">Cancel request</button>
          </form>
        </div>
      <?php else: ?>
        <div class="output" style="margin-bottom:16px">
          <?php if (!$tier): ?>Basic is free and starts immediately — a 3-month term. Standard is US$100 per 2-year term (+3-month activation bonus) with unlimited renewal; an admin verifies the payment before it starts.
          <?php elseif ($tier === 'basic'): ?>Basic cannot be renewed. Extend it by referring Qualified Clients (+3 months each), or upgrade to Standard: new expiry = payment date + 3 months + 2 years.
          <?php else: ?>Each US$100 payment adds 2 years to your current expiry. Payments stack.<?php endif; ?>
        </div>
        <?php if ($lastRejected): ?>
          <div class="alert alert-warn">Your last plan request<?= !empty($lastRejected['payment_ref']) ? ' (ref ' . e($lastRejected['payment_ref']) . ')' : '' ?> was not approved. <?= e(preg_replace('/^Rejected by admin\.\s*/', '', (string) ($lastRejected['notes'] ?? ''))) ?></div>
        <?php endif; ?>
        <?php if (!$offered): ?><p class="empty">No plans available right now.</p><?php endif; ?>

        <div class="tier-cards">
          <?php foreach ($offered as $pk): $std = $pk['tier'] === 'standard'; $label = $offer($pk); ?>
          <div class="tier-card <?= $std ? 'highlight' : '' ?>">
            <span class="label" style="color:var(--<?= $std ? 'orange' : 'green' ?>)"><?= $std ? 'Standard' : 'Basic' ?> Growth Partner</span>
            <h3 class="tier-name"><?= e($pk['name']) ?></h3>
            <div class="tier-price <?= $std ? 'orange' : '' ?>" style="<?= $std ? '' : 'color:var(--green)' ?>"><?= $std ? '$' . number_format((float) $pk['price'], 2) : 'Free' ?></div>
            <?php if (!empty($pk['description'])): ?><p class="tier-desc"><?= e($pk['description']) ?></p><?php endif; ?>
            <?php if (isset($upcoming[$pk['id']])): $up = $upcoming[$pk['id']]; ?><p class="tier-upcoming">Changing <?= date('M j, Y', strtotime($up['effective_at'])) ?>: <?= e($up['summary']) ?></p><?php endif; ?>
            <ul class="tier-features">
              <?php if ($std): ?>
                <li><?= $tier === 'standard' ? '+2 years on your current expiry' : '2-year term + 3-month activation bonus' ?></li>
                <li>Unlimited renewal · 30-day grace period</li>
                <li>Withdraw any time while active</li>
              <?php else: ?>
                <li>3-month term · 7-day grace period</li>
                <li>No renewal — extend by referrals or upgrade</li>
              <?php endif; ?>
              <li>+3 months per Qualified Client</li>
              <li><?= e(pct_label(commission_pct($token))) ?>% of each referred client's advance deposit</li>
            </ul>

            <?php if (!$std): ?>
              <form method="post"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="choose_package"><input type="hidden" name="package_id" value="<?= e($pk['id']) ?>">
                <button class="btn" type="submit" style="border-color:var(--green);color:var(--green)">Start Basic free — activate now →</button>
              </form>
            <?php else: ?>
              <details class="paywall">
                <summary class="btn btn-solid"><?= e($label) ?> — pay $<?= number_format((float) $pk['price'], 2) ?> via QR →</summary>
                <div class="paywall-body">
                  <?php if (!$payReady): ?>
                    <p class="alert alert-warn" style="margin:0">Payments aren't open yet — an admin still needs to add the payment QR code.</p>
                  <?php elseif (($profile['kyc_status'] ?? 'not_submitted') !== 'approved'): ?>
                    <p class="alert alert-warn" style="margin:0"><?= ($profile['kyc_status'] ?? '') === 'pending' ? 'Your identity documents are being reviewed. You can pay once they are approved.' : 'Verify your identity before paying for Standard. <a href="/kyc.php">Verify now →</a>' ?></p>
                  <?php else: ?>
                    <span class="label">Step 1 // Scan &amp; pay exactly $<?= number_format((float) $pk['price'], 2) ?></span>
                    <img class="paywall-qr" src="<?= e($pay['payment_qr']) ?>" alt="Payment QR code" width="220" height="220">
                    <?php if ($pay['payment_account'] !== ''): ?><p class="paywall-acct"><?= e($pay['payment_account']) ?></p><?php endif; ?>
                    <?php if ($pay['payment_instructions'] !== ''): ?><p class="paywall-note"><?= nl2br(e($pay['payment_instructions'])) ?></p><?php endif; ?>
                    <span class="label" style="display:block;margin-top:14px">Step 2 // Enter your receipt reference</span>
                    <form method="post"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="choose_package"><input type="hidden" name="package_id" value="<?= e($pk['id']) ?>">
                      <div class="field" style="margin:6px 0 10px"><input name="payment_ref" required minlength="4" maxlength="64" pattern="[A-Za-z0-9 #_\-]{4,64}" placeholder="e.g. 1234 567 890123" autocomplete="off"></div>
                      <button class="btn btn-solid" type="submit">I've paid — submit for approval →</button>
                    </form>
                  <?php endif; ?>
                </div>
              </details>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </section>
  <?php endif; ?>

  <?php if (!$gated): ?>
    <div data-live="gp-<?= e($view) ?>">
    <?php if ($view === 'overview'): ?>
      <?= networth_html($token, $profile, (string) ($_GET['period'] ?? 'month'), $base . '?view=overview') ?>
      <?= affiliate_account_html($token, $profile, $canRefer, ['stats']) ?>
      <nav class="quick-grid" aria-label="Growth Partner pages">
        <?php foreach ($pages as $key => $pg): if ($key === 'overview') continue; ?>
          <a class="card quick-card" href="<?= e($base . '?view=' . $key) ?>"><span class="label"><?= e($pg['label']) ?></span><span class="quick-go" aria-hidden="true">→</span></a>
        <?php endforeach; ?>
      </nav>
    <?php elseif ($view === 'share'): ?>
      <?= affiliate_account_html($token, $profile, $canRefer, ['share']) ?>
    <?php elseif ($view === 'clients'): ?>
      <?= clients_referred_html($token, $profile) ?>
    <?php elseif ($view === 'payout'): ?>
      <?= affiliate_account_html($token, $profile, $canRefer, ['stats']) ?>
      <?= payout_page_html($token, $profile, $csrf) ?>
    <?php elseif ($view === 'network'): ?>
      <?= affiliate_account_html($token, $profile, $canRefer, ['network']) ?>
    <?php elseif ($view === 'verify'): ?>
      <?= kyc_section($token, $profile) ?>
    <?php elseif ($view === 'business'): ?>
      <?= business_card($token, $profile, $csrf) ?>
    <?php endif; ?>
    </div>
  <?php endif; ?>
</main>
</div>
<?= ui_foot() ?>
