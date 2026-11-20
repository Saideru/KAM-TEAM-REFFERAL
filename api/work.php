<?php
require_once __DIR__ . '/lib/supabase.php';
require_once __DIR__ . '/lib/logo.php';
require_once __DIR__ . '/lib/work.php';

// Client Work Documentation & Reporting (SOP-OPS-001) — admin & staff workspace.
// Staff can do everything here except approve and deliver client reports.
['token' => $token, 'profile' => $profile] = require_role(['admin', 'staff']);
$isAdmin = $profile['role'] === 'admin';
$errors = [];
$notice = flash();
$csrf   = csrf_token();
$adminId = $profile['id'];

$s   = fn($k, $max = 2000) => mb_substr(trim((string) ($_POST[$k] ?? '')), 0, $max);
$d   = fn($k) => ($v = trim((string) ($_POST[$k] ?? ''))) !== '' && strtotime($v) ? date('Y-m-d', strtotime($v)) : null;
$go  = function (string $q) { header('Location: /work.php' . $q); exit; };
$get = fn(string $path) => (is_array($r = supabase_request('GET', $path, null, $token)['body'] ?? null) ? $r : []);

$clients  = $get('/rest/v1/profiles?select=id,full_name,company,username&role=eq.client&status=eq.accepted&deleted_at=is.null&order=full_name.asc');
$services = $get('/rest/v1/packages?select=id,name&kind=eq.service&order=price.asc');
// The team (admins + staff) is suggested in every SOP tag field.
$admins   = $get('/rest/v1/profiles?select=full_name&role=in.(admin,staff)&status=eq.accepted&deleted_at=is.null&order=full_name.asc');
$clientName = array_column($clients, 'full_name', 'id');
$pkgName    = array_column($services, 'name', 'id');
$validClient = fn($id) => isset($clientName[$id]) ? $id : null;
$validPkg    = fn($id) => isset($pkgName[$id]) ? $id : null;

