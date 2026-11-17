<?php
require_once __DIR__ . '/lib/supabase.php';
require_once __DIR__ . '/lib/logo.php';

['token' => $token, 'profile' => $profile] = require_role('admin');
$errors = [];
$notice = flash();
$csrf   = csrf_token();

$roleOptions      = ['admin', 'client_basic', 'client_standard', 'referral'];
$statusOptions    = ['pending', 'accepted', 'suspended'];
$svcStatusList    = ['active', 'paused', 'completed'];
$serviceList      = ['S-01 Marketing', 'S-02 Virtual Assistance', 'S-03 Video Editing', 'S-04 Accounting', 'S-05 Call Center Support', 'S-06 Project Management', 'S-07 AI Implementation'];
$txnStatusOptions = ['pending', 'completed', 'failed', 'reversed'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check()) {
    $act = $_POST['admin_action'] ?? '';

    if ($act === 'change_role') {
        $uid  = $_POST['user_id'] ?? '';
        $role = in_array($_POST['new_role'] ?? '', $roleOptions, true) ? $_POST['new_role'] : null;
        if ($uid && $role) {
            $patch = ['role' => $role];
            // Moving someone onto a client tier by hand starts that tier's lifecycle.
            if (isset(TIER_MONTHS[$role])) $patch['lifecycle_expires_at'] = tier_expiry($role);
            $res = supabase_request('PATCH', '/rest/v1/profiles?id=eq.' . urlencode($uid), $patch, $token, ['Prefer: return=minimal']);
            if (!$res['error']) {
                audit_log($token, 'role_change', 'profile', $uid, 'Changed role to ' . $role);
                flash('success', 'Role updated.'); header('Location: /dashboard-admin.php#users'); exit;
            }
            $errors[] = $res['error'];
        }
    }

    if ($act === 'change_status') {
        $uid    = $_POST['user_id'] ?? '';
        $status = in_array($_POST['new_status'] ?? '', $statusOptions, true) ? $_POST['new_status'] : null;
        if ($uid && $status) {
            $patch = ['status' => $status];
            if ($status === 'accepted') {
                $targetRes = supabase_request('GET', '/rest/v1/profiles?select=role,lifecycle_expires_at&id=eq.' . urlencode($uid), null, $token);
                $target = $targetRes['body'][0] ?? null;
                if ($target && !$target['lifecycle_expires_at']) {
                    $months = match ($target['role']) { 'client_basic' => 3, 'client_standard' => 27, default => 0 };
                    if ($months > 0) $patch['lifecycle_expires_at'] = date('c', strtotime("+{$months} months"));
                }
            }
            $res = supabase_request('PATCH', '/rest/v1/profiles?id=eq.' . urlencode($uid), $patch, $token, ['Prefer: return=minimal']);
            if (!$res['error']) {
                audit_log($token, 'status_change', 'profile', $uid, 'Status → ' . $status);
                flash('success', 'Status updated.'); header('Location: /dashboard-admin.php#users'); exit;
            }
            $errors[] = $res['error'];
        }
    }

    if ($act === 'soft_delete') {
        $uid = $_POST['user_id'] ?? '';
        if ($uid) {
            $res = supabase_request('PATCH', '/rest/v1/profiles?id=eq.' . urlencode($uid), ['deleted_at' => date('c'), 'status' => 'suspended'], $token, ['Prefer: return=minimal']);
            if (!$res['error']) {
                audit_log($token, 'soft_delete', 'profile', $uid, 'User soft-deleted');
                flash('success', 'User trashed.'); header('Location: /dashboard-admin.php#users'); exit;
            }
            $errors[] = $res['error'];
        }
    }

    if ($act === 'restore_user') {
        $uid = $_POST['user_id'] ?? '';
        if ($uid) {
            $res = supabase_request('PATCH', '/rest/v1/profiles?id=eq.' . urlencode($uid), ['deleted_at' => null, 'status' => 'pending'], $token, ['Prefer: return=minimal']);
            if (!$res['error']) {
                audit_log($token, 'restore', 'profile', $uid, 'User restored');
                flash('success', 'User restored.'); header('Location: /dashboard-admin.php#users'); exit;
            }
            $errors[] = $res['error'];
        }
    }

    if ($act === 'create_package') {
        $pkg = [
            'name' => trim($_POST['pkg_name'] ?? ''), 'description' => trim($_POST['pkg_desc'] ?? '') ?: null,
            'price' => max(0, (float) ($_POST['pkg_price'] ?? 0)), 'commission_outright' => max(0, (float) ($_POST['pkg_comm_out'] ?? 0)),
            'commission_unilevel' => max(0, (float) ($_POST['pkg_comm_uni'] ?? 0)),
            'tier' => in_array($_POST['pkg_tier'] ?? '', ['basic', 'standard'], true) ? $_POST['pkg_tier'] : 'basic',
        ];
        if ($pkg['tier'] === 'basic') $pkg['price'] = 0;   // Basic packages are always free
        if ($pkg['tier'] === 'standard' && $pkg['price'] <= 0) {
            $errors[] = 'Standard packages need a price — referrals pay it via the QR paywall.';
        } elseif ($pkg['name'] !== '') {
            $res = supabase_request('POST', '/rest/v1/packages', $pkg, $token, ['Prefer: return=minimal']);
            if (!$res['error']) {
                audit_log($token, 'create_package', 'package', null, 'Created: ' . $pkg['name']);
                flash('success', 'Package created.'); header('Location: /dashboard-admin.php#packages'); exit;
            }
            $errors[] = $res['error'];
        }
    }

    if ($act === 'update_txn') {
        $txnId  = $_POST['txn_id'] ?? '';
        $status = in_array($_POST['txn_status'] ?? '', $txnStatusOptions, true) ? $_POST['txn_status'] : null;
        $txn = $txnId ? fetch_activation_txn($token, $txnId) : null;
        if ($txn && $txn['status'] === 'pending' && in_array($status, ['completed', 'failed'], true)) {
            // A pending activation must go through approve/reject so the account tier follows the payment.
            $err = $status === 'completed' ? approve_activation($token, $txnId, $profile['id']) : reject_activation($token, $txnId, $profile['id']);
            if (!$err) { flash('success', $status === 'completed' ? 'Payment approved — account activated.' : 'Payment rejected.'); header('Location: /dashboard-admin.php#transactions'); exit; }
            $errors[] = $err;
        } elseif ($txnId && $status) {
            $res = supabase_request('PATCH', '/rest/v1/transactions?id=eq.' . urlencode($txnId), ['status' => $status], $token, ['Prefer: return=minimal']);
            if (!$res['error']) {
                audit_log($token, 'update_transaction', 'transaction', $txnId, 'Status → ' . $status);
                flash('success', 'Transaction updated.'); header('Location: /dashboard-admin.php#transactions'); exit;
            }
            $errors[] = $res['error'];
        }
    }

    if ($act === 'approve_payment' || $act === 'reject_payment') {
        $txnId = (string) ($_POST['txn_id'] ?? '');
        $err = $act === 'approve_payment'
            ? approve_activation($token, $txnId, $profile['id'])
            : reject_activation($token, $txnId, $profile['id'], trim((string) ($_POST['reason'] ?? '')));
        if (!$err) {
            flash('success', $act === 'approve_payment' ? 'Payment approved — account upgraded.' : 'Payment rejected. The user can submit again.');
            header('Location: /dashboard-admin.php#approvals'); exit;
        }
        $errors[] = $err;
    }

    if ($act === 'save_payment') {
        $ok = true;
        if (!empty($_FILES['pay_qr']['tmp_name']) && is_uploaded_file($_FILES['pay_qr']['tmp_name'])) {
            $mime = (@getimagesize($_FILES['pay_qr']['tmp_name']))['mime'] ?? '';
            if (!in_array($mime, ['image/png', 'image/jpeg', 'image/webp', 'image/gif'], true)) {
                $errors[] = 'QR code must be a PNG, JPG, WEBP or GIF image.'; $ok = false;
            } elseif ($_FILES['pay_qr']['size'] > 400 * 1024) {
                $errors[] = 'QR image must be under 400 KB.'; $ok = false;
            } else {
                $dataUri = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($_FILES['pay_qr']['tmp_name']));
                if ($saveErr = set_setting($token, 'payment_qr', $dataUri)) { $errors[] = $saveErr; $ok = false; }
            }
        }
        if (!empty($_POST['pay_qr_remove'])) set_setting($token, 'payment_qr', null);
        if ($ok) {
            set_setting($token, 'payment_account', mb_substr(trim((string) ($_POST['pay_account'] ?? '')), 0, 200));
            set_setting($token, 'payment_instructions', mb_substr(trim((string) ($_POST['pay_instructions'] ?? '')), 0, 1000));
            audit_log($token, 'payment_settings', 'app_settings', null, 'Updated payment QR settings');
            flash('success', 'Payment settings saved.'); header('Location: /dashboard-admin.php#payment'); exit;
        }
    }

    if ($act === 'assign_service') {
        $clientId = $_POST['client_id'] ?? '';
        $service  = in_array($_POST['service'] ?? '', $serviceList, true) ? $_POST['service'] : null;
        $svcNotes = trim((string) ($_POST['svc_notes'] ?? '')) ?: null;
        if ($clientId && $service) {
            $res = supabase_request('POST', '/rest/v1/client_services', ['client_id' => $clientId, 'service' => $service, 'notes' => $svcNotes], $token, ['Prefer: return=minimal']);
            if (!$res['error']) {
                audit_log($token, 'assign_service', 'client_service', null, $service . ' → ' . $clientId);
                flash('success', 'Service assigned.'); header('Location: /dashboard-admin.php#services'); exit;
            }
            $errors[] = $res['error'];
        }
    }
}

