<?php
require_once __DIR__ . '/lib/supabase.php';
require_once __DIR__ . '/lib/logo.php';
require_once __DIR__ . '/lib/ui.php';

['token' => $token, 'profile' => $profile] = require_role('admin');
$errors = [];
$notice = flash();
$csrf   = csrf_token();

$roleOptions      = ['admin', 'staff', 'growth_partner', 'client'];
// What an existing admin account may be changed to: Staff, or stay Admin.
const ADMIN_ROLE_TARGETS = ['admin', 'staff'];
$statusOptions    = ['pending', 'accepted', 'suspended', 'rejected'];
$refStatusOptions = ['pending', 'active', 'completed', 'cancelled'];
$audienceOptions  = ['all' => 'Everyone', 'client' => 'Members (Client + Growth Partner)', 'staff' => 'Staff', 'admin' => 'Admins'];
$adminId          = $profile['id'];
$back = fn(string $panel) => header('Location: /dashboard-admin.php#' . $panel);
$svcStatusList    = ['active', 'paused', 'completed'];
$serviceList      = ['S-01 Marketing', 'S-02 Virtual Assistance', 'S-03 Video Editing', 'S-04 Accounting', 'S-05 Call Center Support', 'S-06 Project Management', 'S-07 AI Implementation'];
$txnStatusOptions = ['pending', 'completed', 'failed', 'reversed'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check()) {
    $act = $_POST['admin_action'] ?? '';
    // One "Set" per row in All users posts both pickers as update_account;
    // only the ones that actually changed are applied, through the same
    // role / status rules below.
    $batch = [];
    $done  = [];
    if ($act === 'update_account') {
        $uid = (string) ($_POST['user_id'] ?? '');
        $cur = $uid ? (supabase_request('GET', '/rest/v1/profiles?select=role,status&id=eq.' . urlencode($uid), null, $token)['body'][0] ?? null) : null;
        if ($cur && isset($_POST['new_role']) && $_POST['new_role'] !== $cur['role']) $batch[] = 'change_role';
        if ($cur && isset($_POST['new_status']) && $_POST['new_status'] !== $cur['status']) $batch[] = 'change_status';
        if ($cur && !$batch) { flash('success', 'Nothing changed.'); $back('users'); exit; }
    }

    if ($act === 'change_role' || in_array('change_role', $batch, true)) {
        $uid  = $_POST['user_id'] ?? '';
        $role = in_array($_POST['new_role'] ?? '', $roleOptions, true) ? $_POST['new_role'] : null;
        $cur = $uid ? (supabase_request('GET', '/rest/v1/profiles?select=role,affiliate_tier&id=eq.' . urlencode($uid), null, $token)['body'][0] ?? null) : null;

        // An admin account can only step down to Staff. Going straight to a
        // member account would hand it a referral side and a plan, so that
        // has to be a deliberate second step from Staff.
        if ($cur && $cur['role'] === 'admin' && !in_array($role, ADMIN_ROLE_TARGETS, true)) {
            $errors[] = 'An admin account can only be changed to Staff. Move it to Staff first if it should become a member.';
        } elseif ($cur && $uid === $adminId && $role !== 'admin') {
            $errors[] = 'You cannot change your own role — ask another admin to do it.';
        } elseif ($uid && $role) {
            $patch = ['role' => $role, 'affiliate_tier' => partner_tier_for_role($role, $cur['affiliate_tier'] ?? null)];
            // Only a member's partner side runs on a clock. An admin's never
            // expires; staff have no partner account at all.
            if (!in_array($role, ['client', 'growth_partner'], true) || $patch['affiliate_tier'] === null || $patch['affiliate_tier'] === TIER_UNLIMITED) {
                $patch['lifecycle_expires_at'] = null;
            }
            $res = supabase_request('PATCH', '/rest/v1/profiles?id=eq.' . urlencode($uid), $patch, $token, ['Prefer: return=minimal']);
            if (!$res['error']) {
                audit_log($token, 'role_change', 'profile', $uid, 'Changed role to ' . $role);
                notify($token, 'Your account type changed', 'An admin moved your account to ' . role_name($role) . '.', $uid);
                $done[] = 'Role updated.';
            }
            $errors[] = db_error_message($res['error']);
        }
    }

    // ---- Register a staff member / employee ----
    if ($act === 'create_staff') {
        if (!($err = create_team_account($token, $_POST))) {
            flash('success', (($_POST['role'] ?? '') === 'admin' ? 'Admin' : 'Staff') . ' account created for ' . trim((string) $_POST['full_name']) . '. They can sign in now with the username and temporary password.');
            $back('users'); exit;
        }
        $errors[] = $err;
    }

    if (($act === 'change_status' || in_array('change_status', $batch, true)) && !$errors) {
        $uid    = $_POST['user_id'] ?? '';
        $status = in_array($_POST['new_status'] ?? '', $statusOptions, true) ? $_POST['new_status'] : null;
        $target = $uid ? (supabase_request('GET', '/rest/v1/profiles?select=role,status,lifecycle_expires_at&id=eq.' . urlencode($uid), null, $token)['body'][0] ?? null) : null;
        if ($target && $status === 'accepted' && in_array($target['status'], ['pending', 'rejected'], true)) {
            // Accepting an application goes through the approval-wall flow.
            if (!($err = approve_application($token, $uid))) $done[] = 'Application approved.';
            else $errors[] = $err;
        } elseif ($target && $status) {
            $patch = ['status' => $status];
            $res = supabase_request('PATCH', '/rest/v1/profiles?id=eq.' . urlencode($uid), $patch, $token, ['Prefer: return=minimal']);
            if (!$res['error']) {
                audit_log($token, 'status_change', 'profile', $uid, 'Status → ' . $status);
                if ($status === 'suspended') notify($token, 'Account suspended', 'Your account was suspended by an admin.', $uid);
                $done[] = 'Status updated.';
            } else {
                $errors[] = $res['error'];
            }
        }
    }

    if ($done) {
        // One flash slot: a partial save reports what worked and what did not.
        if ($errors) flash('error', implode(' ', $done) . ' But: ' . implode(' ', $errors));
        else flash('success', implode(' ', $done));
        $back('users'); exit;
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

    // ---- Recycle bin: erase for good. Each one is checked to be in the
    // bin first, and is written to the audit log before it disappears. ----
    if ($act === 'purge_user') {
        $uid  = (string) ($_POST['user_id'] ?? '');
        $typed = trim((string) ($_POST['confirm_username'] ?? ''));
        $u    = $uid ? (supabase_request('GET', '/rest/v1/profiles?select=id,username,full_name,role,deleted_at&id=eq.' . urlencode($uid), null, $token)['body'][0] ?? null) : null;
        if (!$u || empty($u['deleted_at'])) {
            $errors[] = 'That account is not in the recycle bin.';
        } elseif ($uid === $adminId) {
            $errors[] = 'You cannot delete your own account.';
        } elseif (strcasecmp($typed, (string) $u['username']) !== 0) {
            $errors[] = 'Type the username exactly (' . $u['username'] . ') to delete that account for good.';
        } else {
            audit_log($token, 'purge_account', 'profile', $uid,
                'Permanently deleted ' . $u['full_name'] . ' (@' . $u['username'] . ', ' . role_name($u['role']) . ') and everything attached to it');
            $res = supabase_request('DELETE', '/rest/v1/profiles?id=eq.' . urlencode($uid), null, $token, ['Prefer: return=minimal']);
            if (!$res['error']) { flash('success', '@' . $u['username'] . ' is permanently deleted.'); $back('trash'); exit; }
            $errors[] = $res['error'];
        }
    }

    if ($act === 'purge_txn') {
        $tid = (string) ($_POST['txn_id'] ?? '');
        $t   = $tid ? (supabase_request('GET', '/rest/v1/transactions?select=id,amount,type,status,payment_ref&id=eq.' . urlencode($tid), null, $token)['body'][0] ?? null) : null;
        if (!$t || !in_array($t['status'], ['failed', 'reversed'], true)) {
            $errors[] = 'That payment is not in the recycle bin.';
        } else {
            audit_log($token, 'purge_transaction', 'transaction', $tid, 'Permanently deleted a ' . $t['type'] . ' of $' . number_format((float) $t['amount'], 2) . ' (ref ' . ($t['payment_ref'] ?? '—') . ')');
            $res = supabase_request('DELETE', '/rest/v1/transactions?id=eq.' . urlencode($tid) . '&status=in.(failed,reversed)', null, $token, ['Prefer: return=minimal']);
            if (!$res['error']) { flash('success', 'Payment permanently deleted.'); $back('trash'); exit; }
            $errors[] = $res['error'];
        }
    }

    if ($act === 'purge_task') {
        $tid = (string) ($_POST['task_id'] ?? '');
        $t   = $tid ? (supabase_request('GET', '/rest/v1/client_tasks?select=id,task_code,title,status&id=eq.' . urlencode($tid), null, $token)['body'][0] ?? null) : null;
        if (!$t || $t['status'] !== 'cancelled') {
            $errors[] = 'That task is not in the recycle bin.';
        } else {
            audit_log($token, 'purge_task', 'client_task', $tid, 'Permanently deleted ' . $t['task_code'] . ' — ' . $t['title'] . ' and its task log');
            $res = supabase_request('DELETE', '/rest/v1/client_tasks?id=eq.' . urlencode($tid) . '&status=eq.cancelled', null, $token, ['Prefer: return=minimal']);
            if (!$res['error']) { flash('success', $t['task_code'] . ' permanently deleted.'); $back('trash'); exit; }
            $errors[] = $res['error'];
        }
    }

    if ($act === 'purge_package') {
        $pid = (string) ($_POST['pkg_id'] ?? '');
        $pk  = $pid ? (supabase_request('GET', '/rest/v1/packages?select=id,name,active&id=eq.' . urlencode($pid), null, $token)['body'][0] ?? null) : null;
        if (!$pk || !empty($pk['active'])) {
            $errors[] = 'That package is still on sale.';
        } else {
            // Past payments keep the package name in their notes, so the
            // history still says what was bought once the row is gone.
            $used = supabase_request('GET', '/rest/v1/transactions?select=id,notes&package_id=eq.' . urlencode($pid), null, $token)['body'] ?? [];
            foreach (is_array($used) ? $used : [] as $t) {
                if (str_contains((string) $t['notes'], '[Package: ')) continue;
                supabase_request('PATCH', '/rest/v1/transactions?id=eq.' . urlencode($t['id']),
                    ['notes' => trim(($t['notes'] ?? '') . ' [Package: ' . $pk['name'] . ']')], $token, ['Prefer: return=minimal']);
            }
            audit_log($token, 'purge_package', 'package', $pid, 'Permanently deleted the package "' . $pk['name'] . '" (' . count($used) . ' past payment(s) keep its name in their notes)');
            $res = supabase_request('DELETE', '/rest/v1/packages?id=eq.' . urlencode($pid), null, $token, ['Prefer: return=minimal']);
            if (!$res['error']) { flash('success', '"' . $pk['name'] . '" permanently deleted.'); $back('trash'); exit; }
            $errors[] = $res['error'];
        }
    }

    // ---- Recycle bin: put something back into circulation ----
    if ($act === 'restore_txn') {
        $tid = (string) ($_POST['txn_id'] ?? '');
        $res = supabase_request('PATCH', '/rest/v1/transactions?id=eq.' . urlencode($tid) . '&status=in.(failed,reversed)',
            ['status' => 'pending', 'reviewed_by' => null, 'reviewed_at' => null], $token, ['Prefer: return=representation']);
        if (!$res['error'] && !empty($res['body'])) {
            audit_log($token, 'restore_transaction', 'transaction', $tid, 'Restored to pending for review');
            flash('success', 'Transaction restored — it is back in Approvals.'); $back('trash'); exit;
        }
        $errors[] = $res['error'] ?: 'Could not restore that transaction.';
    }

    if ($act === 'restore_task') {
        $tid = (string) ($_POST['task_id'] ?? '');
        $res = supabase_request('PATCH', '/rest/v1/client_tasks?id=eq.' . urlencode($tid) . '&status=eq.cancelled',
            ['status' => 'in_progress', 'cancel_reason' => null, 'cancel_approved_by' => null], $token, ['Prefer: return=representation']);
        if (!$res['error'] && !empty($res['body'])) {
            audit_log($token, 'restore_task', 'client_task', $tid, 'Restored ' . ($res['body'][0]['task_code'] ?? '') . ' to In Progress');
            flash('success', 'Task restored to In Progress.'); $back('trash'); exit;
        }
        $errors[] = $res['error'] ?: 'Could not restore that task.';
    }

    if ($act === 'restore_package') {
        $pid = (string) ($_POST['pkg_id'] ?? '');
        $res = supabase_request('PATCH', '/rest/v1/packages?id=eq.' . urlencode($pid), ['active' => true], $token, ['Prefer: return=representation']);
        if (!$res['error'] && !empty($res['body'])) {
            audit_log($token, 'restore_package', 'package', $pid, 'Package put back on sale: ' . ($res['body'][0]['name'] ?? ''));
            flash('success', 'Package is on sale again.'); $back('trash'); exit;
        }
        $errors[] = $res['error'] ?: 'Could not restore that package.';
    }

    if ($act === 'restore_user') {
        $uid = $_POST['user_id'] ?? '';
        if ($uid) {
            $res = supabase_request('PATCH', '/rest/v1/profiles?id=eq.' . urlencode($uid), ['deleted_at' => null, 'status' => 'pending'], $token, ['Prefer: return=minimal']);
            if (!$res['error']) {
                audit_log($token, 'restore', 'profile', $uid, 'Account restored from the recycle bin');
                flash('success', 'Account restored. It is back in Approvals until you accept it.'); $back('trash'); exit;
            }
            $errors[] = $res['error'];
        }
    }

    // ---- Approval wall: account applications ----
    if ($act === 'approve_app' || $act === 'reject_app') {
        $uid = (string) ($_POST['user_id'] ?? '');
        $err = $act === 'approve_app'
            ? approve_application($token, $uid)
            : reject_application($token, $uid, mb_substr(trim((string) ($_POST['reason'] ?? '')), 0, 200));
        if (!$err) { flash('success', $act === 'approve_app' ? 'Application approved — the dashboard is now open to them.' : 'Application rejected.'); $back('approvals'); exit; }
        $errors[] = $err;
    }

    // ---- Edit any user's details ----
    if ($act === 'edit_user') {
        $uid   = (string) ($_POST['user_id'] ?? '');
        $uname = strtolower(trim((string) ($_POST['username'] ?? '')));
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $pw    = (string) ($_POST['new_password'] ?? '');
        $exp   = trim((string) ($_POST['lifecycle_expires_at'] ?? ''));
        $patch = [
            'full_name' => trim((string) ($_POST['full_name'] ?? '')),
            'username'  => $uname,
            'email'     => $email !== '' ? $email : null,
            'phone'     => trim((string) ($_POST['phone'] ?? '')) ?: null,
            'company'   => trim((string) ($_POST['company'] ?? '')) ?: null,
        ];
        // A blank expiry leaves the lifecycle alone. Clearing it has to be
        // deliberate, otherwise editing a phone number would hand a Basic
        // client unlimited access.
        if (array_key_exists('affiliate_tier', $_POST)) {
            $patch['affiliate_tier'] = in_array($_POST['affiliate_tier'], ['basic', 'standard'], true) ? $_POST['affiliate_tier'] : null;
        }
        if ($exp !== '') {
            $patch['lifecycle_expires_at'] = date('c', strtotime($exp));
        } elseif (!empty($_POST['lifecycle_clear'])) {
            $patch['lifecycle_expires_at'] = null;
        }
        if ($patch['full_name'] === '')                       $errors[] = 'Name is required.';
        if (!preg_match('/^[a-z0-9_]{3,20}$/', $uname))        $errors[] = 'Username must be 3-20 letters, numbers or underscore.';
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter a valid email.';
        if ($exp !== '' && strtotime($exp) === false)          $errors[] = 'Invalid expiry date.';
        if ($pw !== '' && strlen($pw) < 8)                    $errors[] = 'New password must be at least 8 characters.';
        if (!$errors) {
            $clash = supabase_request('GET', '/rest/v1/profiles?select=id&username=eq.' . urlencode($uname) . '&id=neq.' . urlencode($uid), null, $token);
            if (!empty($clash['body'])) $errors[] = 'That username is already taken.';
        }
        if (!$errors) {
            if ($pw !== '') $patch['password_hash'] = password_hash($pw, PASSWORD_BCRYPT);
            $res = supabase_request('PATCH', '/rest/v1/profiles?id=eq.' . urlencode($uid), $patch, $token, ['Prefer: return=minimal']);
            if (!$res['error']) {
                audit_log($token, 'edit_user', 'profile', $uid, 'Edited details' . ($pw !== '' ? ' + reset password' : ''));
                notify($token, 'Your account details were updated', 'An admin updated your profile' . ($pw !== '' ? ' and reset your password' : '') . '.', $uid);
                flash('success', 'User updated.'); $back('users'); exit;
            }
            $errors[] = $res['error'];
        }
    }

    // ---- Business profile (asked at registration) ----
    if ($act === 'edit_business') {
        $uid = (string) ($_POST['user_id'] ?? '');
        $res = supabase_request('PATCH', '/rest/v1/profiles?id=eq.' . urlencode($uid), [
            'business_nature'    => in_array($_POST['business_nature'] ?? '', BUSINESS_NATURES, true) ? $_POST['business_nature'] : null,
            'business_struggles' => array_values(array_intersect(array_keys(BUSINESS_STRUGGLES), (array) ($_POST['business_struggles'] ?? []))),
            'business_notes'     => mb_substr(trim((string) ($_POST['business_notes'] ?? '')), 0, 1000) ?: null,
        ], $token, ['Prefer: return=minimal']);
        if (!$res['error']) { audit_log($token, 'edit_business', 'profile', $uid, 'Edited business profile'); flash('success', 'Business profile updated.'); header('Location: /dashboard-admin.php?edit_user=' . urlencode($uid) . '#users'); exit; }
        $errors[] = $res['error'];
    }

    // ---- Roulette: rotation of approved packages across admins ----
    if (in_array($act, ['roulette_up', 'roulette_down', 'roulette_toggle', 'roulette_add', 'roulette_remove'], true)) {
        $aid     = (string) ($_POST['admin_id'] ?? '');
        $members = roulette_state($token)['members'];
        $ids     = array_column($members, 'admin_id');
        $i       = array_search($aid, $ids, true);
        if ($act === 'roulette_add' && $i === false) {
            supabase_request('POST', '/rest/v1/roulette_members', ['admin_id' => $aid, 'position' => count($ids) + 1], $token, ['Prefer: return=minimal']);
        } elseif ($act === 'roulette_remove' && $i !== false) {
            supabase_request('DELETE', '/rest/v1/roulette_members?admin_id=eq.' . urlencode($aid), null, $token);
        } elseif ($act === 'roulette_toggle' && $i !== false) {
            supabase_request('PATCH', '/rest/v1/roulette_members?admin_id=eq.' . urlencode($aid), ['active' => !$members[$i]['active']], $token, ['Prefer: return=minimal']);
        } elseif ($i !== false) {
            $j = $act === 'roulette_up' ? $i - 1 : $i + 1;
            if (isset($ids[$j])) { [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]]; }
            foreach ($ids as $pos => $id) {   // renumber 1..n in the new order
                supabase_request('PATCH', '/rest/v1/roulette_members?admin_id=eq.' . urlencode($id), ['position' => $pos + 1], $token, ['Prefer: return=minimal']);
            }
        }
        audit_log($token, 'roulette_' . substr($act, 9), 'roulette', $aid, 'Roulette rotation updated');
        flash('success', 'Roulette updated.'); $back('roulette'); exit;
    }

    if ($act === 'reassign') {
        $txnId = (string) ($_POST['txn_id'] ?? '');
        $to    = (string) ($_POST['assigned_admin'] ?? '');
        $txn   = supabase_request('PATCH', '/rest/v1/transactions?id=eq.' . urlencode($txnId), ['assigned_admin' => $to ?: null], $token, ['Prefer: return=representation']);
        if (!$txn['error'] && !empty($txn['body'])) {
            supabase_request('PATCH', '/rest/v1/profiles?id=eq.' . urlencode($txn['body'][0]['user_id']), ['assigned_admin' => $to ?: null], $token, ['Prefer: return=minimal']);
            audit_log($token, 'reassign', 'transaction', $txnId, 'Package reassigned');
            if ($to) notify($token, 'A package was reassigned to you', 'Check the Roulette tab for details.', $to, 'all', '/dashboard-admin.php?mine=1#roulette');
            flash('success', 'Reassigned.'); $back('roulette'); exit;
        }
        $errors[] = $txn['error'] ?? 'Transaction not found.';
    }

    // ---- Packages: edits are scheduled 1-7 days ahead ----
    if ($act === 'edit_package') {
        $pid = (string) ($_POST['pkg_id'] ?? '');
        $cur = supabase_request('GET', '/rest/v1/packages?select=*&id=eq.' . urlencode($pid), null, $token)['body'][0] ?? null;
        if (!$cur) {
            $errors[] = 'Package not found.';
        } else {
            $new = [
                'name' => trim((string) ($_POST['pkg_name'] ?? '')), 'description' => trim((string) ($_POST['pkg_desc'] ?? '')) ?: null,
                'price' => round(max(0, (float) ($_POST['pkg_price'] ?? 0)), 2),
                'monthly_price' => round(max(0, (float) ($_POST['pkg_monthly'] ?? 0)), 2),
                'commission_outright' => round(max(0, (float) ($_POST['pkg_comm_out'] ?? 0)), 2),
                'commission_unilevel' => round(max(0, (float) ($_POST['pkg_comm_uni'] ?? 0)), 2),
                'tier' => in_array($_POST['pkg_tier'] ?? '', ['basic', 'standard'], true) ? $_POST['pkg_tier'] : $cur['tier'],
                'kind' => in_array($_POST['pkg_kind'] ?? '', ['affiliate', 'service'], true) ? $_POST['pkg_kind'] : ($cur['kind'] ?? 'service'),
                'active' => !empty($_POST['pkg_active']),
            ];
            if ($new['kind'] === 'service') $new['tier'] = 'standard';   // tier only applies to affiliate plans
            if ($new['tier'] === 'basic') { $new['price'] = 0; $new['monthly_price'] = 0; }
            if ($new['name'] === '') $errors[] = 'Package name is required.';
            if ($new['tier'] === 'standard' && $new['price'] <= 0) $errors[] = 'Paid packages need a price.';
            if (!$errors) {
                $diff = [];
                foreach ($new as $k => $v) {
                    $old = $cur[$k];
                    $same = is_float($v) ? abs((float) $old - $v) < 0.001 : (is_bool($v) ? (bool) $old === $v : (string) $old === (string) $v);
                    if (!$same) $diff[$k] = $v;
                }
                $err = schedule_package_change($token, $cur, $diff, (int) ($_POST['days'] ?? 0), $adminId);
                if (!$err) { flash('success', 'Change scheduled. Everyone has been notified.'); $back('packages'); exit; }
                $errors[] = $err;
            }
        }
    }

    if ($act === 'cancel_change') {
        if (!($err = cancel_package_change($token, (string) ($_POST['change_id'] ?? '')))) { flash('success', 'Scheduled change cancelled.'); $back('packages'); exit; }
        $errors[] = $err;
    }

    // ---- Services & referral links ----
    if ($act === 'edit_service') {
        $csId = (string) ($_POST['cs_id'] ?? '');
        $st   = in_array($_POST['svc_status'] ?? '', $svcStatusList, true) ? $_POST['svc_status'] : 'active';
        $res  = supabase_request('PATCH', '/rest/v1/client_services?id=eq.' . urlencode($csId),
            ['status' => $st, 'notes' => trim((string) ($_POST['svc_notes'] ?? '')) ?: null], $token, ['Prefer: return=representation']);
        if (!$res['error'] && !empty($res['body'])) {
            audit_log($token, 'edit_service', 'client_service', $csId, $res['body'][0]['service'] . ' → ' . $st);
            notify($token, 'Service updated', $res['body'][0]['service'] . ' is now ' . $st . '.', $res['body'][0]['client_id']);
            flash('success', 'Service updated.'); $back('services'); exit;
        }
        $errors[] = $res['error'] ?? 'Service not found.';
    }

    if ($act === 'remove_service') {
        $csId = (string) ($_POST['cs_id'] ?? '');
        $res  = supabase_request('DELETE', '/rest/v1/client_services?id=eq.' . urlencode($csId), null, $token, ['Prefer: return=representation']);
        if (!$res['error'] && !empty($res['body'])) {
            audit_log($token, 'remove_service', 'client_service', $csId, 'Removed ' . $res['body'][0]['service']);
            flash('success', 'Service removed.'); $back('services'); exit;
        }
        $errors[] = $res['error'] ?? 'Service not found.';
    }

    if ($act === 'edit_referral') {
        $rid = (string) ($_POST['ref_id'] ?? '');
        $st  = in_array($_POST['ref_status'] ?? '', $refStatusOptions, true) ? $_POST['ref_status'] : null;
        if ($rid && $st) {
            $res = supabase_request('PATCH', '/rest/v1/referrals?id=eq.' . urlencode($rid), ['status' => $st], $token, ['Prefer: return=minimal']);
            if (!$res['error']) { audit_log($token, 'edit_referral', 'referral', $rid, 'Status → ' . $st); flash('success', 'Referral updated.'); $back('referrals'); exit; }
            $errors[] = $res['error'];
        }
    }

    // ---- Manual announcement ----
    if ($act === 'send_notice') {
        $title    = trim((string) ($_POST['n_title'] ?? ''));
        $audience = array_key_exists($_POST['n_audience'] ?? '', $audienceOptions) ? $_POST['n_audience'] : 'all';
        if ($title === '') {
            $errors[] = 'Give the notification a title.';
        } else {
            notify($token, $title, trim((string) ($_POST['n_body'] ?? '')) ?: null, null, $audience);
            audit_log($token, 'send_notice', 'notification', null, $audienceOptions[$audience] . ': ' . $title);
            flash('success', 'Notification sent to ' . strtolower($audienceOptions[$audience]) . '.'); $back('notify'); exit;
        }
    }

    if ($act === 'create_package') {
        $pkg = [
            'name' => trim($_POST['pkg_name'] ?? ''), 'description' => trim($_POST['pkg_desc'] ?? '') ?: null,
            'price' => max(0, (float) ($_POST['pkg_price'] ?? 0)), 'monthly_price' => max(0, (float) ($_POST['pkg_monthly'] ?? 0)),
            'commission_outright' => max(0, (float) ($_POST['pkg_comm_out'] ?? 0)),
            'commission_unilevel' => max(0, (float) ($_POST['pkg_comm_uni'] ?? 0)),
            'tier' => in_array($_POST['pkg_tier'] ?? '', ['basic', 'standard'], true) ? $_POST['pkg_tier'] : 'basic',
            'kind' => in_array($_POST['pkg_kind'] ?? '', ['affiliate', 'service'], true) ? $_POST['pkg_kind'] : 'service',
        ];
        if ($pkg['kind'] === 'service') $pkg['tier'] = 'standard';
        if ($pkg['tier'] === 'basic') { $pkg['price'] = 0; $pkg['monthly_price'] = 0; }   // Basic packages are always free
        if ($pkg['tier'] === 'standard' && $pkg['price'] <= 0) {
            $errors[] = 'Paid packages need a price — it is paid via the QR paywall.';
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
            flash('success', $act === 'approve_payment' ? 'Payment approved.' : 'Payment rejected. The user can submit again.');
            header('Location: /dashboard-admin.php#approvals'); exit;
        }
        $errors[] = $err;
    }

    if ($act === 'approve_kyc' || $act === 'reject_kyc') {
        $uid = (string) ($_POST['user_id'] ?? '');
        $err = review_kyc($token, $uid, $profile['id'], $act === 'approve_kyc', mb_substr(trim((string) ($_POST['reason'] ?? '')), 0, 200));
        if (!$err) {
            flash('success', $act === 'approve_kyc' ? 'Identity verified.' : 'KYC rejected. The user can submit again.');
            header('Location: /dashboard-admin.php#kyc'); exit;
        }
        $errors[] = $err;
    }

    if ($act === 'save_commission') {
        $pct = $_POST['commission_pct'] ?? '';
        if (!is_numeric($pct) || (float) $pct < 0 || (float) $pct > 100) {
            $errors[] = 'Commission rate must be a number between 0 and 100.';
        } else {
            $new = pct_label(round((float) $pct, 2));
            set_setting($token, 'referral_commission_pct', $new);
            audit_log($token, 'commission_rate', 'app_settings', null, 'Outright commission set to ' . $new . '% of the advance deposit');
            flash('success', 'Outright commission set to ' . $new . '% of the advance deposit.');
            header('Location: /dashboard-admin.php#payment'); exit;
        }
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

$txnRes  = supabase_request('GET', '/rest/v1/transactions?select=*,profiles!transactions_user_id_fkey(full_name,username,email,phone,role,business_nature,business_struggles),packages!transactions_package_id_fkey(name,tier,kind)&order=created_at.desc', null, $token);
$allTxns = is_array($txnRes['body']) && !$txnRes['error'] ? $txnRes['body'] : [];

$csRes = supabase_request('GET', '/rest/v1/client_services?select=*,profiles!client_services_client_id_fkey(full_name,email)&order=started_at.desc', null, $token);
$allCS = is_array($csRes['body']) && !$csRes['error'] ? $csRes['body'] : [];

$pendingPayments = array_values(array_filter($allTxns, fn($t) => in_array($t['type'], ['activation', 'deposit'], true) && $t['status'] === 'pending'));
$applications    = array_values(array_filter($allProfiles, fn($p) => $p['status'] === 'pending' && !is_team($p) && empty($p['deleted_at'])));
$rejectedApps    = array_values(array_filter($allProfiles, fn($p) => $p['status'] === 'rejected' && empty($p['deleted_at'])));
$approvalCount   = count($applications) + count($pendingPayments);

// Roulette
$roulette  = roulette_state($token);
$admins    = array_values(array_filter($allProfiles, fn($p) => $p['role'] === 'admin' && empty($p['deleted_at'])));
$adminName = array_column($admins, 'full_name', 'id');
$nextUp    = $roulette['next'] ? ($adminName[$roulette['next']] ?? '—') : null;
$assigned  = array_values(array_filter($allTxns, fn($t) => in_array($t['type'], ['activation', 'deposit'], true) && $t['status'] === 'completed' && !empty($t['assigned_admin'])));
$onlyMine  = !empty($_GET['mine']);
$assignedShown = $onlyMine ? array_values(array_filter($assigned, fn($t) => $t['assigned_admin'] === $adminId)) : $assigned;
$perAdmin  = array_count_values(array_column($assigned, 'assigned_admin'));
// The rotation hands out accounts, so the per-admin tally counts accounts.
$ownedAccounts = array_values(array_filter($allProfiles, fn($p) => !empty($p['assigned_admin']) && empty($p['deleted_at'])));
$perAdminAcct  = array_count_values(array_column($ownedAccounts, 'assigned_admin'));

$chRes      = supabase_request('GET', '/rest/v1/package_changes?select=*,packages(name)&order=created_at.desc&limit=30', null, $token);
$allChanges = is_array($chRes['body']) && !$chRes['error'] ? $chRes['body'] : [];
$scheduledByPkg = [];
foreach ($allChanges as $ch) if ($ch['status'] === 'scheduled') $scheduledByPkg[$ch['package_id']] = $ch;

// Row currently opened for editing (?edit_user= / ?edit_pkg= / ?edit_svc=)
$pick = fn(array $rows, string $param) => isset($_GET[$param]) ? (current(array_filter($rows, fn($r) => $r['id'] === $_GET[$param])) ?: null) : null;
$editUser = $pick($allProfiles, 'edit_user');
$editPkg  = $pick($allPkgs, 'edit_pkg');
$editSvc  = $pick($allCS, 'edit_svc');
$pay     = get_settings($token, ['payment_qr', 'payment_account', 'payment_instructions']);
$comPct  = commission_pct($token);

// Full rows (images included) only for the queue actually being reviewed.
$kycRes     = supabase_request('GET', '/rest/v1/kyc_submissions?select=*,profiles!kyc_submissions_user_id_fkey(full_name,username,role)&status=eq.pending&order=created_at.asc', null, $token);
$kycPending = is_array($kycRes['body']) && !$kycRes['error'] ? $kycRes['body'] : [];

$kycDoneRes = supabase_request('GET', '/rest/v1/kyc_submissions?select=user_id,legal_name,id_type,status,note,reviewed_at,profiles!kyc_submissions_user_id_fkey(full_name,username)&status=in.(approved,rejected)&order=reviewed_at.desc&limit=50', null, $token);
$kycDone    = is_array($kycDoneRes['body']) && !$kycDoneRes['error'] ? $kycDoneRes['body'] : [];

$logCat  = isset(LOG_CATEGORIES[$_GET['log'] ?? '']) || ($_GET['log'] ?? '') === 'other' ? $_GET['log'] : '';
$logsRes = supabase_request('GET', '/rest/v1/system_logs?select=*,profiles!system_logs_actor_id_fkey(full_name)' . log_category_filter($logCat) . '&order=created_at.desc&limit=200', null, $token);
$allLogs = is_array($logsRes['body']) && !$logsRes['error'] ? $logsRes['body'] : [];

$totalUsers    = count(array_filter($allProfiles, fn($p) => empty($p['deleted_at'])));
$adminCount    = count(array_filter($allProfiles, fn($p) => $p['role'] === 'admin' && empty($p['deleted_at'])));
$staffCount    = count(array_filter($allProfiles, fn($p) => $p['role'] === 'staff' && empty($p['deleted_at'])));
$clientCount   = count(array_filter($allProfiles, fn($p) => $p['role'] === 'client' && empty($p['deleted_at'])));
// Growth Partner accounts: their own rows, plus legacy dual-side clients
// that still carry a partner plan on the client row.
$gpCount       = count(array_filter($allProfiles, fn($p) => empty($p['deleted_at'])
                    && ($p['role'] === 'growth_partner' || ($p['role'] === 'client' && !empty($p['affiliate_tier'])))));

$clients = array_filter($allProfiles, fn($p) => $p['role'] === 'client' && empty($p['deleted_at']));

// ---- Recycle bin (admin only) -------------------------------------------
// Everything an admin or a staff member took out of circulation, with the
// action that put it there. Nothing is erased, so all of it can come back.
$liveProfiles = array_values(array_filter($allProfiles, fn($p) => empty($p['deleted_at'])));
$binUsers     = array_values(array_filter($allProfiles, fn($p) => !empty($p['deleted_at'])));
$binTxns      = array_values(array_filter($allTxns, fn($t) => in_array($t['status'], ['failed', 'reversed'], true)));
$binPkgs      = array_values(array_filter($allPkgs, fn($p) => empty($p['active'])));
$binTasksRes  = supabase_request('GET', '/rest/v1/client_tasks?select=id,task_code,title,status,cancel_reason,cancel_approved_by,updated_at,client:profiles!client_tasks_client_id_fkey(full_name)&status=eq.cancelled&order=updated_at.desc&limit=100', null, $token);
$binTasks     = is_array($binTasksRes['body']) && !$binTasksRes['error'] ? $binTasksRes['body'] : [];
$trashCount   = count($binUsers) + count($binTxns) + count($binPkgs) + count($binTasks);
$firstName = $profile ? explode(' ', $profile['full_name'])[0] : 'Admin';
?>
<?= ui_head([
    'title'       => 'Admin // KAM Team',
    'description' => 'KAM Team mission control: users, approvals, packages, transactions and audit logs.',
    'csrf'        => $csrf,
    'idle'        => true,
]) ?>
<?= ui_topbar([
    'csrf'       => $csrf,
    'bell'       => notif_bell($token, $profile, $csrf),
    'role'       => 'Admin',
    'role_class' => 'admin',
]) ?>

<div class="shell">
<?= app_sidebar([
    ['label' => 'Users',        'panel' => 'users',        'icon' => 'users'],
    ['label' => 'Approvals',    'panel' => 'approvals',    'icon' => 'check', 'count' => $approvalCount],
    ['label' => 'Roulette',     'panel' => 'roulette',     'icon' => 'wheel'],
    ['label' => 'KYC',          'panel' => 'kyc',          'icon' => 'id',    'count' => count($kycPending)],
    ['label' => 'Payment',      'panel' => 'payment',      'icon' => 'qr'],
    ['label' => 'Referral network', 'panel' => 'referrals', 'icon' => 'tree'],
    ['label' => 'Packages',     'panel' => 'packages',     'icon' => 'box'],
    ['label' => 'Transactions', 'panel' => 'transactions', 'icon' => 'cash'],
    ['label' => 'Services',     'panel' => 'services',     'icon' => 'tool'],
    ['label' => 'Notify',       'panel' => 'notify',       'icon' => 'bell'],
    ['label' => 'Audit logs',   'panel' => 'logs',         'icon' => 'log'],
    ['label' => 'Recycle bin',  'panel' => 'trash',        'icon' => 'trash', 'count' => $trashCount],
    ['label' => 'Client work →', 'href' => '/work.php',     'icon' => 'check'],
    // POSTs to the session switcher — the admin's referral slot now
    // lives on a dedicated linked Growth Partner row (see migration
    // 20261128). Switching flips the session to that row so the GP
    // dashboard renders its own data; "Back to admin" returns here.
    ['label' => 'My referral account →', 'href' => '/notifications.php', 'icon' => 'link',
     'post' => true, 'csrf' => $csrf,
     'params' => ['action' => 'switch', 'to' => $profile['linked_profile_id'] ?? '']],
    ['label' => 'Program docs →', 'href' => '/report.php?doc=components', 'icon' => 'log'],
], 'Admin', 'users') ?>
<main class="wrap" id="main" tabindex="-1">
  <?= ui_page_title('Mission control // ' . e($profile['full_name'] ?? ''), 'Admin dashboard.', 'Manage the program.') ?>

  <?= ui_alerts($notice, $errors) ?>

  <?= ui_stat_grid([
      ['label' => 'Users',    'value' => $totalUsers, 'tone' => 'cyan',
       'note'  => $adminCount . ' admin &middot; ' . $staffCount . ' staff &middot; ' . $gpCount . ' growth partner' . ($gpCount === 1 ? '' : 's') . ' &middot; ' . $clientCount . ' client' . ($clientCount === 1 ? '' : 's')],
      ['label' => 'Referrals', 'value' => count($allRefs)],
      ['label' => 'Packages',  'value' => count($allPkgs)],
      ['label' => 'Revenue',   'value' => '$' . number_format(array_sum(array_map(fn($t) => in_array($t['type'], ['activation', 'deposit'], true) && $t['status'] === 'completed' ? (float) $t['amount'] : 0, $allTxns)), 2), 'tone' => 'green'],
  ]) ?>

  <?php if ($approvalCount): ?>
    <div class="alert alert-warn"><strong><?= count($applications) ?> account application<?= count($applications) === 1 ? '' : 's' ?> and <?= count($pendingPayments) ?> payment<?= count($pendingPayments) === 1 ? '' : 's' ?> (Growth Partner plans &amp; client deposits) waiting.</strong> <a href="#approvals" onclick="document.querySelector('.admin-tab[data-panel=approvals]').click()">Review now →</a></div>
  <?php endif; ?>
  <?php if (partner_tier_blocked()): ?>
    <div class="alert alert-error">Admin referral accounts are switched off in the database. Run <code>supabase/migrations/20261125_admin_unlimited_partner.sql</code> in the Supabase SQL Editor — admins get their unlimited Growth Partner account on their next page load.</div>
  <?php endif; ?>
  <?php if ($pay['payment_qr'] === ''): ?>
    <div class="alert alert-warn">No payment QR code yet — Growth Partners can't pay for Standard and clients can't pay deposits until you add one in the <a href="#payment" onclick="document.querySelector('.admin-tab[data-panel=payment]').click()">Payment tab</a>.</div>
  <?php endif; ?>


  <!-- USERS -->
  <div id="panel-users" class="admin-panel active">
    <?php if ($editUser): ?>
    <div class="card edit-card">
      <div class="edit-head"><h2>Edit user — <?= e($editUser['full_name']) ?></h2><a class="btn btn-ghost btn-sm" href="/dashboard-admin.php#users">Close</a></div>
      <form method="post"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="admin_action" value="edit_user"><input type="hidden" name="user_id" value="<?= e($editUser['id']) ?>">
        <div class="row"><div class="field"><label>Full name</label><input name="full_name" required value="<?= e($editUser['full_name']) ?>"></div><div class="field"><label>Username</label><input name="username" required pattern="[A-Za-z0-9_]{3,20}" value="<?= e($editUser['username']) ?>"></div></div>
        <div class="row"><div class="field"><label>Email</label><input name="email" type="email" value="<?= e($editUser['email'] ?? '') ?>"></div><div class="field"><label>Phone</label><input name="phone" value="<?= e($editUser['phone'] ?? '') ?>"></div></div>
        <div class="row"><div class="field"><label>Company</label><input name="company" value="<?= e($editUser['company'] ?? '') ?>"></div><div class="field"><label>Growth Partner term expires (UTC)</label><input name="lifecycle_expires_at" type="datetime-local" value="<?= $editUser['lifecycle_expires_at'] ? e(gmdate('Y-m-d\TH:i', strtotime($editUser['lifecycle_expires_at']))) : '' ?>"><div class="hint">Leave empty to keep it as it is. Client and admin accounts never expire.</div><label class="check" style="margin-top:6px"><input type="checkbox" name="lifecycle_clear" value="1"> Remove the expiry</label></div></div>
        <?php if ($editUser['role'] === 'client'): ?><div class="row"><div class="field"><label>Growth Partner plan</label><select name="affiliate_tier"><option value="">No plan yet</option><option value="basic" <?= ($editUser['affiliate_tier'] ?? '') === 'basic' ? 'selected' : '' ?>>Basic</option><option value="standard" <?= ($editUser['affiliate_tier'] ?? '') === 'standard' ? 'selected' : '' ?>>Standard</option></select></div><div class="field"></div></div><?php endif; ?>
        <div class="row"><div class="field"><label>New password</label><input name="new_password" type="password" minlength="8" autocomplete="new-password" placeholder="Leave empty to keep current"></div><div class="field"><label>Role / status</label><div class="hint" style="margin-top:10px">Change these with the selectors in the table below.</div></div></div>
        <button class="btn btn-sm btn-solid" type="submit">Save user →</button>
      </form>

      <form method="post" class="biz-edit"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="admin_action" value="edit_business"><input type="hidden" name="user_id" value="<?= e($editUser['id']) ?>">
        <h3>Business profile</h3>
        <?php $sv = suggested_services($editUser['business_struggles'] ?? []); if ($sv): ?><p class="hint" style="margin:0 0 10px">Suggested services: <?= e(implode(', ', $sv)) ?></p><?php endif; ?>
        <div class="field"><label>Nature of business</label><select name="business_nature"><option value="">—</option><?php foreach (BUSINESS_NATURES as $bn): ?><option <?= ($editUser['business_nature'] ?? '') === $bn ? 'selected' : '' ?>><?= e($bn) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label>Struggles</label><div class="struggle-pick"><?php foreach (BUSINESS_STRUGGLES as $key => [$label]): ?><label class="struggle-option"><input type="checkbox" name="business_struggles[]" value="<?= e($key) ?>" <?= in_array($key, $editUser['business_struggles'] ?? [], true) ? 'checked' : '' ?>><span><?= e($label) ?></span></label><?php endforeach; ?></div></div>
        <div class="field"><label>Notes</label><textarea name="business_notes" rows="2" maxlength="1000"><?= e($editUser['business_notes'] ?? '') ?></textarea></div>
        <button class="btn btn-sm" type="submit">Save business profile →</button>
      </form>
    </div>
    <?php endif; ?>
    <?php $staffOpen = ($_POST['admin_action'] ?? '') === 'create_staff' && $errors; $sp = $staffOpen ? $_POST : []; ?>
    <details class="card staff-card" id="add-staff" <?= $staffOpen ? 'open' : '' ?>>
      <summary><h2>+ Register staff / employee</h2><span class="hint">Creates an approved account right away — no application, no program terms, no expiry.</span></summary>
      <form method="post" autocomplete="off"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="admin_action" value="create_staff">
        <div class="row"><div class="field"><label>Full name</label><input name="full_name" required maxlength="120" value="<?= e($sp['full_name'] ?? '') ?>"></div>
          <div class="field"><label>Username</label><input name="username" required pattern="[A-Za-z0-9_]{3,20}" title="3–20 letters, numbers or underscores" value="<?= e($sp['username'] ?? '') ?>"></div></div>
        <div class="row"><div class="field"><label>Access</label><select name="role">
            <option value="staff" <?= ($sp['role'] ?? 'staff') === 'staff' ? 'selected' : '' ?>>Staff — client work &amp; task logs</option>
            <option value="admin" <?= ($sp['role'] ?? '') === 'admin' ? 'selected' : '' ?>>Admin — full admin dashboard</option>
          </select></div><div class="field"></div></div>
        <div class="row"><div class="field"><label>Work email</label><input name="email" type="email" value="<?= e($sp['email'] ?? '') ?>"></div>
          <div class="field"><label>Phone</label><input name="phone" maxlength="40" value="<?= e($sp['phone'] ?? '') ?>"></div></div>
        <div class="row"><div class="field"><label>Temporary password</label><input name="password" type="password" required minlength="8" autocomplete="new-password"></div>
          <div class="field"><label>Confirm password</label><input name="password_confirm" type="password" required minlength="8" autocomplete="new-password"></div></div>
        <p class="hint" style="margin:0 0 10px">Staff sign in on the normal login page and land in <strong>Client work</strong>: they update tasks, keep the task log, attach evidence, flag blockers and draft reports. Approving and delivering client reports stays with admins. Their names are suggested in every SOP tag field.</p>
        <button class="btn btn-sm btn-solid" type="submit">Create account →</button>
      </form>
    </details>

    <?php
      // Account types for the filter bar. A legacy dual-side member
      // (role 'client' with a partner plan) counts as both Client and
      // Growth Partner, so it shows under either filter.
      $byId     = array_column($liveProfiles, null, 'id');
      $typesOf  = function (array $p): array {
          $t = [$p['role'] === 'affiliate' ? 'client' : $p['role']];
          if ($p['role'] === 'client' && !empty($p['affiliate_tier'])) $t[] = 'growth_partner';
          return $t;
      };
      $typeCount = ['admin' => 0, 'staff' => 0, 'growth_partner' => 0, 'client' => 0];
      foreach ($liveProfiles as $p) foreach ($typesOf($p) as $t) if (isset($typeCount[$t])) $typeCount[$t]++;
      $linkedName = function (array $p) use ($byId): ?string {
          $l = $byId[$p['linked_profile_id'] ?? ''] ?? null;
          return $l ? $l['username'] : null;
      };
    ?>
    <div class="card"><h2>All users<?= $binUsers ? ' <span class="hint">· ' . count($binUsers) . ' in the <a href="/dashboard-admin.php#trash" onclick="document.querySelector(\'.admin-tab[data-panel=trash]\').click()">recycle bin</a></span>' : '' ?></h2>

      <div class="filter-bar" role="group" aria-label="Filter users by account type" data-filter-for="users-table">
        <button type="button" class="filter-chip is-on" data-filter="all" aria-pressed="true">All <span class="filter-count"><?= count($liveProfiles) ?></span></button>
        <button type="button" class="filter-chip" data-filter="admin" aria-pressed="false">Admin <span class="filter-count"><?= $typeCount['admin'] ?></span></button>
        <button type="button" class="filter-chip" data-filter="staff" aria-pressed="false">Staff / Employee <span class="filter-count"><?= $typeCount['staff'] ?></span></button>
        <button type="button" class="filter-chip" data-filter="growth_partner" aria-pressed="false">Growth Partner <span class="filter-count"><?= $typeCount['growth_partner'] ?></span></button>
        <button type="button" class="filter-chip" data-filter="client" aria-pressed="false">Client <span class="filter-count"><?= $typeCount['client'] ?></span></button>
      </div>
      <p class="filter-empty hint" id="users-table-empty" hidden>No accounts of this type yet.</p>

      <div class="table-scroll" tabindex="0" role="region" aria-label="All users"><table id="users-table" class="users-table">
        <thead><tr><th scope="col">Account</th><th scope="col">Type</th><th scope="col">Status</th><th scope="col">Lifecycle</th><th scope="col">Code</th><th scope="col" class="ut-actions-h">Actions</th></tr></thead>
        <tbody>
        <?php foreach ($liveProfiles as $p):
          $linkedRole = ($byId[$p['linked_profile_id'] ?? ''] ?? [])['role'] ?? null;
          $teamGp     = is_team_partner($p, $linkedRole ?? '');
          $linkedTo   = $linkedName($p);
          $isMe       = $p['id'] === $adminId;
          $formId     = 'ua-' . $p['id'];
        ?>
          <tr data-types="<?= e(implode(' ', $typesOf($p))) ?>"<?= $isMe ? ' class="is-me"' : '' ?>>
            <td class="ut-account">
              <span class="ut-avatar" aria-hidden="true"><?= e(strtoupper(mb_substr((string) $p['full_name'], 0, 1)) ?: '?') ?></span>
              <span class="ut-id">
                <strong><?= e($p['full_name']) ?></strong><?= $isMe ? ' <span class="ut-you">you</span>' : '' ?>
                <span class="ut-sub">@<?= e($p['username']) ?></span>
                <?php if (!empty($p['email'])): ?><span class="ut-sub ut-mail"><?= e($p['email']) ?></span><?php endif; ?>
              </span>
            </td>
            <td>
              <span class="role-badge <?= e($p['role']) ?>"><?= e(role_name($p['role'])) ?></span>
              <?php if ($p['role'] === 'growth_partner'): ?>
                <span class="ut-sub"><?= e(tier_label($p['affiliate_tier'] ?? null)) ?><?= $linkedTo ? ' · linked to @' . e($linkedTo) : '' ?></span>
              <?php elseif ($p['role'] === 'client' && !empty($p['affiliate_tier'])): ?>
                <span class="ut-sub">+ Growth Partner · <?= e(tier_label($p['affiliate_tier'])) ?></span>
              <?php elseif ($linkedTo): ?>
                <span class="ut-sub">Partner account: @<?= e($linkedTo) ?></span>
              <?php endif; ?>
            </td>
            <td><span class="pill <?= e($p['status']) ?>"><?= e($p['status']) ?></span><?= terms_outdated($p, $teamGp) ? '<span class="ut-sub" title="Has not accepted Manual v' . e(TERMS_VERSION) . '">terms pending</span>' : '' ?></td>
            <td class="ut-life">
              <?php if (in_array($p['role'], ['admin', 'staff'], true)): ?>Permanent
              <?php elseif ($p['role'] === 'growth_partner' && tier_unlimited($p)): ?>Lifetime<span class="ut-sub">Unlimited · never expires</span>
              <?php elseif (empty($p['lifecycle_expires_at'])): ?><?= $p['role'] === 'growth_partner' ? 'No plan yet' : 'Client permanent' ?>
              <?php else: $st = affiliate_state($p); ?>Partner to <?= date('M j, Y', strtotime($p['lifecycle_expires_at'])) ?><?= $st !== 'active' ? ' <span class="pill ' . ($st === 'grace' ? 'pending' : 'failed') . '">' . e($st) . '</span>' : '' ?>
              <?php endif; ?>
            </td>
            <td><?= !empty($p['referral_code']) ? '<span class="ref-code">' . e($p['referral_code']) . '</span>' : '<span class="hint">—</span>' ?></td>
            <td class="ut-actions">
              <form method="post" class="ut-set" id="<?= e($formId) ?>">
                <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                <input type="hidden" name="admin_action" value="update_account">
                <input type="hidden" name="user_id" value="<?= e($p['id']) ?>">
                <?php if ($isMe): ?>
                  <span class="ut-locked" title="Ask another admin to change your role">Role locked</span>
                <?php else: $choices = $p['role'] === 'admin' ? ADMIN_ROLE_TARGETS : $roleOptions; ?>
                  <select name="new_role" aria-label="Account type for <?= e($p['username']) ?>"<?= $p['role'] === 'admin' ? ' title="An admin can only step down to Staff"' : '' ?>><?php foreach ($choices as $ro): ?><option value="<?= e($ro) ?>" <?= $p['role'] === $ro ? 'selected' : '' ?>><?= e(role_name($ro)) ?></option><?php endforeach; ?></select>
                <?php endif; ?>
                <select name="new_status" aria-label="Status for <?= e($p['username']) ?>"><?php foreach ($statusOptions as $so): ?><option value="<?= e($so) ?>" <?= $p['status'] === $so ? 'selected' : '' ?>><?= e(ucfirst($so)) ?></option><?php endforeach; ?></select>
                <button class="btn btn-sm btn-solid" type="submit">Set</button>
              </form>
              <div class="ut-row">
                <a class="btn btn-sm" href="/dashboard-admin.php?edit_user=<?= e($p['id']) ?>#users">Edit</a>
                <form method="post" class="inline-form" onsubmit="return confirm('Move @<?= e($p['username']) ?> to the recycle bin?')"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="admin_action" value="soft_delete"><input type="hidden" name="user_id" value="<?= e($p['id']) ?>"><button class="btn btn-sm btn-danger" type="submit">Trash</button></form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    </div>
  </div>

  <!-- APPROVALS -->
  <div id="panel-approvals" class="admin-panel">
    <div class="card" style="margin-bottom:12px"><h2>Account applications</h2>
      <p style="font-size:13px;color:var(--text-dim);margin:0 0 12px">New accounts wait behind the approval wall until you approve them. Approving opens their dashboard. Every account is a permanent Client plus a Growth Partner. After approval the member picks Basic or Standard; the Growth Partner term starts then.</p>
      <?php if (!$applications): ?><p class="empty">No applications waiting.</p>
      <?php else: ?><div class="table-scroll"><table>
        <thead><tr><th>Applicant</th><th>Applied as</th><th>Business &amp; struggles</th><th>Contact</th><th>Referred by</th><th>Applied</th><th>Decision</th></tr></thead>
        <tbody><?php foreach ($applications as $a):
            $refBy = $a['referred_by'] ? (current(array_filter($allProfiles, fn($x) => $x['id'] === $a['referred_by']))['full_name'] ?? '—') : '—'; ?>
          <tr>
            <td><strong><?= e($a['full_name']) ?></strong><br><span style="font-size:11px;color:var(--text-dim)"><?= e($a['username']) ?><?= $a['company'] ? ' · ' . e($a['company']) : '' ?></span></td>
            <td><span class="role-badge <?= e($a['role']) ?>"><?= e(role_name($a['role'])) ?></span></td>
            <td style="min-width:200px"><?= business_summary($a) ?></td>
            <td style="font-size:12px"><?= e($a['email'] ?? '—') ?><br><?= e($a['phone'] ?? '') ?></td>
            <td style="font-size:12px"><?= e($refBy) ?></td>
            <td style="font-size:12px"><?= date('M j, Y H:i', strtotime($a['created_at'])) ?></td>
            <td>
              <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">
                <form method="post" class="inline-form"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="admin_action" value="approve_app"><input type="hidden" name="user_id" value="<?= e($a['id']) ?>"><button class="btn btn-sm btn-solid" type="submit">Approve</button></form>
                <form method="post" class="inline-form"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="admin_action" value="reject_app"><input type="hidden" name="user_id" value="<?= e($a['id']) ?>"><input name="reason" type="text" maxlength="200" placeholder="Reason (shown to them)" style="width:150px"><button class="btn btn-sm btn-danger" type="submit">Reject</button></form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?></tbody>
      </table></div><?php endif; ?>
      <?php if ($rejectedApps): ?>
        <details class="sub-list"><summary><?= count($rejectedApps) ?> rejected application<?= count($rejectedApps) === 1 ? '' : 's' ?></summary>
          <?php foreach ($rejectedApps as $a): ?>
            <div class="sub-row"><span><strong><?= e($a['full_name']) ?></strong> · <?= e(role_name($a['role'])) ?><?= $a['review_note'] ? ' — ' . e($a['review_note']) : '' ?></span>
              <form method="post" class="inline-form"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="admin_action" value="approve_app"><input type="hidden" name="user_id" value="<?= e($a['id']) ?>"><button class="btn btn-sm" type="submit">Approve anyway</button></form></div>
          <?php endforeach; ?>
        </details>
      <?php endif; ?>
    </div>

    <div class="card"><h2>Payments to verify</h2>
      <?php if ($nextUp): ?><p class="roulette-next">Next approved plan goes to <strong><?= e($nextUp) ?></strong> (roulette)</p><?php endif; ?>
      <p style="font-size:13px;color:var(--text-dim);margin:0 0 12px"><strong>Growth Partner plans</strong> — Basic (free) or Standard (US$100: first plan, upgrade, or +2-year renewal); never commissionable. <strong>Client deposits</strong> — the 50% advance deposit on a service package; approving pays the referrer's Outright Commission (<?= e(pct_label($comPct)) ?>% of the deposit) and adds 3 months to a Growth Partner referrer's term. Check the reference number in your payment app first.</p>
      <?php if (!$pendingPayments): ?><p class="empty">No plan requests waiting.</p>
      <?php else: ?><div class="table-scroll"><table>
        <thead><tr><th>User</th><th>For</th><th>Amount</th><th>Reference</th><th>Submitted</th><th>Decision</th></tr></thead>
        <tbody><?php foreach ($pendingPayments as $t): ?>
          <tr>
            <td><strong><?= e($t['profiles']['full_name'] ?? '—') ?></strong><br><span style="font-size:11px;color:var(--text-dim)"><?= e($t['profiles']['username'] ?? '') ?><?= !empty($t['profiles']['phone']) ? ' · ' . e($t['profiles']['phone']) : '' ?></span></td>
            <td><?= e($t['packages']['name'] ?? '—') ?><br><span class="role-badge <?= $t['type'] === 'deposit' ? 'client' : 'affiliate' ?>"><?= $t['type'] === 'deposit' ? 'Client deposit (50%)' : 'Growth Partner plan' ?></span></td>
            <td style="color:var(--green);font-weight:600"><?= (float) $t['amount'] > 0 ? '$' . number_format((float) $t['amount'], 2) : 'Free' ?></td>
            <td style="font-family:var(--font);font-size:13px"><?= e($t['payment_ref'] ?? '—') ?></td>
            <td style="font-size:12px"><?= date('M j, Y H:i', strtotime($t['created_at'])) ?></td>
            <td>
              <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">
                <form method="post" class="inline-form" onsubmit="return confirm('Confirm this payment was received?')"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="admin_action" value="approve_payment"><input type="hidden" name="txn_id" value="<?= e($t['id']) ?>"><button class="btn btn-sm btn-solid" type="submit">Approve</button></form>
                <form method="post" class="inline-form"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="admin_action" value="reject_payment"><input type="hidden" name="txn_id" value="<?= e($t['id']) ?>"><input name="reason" type="text" maxlength="200" placeholder="Reason (optional)" style="width:140px"><button class="btn btn-sm btn-danger" type="submit">Reject</button></form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?></tbody>
      </table></div><?php endif; ?>
    </div>
  </div>

  <!-- KYC -->
  <div id="panel-kyc" class="admin-panel">
    <div class="card" style="margin-bottom:12px"><h2>Identity verification</h2>
      <p style="font-size:13px;color:var(--text-dim);margin:0 0 12px">Check that the name and ID number match the document images. Approving marks the account verified; rejecting lets them submit again.</p>
      <?php if (!$kycPending): ?><p class="empty">No submissions waiting.</p>
      <?php else: ?>
        <?php foreach ($kycPending as $k): ?>
          <div class="card" style="margin-bottom:12px;background:var(--bg)">
            <div style="display:flex;justify-content:space-between;align-items:baseline;flex-wrap:wrap;gap:8px">
              <h3 style="margin:0;font-size:16px"><?= e($k['profiles']['full_name'] ?? '—') ?>
                <span style="font-size:12px;color:var(--text-dim);font-weight:400"><?= e($k['profiles']['username'] ?? '') ?> · <?= e(role_name($k['profiles']['role'] ?? 'affiliate')) ?></span>
              </h3>
              <span style="font-size:12px;color:var(--text-dim)"><?= e(date('M j, Y H:i', strtotime($k['created_at']))) ?></span>
            </div>

            <div class="profile-info" style="margin-top:10px">
              <div class="profile-row"><span class="label">Legal name</span><span><?= e($k['legal_name']) ?></span></div>
              <div class="profile-row"><span class="label">ID type</span><span><?= e(KYC_ID_TYPES[$k['id_type']] ?? $k['id_type']) ?></span></div>
              <div class="profile-row"><span class="label">ID number</span><span><?= e($k['id_number']) ?></span></div>
              <?php if ($k['dob']): ?><div class="profile-row"><span class="label">Date of birth</span><span><?= e(date('M j, Y', strtotime($k['dob']))) ?></span></div><?php endif; ?>
              <?php if ($k['address']): ?><div class="profile-row"><span class="label">Address</span><span><?= e($k['address']) ?></span></div><?php endif; ?>
            </div>

            <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:12px">
              <?php foreach (['id_front' => 'Front', 'id_back' => 'Back', 'selfie' => 'Selfie'] as $f => $lbl): ?>
                <?php if (!empty($k[$f])): ?>
                  <figure style="margin:0">
                    <a href="<?= e($k[$f]) ?>" target="_blank" rel="noopener">
                      <img src="<?= e($k[$f]) ?>" alt="<?= e($lbl) ?>" style="width:160px;height:110px;object-fit:cover;border:1px solid var(--line);border-radius:6px;display:block">
                    </a>
                    <figcaption style="font-size:11px;color:var(--text-dim);margin-top:3px"><?= e($lbl) ?> — click to enlarge</figcaption>
                  </figure>
                <?php endif; ?>
              <?php endforeach; ?>
            </div>

            <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin-top:12px">
              <form method="post" class="inline-form"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="admin_action" value="approve_kyc"><input type="hidden" name="user_id" value="<?= e($k['user_id']) ?>"><button class="btn btn-sm btn-solid" type="submit">Verify</button></form>
              <form method="post" class="inline-form"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="admin_action" value="reject_kyc"><input type="hidden" name="user_id" value="<?= e($k['user_id']) ?>"><input name="reason" type="text" maxlength="200" placeholder="Reason (shown to them)" style="width:200px"><button class="btn btn-sm btn-danger" type="submit">Reject</button></form>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <?php if ($kycDone): ?>
    <div class="card"><h2>Reviewed</h2>
      <div class="table-scroll"><table>
        <thead><tr><th>User</th><th>Legal name</th><th>ID</th><th>Result</th><th>Reviewed</th></tr></thead>
        <tbody><?php foreach ($kycDone as $k): ?>
          <tr>
            <td><strong><?= e($k['profiles']['full_name'] ?? '—') ?></strong><br><span style="font-size:11px;color:var(--text-dim)"><?= e($k['profiles']['username'] ?? '') ?></span></td>
            <td style="font-size:12px"><?= e($k['legal_name']) ?></td>
            <td style="font-size:12px"><?= e(KYC_ID_TYPES[$k['id_type']] ?? $k['id_type']) ?></td>
            <td><span class="pill <?= $k['status'] === 'approved' ? 'active' : 'failed' ?>"><?= e($k['status']) ?></span><?= $k['note'] ? '<br><span style="font-size:11px;color:var(--text-dim)">' . e($k['note']) . '</span>' : '' ?></td>
            <td style="font-size:12px"><?= $k['reviewed_at'] ? date('M j, Y', strtotime($k['reviewed_at'])) : '—' ?></td>
          </tr>
        <?php endforeach; ?></tbody>
      </table></div>
    </div>
    <?php endif; ?>
  </div>

  <!-- PAYMENT SETTINGS -->
  <div id="panel-payment" class="admin-panel">
    <div class="card" style="margin-bottom:12px"><h2>Outright commission</h2>
      <p style="font-size:13px;color:var(--text-dim);margin:0 0 12px">When a client who signed up with a referral code pays the 50% advance deposit on a service package, the referrer (Growth Partner or client) earns this percentage of the deposit — Manual §3.1 sets it at 10%. Growth Partner plan payments, the remaining setup balance and monthly fees are never commissionable.</p>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="admin_action" value="save_commission">
        <div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
          <div class="field" style="margin:0">
            <label for="com-pct">Commission rate (%)</label>
            <input id="com-pct" name="commission_pct" type="number" min="0" max="100" step="0.1" value="<?= e(pct_label($comPct)) ?>" style="width:120px">
          </div>
          <button class="btn btn-sm btn-solid" type="submit">Save rate →</button>
          <span style="font-size:12px;color:var(--text-dim)">A $1,000 deposit (on a $2,000 setup fee) pays the referrer $<?= number_format(1000 * $comPct / 100, 2) ?>.</span>
        </div>
      </form>
    </div>

    <div class="card"><h2>QR paywall</h2>
      <p style="font-size:13px;color:var(--text-dim);margin:0 0 12px">Growth Partners scan this QR code to pay for Standard, and clients to pay their 50% advance deposit, then enter their receipt reference for you to approve.</p>
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
    <?php
      // Referral network: one row per account in the network, built from
      // profiles.referred_by (the same link the partner's own downline uses).
      // Status follows the account itself — once an admin accepts the
      // account it reads Active; nothing to set by hand here.
      $netById = [];
      foreach ($liveProfiles as $np) $netById[$np['id']] = $np;
      $netKids = [];
      foreach ($liveProfiles as $np) {
          if (!empty($np['referred_by']) && isset($netById[$np['referred_by']])) $netKids[$np['referred_by']][] = $np;
      }
      // People below an account, every level, guarded against loops.
      $netBelow = function (string $id) use ($netKids): int {
          $n = 0; $seen = [$id => true]; $stack = [$id];
          while ($stack) {
              foreach ($netKids[array_pop($stack)] ?? [] as $k) {
                  if (isset($seen[$k['id']])) continue;
                  $seen[$k['id']] = true; $n++; $stack[] = $k['id'];
              }
          }
          return $n;
      };
      $netTree = function (string $id, int $lvl, array $seen) use (&$netTree, $netKids, $netBelow): string {
          if (empty($netKids[$id])) return '';
          $html = '<ul>';
          foreach ($netKids[$id] as $k) {
              if (isset($seen[$k['id']])) continue;
              $below = $netBelow($k['id']);
              $type  = role_name($k['role']) . (!empty($k['affiliate_tier']) && $k['role'] !== 'growth_partner' ? ' · ' . tier_label($k['affiliate_tier']) . ' partner' : (!empty($k['affiliate_tier']) ? ' · ' . tier_label($k['affiliate_tier']) : ''));
              $html .= '<li><div class="org-node ' . e($k['role']) . ($k['status'] !== 'accepted' ? ' is-pending' : '') . '" title="Joined ' . e(date('M j, Y', strtotime($k['created_at']))) . '">'
                     . '<span class="org-lvl">L' . $lvl . '</span><strong>' . e($k['full_name']) . '</strong>'
                     . '<span class="org-type">' . e($type) . ($k['status'] !== 'accepted' ? ' · ' . e($k['status']) : '') . '</span>'
                     . ($below ? '<span class="org-below">' . $below . ' below</span>' : '')
                     . '</div>' . $netTree($k['id'], $lvl + 1, $seen + [$k['id'] => true]) . '</li>';
          }
          return $html . '</ul>';
      };
      $netStatus = fn(array $p) => match ($p['status']) {
          'accepted'  => ['Active', 'active'],
          'pending'   => ['Pending approval', 'pending'],
          'rejected'  => ['Rejected', 'failed'],
          'suspended' => ['Suspended', 'suspended'],
          default     => [ucfirst((string) $p['status']), 'pending'],
      };
      $netRows = array_values(array_filter($liveProfiles, fn($np) => !empty($np['referral_code']) || !empty($np['referred_by']) || !empty($netKids[$np['id']])));
      $netCount = [];
      foreach ($netRows as $np) $netCount[$np['id']] = $netBelow($np['id']);
      // Biggest networks first, then A–Z.
      usort($netRows, fn($a, $b) => ($netCount[$b['id']] <=> $netCount[$a['id']]) ?: strcasecmp($a['full_name'], $b['full_name']));
    ?>
    <div class="card"><h2>Referral network</h2>
      <p class="hint" style="margin:0 0 12px">Every account with a referral code, an upline or a downline. Status follows the account: once you accept an account it is Active. Totals count every level below the account.</p>
      <?php if (!$netRows): ?><p class="empty">No referrals yet.</p>
      <?php else: ?><div class="table-scroll" tabindex="0" role="region" aria-label="Referral network"><table class="users-table net-table">
        <thead><tr><th scope="col">Account</th><th scope="col">Upline</th><th scope="col">Status</th><th scope="col" class="num">Direct (L1)</th><th scope="col" class="num">Total downline</th><th scope="col" class="ut-actions-h">Tree</th></tr></thead>
        <tbody><?php foreach ($netRows as $np):
            $up = $netById[$np['referred_by'] ?? ''] ?? null;
            [$stLabel, $stPill] = $netStatus($np);
            $direct = count($netKids[$np['id']] ?? []);
            $total  = $netCount[$np['id']];
            $dlgId  = 'tree-' . $np['id'];
        ?>
          <tr>
            <td class="ut-account">
              <span class="ut-avatar" aria-hidden="true"><?= e(strtoupper(mb_substr((string) $np['full_name'], 0, 1)) ?: '?') ?></span>
              <span class="ut-id"><strong><?= e($np['full_name']) ?></strong>
                <span class="ut-sub">@<?= e($np['username']) ?> · <?= e(role_name($np['role'])) ?></span>
                <?php if (!empty($np['referral_code'])): ?><span class="ut-sub"><span class="ref-code"><?= e($np['referral_code']) ?></span></span><?php endif; ?>
              </span>
            </td>
            <td><?= $up ? '<strong>' . e($up['full_name']) . '</strong><span class="ut-sub">@' . e($up['username']) . '</span>' : '<span class="hint">— top of network</span>' ?></td>
            <td><span class="pill <?= e($stPill) ?>"><?= e($stLabel) ?></span></td>
            <td class="num"><?= $direct ?></td>
            <td class="num"><strong class="net-total"><?= $total ?></strong></td>
            <td class="ut-actions">
              <?php if ($total): ?>
                <button type="button" class="btn btn-sm" onclick="document.getElementById('<?= e($dlgId) ?>').showModal()">View tree →</button>
                <dialog class="tree-dialog" id="<?= e($dlgId) ?>" aria-labelledby="<?= e($dlgId) ?>-t">
                  <div class="tree-dlg-head">
                    <div><span class="label"><?= $total ?> in downline · <?= $direct ?> direct</span><h3 id="<?= e($dlgId) ?>-t"><?= e($np['full_name']) ?>'s tree</h3></div>
                    <form method="dialog"><button class="btn btn-sm btn-ghost" type="submit">Close</button></form>
                  </div>
                  <div class="tree-dlg-body">
                    <div class="org"><div class="org-inner"><ul><li><div class="org-node org-root"><span class="org-lvl">Top</span><strong><?= e($np['full_name']) ?></strong><span class="org-type"><?= e($np['referral_code'] ?: role_name($np['role'])) ?></span></div><?= $netTree($np['id'], 1, [$np['id'] => true]) ?></li></ul></div></div>
                  </div>
                </dialog>
              <?php else: ?>
                <span class="hint">No downline</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?></tbody>
      </table></div><?php endif; ?>
    </div>
  </div>

  <!-- PACKAGES -->
  <div id="panel-packages" class="admin-panel">
    <?php if ($editPkg): $epHasChange = isset($scheduledByPkg[$editPkg['id']]); ?>
    <div class="card edit-card">
      <div class="edit-head"><h2>Edit package — <?= e($editPkg['name']) ?></h2><a class="btn btn-ghost btn-sm" href="/dashboard-admin.php#packages">Close</a></div>
      <?php if ($epHasChange): ?>
        <div class="alert alert-warn" style="margin:0">This package already has a change scheduled for <?= date('M j, Y H:i', strtotime($scheduledByPkg[$editPkg['id']]['effective_at'])) ?>. Cancel it below before scheduling another.</div>
      <?php else: ?>
      <p style="font-size:13px;color:var(--text-dim);margin:0 0 12px">Changes don't apply right away. Pick a notice period — everyone is notified now, and the change becomes permanent on that date. You can cancel it any time before then.</p>
      <form method="post"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="admin_action" value="edit_package"><input type="hidden" name="pkg_id" value="<?= e($editPkg['id']) ?>">
        <div class="row"><div class="field"><label>Name</label><input name="pkg_name" required value="<?= e($editPkg['name']) ?>"></div><div class="field"><label>Kind</label><select name="pkg_kind"><option value="service" <?= ($editPkg['kind'] ?? 'service') === 'service' ? 'selected' : '' ?>>Client service package</option><option value="affiliate" <?= ($editPkg['kind'] ?? '') === 'affiliate' ? 'selected' : '' ?>>Growth Partner plan</option></select></div></div>
        <div class="row"><div class="field"><label>Partner tier <span class="hint">(Growth Partner plans only)</span></label><select name="pkg_tier"><option value="basic" <?= $editPkg['tier'] === 'basic' ? 'selected' : '' ?>>Basic (free)</option><option value="standard" <?= $editPkg['tier'] === 'standard' ? 'selected' : '' ?>>Standard (paid)</option></select></div><div class="field"></div></div>
        <div class="row"><div class="field"><label>Price ($)</label><input name="pkg_price" type="number" step="0.01" min="0" value="<?= e((string) $editPkg['price']) ?>"><div class="hint">Ignored for Basic — Basic is always free.</div></div><div class="field"><label>Outright commission ($)</label><input name="pkg_comm_out" type="number" step="0.01" min="0" value="<?= e((string) $editPkg['commission_outright']) ?>"></div></div>
        <div class="row"><div class="field"><label>Unilevel (%)</label><input name="pkg_comm_uni" type="number" step="0.01" min="0" value="<?= e((string) $editPkg['commission_unilevel']) ?>"></div><div class="field"><label>Description</label><input name="pkg_desc" value="<?= e($editPkg['description'] ?? '') ?>"></div></div>
        <div class="row">
          <div class="field"><label>Apply change in</label><select name="days" required><?php for ($d = 1; $d <= 7; $d++): ?><option value="<?= $d ?>"><?= $d ?> day<?= $d > 1 ? 's' : '' ?> — <?= date('M j', strtotime("+{$d} days")) ?></option><?php endfor; ?></select></div>
          <div class="field"><label>Availability</label><label class="check" style="margin-top:10px"><input type="checkbox" name="pkg_active" value="1" <?= $editPkg['active'] ? 'checked' : '' ?>> Show this package (to Growth Partners or clients)</label></div>
        </div>
        <button class="btn btn-sm btn-solid" type="submit">Schedule change &amp; notify everyone →</button>
      </form>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="card"><h2>Packages</h2>
      <?php if ($allPkgs): ?><div class="table-scroll"><table>
        <thead><tr><th>Name</th><th>Kind</th><th>Price</th><th>Outright</th><th>Unilevel %</th><th>Active</th><th>Scheduled change</th><th></th></tr></thead>
        <tbody><?php foreach ($allPkgs as $pk): $sc = $scheduledByPkg[$pk['id']] ?? null; ?>
          <tr>
            <td><strong><?= e($pk['name']) ?></strong><br><span style="font-size:11px;color:var(--text-dim)"><?= e($pk['description'] ?? '') ?></span></td>
            <td><span class="role-badge <?= ($pk['kind'] ?? 'service') === 'service' ? 'client' : 'affiliate' ?>"><?= ($pk['kind'] ?? 'service') === 'service' ? 'Service' : 'Growth Partner · ' . e(tier_label($pk['tier'])) ?></span></td>
            <td><?= $pk['tier'] === 'basic' ? '<span style="color:var(--green)">Free</span>' : '$' . number_format((float) $pk['price'], 2) ?></td>
            <td>$<?= number_format((float) $pk['commission_outright'], 2) ?></td>
            <td><?= e($pk['commission_unilevel']) ?>%</td>
            <td><?= $pk['active'] ? '<span style="color:var(--green)">Yes</span>' : '<span style="color:var(--red)">No</span>' ?></td>
            <td style="font-size:12px;max-width:260px"><?php if ($sc): ?>
              <span class="pill pending">on <?= date('M j, H:i', strtotime($sc['effective_at'])) ?></span><br><?= e($sc['summary']) ?>
              <form method="post" class="inline-form" style="margin-top:4px" onsubmit="return confirm('Cancel this scheduled change?')"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="admin_action" value="cancel_change"><input type="hidden" name="change_id" value="<?= e($sc['id']) ?>"><button class="btn btn-sm btn-danger" type="submit">Cancel change</button></form>
            <?php else: ?>—<?php endif; ?></td>
            <td><a class="btn btn-sm" href="/dashboard-admin.php?edit_pkg=<?= e($pk['id']) ?>#packages">Edit</a></td>
          </tr>
        <?php endforeach; ?></tbody>
      </table></div><?php endif; ?>

      <?php $history = array_filter($allChanges, fn($c) => $c['status'] !== 'scheduled'); if ($history): ?>
        <details class="sub-list"><summary>Change history (<?= count($history) ?>)</summary>
          <?php foreach ($history as $c): ?>
            <div class="sub-row"><span><span class="pill <?= $c['status'] === 'applied' ? 'completed' : 'cancelled' ?>"><?= e($c['status']) ?></span> <strong><?= e($c['packages']['name'] ?? '—') ?></strong> — <?= e($c['summary'] ?? '') ?></span><span style="font-size:11px;color:var(--text-dim)"><?= date('M j, Y', strtotime($c['applied_at'] ?? $c['created_at'])) ?></span></div>
          <?php endforeach; ?>
        </details>
      <?php endif; ?>
      <div class="assign-form"><h3>+ Create package</h3>
        <form method="post"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="admin_action" value="create_package">
          <div class="row" style="margin-bottom:10px"><div class="field"><label>Name</label><input name="pkg_name" required placeholder="Package name"></div><div class="field"><label>Kind</label><select name="pkg_kind"><option value="service">Client service package</option><option value="affiliate">Growth Partner plan</option></select></div></div>
          <div class="row" style="margin-bottom:10px"><div class="field"><label>Partner tier <span class="hint">(Growth Partner plans only)</span></label><select name="pkg_tier"><option value="basic">Basic</option><option value="standard">Standard</option></select></div><div class="field"></div></div>
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
    <?php if ($editSvc): ?>
    <div class="card edit-card">
      <div class="edit-head"><h2>Edit service — <?= e($editSvc['service']) ?> · <?= e($editSvc['profiles']['full_name'] ?? '') ?></h2><a class="btn btn-ghost btn-sm" href="/dashboard-admin.php#services">Close</a></div>
      <form method="post"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="admin_action" value="edit_service"><input type="hidden" name="cs_id" value="<?= e($editSvc['id']) ?>">
        <div class="row"><div class="field"><label>Status</label><select name="svc_status"><?php foreach ($svcStatusList as $ss): ?><option <?= $editSvc['status'] === $ss ? 'selected' : '' ?>><?= e($ss) ?></option><?php endforeach; ?></select></div><div class="field"><label>Notes</label><input name="svc_notes" maxlength="500" value="<?= e($editSvc['notes'] ?? '') ?>"></div></div>
        <div style="display:flex;gap:8px"><button class="btn btn-sm btn-solid" type="submit">Save service →</button></div>
      </form>
      <form method="post" style="margin-top:8px" onsubmit="return confirm('Remove this service from the client?')"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="admin_action" value="remove_service"><input type="hidden" name="cs_id" value="<?= e($editSvc['id']) ?>"><button class="btn btn-sm btn-danger" type="submit">Remove service</button></form>
    </div>
    <?php endif; ?>
    <div class="card"><h2>Client services</h2>
      <?php if ($allCS): ?><div class="table-scroll"><table>
        <thead><tr><th>Client</th><th>Service</th><th>Status</th><th>Started</th><th>Notes</th><th></th></tr></thead>
        <tbody><?php foreach ($allCS as $cs): ?>
          <tr><td><strong><?= e($cs['profiles']['full_name'] ?? '—') ?></strong></td><td><?= e($cs['service']) ?></td><td><span class="pill <?= e($cs['status']) ?>"><?= e($cs['status']) ?></span></td><td style="font-size:12px"><?= date('M j, Y', strtotime($cs['started_at'])) ?></td><td style="font-size:12px;max-width:200px"><?= e($cs['notes'] ?? '—') ?></td><td><a class="btn btn-sm" href="/dashboard-admin.php?edit_svc=<?= e($cs['id']) ?>#services">Edit</a></td></tr>
        <?php endforeach; ?></tbody>
      </table></div><?php endif; ?>
      <div class="assign-form"><h3>+ Assign service</h3>
        <?php if (!$clients): ?><p style="font-size:13px;color:var(--text-dim)">No clients.</p>
        <?php else: ?><form method="post" class="inline-form" style="flex-wrap:wrap;gap:8px"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="admin_action" value="assign_service"><select name="client_id" required><option value="">Select client…</option><?php foreach ($clients as $c): ?><option value="<?= e($c['id']) ?>"><?= e($c['full_name']) ?></option><?php endforeach; ?></select><select name="service" required><?php foreach ($serviceList as $sv): ?><option><?= e($sv) ?></option><?php endforeach; ?></select><input name="svc_notes" type="text" placeholder="Notes" style="flex:1;min-width:120px"><button class="btn btn-sm btn-solid" type="submit">Assign →</button></form><?php endif; ?>
      </div>
    </div>
  </div>

  <!-- ROULETTE -->
  <div id="panel-roulette" class="admin-panel">
    <div class="card" style="margin-bottom:12px"><h2>Roulette rotation</h2>
      <p style="font-size:13px;color:var(--text-dim);margin:0 0 12px">Every account you approve is handed to the next admin in this order, then it starts again from the top: 1, 2, 3, 1, 2, 3… Paused admins are skipped. An account keeps its admin for good, so any package it buys later goes to the same person instead of taking a new turn.</p>
      <?php if (!$roulette['members']): ?><p class="empty">Nobody is in the rotation — approved accounts won't be assigned. Add an admin below.</p>
      <?php else: ?>
      <ol class="roulette">
        <?php $n = count($roulette['members']); foreach ($roulette['members'] as $k => $m): $isNext = $m['admin_id'] === $roulette['next']; ?>
          <li class="roulette-slot<?= $isNext ? ' is-next' : '' ?><?= $m['active'] ? '' : ' is-paused' ?>">
            <span class="roulette-num"><?= $k + 1 ?></span>
            <span class="roulette-name"><strong><?= e($m['admin']['full_name'] ?? '—') ?></strong><small><?= (int) ($perAdminAcct[$m['admin_id']] ?? 0) ?> account<?= ($perAdminAcct[$m['admin_id']] ?? 0) == 1 ? '' : 's' ?> · <?= (int) ($perAdmin[$m['admin_id']] ?? 0) ?> package<?= ($perAdmin[$m['admin_id']] ?? 0) == 1 ? '' : 's' ?><?= $m['active'] ? '' : ' · paused' ?></small></span>
            <?php if ($isNext): ?><span class="pill pending">next up</span><?php endif; ?>
            <span class="roulette-actions">
              <?php foreach ([['roulette_up', '↑', $k > 0], ['roulette_down', '↓', $k < $n - 1], ['roulette_toggle', $m['active'] ? 'Pause' : 'Resume', true], ['roulette_remove', 'Remove', true]] as [$a, $lbl, $show]): if (!$show) continue; ?>
                <form method="post" class="inline-form" <?= $a === 'roulette_remove' ? 'onsubmit="return confirm(\'Remove from the rotation?\')"' : '' ?>><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="admin_action" value="<?= $a ?>"><input type="hidden" name="admin_id" value="<?= e($m['admin_id']) ?>"><button class="btn btn-sm<?= $a === 'roulette_remove' ? ' btn-danger' : '' ?>" type="submit"><?= e($lbl) ?></button></form>
              <?php endforeach; ?>
            </span>
          </li>
        <?php endforeach; ?>
      </ol>
      <?php endif; ?>
      <?php $outside = array_filter($admins, fn($a) => !in_array($a['id'], array_column($roulette['members'], 'admin_id'), true)); if ($outside): ?>
        <form method="post" class="inline-form" style="margin-top:12px"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="admin_action" value="roulette_add"><select name="admin_id"><?php foreach ($outside as $a): ?><option value="<?= e($a['id']) ?>"><?= e($a['full_name']) ?></option><?php endforeach; ?></select><button class="btn btn-sm" type="submit">+ Add to rotation</button></form>
      <?php endif; ?>
    </div>

    <div class="card"><div class="edit-head"><h2>Assigned packages</h2>
      <a class="btn btn-sm btn-ghost" href="/dashboard-admin.php<?= $onlyMine ? '' : '?mine=1' ?>#roulette"><?= $onlyMine ? 'Show everyone' : 'Only mine' ?></a></div>
      <?php if (!$assignedShown): ?><p class="empty"><?= $onlyMine ? 'Nothing assigned to you yet.' : 'No packages assigned yet.' ?></p>
      <?php else: ?><div class="table-scroll"><table>
        <thead><tr><th>Client</th><th>Package</th><th>Business &amp; struggles</th><th>Approved</th><th>Assigned to</th></tr></thead>
        <tbody><?php foreach ($assignedShown as $t): ?>
          <tr>
            <td><strong><?= e($t['profiles']['full_name'] ?? '—') ?></strong><br><span style="font-size:11px;color:var(--text-dim)"><?= e($t['profiles']['email'] ?? '') ?> <?= e($t['profiles']['phone'] ?? '') ?></span></td>
            <td><?= e($t['packages']['name'] ?? '—') ?><br><span class="role-badge <?= $t['type'] === 'deposit' ? 'client' : 'affiliate' ?>"><?= $t['type'] === 'deposit' ? 'Client package' : 'Growth Partner plan' ?></span></td>
            <td style="min-width:200px"><?= business_summary($t['profiles'] ?? []) ?></td>
            <td style="font-size:12px"><?= date('M j, Y', strtotime($t['reviewed_at'] ?? $t['created_at'])) ?></td>
            <td><form method="post" class="inline-form"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="admin_action" value="reassign"><input type="hidden" name="txn_id" value="<?= e($t['id']) ?>"><select name="assigned_admin"><?php foreach ($admins as $a): ?><option value="<?= e($a['id']) ?>" <?= $t['assigned_admin'] === $a['id'] ? 'selected' : '' ?>><?= e($a['full_name']) ?></option><?php endforeach; ?></select><button class="btn btn-sm" type="submit">Move</button></form></td>
          </tr>
        <?php endforeach; ?></tbody>
      </table></div><?php endif; ?>
    </div>
  </div>

  <!-- NOTIFY -->
  <div id="panel-notify" class="admin-panel">
    <div class="card"><h2>Send a notification</h2>
      <p style="font-size:13px;color:var(--text-dim);margin:0 0 12px">Shows up in the bell on everyone's dashboard in the chosen group. Package changes, approvals and account edits already send their own notifications automatically.</p>
      <form method="post"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="admin_action" value="send_notice">
        <div class="row"><div class="field"><label>Send to</label><select name="n_audience"><?php foreach ($audienceOptions as $k => $lbl): ?><option value="<?= e($k) ?>"><?= e($lbl) ?></option><?php endforeach; ?></select></div><div class="field"><label>Title</label><input name="n_title" required maxlength="200" placeholder="e.g. Office closed on Monday"></div></div>
        <div class="field"><label>Message</label><textarea name="n_body" rows="3" maxlength="1000" placeholder="Optional details"></textarea></div>
        <button class="btn btn-sm btn-solid" type="submit">Send notification →</button>
      </form>
    </div>
  </div>

  <!-- RECYCLE BIN -->
  <div id="panel-trash" class="admin-panel">
    <div class="card" style="margin-bottom:12px"><h2>Recycle bin</h2>
      <p style="font-size:13px;color:var(--text-dim);margin:0">Everything you or your staff took out of circulation. Nothing here is erased, so all of it can be put back. Who did what, and when, is in the <a href="/dashboard-admin.php#logs" onclick="document.querySelector('.admin-tab[data-panel=logs]').click()">audit log</a>.</p>
      <?php if (!$trashCount): ?><p class="empty" style="margin-top:10px">The recycle bin is empty.</p><?php endif; ?>
    </div>

    <?php if ($binUsers): ?>
    <div class="card" style="margin-bottom:12px"><h2>Deleted accounts <span class="hint">(<?= count($binUsers) ?>)</span></h2>
      <div class="table-scroll"><table>
        <thead><tr><th>Name</th><th>Username</th><th>Role</th><th>Deleted</th><th>Code</th><th></th></tr></thead>
        <tbody><?php foreach ($binUsers as $p): ?>
          <tr>
            <td><strong><?= e($p['full_name']) ?></strong><?php if (!empty($p['email'])): ?><br><span class="hint"><?= e($p['email']) ?></span><?php endif; ?></td>
            <td style="font-size:12px"><?= e($p['username']) ?></td>
            <td><span class="role-badge <?= e($p['role']) ?>"><?= e(role_name($p['role'])) ?></span></td>
            <td style="font-size:12px"><?= date('M j, Y H:i', strtotime($p['deleted_at'])) ?></td>
            <td style="font-size:11px"><?= e($p['referral_code']) ?></td>
            <td><div class="bin-actions">
              <form method="post" class="inline-form"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="admin_action" value="restore_user"><input type="hidden" name="user_id" value="<?= e($p['id']) ?>"><button class="btn btn-sm" type="submit">Restore</button></form>
              <details class="bin-purge"><summary class="btn btn-sm btn-danger">Delete forever</summary>
                <form method="post"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="admin_action" value="purge_user"><input type="hidden" name="user_id" value="<?= e($p['id']) ?>">
                  <p>This erases the account <strong>and everything attached to it</strong>: payments, commissions, referrals, tasks, reports, consents and identity documents. People they referred lose their upline. It cannot be undone.</p>
                  <label>Type <strong><?= e($p['username']) ?></strong> to confirm</label>
                  <input name="confirm_username" required autocomplete="off" placeholder="<?= e($p['username']) ?>">
                  <button class="btn btn-sm btn-danger" type="submit">Delete permanently</button>
                </form>
              </details>
            </div></td>
          </tr>
        <?php endforeach; ?></tbody>
      </table></div>
      <p class="hint" style="margin:8px 0 0">A restored account goes back to Pending, so approve it again in Approvals. Its referral code, referrals and commissions are kept.</p>
    </div>
    <?php endif; ?>

    <?php if ($binTxns): ?>
    <div class="card" style="margin-bottom:12px"><h2>Cancelled &amp; rejected payments <span class="hint">(<?= count($binTxns) ?>)</span></h2>
      <div class="table-scroll"><table>
        <thead><tr><th>User</th><th>Package</th><th>Amount</th><th>Why</th><th>When</th><th></th></tr></thead>
        <tbody><?php foreach ($binTxns as $t): ?>
          <tr>
            <td><strong><?= e($t['profiles']['full_name'] ?? '—') ?></strong><br><span class="hint"><?= e($t['type']) ?></span></td>
            <td style="font-size:12px"><?= e($t['packages']['name'] ?? '—') ?><?= !empty($t['payment_ref']) ? '<br><span class="hint">ref ' . e($t['payment_ref']) . '</span>' : '' ?></td>
            <td>$<?= number_format((float) $t['amount'], 2) ?></td>
            <td style="font-size:12px"><?= e($t['notes'] ?? '—') ?></td>
            <td style="font-size:12px"><?= date('M j, Y', strtotime($t['reviewed_at'] ?? $t['created_at'])) ?></td>
            <td><div class="bin-actions"><form method="post" class="inline-form" onsubmit="return confirm('Put this payment back in Approvals?')"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="admin_action" value="restore_txn"><input type="hidden" name="txn_id" value="<?= e($t['id']) ?>"><button class="btn btn-sm" type="submit">Restore</button></form>
              <form method="post" class="inline-form" onsubmit="return confirm('Delete this payment record for good? This cannot be undone.')"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="admin_action" value="purge_txn"><input type="hidden" name="txn_id" value="<?= e($t['id']) ?>"><button class="btn btn-sm btn-danger" type="submit">Delete</button></form></div></td>
          </tr>
        <?php endforeach; ?></tbody>
      </table></div>
      <p class="hint" style="margin:8px 0 0">Restoring sets the payment back to pending so you can approve or reject it again in Approvals.</p>
    </div>
    <?php endif; ?>

    <?php if ($binTasks): ?>
    <div class="card" style="margin-bottom:12px"><h2>Cancelled client tasks <span class="hint">(<?= count($binTasks) ?>)</span></h2>
      <div class="table-scroll"><table>
        <thead><tr><th>Task</th><th>Client</th><th>Reason</th><th>Approved by</th><th>When</th><th></th></tr></thead>
        <tbody><?php foreach ($binTasks as $t): ?>
          <tr>
            <td><a href="/work.php?task=<?= e($t['id']) ?>"><strong><?= e($t['task_code']) ?></strong> <?= e($t['title']) ?></a></td>
            <td style="font-size:12px"><?= e($t['client']['full_name'] ?? '—') ?></td>
            <td style="font-size:12px"><?= e($t['cancel_reason'] ?? '—') ?></td>
            <td style="font-size:12px"><span class="tag"><?= e($t['cancel_approved_by'] ?: '—') ?></span></td>
            <td style="font-size:12px"><?= date('M j, Y', strtotime($t['updated_at'])) ?></td>
            <td><div class="bin-actions"><form method="post" class="inline-form"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="admin_action" value="restore_task"><input type="hidden" name="task_id" value="<?= e($t['id']) ?>"><button class="btn btn-sm" type="submit">Restore</button></form>
              <form method="post" class="inline-form" onsubmit="return confirm('Delete this task and its whole task log for good? This cannot be undone.')"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="admin_action" value="purge_task"><input type="hidden" name="task_id" value="<?= e($t['id']) ?>"><button class="btn btn-sm btn-danger" type="submit">Delete</button></form></div></td>
          </tr>
        <?php endforeach; ?></tbody>
      </table></div>
      <p class="hint" style="margin:8px 0 0">A restored task comes back as In Progress with its task log and evidence intact.</p>
    </div>
    <?php endif; ?>

    <?php if ($binPkgs): ?>
    <div class="card"><h2>Retired packages <span class="hint">(<?= count($binPkgs) ?>)</span></h2>
      <div class="table-scroll"><table>
        <thead><tr><th>Name</th><th>Kind</th><th>Price</th><th>Monthly</th><th></th></tr></thead>
        <tbody><?php foreach ($binPkgs as $pk): ?>
          <tr>
            <td><strong><?= e($pk['name']) ?></strong><?= !empty($pk['description']) ? '<br><span class="hint">' . e(mb_substr($pk['description'], 0, 70)) . '</span>' : '' ?></td>
            <td><span class="role-badge <?= ($pk['kind'] ?? 'service') === 'service' ? 'client' : 'affiliate' ?>"><?= ($pk['kind'] ?? 'service') === 'service' ? 'Service' : 'Growth Partner · ' . e(tier_label($pk['tier'])) ?></span></td>
            <td>$<?= number_format((float) $pk['price'], 2) ?></td>
            <td style="font-size:12px"><?= (float) ($pk['monthly_price'] ?? 0) > 0 ? '$' . number_format((float) $pk['monthly_price'], 2) . '/mo' : '—' ?></td>
            <td><div class="bin-actions"><form method="post" class="inline-form"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="admin_action" value="restore_package"><input type="hidden" name="pkg_id" value="<?= e($pk['id']) ?>"><button class="btn btn-sm" type="submit">Put back on sale</button></form>
              <form method="post" class="inline-form" onsubmit="return confirm('Delete this package for good? Past payments keep its name in their notes. This cannot be undone.')"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="admin_action" value="purge_package"><input type="hidden" name="pkg_id" value="<?= e($pk['id']) ?>"><button class="btn btn-sm btn-danger" type="submit">Delete</button></form></div></td>
          </tr>
        <?php endforeach; ?></tbody>
      </table></div>
      <p class="hint" style="margin:8px 0 0">Hidden packages keep every past transaction that points at them.</p>
    </div>
    <?php endif; ?>
  </div>

  <!-- AUDIT LOGS -->
  <div id="panel-logs" class="admin-panel">
    <div class="card"><h2>System audit log</h2>
      <nav class="log-cats" aria-label="Log categories">
        <a href="/dashboard-admin.php#logs" class="<?= $logCat === '' ? 'on' : '' ?>">All</a>
        <?php foreach (LOG_CATEGORIES as $ck => $c): ?><a href="/dashboard-admin.php?log=<?= e($ck) ?>#logs" class="<?= $logCat === $ck ? 'on' : '' ?>"><?= e($c['label']) ?></a><?php endforeach; ?>
        <a href="/dashboard-admin.php?log=other#logs" class="<?= $logCat === 'other' ? 'on' : '' ?>">Other</a>
      </nav>
      <div class="terminal-header"><span class="dots"><span></span><span></span><span></span></span><span>system_logs // <?= e($logCat === '' ? 'all categories' : (LOG_CATEGORIES[$logCat]['label'] ?? 'other')) ?> · last 200</span></div>
      <div class="terminal-body log-wrap">
        <?php if (!$allLogs): ?><div class="prompt">$ tail -f /var/log/kamteam.log</div><div class="output">[ nothing in this category yet ]</div>
        <?php else: $day = null; $i = 0; ?>
        <table class="log-table">
          <thead><tr><th>Time</th><th>Category</th><th>Action</th><th>What happened</th><th>By</th><th>IP</th></tr></thead>
          <tbody>
          <?php foreach ($allLogs as $log):
              $cat  = log_category($log['action']);
              $when = strtotime($log['created_at']);
              $date = date('Y-m-d', $when);
              if ($date !== $day): $day = $date; $i = 0; ?>
              <tr class="log-day"><td colspan="6"><?= date('l, F j, Y', $when) ?></td></tr>
          <?php endif; ?>
            <tr<?= ++$i % 2 === 0 ? ' class="alt"' : '' ?>>
              <td class="log-time"><?= date('H:i:s', $when) ?></td>
              <td><span class="log-cat log-cat-<?= e($cat) ?>"><?= e(LOG_CATEGORIES[$cat]['label'] ?? 'Other') ?></span></td>
              <td class="log-action"><?= e($log['action']) ?></td>
              <td class="log-what"><?= e($log['summary'] ?? '—') ?>
                <?php if (($log['entity_type'] ?? '') === 'client_task' && $log['entity_id']): ?><a href="/work.php?task=<?= e($log['entity_id']) ?>">open task →</a>
                <?php elseif (($log['entity_type'] ?? '') === 'client_report' && $log['entity_id']): ?><a href="/work.php?report=<?= e($log['entity_id']) ?>">open report →</a><?php endif; ?></td>
              <td class="log-by"><?= e($log['profiles']['full_name'] ?? 'system') ?></td>
              <td class="log-ip"><?= e($log['ip_address'] ?: '—') ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
    </div>
  </div>
</main>
</div>

<?= ui_foot() ?>