$taskFields = fn() => [
    'title' => $s('title', 200), 'scope_ref' => $s('scope_ref', 300) ?: null, 'milestone' => $s('milestone', 120) ?: null,
    'assignee' => $s('assignee', 80) ?: null, 'reviewer' => $s('reviewer', 80) ?: null,
    'target_date' => $d('target_date'), 'work_performed' => $s('work_performed') ?: null,
    'deliverable' => $s('deliverable', 1000) ?: null, 'evidence' => $s('evidence', 2000) ?: null,
    'blockers' => $s('blockers', 1000) ?: null, 'next_action' => $s('next_action', 300) ?: null,
    'next_owner' => $s('next_owner', 80) ?: null, 'next_date' => $d('next_date'),
    'package_id' => $validPkg($_POST['package_id'] ?? '') ,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check()) {
    $act = $_POST['act'] ?? '';

    // ---- Step 1: intake ----
    if ($act === 'create_task') {
        $f = $taskFields();
        $f['client_id']   = $validClient($_POST['client_id'] ?? '');
        $f['assigned_on'] = $d('assigned_on') ?: date('Y-m-d');
        $f['created_by']  = $adminId;
        if (!$f['client_id'])  $errors[] = 'Choose the client.';
        if ($f['title'] === '') $errors[] = 'Give the task a short, action-oriented title.';
        if (!$errors) {
            $res = supabase_request('POST', '/rest/v1/client_tasks', $f, $token, ['Prefer: return=representation']);
            if (!$res['error'] && !empty($res['body'][0])) {
                $t = $res['body'][0];
                task_log($token, $t, 'update', 'Task created and assigned' . ($t['assignee'] ? ' to ' . $t['assignee'] : '') . '.', null, null, $s('author_tag', 80));
                flash('success', $t['task_code'] . ' created.'); $go('?task=' . $t['id']);
            }
            $errors[] = $res['error'] ?: 'Could not create the task.';
        }
    }

    $taskId = (string) ($_POST['task_id'] ?? '');
    $task   = $taskId ? ($get('/rest/v1/client_tasks?select=*,packages(name)&id=eq.' . urlencode($taskId))[0] ?? null) : null;

    // ---- Steps 2-3: execution log, evidence, record upkeep ----
    if ($act === 'save_task' && $task) {
        $f = $taskFields();
        if ($f['title'] === '') $errors[] = 'Title is required.';
        if (in_array($task['status'], ['for_review', 'completed'], true)) {
            foreach (task_missing_fields($f) as $m) $errors[] = $m . ' cannot be blank while the task is ' . task_status_label($task['status']) . '.';
        }
        if (!$errors) {
            $changed = array_keys(array_filter($f, fn($v, $k) => (string) $v !== (string) ($task[$k] ?? ''), ARRAY_FILTER_USE_BOTH));
            supabase_request('PATCH', '/rest/v1/client_tasks?id=eq.' . urlencode($task['id']), $f, $token, ['Prefer: return=minimal']);
            if ($changed) task_log($token, $task, 'update', 'Record updated: ' . implode(', ', str_replace('_', ' ', $changed)) . '.', null, null, $s('author_tag', 80));
            flash('success', 'Task record saved.'); $go('?task=' . $task['id']);
        }
    }

    if ($act === 'log_entry' && $task) {
        $kind = isset(TASK_LOG_KINDS[$_POST['kind'] ?? '']) ? $_POST['kind'] : 'update';
        $note = $s('note');
        if ($note === '') {
            $errors[] = 'Write the log entry.';
        } else {
            $patch = [];
            // Evidence and blockers also land on the task record itself.
            if ($kind === 'evidence') $patch['evidence'] = trim(($task['evidence'] ? $task['evidence'] . "\n" : '') . $note);
            if ($kind === 'blocker')  $patch['blockers'] = trim(($task['blockers'] ? $task['blockers'] . "\n" : '') . $note);
            if ($patch) supabase_request('PATCH', '/rest/v1/client_tasks?id=eq.' . urlencode($task['id']), $patch, $token, ['Prefer: return=minimal']);
            task_log($token, $task, $kind, $note, null, null, $s('author_tag', 80));
            flash('success', TASK_LOG_KINDS[$kind] . ' logged.'); $go('?task=' . $task['id']);
        }
    }

    // ---- Steps 4-5: status update and verification ----
    if ($act === 'set_status' && $task) {
        $to    = (string) ($_POST['status'] ?? '');
        $input = ['completed_on' => $d('completed_on'), 'cancel_reason' => $s('cancel_reason', 300) ?: null,
                  'cancel_approved_by' => $s('cancel_approved_by', 80) ?: null, 'note' => $s('note')];
        if ($s('blockers', 1000) !== '') $input['blockers'] = $s('blockers', 1000);
        $errors = task_transition_errors($task, $to, $input);
        if (!$errors && $to !== $task['status']) {
            $patch = ['status' => $to];
            if ($to === 'completed')  $patch['completed_on'] = $input['completed_on'];
            if ($to !== 'completed' && $task['status'] === 'completed') $patch['completed_on'] = null;
            if ($to === 'cancelled')  $patch += ['cancel_reason' => $input['cancel_reason'], 'cancel_approved_by' => $input['cancel_approved_by']];
            if (isset($input['blockers'])) $patch['blockers'] = $input['blockers'];
            supabase_request('PATCH', '/rest/v1/client_tasks?id=eq.' . urlencode($task['id']), $patch, $token, ['Prefer: return=minimal']);
            $note = $input['note'] ?: ($to === 'completed' ? 'Verified against acceptance criteria' . ($task['reviewer'] ? ' by ' . $task['reviewer'] : '') . '.'
                  : ($to === 'cancelled' ? 'Cancelled: ' . $input['cancel_reason'] . ' (approved by ' . $input['cancel_approved_by'] . ')' : 'Status changed.'));
            task_log($token, $task, $to === 'completed' ? 'completion' : 'update', $note, $task['status'], $to, $s('author_tag', 80));
            if ($to === 'for_review' && $task['reviewer']) {
                foreach (['admin', 'staff'] as $aud) {
                    notify($token, 'Task for review: ' . $task['task_code'], $task['title'] . ' is ready for verification (' . $task['reviewer'] . ').', null, $aud, '/work.php?task=' . $task['id']);
                }
            }
            flash('success', $task['task_code'] . ' → ' . task_status_label($to) . '.'); $go('?task=' . $task['id']);
        }
    }

    // ---- Step 6: compile a draft report ----
    if ($act === 'create_report') {
        $r = [
            'client_id' => $validClient($_POST['client_id'] ?? ''), 'package_id' => $validPkg($_POST['package_id'] ?? ''),
            'report_type' => isset(REPORT_TYPES[$_POST['report_type'] ?? '']) ? $_POST['report_type'] : 'monthly',
            'period_start' => $d('period_start'), 'period_end' => $d('period_end'),
            'owner' => $s('owner', 80) ?: null, 'coordinator' => $s('coordinator', 80) ?: null,
            'prepared_by' => $s('coordinator', 80) ?: null, 'created_by' => $adminId,
        ];
        if (!$r['client_id']) $errors[] = 'Choose the client.';
        if (!$r['period_start'] || !$r['period_end'] || $r['period_end'] < $r['period_start']) $errors[] = 'Enter a valid reporting period.';
        if (!$errors) {
            $res = supabase_request('POST', '/rest/v1/client_reports', $r, $token, ['Prefer: return=representation']);
            if (!$res['error'] && !empty($res['body'][0])) {
                $new = $res['body'][0];
                audit_log($token, 'report_draft', 'client_report', $new['id'], REPORT_TYPES[$new['report_type']] . ' draft for ' . $clientName[$new['client_id']] . ' (' . $new['period_start'] . ' – ' . $new['period_end'] . ')');
                flash('success', 'Draft report created from the tracker.'); $go('?report=' . $new['id']);
            }
            $errors[] = $res['error'] ?: 'Could not create the report.';
        }
    }

    $repId  = (string) ($_POST['report_id'] ?? '');
    $report = $repId ? ($get('/rest/v1/client_reports?select=*&id=eq.' . urlencode($repId))[0] ?? null) : null;

    // ---- Step 7: QA and approval ----
    if ($act === 'approve_report' && !$isAdmin) $act = 'save_report';   // staff save the draft; an admin approves
    if (in_array($act, ['save_report', 'approve_report'], true) && $report) {
        if ($report['status'] !== 'draft') {
            $errors[] = 'Only a draft can be edited. Reissue it as a new version instead.';
        } else {
            $patch = [
                'summary' => $s('summary', 4000) ?: null, 'results' => $s('results', 4000) ?: null,
                'next_plan' => $s('next_plan', 4000) ?: null, 'client_decisions' => $s('client_decisions', 2000) ?: null,
                'owner' => $s('owner', 80) ?: null, 'coordinator' => $s('coordinator', 80) ?: null,
                'prepared_by' => $s('prepared_by', 80) ?: null, 'verified_by' => $s('verified_by', 80) ?: null,
                'approved_by' => $s('approved_by', 80) ?: null,
                'qa' => array_fill_keys(array_keys(array_intersect_key((array) ($_POST['qa'] ?? []), REPORT_QA)), true),
            ];
            $blocked = [];
            if ($act === 'approve_report') {
                $missingQa = array_diff_key(REPORT_QA, $patch['qa']);
                if ($missingQa) $blocked[] = 'QA must confirm all ' . count(REPORT_QA) . ' checklist items (' . count($missingQa) . ' still open).';
                foreach (['summary' => 'Executive summary', 'prepared_by' => 'Prepared by', 'verified_by' => 'Verified by', 'approved_by' => 'Approved by'] as $k => $lbl) {
                    if (!$patch[$k]) $blocked[] = $lbl . ' is required.';
                }
                if (!$blocked) {
                    $patch['snapshot']    = report_compile($token, $report);
                    $patch['status']      = 'approved';
                    $patch['report_date'] = date('Y-m-d');
                }
            }
            // Draft edits are always kept, even when approval is blocked.
            supabase_request('PATCH', '/rest/v1/client_reports?id=eq.' . urlencode($report['id']), $patch, $token, ['Prefer: return=minimal']);
            $approved = $act === 'approve_report' && !$blocked;
            audit_log($token, $approved ? 'report_approved' : 'report_saved', 'client_report', $report['id'],
                ($clientName[$report['client_id']] ?? 'Client') . ' · ' . REPORT_TYPES[$report['report_type']] . ' v' . $report['version']
                . ($approved ? ' approved by ' . $patch['approved_by'] : ' draft saved'));
            if ($blocked) flash('error', 'Draft saved, but it cannot be approved yet: ' . implode(' ', $blocked));
            else flash('success', $approved ? 'Report approved and frozen. You can now deliver it.' : 'Draft saved.');
            $go('?report=' . $report['id']);
        }
    }

    // ---- Step 8: delivery ----
    if ($act === 'deliver_report' && $report) {
        $channel = $s('delivery_channel', 80);
        if (!$isAdmin) $errors[] = 'Only an admin can deliver a client report.';
        if ($report['status'] !== 'approved') $errors[] = 'Only an approved report can be delivered.';
        if ($channel === '') $errors[] = 'Record the delivery channel.';
        if (!$errors) {
            supabase_request('PATCH', '/rest/v1/client_reports?id=eq.' . urlencode($report['id']), [
                'status' => 'delivered', 'delivered_at' => date('c'), 'delivery_channel' => $channel,
                'delivery_recipients' => $s('delivery_recipients', 300) ?: null,
            ], $token, ['Prefer: return=minimal']);
            notify($token, 'New report: ' . REPORT_TYPES[$report['report_type']],
                'Your report for ' . date('M j', strtotime($report['period_start'])) . ' – ' . date('M j, Y', strtotime($report['period_end'])) . ' is ready.',
                $report['client_id'], 'all', '/report.php?id=' . $report['id']);
            audit_log($token, 'report_delivered', 'client_report', $report['id'], ($clientName[$report['client_id']] ?? 'Client') . ' · v' . $report['version'] . ' via ' . $channel);
            flash('success', 'Delivered. The client can open it from their dashboard.'); $go('?report=' . $report['id']);
        }
    }

    // ---- Step 9: archive & follow-up ----
    if ($act === 'report_feedback' && $report) {
        supabase_request('PATCH', '/rest/v1/client_reports?id=eq.' . urlencode($report['id']), ['client_feedback' => $s('client_feedback', 2000) ?: null], $token, ['Prefer: return=minimal']);
        audit_log($token, 'report_feedback', 'client_report', $report['id'], 'Client feedback recorded on v' . $report['version']);
        flash('success', 'Feedback recorded. Log any requests as new tasks.'); $go('?report=' . $report['id']);
    }

    // QA failed after sending, or a correction is needed: issue a new version.
    if ($act === 'reissue_report' && $report) {
        $note = $s('revision_note', 500);
        if ($report['status'] === 'draft') $errors[] = 'This report is still a draft — just edit it.';
        if ($note === '') $errors[] = 'Explain what changed in the revision note.';
        if (!$errors) {
            $copy = array_intersect_key($report, array_flip(['client_id', 'package_id', 'report_type', 'period_start', 'period_end', 'owner', 'summary', 'results', 'next_plan', 'client_decisions', 'coordinator', 'prepared_by']));
            $copy += ['version' => (int) $report['version'] + 1, 'revision_of' => $report['id'], 'revision_note' => $note, 'created_by' => $adminId];
            $res = supabase_request('POST', '/rest/v1/client_reports', $copy, $token, ['Prefer: return=representation']);
            if (!$res['error'] && !empty($res['body'][0])) {
                audit_log($token, 'report_reissued', 'client_report', $res['body'][0]['id'], 'v' . $copy['version'] . ' of ' . ($clientName[$report['client_id']] ?? 'client') . ' report — ' . $note);
                flash('success', 'Version ' . $copy['version'] . ' drafted. Fix the tracker records first, then re-approve.'); $go('?report=' . $res['body'][0]['id']);
            }
            $errors[] = $res['error'] ?: 'Could not create the new version.';
        }
    }
}