// Fetch data
$profilesRes = supabase_request('GET', '/rest/v1/profiles?select=*&order=created_at.desc', null, $token);
$allProfiles = is_array($profilesRes['body']) && !$profilesRes['error'] ? $profilesRes['body'] : [];

$refRes  = supabase_request('GET', '/rest/v1/referrals?select=*,referrer:profiles!referrals_referrer_id_fkey(full_name,username),referred:profiles!referrals_referred_id_fkey(full_name,username)&order=created_at.desc', null, $token);
$allRefs = is_array($refRes['body']) && !$refRes['error'] ? $refRes['body'] : [];

$pkgRes  = supabase_request('GET', '/rest/v1/packages?select=*&order=created_at.desc', null, $token);
$allPkgs = is_array($pkgRes['body']) && !$pkgRes['error'] ? $pkgRes['body'] : [];

$txnRes  = supabase_request('GET', '/rest/v1/transactions?select=*,profiles!transactions_user_id_fkey(full_name,username,email,phone),packages!transactions_package_id_fkey(name,tier)&order=created_at.desc', null, $token);
$allTxns = is_array($txnRes['body']) && !$txnRes['error'] ? $txnRes['body'] : [];

$csRes = supabase_request('GET', '/rest/v1/client_services?select=*,profiles!client_services_client_id_fkey(full_name,email)&order=started_at.desc', null, $token);
$allCS = is_array($csRes['body']) && !$csRes['error'] ? $csRes['body'] : [];

