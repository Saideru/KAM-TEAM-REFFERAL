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
            $res = supabase_request('PATCH', '/rest/v1/profiles?id=eq.' . urlencode($uid), ['role' => $role], $token, ['Prefer: return=minimal']);
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
        if ($pkg['name'] !== '') {
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
        if ($txnId && $status) {
            $res = supabase_request('PATCH', '/rest/v1/transactions?id=eq.' . urlencode($txnId), ['status' => $status], $token, ['Prefer: return=minimal']);
            if (!$res['error']) {
                audit_log($token, 'update_transaction', 'transaction', $txnId, 'Status → ' . $status);
                flash('success', 'Transaction updated.'); header('Location: /dashboard-admin.php#transactions'); exit;
            }
            $errors[] = $res['error'];
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

$refRes  = supabase_request('GET', '/rest/v1/referrals?select=*,referrer:profiles!referrals_referrer_id_fkey(full_name,email),referred:profiles!referrals_referred_id_fkey(full_name,email)&order=created_at.desc', null, $token);
$allRefs = is_array($refRes['body']) && !$refRes['error'] ? $refRes['body'] : [];

$pkgRes  = supabase_request('GET', '/rest/v1/packages?select=*&order=created_at.desc', null, $token);
$allPkgs = is_array($pkgRes['body']) && !$pkgRes['error'] ? $pkgRes['body'] : [];

$txnRes  = supabase_request('GET', '/rest/v1/transactions?select=*,profiles!transactions_user_id_fkey(full_name,email),packages!transactions_package_id_fkey(name)&order=created_at.desc', null, $token);
$allTxns = is_array($txnRes['body']) && !$txnRes['error'] ? $txnRes['body'] : [];

$csRes = supabase_request('GET', '/rest/v1/client_services?select=*,profiles!client_services_client_id_fkey(full_name,email)&order=started_at.desc', null, $token);
$allCS = is_array($csRes['body']) && !$csRes['error'] ? $csRes['body'] : [];

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
  <title>Admin // KAMTEAM Portal</title>
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
  <h1>Admin terminal. <span class="accent">Full oversight.</span></h1>

  <?php if ($notice): ?><div class="alert alert-<?= e($notice['type']) ?>"><?= e($notice['message']) ?></div><?php endif; ?>
  <?php if ($errors): ?><div class="alert alert-error"><?php foreach ($errors as $err): ?><div>> <?= e($err) ?></div><?php endforeach; ?></div><?php endif; ?>

  <section class="grid">
    <div class="card"><span class="label">01 // Users</span><div class="big cyan"><?= $totalUsers ?></div><span class="label" style="font-size:10px;display:block;margin-top:4px"><?= $adminCount ?>A · <?= $basicCount ?>B · <?= $standardCount ?>S · <?= $refCount ?>R</span></div>
    <div class="card"><span class="label">02 // Referrals</span><div class="big"><?= count($allRefs) ?></div></div>
    <div class="card"><span class="label">03 // Packages</span><div class="big"><?= count($allPkgs) ?></div></div>
    <div class="card"><span class="label">04 // Revenue</span><div class="big green">$<?= number_format(array_sum(array_map(fn($t) => $t['type'] === 'activation' && $t['status'] === 'completed' ? (float) $t['amount'] : 0, $allTxns)), 2) ?></div></div>
  </section>

  <div class="admin-tabs">
    <button class="admin-tab active" data-panel="users">Users</button>
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
        <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Lifecycle</th><th>Code</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($allProfiles as $p): $deleted = !empty($p['deleted_at']); ?>
          <tr style="<?= $deleted ? 'opacity:.5' : '' ?>">
            <td><strong><?= e($p['full_name']) ?></strong><?= $deleted ? ' <span style="color:var(--red)">[DEL]</span>' : '' ?></td>
            <td style="font-size:12px"><?= e($p['email']) ?></td>
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

  <!-- REFERRALS -->
  <div id="panel-referrals" class="admin-panel">
    <div class="card"><h2>Referral network</h2>
      <?php if (!$allRefs): ?><p class="empty">No referrals yet.</p>
      <?php else: ?><div class="table-scroll"><table>
        <thead><tr><th>Referrer</th><th>Referred</th><th>Level</th><th>Status</th><th>Created</th></tr></thead>
        <tbody><?php foreach ($allRefs as $r): ?>
          <tr>
            <td><strong><?= e($r['referrer']['full_name'] ?? '—') ?></strong><br><span style="font-size:11px;color:var(--text-dim)"><?= e($r['referrer']['email'] ?? '') ?></span></td>
            <td><strong><?= e($r['referred']['full_name'] ?? '—') ?></strong><br><span style="font-size:11px;color:var(--text-dim)"><?= e($r['referred']['email'] ?? '') ?></span></td>
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
            <td>$<?= number_format((float) $pk['price'], 2) ?></td>
            <td>$<?= number_format((float) $pk['commission_outright'], 2) ?></td>
            <td><?= e($pk['commission_unilevel']) ?>%</td>
            <td><?= $pk['active'] ? '<span style="color:var(--green)">Yes</span>' : '<span style="color:var(--red)">No</span>' ?></td>
          </tr>
        <?php endforeach; ?></tbody>
      </table></div><?php endif; ?>
      <div class="assign-form"><h3>+ Create package</h3>
        <form method="post"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="admin_action" value="create_package">
          <div class="row" style="margin-bottom:10px"><div class="field"><label>Name</label><input name="pkg_name" required placeholder="Package name"></div><div class="field"><label>Tier</label><select name="pkg_tier"><option value="basic">Basic</option><option value="standard">Standard</option></select></div></div>
          <div class="row" style="margin-bottom:10px"><div class="field"><label>Price ($)</label><input name="pkg_price" type="number" step="0.01" min="0" value="0"></div><div class="field"><label>Outright ($)</label><input name="pkg_comm_out" type="number" step="0.01" min="0" value="0"></div></div>
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