// ------------------------------------------------------------------ views
$view   = isset($_GET['task']) ? 'task' : (isset($_GET['report']) ? 'report' : (($_GET['view'] ?? '') === 'reports' ? 'reports' : 'tasks'));
$fClient = $validClient($_GET['client'] ?? '');
$fStatus = isset(TASK_STATUSES[$_GET['status'] ?? '']) ? $_GET['status'] : '';

$used = $get('/rest/v1/client_tasks?select=assignee,reviewer&order=updated_at.desc&limit=300');
$tags = array_values(array_unique(array_filter(array_merge(array_column($admins, 'full_name'),
    array_column($used, 'assignee'), array_column($used, 'reviewer')))));
$datalist = '<datalist id="people">' . implode('', array_map(fn($n) => '<option value="' . e($n) . '">', $tags)) . '</datalist>';
$opts = fn(array $map, ?string $sel, string $blank = '') => ($blank !== '' ? '<option value="">' . e($blank) . '</option>' : '')
    . implode('', array_map(fn($k, $v) => '<option value="' . e($k) . '"' . ((string) $sel === (string) $k ? ' selected' : '') . '>' . e($v) . '</option>', array_keys($map), $map));
$hid = fn(array $f) => '<input type="hidden" name="csrf" value="' . e($csrf) . '">' . implode('', array_map(fn($k, $v) => '<input type="hidden" name="' . e($k) . '" value="' . e($v) . '">', array_keys($f), $f));