$pendingPayments = array_values(array_filter($allTxns, fn($t) => $t['type'] === 'activation' && $t['status'] === 'pending'));
$pay = get_settings($token, ['payment_qr', 'payment_account', 'payment_instructions']);

$logsRes = supabase_request('GET', '/rest/v1/system_logs?select=*,profiles!system_logs_actor_id_fkey(full_name)&order=created_at.desc&limit=100', null, $token);
$allLogs = is_array($logsRes['body']) && !$logsRes['error'] ? $logsRes['body'] : [];

$totalUsers    = count(array_filter($allProfiles, fn($p) => empty($p['deleted_at'])));
$adminCount    = count(array_filter($allProfiles, fn($p) => $p['role'] === 'admin' && empty($p['deleted_at'])));
$basicCount    = count(array_filter($allProfiles, fn($p) => $p['role'] === 'client_basic' && empty($p['deleted_at'])));
$standardCount = count(array_filter($allProfiles, fn($p) => $p['role'] === 'client_standard' && empty($p['deleted_at'])));
$refCount      = count(array_filter($allProfiles, fn($p) => $p['role'] === 'referral' && empty($p['deleted_at'])));

$clients = array_filter($allProfiles, fn($p) => in_array($p['role'], ['client_basic', 'client_standard'], true) && empty($p['deleted_at']));
$firstName = $profile ? explode(' ', $profile['full_name'])[0] : 'Admin';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Admin // KAM Team</title>
  <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 20 20'><rect fill='%230a0a0f' width='20' height='20' rx='4'/><text x='3' y='15' font-size='13' fill='%2300f0ff'>A</text></svg>">
  <link rel="stylesheet" href="/assets/styles.css">
</head>
<body>
<header class="topbar">
  <div class="topbar-inner">
    <?= kam_lockup() ?>
    <div style="display:flex;align-items:center;gap:10px">
      <span class="role-label admin">Admin</span>
      <form method="post" action="/logout.php" style="margin:0"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><button class="btn btn-ghost btn-sm" type="submit">Logout</button></form>
    </div>
  </div>
</header>

<main class="wrap">
  <span class="label"><span class="dot"></span>Mission control // <?= e($profile['full_name'] ?? '') ?></span>
  <h1>Admin dashboard. <span class="accent">Manage the program.</span></h1>

  <?php if ($notice): ?><div class="alert alert-<?= e($notice['type']) ?>"><?= e($notice['message']) ?></div><?php endif; ?>
  <?php if ($errors): ?><div class="alert alert-error"><?php foreach ($errors as $err): ?><div>> <?= e($err) ?></div><?php endforeach; ?></div><?php endif; ?>

  <section class="grid">
    <div class="card"><span class="label">01 // Users</span><div class="big cyan"><?= $totalUsers ?></div><span class="label" style="font-size:10px;display:block;margin-top:4px"><?= $adminCount ?>A · <?= $basicCount ?>B · <?= $standardCount ?>S · <?= $refCount ?>R</span></div>
    <div class="card"><span class="label">02 // Referrals</span><div class="big"><?= count($allRefs) ?></div></div>
    <div class="card"><span class="label">03 // Packages</span><div class="big"><?= count($allPkgs) ?></div></div>
    <div class="card"><span class="label">04 // Revenue</span><div class="big green">$<?= number_format(array_sum(array_map(fn($t) => $t['type'] === 'activation' && $t['status'] === 'completed' ? (float) $t['amount'] : 0, $allTxns)), 2) ?></div></div>
  </section>

  <?php if ($pendingPayments): ?>
    <div class="alert alert-warn"><strong><?= count($pendingPayments) ?> payment<?= count($pendingPayments) === 1 ? '' : 's' ?> waiting for approval.</strong> <a href="#approvals" onclick="document.querySelector('.admin-tab[data-panel=approvals]').click()">Review now →</a></div>
  <?php endif; ?>
  <?php if ($pay['payment_qr'] === ''): ?>
    <div class="alert alert-warn">No payment QR code yet — referrals can't pay for Standard until you add one in the <a href="#payment" onclick="document.querySelector('.admin-tab[data-panel=payment]').click()">Payment tab</a>.</div>
  <?php endif; ?>

  <div class="admin-tabs">
    <button class="admin-tab active" data-panel="users">Users</button>
    <button class="admin-tab" data-panel="approvals">Approvals<?= $pendingPayments ? ' <span class="tab-count">' . count($pendingPayments) . '</span>' : '' ?></button>
    <button class="admin-tab" data-panel="payment">Payment</button>
    <button class="admin-tab" data-panel="referrals">Referrals</button>
    <button class="admin-tab" data-panel="packages">Packages</button>
    <button class="admin-tab" data-panel="transactions">Transactions</button>
    <button class="admin-tab" data-panel="services">Services</button>
    <button class="admin-tab" data-panel="logs">Audit logs</button>
  </div>

  <!-- USERS -->
  <div id="panel-users" class="admin-panel active">
    <div class="card"><h2>All users</h2>
      <div class="table-scroll"><table>
        <thead><tr><th>Name</th><th>Username</th><th>Role</th><th>Status</th><th>Lifecycle</th><th>Code</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($allProfiles as $p): $deleted = !empty($p['deleted_at']); ?>
          <tr style="<?= $deleted ? 'opacity:.5' : '' ?>">
            <td><strong><?= e($p['full_name']) ?></strong><?= $deleted ? ' <span style="color:var(--red)">[DEL]</span>' : '' ?></td>
            <td style="font-size:12px"><?= e($p['username']) ?><?php if (!empty($p['email'])): ?><br><span style="font-size:11px;color:var(--text-dim)"><?= e($p['email']) ?></span><?php endif; ?></td>
            <td><span class="role-badge <?= e($p['role']) ?>"><?= e(str_replace('_', ' ', $p['role'])) ?></span></td>
            <td><span class="pill <?= e($p['status']) ?>"><?= e($p['status']) ?></span></td>
            <td style="font-size:12px"><?= $p['lifecycle_expires_at'] ? date('M j, Y', strtotime($p['lifecycle_expires_at'])) : '—' ?></td>
            <td style="font-size:11px"><?= e($p['referral_code']) ?></td>
            <td><?php if ($deleted): ?>
              <form method="post" class="inline-form"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="admin_action" value="restore_user"><input type="hidden" name="user_id" value="<?= e($p['id']) ?>"><button class="btn btn-sm" type="submit">Restore</button></form>
            <?php else: ?>
              <div style="display:flex;gap:4px;flex-wrap:wrap">
                <form method="post" class="inline-form"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="admin_action" value="change_role"><input type="hidden" name="user_id" value="<?= e($p['id']) ?>"><select name="new_role"><?php foreach ($roleOptions as $ro): ?><option <?= $p['role'] === $ro ? 'selected' : '' ?>><?= e($ro) ?></option><?php endforeach; ?></select><button class="btn btn-sm" type="submit">Set</button></form>
                <form method="post" class="inline-form"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="admin_action" value="change_status"><input type="hidden" name="user_id" value="<?= e($p['id']) ?>"><select name="new_status"><?php foreach ($statusOptions as $so): ?><option <?= $p['status'] === $so ? 'selected' : '' ?>><?= e($so) ?></option><?php endforeach; ?></select><button class="btn btn-sm" type="submit">Set</button></form>
                <form method="post" class="inline-form" onsubmit="return confirm('Soft-delete this user?')"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="admin_action" value="soft_delete"><input type="hidden" name="user_id" value="<?= e($p['id']) ?>"><button class="btn btn-sm btn-danger" type="submit">Trash</button></form>
              </div>
            <?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    </div>
  </div>

  <!-- APPROVALS -->
  <div id="panel-approvals" class="admin-panel">
    <div class="card"><h2>Standard account requests</h2>
      <p style="font-size:13px;color:var(--text-dim);margin:0 0 12px">Check each reference number against your payment app before approving. Approving upgrades the user to the tier of the package they paid for and starts their countdown.</p>
      <?php if (!$pendingPayments): ?><p class="empty">No payments waiting for approval.</p>
      <?php else: ?><div class="table-scroll"><table>
        <thead><tr><th>User</th><th>Package</th><th>Amount</th><th>Reference</th><th>Submitted</th><th>Decision</th></tr></thead>
        <tbody><?php foreach ($pendingPayments as $t): ?>
          <tr>
            <td><strong><?= e($t['profiles']['full_name'] ?? '—') ?></strong><br><span style="font-size:11px;color:var(--text-dim)"><?= e($t['profiles']['username'] ?? '') ?><?= !empty($t['profiles']['phone']) ? ' · ' . e($t['profiles']['phone']) : '' ?></span></td>
            <td><?= e($t['packages']['name'] ?? '—') ?><br><span class="role-badge <?= e(tier_role($t['packages']['tier'] ?? 'standard')) ?>"><?= e($t['packages']['tier'] ?? 'standard') ?></span></td>
            <td style="color:var(--green);font-weight:600">$<?= number_format((float) $t['amount'], 2) ?></td>
            <td style="font-family:var(--font);font-size:13px"><?= e($t['payment_ref'] ?? '—') ?></td>
            <td style="font-size:12px"><?= date('M j, Y H:i', strtotime($t['created_at'])) ?></td>
            <td>
              <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">
                <form method="post" class="inline-form" onsubmit="return confirm('Approve this payment and upgrade the account?')"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="admin_action" value="approve_payment"><input type="hidden" name="txn_id" value="<?= e($t['id']) ?>"><button class="btn btn-sm btn-solid" type="submit">Approve</button></form>
                <form method="post" class="inline-form"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="admin_action" value="reject_payment"><input type="hidden" name="txn_id" value="<?= e($t['id']) ?>"><input name="reason" type="text" maxlength="200" placeholder="Reason (optional)" style="width:140px"><button class="btn btn-sm btn-danger" type="submit">Reject</button></form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?></tbody>
      </table></div><?php endif; ?>
    </div>
  </div>

  <!-- PAYMENT SETTINGS -->
  <div id="panel-payment" class="admin-panel">
    <div class="card"><h2>QR paywall</h2>
      <p style="font-size:13px;color:var(--text-dim);margin:0 0 12px">Referrals scan this QR code to pay for a Standard package, then enter their receipt reference for you to approve.</p>
      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="admin_action" value="save_payment">
        <div class="paywall-admin">
          <div>
            <?php if ($pay['payment_qr'] !== ''): ?>
              <img class="paywall-qr" src="<?= e($pay['payment_qr']) ?>" alt="Current payment QR" width="200" height="200">
              <label class="check" style="margin-top:8px"><input type="checkbox" name="pay_qr_remove" value="1"> Remove this QR</label>
            <?php else: ?>
              <div class="paywall-qr paywall-qr-empty">No QR yet</div>
            <?php endif; ?>
          </div>
          <div style="flex:1;min-width:220px">
            <div class="field"><label>QR image (PNG/JPG/WEBP, max 400 KB)</label><input type="file" name="pay_qr" accept="image/png,image/jpeg,image/webp,image/gif"></div>
            <div class="field"><label>Account name / number</label><input name="pay_account" maxlength="200" value="<?= e($pay['payment_account']) ?>" placeholder="e.g. GCash · KAM Team · 0917 000 0000"></div>
            <div class="field"><label>Instructions</label><textarea name="pay_instructions" rows="3" maxlength="1000" placeholder="e.g. Put your username in the payment note."><?= e($pay['payment_instructions']) ?></textarea></div>
            <button class="btn btn-sm btn-solid" type="submit">Save payment settings →</button>
          </div>
        </div>
      </form>
    </div>
  </div>

  <!-- REFERRALS -->
  <div id="panel-referrals" class="admin-panel">
    <div class="card"><h2>Referral network</h2>
      <?php if (!$allRefs): ?><p class="empty">No referrals yet.</p>
      <?php else: ?><div class="table-scroll"><table>
        <thead><tr><th>Referrer</th><th>Referred</th><th>Level</th><th>Status</th><th>Created</th></tr></thead>
        <tbody><?php foreach ($allRefs as $r): ?>
          <tr>
            <td><strong><?= e($r['referrer']['full_name'] ?? '—') ?></strong><br><span style="font-size:11px;color:var(--text-dim)"><?= e($r['referrer']['username'] ?? '') ?></span></td>
            <td><strong><?= e($r['referred']['full_name'] ?? '—') ?></strong><br><span style="font-size:11px;color:var(--text-dim)"><?= e($r['referred']['username'] ?? '') ?></span></td>
            <td>L<?= e((string) $r['level']) ?></td>
            <td><span class="pill <?= e($r['status']) ?>"><?= e($r['status']) ?></span></td>
            <td style="font-size:12px"><?= date('M j, Y', strtotime($r['created_at'])) ?></td>
          </tr>
        <?php endforeach; ?></tbody>
      </table></div><?php endif; ?>
    </div>
  </div>

  <!-- PACKAGES -->
  <div id="panel-packages" class="admin-panel">
    <div class="card"><h2>Packages</h2>
      <?php if ($allPkgs): ?><div class="table-scroll"><table>
        <thead><tr><th>Name</th><th>Tier</th><th>Price</th><th>Outright</th><th>Unilevel %</th><th>Active</th></tr></thead>
        <tbody><?php foreach ($allPkgs as $pk): ?>
          <tr>
            <td><strong><?= e($pk['name']) ?></strong><br><span style="font-size:11px;color:var(--text-dim)"><?= e($pk['description'] ?? '') ?></span></td>
            <td><span class="role-badge <?= $pk['tier'] === 'standard' ? 'client_standard' : 'client_basic' ?>"><?= e($pk['tier']) ?></span></td>
            <td><?= $pk['tier'] === 'basic' ? '<span style="color:var(--green)">Free</span>' : '$' . number_format((float) $pk['price'], 2) ?></td>
            <td>$<?= number_format((float) $pk['commission_outright'], 2) ?></td>
            <td><?= e($pk['commission_unilevel']) ?>%</td>
            <td><?= $pk['active'] ? '<span style="color:var(--green)">Yes</span>' : '<span style="color:var(--red)">No</span>' ?></td>
          </tr>
        <?php endforeach; ?></tbody>
      </table></div><?php endif; ?>
      <div class="assign-form"><h3>+ Create package</h3>
        <form method="post"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="admin_action" value="create_package">
          <div class="row" style="margin-bottom:10px"><div class="field"><label>Name</label><input name="pkg_name" required placeholder="Package name"></div><div class="field"><label>Tier</label><select name="pkg_tier"><option value="basic">Basic</option><option value="standard">Standard</option></select></div></div>
          <div class="row" style="margin-bottom:10px"><div class="field"><label>Price ($)</label><input name="pkg_price" type="number" step="0.01" min="0" value="0"><div class="hint">Ignored for Basic — Basic is always free.</div></div><div class="field"><label>Outright ($)</label><input name="pkg_comm_out" type="number" step="0.01" min="0" value="0"></div></div>
          <div class="row" style="margin-bottom:10px"><div class="field"><label>Unilevel (%)</label><input name="pkg_comm_uni" type="number" step="0.01" min="0" value="0"></div><div class="field"><label>Description</label><input name="pkg_desc" placeholder="Optional"></div></div>
          <button class="btn btn-sm btn-solid" type="submit">Create package →</button>
        </form>
      </div>
    </div>
  </div>

  <!-- TRANSACTIONS -->
  <div id="panel-transactions" class="admin-panel">
    <div class="card"><h2>Transaction ledger</h2>
      <?php if (!$allTxns): ?><p class="empty">No transactions.</p>
      <?php else: ?><div class="table-scroll"><table>
        <thead><tr><th>User</th><th>Type</th><th>Amount</th><th>Package</th><th>Status</th><th>Date</th><th>Update</th></tr></thead>
        <tbody><?php foreach ($allTxns as $t): ?>
          <tr>
            <td><strong><?= e($t['profiles']['full_name'] ?? '—') ?></strong></td>
            <td><span class="pill"><?= e($t['type']) ?></span></td>
            <td style="color:<?= $t['type'] === 'payout' ? 'var(--red)' : 'var(--green)' ?>">$<?= number_format((float) $t['amount'], 2) ?></td>
            <td style="font-size:12px"><?= e($t['packages']['name'] ?? '—') ?></td>
            <td><span class="pill <?= e($t['status']) ?>"><?= e($t['status']) ?></span></td>
            <td style="font-size:12px"><?= date('M j H:i', strtotime($t['created_at'])) ?></td>
            <td><form method="post" class="inline-form"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="admin_action" value="update_txn"><input type="hidden" name="txn_id" value="<?= e($t['id']) ?>"><select name="txn_status"><?php foreach ($txnStatusOptions as $ts): ?><option <?= $t['status'] === $ts ? 'selected' : '' ?>><?= e($ts) ?></option><?php endforeach; ?></select><button class="btn btn-sm" type="submit">Save</button></form></td>
          </tr>
        <?php endforeach; ?></tbody>
      </table></div><?php endif; ?>
    </div>
  </div>

  <!-- SERVICES -->
  <div id="panel-services" class="admin-panel">
    <div class="card"><h2>Client services</h2>
      <?php if ($allCS): ?><div class="table-scroll"><table>
        <thead><tr><th>Client</th><th>Service</th><th>Status</th><th>Started</th><th>Notes</th></tr></thead>
        <tbody><?php foreach ($allCS as $cs): ?>
          <tr><td><strong><?= e($cs['profiles']['full_name'] ?? '—') ?></strong></td><td><?= e($cs['service']) ?></td><td><span class="pill <?= e($cs['status']) ?>"><?= e($cs['status']) ?></span></td><td style="font-size:12px"><?= date('M j, Y', strtotime($cs['started_at'])) ?></td><td style="font-size:12px;max-width:200px"><?= e($cs['notes'] ?? '—') ?></td></tr>
        <?php endforeach; ?></tbody>
      </table></div><?php endif; ?>
      <div class="assign-form"><h3>+ Assign service</h3>
        <?php if (!$clients): ?><p style="font-size:13px;color:var(--text-dim)">No clients.</p>
        <?php else: ?><form method="post" class="inline-form" style="flex-wrap:wrap;gap:8px"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="admin_action" value="assign_service"><select name="client_id" required><option value="">Select client…</option><?php foreach ($clients as $c): ?><option value="<?= e($c['id']) ?>"><?= e($c['full_name']) ?></option><?php endforeach; ?></select><select name="service" required><?php foreach ($serviceList as $sv): ?><option><?= e($sv) ?></option><?php endforeach; ?></select><input name="svc_notes" type="text" placeholder="Notes" style="flex:1;min-width:120px"><button class="btn btn-sm btn-solid" type="submit">Assign →</button></form><?php endif; ?>
      </div>
    </div>
  </div>

  <!-- AUDIT LOGS -->
  <div id="panel-logs" class="admin-panel">
    <div class="card"><h2>System audit log</h2>
      <div class="terminal-header"><span class="dots"><span></span><span></span><span></span></span><span>system_logs // last 100</span></div>
      <div class="terminal-body" style="max-height:500px;overflow-y:auto;font-size:12px">
        <?php if (!$allLogs): ?><div class="prompt">$ tail -f /var/log/kamteam.log</div><div class="output">[ waiting... ]</div>
        <?php else: foreach ($allLogs as $log): ?>
          <div style="padding:4px 0;border-bottom:1px solid var(--border)">
            <span style="color:var(--text-dim)"><?= date('Y-m-d H:i:s', strtotime($log['created_at'])) ?></span>
            <span style="color:var(--cyan)">[<?= e($log['action']) ?>]</span>
            <span><?= e($log['summary'] ?? '') ?></span>
            <span style="color:var(--text-dim)">by <?= e($log['profiles']['full_name'] ?? 'system') ?></span>
            <?php if ($log['ip_address']): ?><span style="color:var(--text-dim);font-size:10px">(<?= e($log['ip_address']) ?>)</span><?php endif; ?>
          </div>
        <?php endforeach; endif; ?>
      </div>
    </div>
  </div>
</main>

<script>
  (function () {
    var tabs = document.querySelectorAll('.admin-tab'), panels = document.querySelectorAll('.admin-panel');
    tabs.forEach(function (tab) {
      tab.addEventListener('click', function () {
        tabs.forEach(function (t) { t.classList.remove('active'); });
        panels.forEach(function (p) { p.classList.remove('active'); });
        tab.classList.add('active');
        document.getElementById('panel-' + tab.dataset.panel).classList.add('active');
        history.replaceState(null, '', '#' + tab.dataset.panel);
      });
    });
    var hash = location.hash.replace('#', '');
    if (hash) { var t = document.querySelector('.admin-tab[data-panel="' + hash + '"]'); if (t) t.click(); }
  })();
</script>
</body>
</html>