if ($view === 'tasks') {
    $q = '/rest/v1/client_tasks?select=*,packages(name),client:profiles!client_tasks_client_id_fkey(full_name)&order=updated_at.desc&limit=300'
       . ($fClient ? '&client_id=eq.' . urlencode($fClient) : '') . ($fStatus ? '&status=eq.' . $fStatus : '');
    $tasks = $get($q);
    $counts = array_count_values(array_column($get('/rest/v1/client_tasks?select=status' . ($fClient ? '&client_id=eq.' . urlencode($fClient) : '')), 'status'));
    $miles = $fClient ? milestones(client_tasks($token, $fClient)) : [];
}
if ($view === 'task') {
    $task = $get('/rest/v1/client_tasks?select=*,packages(name),client:profiles!client_tasks_client_id_fkey(full_name)&id=eq.' . urlencode($_GET['task']))[0] ?? null;
    $log  = $task ? $get('/rest/v1/client_task_updates?select=*,author:profiles!client_task_updates_author_id_fkey(full_name)&task_id=eq.' . urlencode($task['id']) . '&order=created_at.desc') : [];
    $taskReports = [];
}
if ($view === 'reports') {
    $reports = $get('/rest/v1/client_reports?select=*,packages(name),client:profiles!client_reports_client_id_fkey(full_name)&order=created_at.desc&limit=200' . ($fClient ? '&client_id=eq.' . urlencode($fClient) : ''));
}
if ($view === 'report') {
    $report = $get('/rest/v1/client_reports?select=*,packages(name),client:profiles!client_reports_client_id_fkey(full_name)&id=eq.' . urlencode($_GET['report']))[0] ?? null;
    if ($report) {
        $data = $report['status'] === 'draft' || !$report['snapshot'] ? report_compile($token, $report) : $report['snapshot'];
        $versions = $get('/rest/v1/client_reports?select=id,version,status&client_id=eq.' . urlencode($report['client_id']) . '&report_type=eq.' . $report['report_type'] . '&period_start=eq.' . $report['period_start'] . '&period_end=eq.' . $report['period_end'] . '&order=version.asc');
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Client work // KAM Team</title>
  <link rel="icon" type="image/png" href="/assets/img/kam-team-mark.png">
  <link rel="stylesheet" href="/assets/styles.css?v=20261121c">
</head>
<body>
<header class="topbar">
  <div class="topbar-inner">
    <?= kam_lockup() ?>
    <div style="display:flex;align-items:center;gap:10px">
      <?= notif_bell($token, $profile, $csrf) ?>
      <span class="role-label <?= e($profile['role']) ?>"><?= e(role_name($profile['role'])) ?></span>
      <form method="post" action="/logout.php" style="margin:0"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><button class="btn btn-ghost btn-sm" type="submit">Logout</button></form>
    </div>
  </div>
</header>

<div class="shell">
<?= app_sidebar(array_merge($isAdmin ? [['label' => 'Admin dashboard', 'href' => '/dashboard-admin.php', 'icon' => 'home']] : [], [
    ['label' => 'Task tracker',    'href' => '/work.php',            'icon' => 'check'],
    ['label' => 'Client reports',  'href' => '/work.php?view=reports', 'icon' => 'log'],
    ['label' => 'Program docs →',  'href' => '/report.php?doc=components', 'icon' => 'brief'],
]),'Client work', in_array($view, ['reports', 'report'], true) ? '/work.php?view=reports' : '/work.php') ?>
<main class="wrap">
  <span class="label"><span class="dot"></span><?= SOP_ID ?> v<?= SOP_VERSION ?> // Client work documentation &amp; reporting</span>
  <h1>Client work. <span class="accent">Traceable, verified, reported.</span></h1>
  <p class="hint" style="margin:-6px 0 14px">Every task is traceable to a scope, assigned to an owner, supported by evidence, verified before it is Completed, and included in the right client report. Names in the role fields are tags only.</p>

  <?php if ($notice): ?><div class="alert alert-<?= e($notice['type']) ?>"><?= e($notice['message']) ?></div><?php endif; ?>
  <?php if ($errors): ?><div class="alert alert-error"><?php foreach ($errors as $err): ?><div>> <?= e($err) ?></div><?php endforeach; ?></div><?php endif; ?>
  <?= $datalist ?>

<?php if ($view === 'tasks'): ?>
  <section class="card" style="margin-bottom:12px">
    <form method="get" class="inline-form" style="flex-wrap:wrap;gap:8px">
      <select name="client"><?= $opts($clientName, $fClient, 'All clients') ?></select>
      <select name="status"><?= $opts(array_map(fn($x) => $x[0], TASK_STATUSES), $fStatus, 'All statuses') ?></select>
      <button class="btn btn-sm" type="submit">Filter</button>
    </form>
    <div class="status-strip"><?php foreach (TASK_STATUSES as $k => [$lbl]): ?><a href="/work.php?status=<?= $k ?><?= $fClient ? '&client=' . e($fClient) : '' ?>" class="<?= $fStatus === $k ? 'on' : '' ?>"><?= e($lbl) ?> <b><?= (int) ($counts[$k] ?? 0) ?></b></a><?php endforeach; ?></div>
  </section>

  <?php if ($fClient): ?>
  <section class="card" style="margin-bottom:12px"><h2>Milestones — <?= e($clientName[$fClient]) ?></h2>
    <?php if (!$miles): ?><p class="empty">No tasks for this client yet.</p><?php else: ?>
    <div class="table-scroll"><table><thead><tr><th>Package › milestone</th><th>Progress</th><th>Status</th></tr></thead><tbody>
      <?php foreach ($miles as $m): ?><tr><td><?= e($m['name']) ?></td><td><div class="lifecycle-bar" style="max-width:180px"><div class="lifecycle-fill ok" style="width:<?= $m['pct'] ?>%"></div></div><span class="hint"><?= $m['done'] ?> / <?= $m['total'] ?> completed</span></td><td><?= status_pill($m['status']) ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <p class="hint">Milestone status is reconciled from its tasks: Completed only when every live task is verified Completed; Blocked if any task is blocked.</p><?php endif; ?>
  </section>
  <?php endif; ?>

  <section class="card" style="margin-bottom:12px"><h2>Task tracker</h2>
    <?php if (!$tasks): ?><p class="empty">No tasks match.</p><?php else: ?>
    <div class="table-scroll"><table><thead><tr><th>Task</th><th>Client · package</th><th>Assignee / reviewer</th><th>Status</th><th>Target</th><th>Updated</th></tr></thead><tbody>
      <?php foreach ($tasks as $t): $late = $t['target_date'] && $t['target_date'] < date('Y-m-d') && !in_array($t['status'], ['completed', 'cancelled'], true); ?>
        <tr><td><a href="/work.php?task=<?= e($t['id']) ?>"><strong><?= e($t['task_code']) ?></strong> <?= e($t['title']) ?></a><?= $t['milestone'] ? '<br><span class="hint">' . e($t['milestone']) . '</span>' : '' ?></td>
          <td style="font-size:12px"><?= e($t['client']['full_name'] ?? '—') ?><br><span class="hint"><?= e($t['packages']['name'] ?? '—') ?></span></td>
          <td style="font-size:12px"><span class="tag"><?= e($t['assignee'] ?: '—') ?></span> <span class="tag tag-alt"><?= e($t['reviewer'] ?: '—') ?></span></td>
          <td><?= status_pill($t['status']) ?><?= $t['status'] === 'blocked' && $t['blockers'] ? '<br><span class="hint">' . e(mb_substr($t['blockers'], 0, 60)) . '</span>' : '' ?></td>
          <td style="font-size:12px;<?= $late ? 'color:var(--red);font-weight:600' : '' ?>"><?= $t['target_date'] ? date('M j', strtotime($t['target_date'])) : '—' ?></td>
          <td style="font-size:11px"><?= date('M j H:i', strtotime($t['updated_at'])) ?></td></tr>
      <?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
  </section>

  <section class="card"><h2>+ New task <span class="hint">(SOP step 1 · intake)</span></h2>
    <?php if (!$clients): ?><p class="empty">No approved client accounts yet.</p><?php else: ?>
    <form method="post"><?= $hid(['act' => 'create_task']) ?>
      <div class="row"><div class="field"><label>Client</label><select name="client_id" required><?= $opts($clientName, $fClient, 'Select client…') ?></select></div>
        <div class="field"><label>Service package</label><select name="package_id"><?= $opts($pkgName, null, 'Select package…') ?></select></div></div>
      <div class="row"><div class="field"><label>Task title</label><input name="title" required maxlength="200" placeholder="e.g. Configure lead-capture workflow in GHL"></div>
        <div class="field"><label>Scope reference</label><input name="scope_ref" maxlength="300" placeholder="Contract deliverable, brief, ticket or change request"></div></div>
      <div class="row"><div class="field"><label>Milestone</label><input name="milestone" maxlength="120" placeholder="e.g. Phase 1 — CRM setup"></div>
        <div class="field"><label>Target date</label><input name="target_date" type="date"></div></div>
      <div class="row"><div class="field"><label>Assignee <span class="hint">(tag)</span></label><input name="assignee" list="people" maxlength="80"></div>
        <div class="field"><label>Reviewer <span class="hint">(tag)</span></label><input name="reviewer" list="people" maxlength="80"></div></div>
      <button class="btn btn-sm btn-solid" type="submit">Create task →</button>
    </form><?php endif; ?>
  </section>

<?php elseif ($view === 'task'): ?>
  <?php if (!$task): ?><p class="empty">Task not found. <a href="/work.php">Back to tracker</a></p><?php else:
    $missing = task_missing_fields($task); ?>
  <p><a href="/work.php?client=<?= e($task['client_id']) ?>">← <?= e($task['client']['full_name'] ?? 'Client') ?> tasks</a></p>
  <section class="card" style="margin-bottom:12px">
    <div class="edit-head"><h2><?= e($task['task_code']) ?> · <?= e($task['title']) ?></h2><?= status_pill($task['status']) ?></div>
    <p class="hint" style="margin:0 0 10px"><?= e(TASK_STATUSES[$task['status']][1]) ?> · Assigned <?= date('M j, Y', strtotime($task['assigned_on'])) ?><?= $task['completed_on'] ? ' · Completed ' . date('M j, Y', strtotime($task['completed_on'])) : '' ?></p>
    <?php if ($missing && !in_array($task['status'], ['completed', 'cancelled'], true)): ?><div class="alert alert-warn" style="margin:0 0 12px">Before For Review this task still needs: <?= e(implode(', ', $missing)) ?> (SOP §5).</div><?php endif; ?>
    <form method="post"><?= $hid(['act' => 'save_task', 'task_id' => $task['id']]) ?>
      <div class="row"><div class="field"><label>Task title</label><input name="title" required maxlength="200" value="<?= e($task['title']) ?>"></div>
        <div class="field"><label>Service package</label><select name="package_id"><?= $opts($pkgName, $task['package_id'], 'Select package…') ?></select></div></div>
      <div class="row"><div class="field"><label>Scope reference</label><input name="scope_ref" maxlength="300" value="<?= e($task['scope_ref'] ?? '') ?>"></div>
        <div class="field"><label>Milestone</label><input name="milestone" maxlength="120" value="<?= e($task['milestone'] ?? '') ?>"></div></div>
      <div class="row"><div class="field"><label>Assignee <span class="hint">(tag)</span></label><input name="assignee" list="people" maxlength="80" value="<?= e($task['assignee'] ?? '') ?>"></div>
        <div class="field"><label>Reviewer <span class="hint">(tag)</span></label><input name="reviewer" list="people" maxlength="80" value="<?= e($task['reviewer'] ?? '') ?>"></div></div>
      <div class="row"><div class="field"><label>Target date</label><input name="target_date" type="date" value="<?= e($task['target_date'] ?? '') ?>"></div><div class="field"></div></div>
      <div class="field"><label>Work performed <span class="hint">— what changed, where, and how completion was confirmed</span></label><textarea name="work_performed" rows="3" placeholder="e.g. Adjusted the workflow trigger and confirmed the fix with a test form submission."><?= e($task['work_performed'] ?? '') ?></textarea></div>
      <div class="row"><div class="field"><label>Deliverable <span class="hint">(link or reference)</span></label><textarea name="deliverable" rows="2"><?= e($task['deliverable'] ?? '') ?></textarea></div>
        <div class="field"><label>Evidence <span class="hint">(URLs, logs, screenshots — one per line; link access-controlled files)</span></label><textarea name="evidence" rows="2"><?= e($task['evidence'] ?? '') ?></textarea></div></div>
      <div class="field"><label>Blockers</label><textarea name="blockers" rows="2"><?= e($task['blockers'] ?? '') ?></textarea></div>
      <div class="row"><div class="field"><label>Next action</label><input name="next_action" maxlength="300" value="<?= e($task['next_action'] ?? '') ?>"></div>
        <div class="field"><label>Next owner <span class="hint">(tag)</span> / date</label><div style="display:flex;gap:6px"><input name="next_owner" list="people" maxlength="80" value="<?= e($task['next_owner'] ?? '') ?>"><input name="next_date" type="date" value="<?= e($task['next_date'] ?? '') ?>" style="max-width:160px"></div></div></div>
      <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap"><input name="author_tag" list="people" maxlength="80" placeholder="Updated by (tag)" style="max-width:200px"><button class="btn btn-sm btn-solid" type="submit">Save task record →</button></div>
    </form>
  </section>

  <div class="cols">
    <section class="card"><h2>Change status <span class="hint">(steps 4–5)</span></h2>
      <form method="post"><?= $hid(['act' => 'set_status', 'task_id' => $task['id']]) ?>
        <div class="field"><label>New status</label><select name="status" id="st-sel"><?= $opts(array_map(fn($x) => $x[0], TASK_STATUSES), $task['status']) ?></select></div>
        <div class="field st-extra" data-for="completed"><label>Actual completion date</label><input name="completed_on" type="date" value="<?= e($task['completed_on'] ?? date('Y-m-d')) ?>"></div>
        <div class="field st-extra" data-for="blocked"><label>Blocker / dependency</label><textarea name="blockers" rows="2"><?= e($task['blockers'] ?? '') ?></textarea></div>
        <div class="row st-extra" data-for="cancelled"><div class="field"><label>Cancellation reason</label><input name="cancel_reason" maxlength="300"></div><div class="field"><label>Approved by <span class="hint">(tag)</span></label><input name="cancel_approved_by" list="people" maxlength="80"></div></div>
        <div class="field"><label>Note</label><input name="note" maxlength="500" placeholder="Reviewer decision, who authorized a hold, etc."></div>
        <div style="display:flex;gap:8px;flex-wrap:wrap"><input name="author_tag" list="people" maxlength="80" placeholder="By (tag)" style="max-width:180px"><button class="btn btn-sm" type="submit">Update status →</button></div>
        <p class="hint" style="margin-top:8px">Completed requires For Review first, a reviewer, evidence and the completion date. Only Completed tasks appear as accomplishments in client reports.</p>
      </form>
    </section>

    <section class="card"><h2>Add to task log <span class="hint">(steps 2–3)</span></h2>
      <form method="post"><?= $hid(['act' => 'log_entry', 'task_id' => $task['id']]) ?>
        <div class="field"><label>Entry type</label><select name="kind"><?= $opts(TASK_LOG_KINDS, 'update') ?></select></div>
        <div class="field"><label>Entry</label><textarea name="note" rows="3" required placeholder="Factual note. Evidence and blockers are also added to the task record."></textarea></div>
        <div style="display:flex;gap:8px;flex-wrap:wrap"><input name="author_tag" list="people" maxlength="80" placeholder="By (tag)" style="max-width:180px"><button class="btn btn-sm btn-solid" type="submit">Log entry →</button></div>
      </form>
    </section>
  </div>

  <section class="card" style="margin-top:12px"><h2>Task log</h2>
    <?php if (!$log): ?><p class="empty">No entries yet.</p><?php else: ?>
    <div class="task-log"><?php foreach ($log as $l): ?>
      <div class="task-log-item kind-<?= e($l['kind']) ?>">
        <span class="hint"><?= date('M j, Y H:i', strtotime($l['created_at'])) ?> · <?= e(TASK_LOG_KINDS[$l['kind']] ?? ucfirst($l['kind'])) ?><?= $l['status_to'] ? ' · ' . e(task_status_label($l['status_from'] ?? '')) . ' → ' . e(task_status_label($l['status_to'])) : '' ?> · <?= e($l['author_tag'] ?: ($l['author']['full_name'] ?? 'system')) ?></span>
        <div><?= nl2br(e($l['note'])) ?></div>
      </div>
    <?php endforeach; ?></div><?php endif; ?>
  </section>
  <?php endif; ?>

<?php elseif ($view === 'reports'): ?>
  <section class="card" style="margin-bottom:12px"><h2>+ Draft a client report <span class="hint">(step 6 · compiled from verified records)</span></h2>
    <?php if (!$clients): ?><p class="empty">No approved client accounts yet.</p><?php else: ?>
    <form method="post"><?= $hid(['act' => 'create_report']) ?>
      <div class="row"><div class="field"><label>Client</label><select name="client_id" required><?= $opts($clientName, $fClient, 'Select client…') ?></select></div>
        <div class="field"><label>Package / project</label><select name="package_id"><?= $opts($pkgName, null, 'All services') ?></select></div></div>
      <div class="row"><div class="field"><label>Report type</label><select name="report_type"><?= $opts(REPORT_TYPES, 'monthly') ?></select></div>
        <div class="field"><label>Reporting period</label><div style="display:flex;gap:6px"><input name="period_start" type="date" required value="<?= date('Y-m-01') ?>"><input name="period_end" type="date" required value="<?= date('Y-m-t') ?>"></div></div></div>
      <div class="row"><div class="field"><label>KAM TEAM contact / owner</label><input name="owner" list="people" maxlength="80"></div>
        <div class="field"><label>Reporting coordinator <span class="hint">(tag — manages the period, format and draft)</span></label><input name="coordinator" list="people" maxlength="80"></div></div>
      <button class="btn btn-sm btn-solid" type="submit">Create draft →</button>
    </form><?php endif; ?>
  </section>

  <section class="card"><h2>Client reports</h2>
    <form method="get" class="inline-form" style="margin-bottom:10px"><input type="hidden" name="view" value="reports"><select name="client"><?= $opts($clientName, $fClient, 'All clients') ?></select><button class="btn btn-sm" type="submit">Filter</button></form>
    <?php if (!$reports): ?><p class="empty">No reports yet.</p><?php else: ?>
    <div class="table-scroll"><table><thead><tr><th>Client</th><th>Report</th><th>Period</th><th>Version</th><th>Status</th><th>Delivered</th></tr></thead><tbody>
      <?php foreach ($reports as $r): ?>
        <tr><td><?= e($r['client']['full_name'] ?? '—') ?><br><span class="hint"><?= e($r['packages']['name'] ?? 'All services') ?></span></td>
          <td><a href="/work.php?report=<?= e($r['id']) ?>"><?= e(REPORT_TYPES[$r['report_type']]) ?></a></td>
          <td style="font-size:12px"><?= date('M j', strtotime($r['period_start'])) ?> – <?= date('M j, Y', strtotime($r['period_end'])) ?></td>
          <td>v<?= str_pad((string) $r['version'], 2, '0', STR_PAD_LEFT) ?></td>
          <td><span class="pill <?= $r['status'] === 'delivered' ? 'active' : ($r['status'] === 'approved' ? 'completed' : 'pending') ?>"><?= e($r['status']) ?></span></td>
          <td style="font-size:12px"><?= $r['delivered_at'] ? date('M j, Y', strtotime($r['delivered_at'])) . ' · ' . e($r['delivery_channel']) : '—' ?></td></tr>
      <?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
  </section>

<?php elseif ($view === 'report'): ?>
  <?php if (!$report): ?><p class="empty">Report not found. <a href="/work.php?view=reports">Back</a></p><?php else:
    $cName = $report['client']['full_name'] ?? 'Client'; $pName = $report['packages']['name'] ?? '';
    $isDraft = $report['status'] === 'draft'; $qa = (array) $report['qa']; ?>
  <p><a href="/work.php?view=reports&client=<?= e($report['client_id']) ?>">← Reports</a>
    <?php if (count($versions) > 1): ?> · Versions: <?php foreach ($versions as $v): ?><a href="/work.php?report=<?= e($v['id']) ?>" style="<?= $v['id'] === $report['id'] ? 'font-weight:700' : '' ?>">v<?= (int) $v['version'] ?></a> <?php endforeach; endif; ?></p>
  <section class="card" style="margin-bottom:12px">
    <div class="edit-head"><h2><?= e(REPORT_TYPES[$report['report_type']]) ?> — <?= e($cName) ?></h2>
      <span class="pill <?= $report['status'] === 'delivered' ? 'active' : ($report['status'] === 'approved' ? 'completed' : 'pending') ?>"><?= e($report['status']) ?></span></div>
    <p class="hint" style="margin:0">File: <code><?= e(report_filename($report, $cName, $pName)) ?>.pdf</code> · <a href="/report.php?id=<?= e($report['id']) ?>" target="_blank" rel="noopener">Open printable version →</a></p>
  </section>

  <?php if ($isDraft): ?>
  <form method="post" class="card" style="margin-bottom:12px"><?= $hid(['report_id' => $report['id']]) ?>
    <h2>Draft <span class="hint">(coordinator prepares · reviewer verifies · approver authorizes)</span></h2>
    <div class="row"><div class="field"><label>KAM TEAM contact / owner</label><input name="owner" list="people" maxlength="80" value="<?= e($report['owner'] ?? '') ?>"></div>
      <div class="field"><label>Reporting coordinator <span class="hint">(tag)</span></label><input name="coordinator" list="people" maxlength="80" value="<?= e($report['coordinator'] ?? '') ?>"></div></div>
    <div class="field"><label>Executive summary — the most important verified outputs</label><textarea name="summary" rows="3"><?= e($report['summary'] ?? '') ?></textarea></div>
    <div class="field"><label>Performance and results — metric · source platform · date range · limits</label><textarea name="results" rows="3" placeholder="e.g. GSC clicks: 1,240 (Google Search Console, Nov 1–30). Actual, not projected."><?= e($report['results'] ?? '') ?></textarea></div>
    <div class="row"><div class="field"><label>Next-period plan</label><textarea name="next_plan" rows="3"><?= e($report['next_plan'] ?? '') ?></textarea></div>
      <div class="field"><label>Decisions needed from the client</label><textarea name="client_decisions" rows="3"><?= e($report['client_decisions'] ?? '') ?></textarea></div></div>
    <div class="row"><div class="field"><label>Prepared by <span class="hint">(tag)</span></label><input name="prepared_by" list="people" maxlength="80" value="<?= e($report['prepared_by'] ?? '') ?>"></div>
      <div class="field"><label>Verified by <span class="hint">(QA tag)</span></label><input name="verified_by" list="people" maxlength="80" value="<?= e($report['verified_by'] ?? '') ?>"></div></div>
    <div class="row"><div class="field"><label>Approved by <span class="hint">(account manager tag)</span></label><input name="approved_by" list="people" maxlength="80" value="<?= e($report['approved_by'] ?? '') ?>"></div><div class="field"></div></div>
    <fieldset class="biz-fieldset"><legend><span class="label kicker">QA checklist · SOP §12</span>All items must be confirmed before approval.</legend>
      <?php foreach (REPORT_QA as $k => $txt): ?><label class="terms" style="margin-bottom:8px"><input type="checkbox" name="qa[<?= $k ?>]" value="1" <?= !empty($qa[$k]) ? 'checked' : '' ?>><span><?= e($txt) ?></span></label><?php endforeach; ?>
    </fieldset>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <button class="btn btn-sm" type="submit" name="act" value="save_report">Save draft</button>
      <?php if ($isAdmin): ?><button class="btn btn-sm btn-solid" type="submit" name="act" value="approve_report" onclick="return confirm('Approve and freeze this report?')">Approve report →</button>
      <?php else: ?><span class="hint" style="align-self:center">An admin approves the report once the QA checklist is complete.</span><?php endif; ?>
    </div>
    <p class="hint" style="margin-top:8px">If QA fails, fix the task records in the tracker first — the accomplishment tables below are rebuilt from them until approval.</p>
  </form>
  <?php else: ?>
  <div class="cols" style="margin-bottom:12px">
    <?php if ($report['status'] === 'approved' && !$isAdmin): ?>
    <section class="card"><h2>Deliver <span class="hint">(step 8)</span></h2><p class="hint">Approved and frozen. An admin delivers it to the client.</p></section>
    <?php elseif ($report['status'] === 'approved'): ?>
    <form method="post" class="card"><?= $hid(['act' => 'deliver_report', 'report_id' => $report['id']]) ?>
      <h2>Deliver <span class="hint">(step 8)</span></h2>
      <div class="field"><label>Channel</label><input name="delivery_channel" required maxlength="80" placeholder="Client dashboard + email" value="Client dashboard"></div>
      <div class="field"><label>Recipients</label><input name="delivery_recipients" maxlength="300" placeholder="Approved recipient list"></div>
      <button class="btn btn-sm btn-solid" type="submit">Mark delivered &amp; notify client →</button>
    </form>
    <?php else: ?>
    <form method="post" class="card"><?= $hid(['act' => 'report_feedback', 'report_id' => $report['id']]) ?>
      <h2>Archive &amp; follow-up <span class="hint">(step 9)</span></h2>
      <p class="hint">Delivered <?= date('M j, Y H:i', strtotime($report['delivered_at'])) ?> via <?= e($report['delivery_channel']) ?><?= $report['delivery_recipients'] ? ' to ' . e($report['delivery_recipients']) : '' ?>.</p>
      <div class="field"><label>Client feedback / decisions</label><textarea name="client_feedback" rows="3"><?= e($report['client_feedback'] ?? '') ?></textarea></div>
      <button class="btn btn-sm" type="submit">Save feedback</button>
      <p class="hint">Log any client requests as new tasks in the tracker.</p>
    </form>
    <?php endif; ?>
    <form method="post" class="card"><?= $hid(['act' => 'reissue_report', 'report_id' => $report['id']]) ?>
      <h2>Reissue as v<?= (int) $report['version'] + 1 ?></h2>
      <div class="field"><label>Revision note <span class="hint">(attached to the new version)</span></label><textarea name="revision_note" rows="3" required></textarea></div>
      <button class="btn btn-sm" type="submit">Create new version →</button>
    </form>
  </div>
  <?php endif; ?>

  <section class="card report-doc"><?= report_body_html($report, $cName, $pName, $data) ?></section>
  <?php endif; ?>
<?php endif; ?>
</main>
</div>
<script>
(function () {
  var sel = document.getElementById('st-sel');
  if (!sel) return;
  function sync() { document.querySelectorAll('.st-extra').forEach(function (el) { el.style.display = el.dataset.for === sel.value ? '' : 'none'; }); }
  sel.addEventListener('change', sync); sync();
})();
</script>
</body>
</html>
