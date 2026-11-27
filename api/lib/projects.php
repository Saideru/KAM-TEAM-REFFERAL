<?php
// =====================================================================
// KAM Team // Projects & roadmaps
// ---------------------------------------------------------------------
// A project is one approved package for one client. Approving the 50%
// advance deposit opens it with a starting roadmap, hands it to the
// staff member assigned to that client, and tells both sides.
//
//   project   -> milestones (ordered phases) -> tasks (SOP-OPS-001)
//
// The board is the same board for everyone: staff and admins edit it,
// the client watches it and can request work on it. A request is not a
// task until a staff member accepts it.
//
// Permissions (decided here, in PHP, like the rest of the app):
//   admin, staff   see and edit every project
//   client         sees only their own, read-only, and may request work
// =====================================================================

require_once __DIR__ . '/supabase.php';
require_once __DIR__ . '/work.php';

const PROJECT_STATUSES = [
    'planning'  => 'Planning',
    'active'    => 'Active',
    'on_hold'   => 'On hold',
    'completed' => 'Completed',
    'cancelled' => 'Cancelled',
];

// A milestone uses the same seven statuses as a task, so one vocabulary
// runs from the roadmap down to the task record (SOP §6).
const MILESTONE_STATUSES = TASK_STATUSES;

// The weekly plan never runs past this many weeks on its own — beyond
// that, staff add the weeks they need.
const PROJECT_MAX_WEEKS = 16;

// "Nearly done" — the point at which the client is told the roadmap is
// in its final stretch.
const PROJECT_NEARLY_DONE_PCT = 80;

const REQUEST_STATUSES = [
    'open'      => 'Waiting for KAM Team',
    'accepted'  => 'Accepted — on the roadmap',
    'declined'  => 'Not taken on',
    'withdrawn' => 'Withdrawn',
];

// The roadmap a new project starts with. Staff rename, re-date, reorder,
// add and remove these — it is a starting point, not a rule. 'days' is
// the target date, counted from the day the project opens.
const PROJECT_TEMPLATE = [
    ['Kickoff & discovery', 'Onboarding call, access handover, goals and success measures agreed.', 7],
    ['Setup & build',       'The main build: accounts, systems, content or campaigns configured.', 28],
    ['Review & QA',         'Internal verification against the agreed scope before anything goes live.', 42],
    ['Launch / delivery',    'Go-live, handover of deliverables and the walkthrough with the client.', 56],
    ['Optimise & report',   'Monitoring, adjustments and the first full progress report.', 84],
];

// ---------------------------------------------------------------------
// Reading
// ---------------------------------------------------------------------

function project_get(string $token, string $id): ?array
{
    $res = supabase_request('GET', '/rest/v1/client_projects?select=*,packages(name,price,monthly_price),'
        . 'client:profiles!client_projects_client_id_fkey(id,full_name,company,username),'
        . 'lead:profiles!client_projects_lead_staff_id_fkey(id,full_name)'
        . '&id=eq.' . urlencode($id) . '&limit=1', null, $token);
    return (!$res['error'] && !empty($res['body'][0])) ? $res['body'][0] : null;
}

/** Every project, or one client's. Newest first. */
function projects_list(string $token, ?string $clientId = null, ?string $status = null): array
{
    $res = supabase_request('GET', '/rest/v1/client_projects?select=*,packages(name),'
        . 'client:profiles!client_projects_client_id_fkey(id,full_name,company),'
        . 'lead:profiles!client_projects_lead_staff_id_fkey(id,full_name)'
        . ($clientId ? '&client_id=eq.' . urlencode($clientId) : '')
        . ($status ? '&status=eq.' . urlencode($status) : '')
        . '&order=created_at.desc&limit=200', null, $token);
    return (!$res['error'] && is_array($res['body'])) ? $res['body'] : [];
}

function project_milestone_rows(string $token, string $projectId): array
{
    $res = supabase_request('GET', '/rest/v1/project_milestones?select=*&project_id=eq.' . urlencode($projectId)
        . '&order=position.asc,created_at.asc', null, $token);
    return (!$res['error'] && is_array($res['body'])) ? $res['body'] : [];
}

function project_task_rows(string $token, string $projectId): array
{
    $res = supabase_request('GET', '/rest/v1/client_tasks?select=id,task_code,title,status,assignee,target_date,'
        . 'completed_on,milestone_id,source,next_action,next_date,blockers,updated_at'
        . '&project_id=eq.' . urlencode($projectId) . '&order=created_at.asc', null, $token);
    return (!$res['error'] && is_array($res['body'])) ? $res['body'] : [];
}

/** Client requests on a project, or everything a client has asked for. */
function project_request_rows(string $token, ?string $projectId = null, ?string $clientId = null): array
{
    $res = supabase_request('GET', '/rest/v1/project_requests?select=*,'
        . 'client:profiles!project_requests_client_id_fkey(full_name),'
        . 'project:client_projects!project_requests_project_id_fkey(name,client_id)'
        . ($projectId ? '&project_id=eq.' . urlencode($projectId) : '')
        . ($clientId ? '&client_id=eq.' . urlencode($clientId) : '')
        . '&order=created_at.desc&limit=200', null, $token);
    return (!$res['error'] && is_array($res['body'])) ? $res['body'] : [];
}

// Completed / live tasks and a percentage. Cancelled tasks do not count.
function project_progress(array $tasks): array
{
    $live = array_values(array_filter($tasks, fn($t) => $t['status'] !== 'cancelled'));
    $done = count(array_filter($live, fn($t) => $t['status'] === 'completed'));
    return ['done' => $done, 'total' => count($live),
            'pct'  => $live ? (int) round($done / count($live) * 100) : 0];
}

function project_tasks_of(array $tasks, string $milestoneId): array
{
    return array_values(array_filter($tasks, fn($t) => ($t['milestone_id'] ?? null) === $milestoneId));
}

// Tasks on the project that nobody filed under a milestone yet.
function project_loose_tasks(array $tasks): array
{
    return array_values(array_filter($tasks, fn($t) => empty($t['milestone_id'])));
}

// ---------------------------------------------------------------------
// Writing
// ---------------------------------------------------------------------

/**
 * Opens a project with its starting roadmap.
 * @return array{0: ?string, 1: ?array}  [error, project]
 */
function project_create(string $token, array $in, ?string $actorId = null, bool $seed = true): array
{
    $row = [
        'client_id'   => $in['client_id'] ?? null,
        'package_id'  => $in['package_id'] ?? null,
        'name'        => mb_substr(trim((string) ($in['name'] ?? '')), 0, 200),
        'summary'     => ($s = trim((string) ($in['summary'] ?? ''))) !== '' ? mb_substr($s, 0, 2000) : null,
        'status'      => isset(PROJECT_STATUSES[$in['status'] ?? '']) ? $in['status'] : 'planning',
        'start_date'  => $in['start_date'] ?? date('Y-m-d'),
        'target_date' => $in['target_date'] ?? null,
        'lead_staff_id' => $in['lead_staff_id'] ?? null,
        'created_by'  => $actorId,
    ];
    if (!empty($in['deposit_txn_id'])) $row['deposit_txn_id'] = $in['deposit_txn_id'];
    if (!empty($in['contract_id']))    $row['contract_id']    = $in['contract_id'];
    if (!$row['client_id']) return ['Choose the client.', null];
    if ($row['name'] === '')  return ['Give the project a name.', null];

    $res = supabase_request('POST', '/rest/v1/client_projects', $row, $token, ['Prefer: return=representation']);
    // 409: this deposit already opened a project (the unique index).
    if ($res['status'] === 409 && !empty($in['deposit_txn_id'])) return [null, null];
    if ($res['error'] || empty($res['body'][0])) return [db_error_message($res['error']) ?: 'Could not create the project.', null];

    $project = $res['body'][0];
    if ($seed) project_seed_roadmap($token, $project);
    return [null, $project];
}

// Writes the starting roadmap: the milestones, and a task for every week
// of the plan filed under the milestone that week falls in. Only ever
// called on a project that has none.
function project_seed_roadmap(string $token, array $project): void
{
    $start = strtotime($project['start_date'] ?? 'today') ?: time();
    $rows  = [];
    foreach (PROJECT_TEMPLATE as $i => [$title, $detail, $days]) {
        $rows[] = [
            'project_id'  => $project['id'],
            'title'       => $title,
            'detail'      => $detail,
            'position'    => ($i + 1) * 10,
            'start_date'  => date('Y-m-d', $start + ($i === 0 ? 0 : PROJECT_TEMPLATE[$i - 1][2] * 86400)),
            'target_date' => date('Y-m-d', $start + $days * 86400),
        ];
    }
    $res = supabase_request('POST', '/rest/v1/project_milestones', $rows, $token, ['Prefer: return=representation']);
    $made = (!$res['error'] && is_array($res['body'])) ? $res['body'] : [];
    if ($made) project_seed_weekly_tasks($token, $project, $made);
}

/**
 * One task per week of the plan, under the milestone that week belongs
 * to: the standing weekly cadence (execute, log evidence, update the
 * client). Staff rename, re-date or delete them freely.
 */
function project_seed_weekly_tasks(string $token, array $project, array $milestones): void
{
    usort($milestones, fn($a, $b) => (int) $a['position'] <=> (int) $b['position']);
    $start = strtotime($project['start_date'] ?? 'today') ?: time();
    $last  = 0;
    foreach ($milestones as $m) {
        $t = $m['target_date'] ? strtotime($m['target_date']) : 0;
        if ($t > $last) $last = $t;
    }
    if (!$last) return;

    $weeks = (int) min(PROJECT_MAX_WEEKS, max(1, ceil(($last - $start) / (7 * 86400))));
    $rows  = [];
    for ($w = 1; $w <= $weeks; $w++) {
        $due = $start + $w * 7 * 86400;
        // The first milestone whose target has not passed by that week.
        $ms  = null;
        foreach ($milestones as $m) {
            if (!$m['target_date'] || strtotime($m['target_date']) >= $due) { $ms = $m; break; }
        }
        $ms = $ms ?: end($milestones);
        $rows[] = [
            'client_id'    => $project['client_id'],
            'project_id'   => $project['id'],
            'milestone_id' => $ms['id'],
            'package_id'   => $project['package_id'] ?? null,
            'milestone'    => $ms['title'],
            'week_no'      => $w,
            'title'        => 'Week ' . $w . ' — ' . $ms['title'],
            'scope_ref'    => 'Weekly plan, week ' . $w . ' of ' . $weeks,
            'next_action'  => 'Log the week\'s work and evidence, then update the client.',
            'target_date'  => date('Y-m-d', $due),
            'assigned_on'  => date('Y-m-d', max($start, $due - 7 * 86400)),
            'created_by'   => $project['created_by'] ?? null,
            'source'       => 'team',
        ];
    }
    supabase_request('POST', '/rest/v1/client_tasks', $rows, $token, ['Prefer: return=minimal']);
}

/**
 * Tells the client when a milestone or the project is nearly done, and
 * when it is done — once per threshold, never on every task.
 * Call after anything that can change a project's task statuses.
 */
function project_progress_notify(string $token, string $projectId): void
{
    $project = project_get($token, $projectId);
    if (!$project || in_array($project['status'], ['cancelled'], true)) return;

    $tasks = project_task_rows($token, $projectId);
    $name  = $project['name'];

    // Milestones first, then the project as a whole.
    foreach (project_milestone_rows($token, $projectId) as $m) {
        $mt = project_tasks_of($tasks, $m['id']);
        if (!$mt) continue;
        $pct  = project_progress($mt)['pct'];
        $step = project_threshold($pct);
        if ($step <= (int) ($m['notified_pct'] ?? 0)) continue;
        supabase_request('PATCH', '/rest/v1/project_milestones?id=eq.' . urlencode($m['id']),
            ['notified_pct' => $step], $token, ['Prefer: return=minimal']);
        notify($token,
            $step >= 100 ? 'Milestone complete: ' . $m['title'] : 'Nearly done: ' . $m['title'],
            $step >= 100
                ? $name . ' — every task in this phase is verified complete.'
                : $name . ' — this phase is ' . $pct . '% complete. The last tasks are in progress.',
            $project['client_id'], 'all', '/dashboard-client.php#roadmap');
    }

    $pct  = project_progress($tasks)['pct'];
    $step = project_threshold($pct);
    if (!$tasks || $step <= (int) ($project['notified_pct'] ?? 0)) return;
    supabase_request('PATCH', '/rest/v1/client_projects?id=eq.' . urlencode($projectId),
        ['notified_pct' => $step], $token, ['Prefer: return=minimal']);
    notify($token,
        $step >= 100 ? 'Your project is complete: ' . $name : 'Your project is nearly done: ' . $name,
        $step >= 100
            ? 'Every task on the roadmap is verified complete. Your final report follows.'
            : $name . ' is ' . $pct . '% complete — the roadmap is in its final stretch.',
        $project['client_id'], 'all', '/dashboard-client.php#roadmap');
}

// The announcement steps: half way, nearly done, done.
function project_threshold(int $pct): int
{
    if ($pct >= 100) return 100;
    if ($pct >= PROJECT_NEARLY_DONE_PCT) return PROJECT_NEARLY_DONE_PCT;
    if ($pct >= 50) return 50;
    return 0;
}

/**
 * The hook: an admin approved a client's deposit, so that package becomes
 * a project. Idempotent — the deposit can only ever open one project.
 * Never blocks the approval: a failure here is logged, not returned.
 */
function project_open_for_deposit(string $token, array $txn, ?string $adminId = null): ?array
{
    $clientId = $txn['user_id'] ?? null;
    if (!$clientId) return null;

    // Already opened (e.g. a re-run of the approval)?
    $existing = supabase_request('GET', '/rest/v1/client_projects?select=id&deposit_txn_id=eq.' . urlencode($txn['id']) . '&limit=1', null, $token);
    if (!$existing['error'] && !empty($existing['body'][0])) return null;

    // The staff member an admin put on this account leads it. With none
    // assigned, the project waits for an admin to name a lead.
    $lead = supabase_request('GET', '/rest/v1/staff_assignments?select=staff_id&member_id=eq.' . urlencode($clientId) . '&order=created_at.asc&limit=1', null, $token);
    $leadId = (!$lead['error'] && !empty($lead['body'][0])) ? $lead['body'][0]['staff_id'] : null;

    // The signed agreement this package was sold under, so the project
    // points back at the contract that authorised it.
    $contractId = null;
    if (!empty($txn['package_id'])) {
        require_once __DIR__ . '/contract.php';
        $signed = contract_for_package($token, $clientId, (string) $txn['package_id']);
        if ($signed && $signed['status'] === 'approved') $contractId = $signed['id'];
    }

    $pkgName = $txn['packages']['name'] ?? 'Service package';
    [$err, $project] = project_create($token, [
        'contract_id'    => $contractId,
        'client_id'      => $clientId,
        'package_id'     => $txn['package_id'] ?? null,
        'deposit_txn_id' => $txn['id'],
        'name'           => $pkgName,
        'summary'        => 'Opened automatically when the 50% advance deposit for ' . $pkgName . ' was approved.',
        'status'         => 'planning',
        'lead_staff_id'  => $leadId,
    ], $adminId);

    if ($err || !$project) return null;

    audit_log($token, 'project_opened', 'client_project', $project['id'],
        'Project "' . $project['name'] . '" opened on deposit approval with a ' . count(PROJECT_TEMPLATE) . '-milestone roadmap');

    // Tell the people who have to act on it.
    if ($leadId) {
        notify($token, 'New project to plan: ' . $project['name'],
            'A deposit was approved for your client. Build the roadmap — set the milestones, dates and first tasks.',
            $leadId, 'all', '/work.php?project=' . $project['id']);
    } else {
        notify($token, 'Project opened with no staff lead: ' . $project['name'],
            'Assign a staff member to this client, then name them project lead.', null, 'admin', '/work.php?project=' . $project['id']);
    }
    notify($token, 'Your project has started: ' . $project['name'],
        'Your roadmap is in your dashboard — follow each milestone as it moves, and request work on it any time.',
        $clientId, 'all', '/dashboard-client.php#roadmap');

    return $project;
}

// ---------------------------------------------------------------------
// POST handlers
// ---------------------------------------------------------------------

/**
 * Staff / admin actions on a project board. Call after csrf_check().
 * Returns null when the post was not a project action, else
 * [error, success] — and sets $GLOBALS['project_redirect'] when the
 * caller should go somewhere specific afterwards.
 */
function project_team_action(string $token, array $profile): ?array
{
    $act = (string) ($_POST['project_action'] ?? '');
    if ($act === '') return null;
    if (!in_array($profile['role'] ?? '', ['admin', 'staff'], true)) return ['Only KAM Team can change a roadmap.', null];

    $me  = $profile['id'];
    $s   = fn(string $k, int $max = 2000) => mb_substr(trim((string) ($_POST[$k] ?? '')), 0, $max);
    $d   = fn(string $k) => ($v = trim((string) ($_POST[$k] ?? ''))) !== '' && strtotime($v) ? date('Y-m-d', strtotime($v)) : null;
    $pid = $s('project_id', 64);

    $project = $pid ? project_get($token, $pid) : null;
    if ($act !== 'project_new' && !$project) return ['Project not found.', null];
    if ($project) $GLOBALS['project_redirect'] = '/work.php?project=' . $project['id'];

    // ---- a project by hand (no deposit behind it) ----
    if ($act === 'project_new') {
        [$err, $new] = project_create($token, [
            'client_id'  => $s('client_id', 64) ?: null,
            'package_id' => $s('package_id', 64) ?: null,
            'name'       => $s('name', 200),
            'summary'    => $s('summary'),
            'start_date' => $d('start_date') ?: date('Y-m-d'),
            'target_date' => $d('target_date'),
            'lead_staff_id' => $s('lead_staff_id', 64) ?: null,
        ], $me, ($_POST['seed'] ?? '1') === '1');
        if ($err) return [$err, null];
        audit_log($token, 'project_opened', 'client_project', $new['id'], 'Project "' . $new['name'] . '" created by hand');
        $GLOBALS['project_redirect'] = '/work.php?project=' . $new['id'];
        return [null, 'Project created.'];
    }

    // ---- project header ----
    if ($act === 'project_save') {
        $patch = [
            'name'        => $s('name', 200),
            'summary'     => $s('summary') ?: null,
            'status'      => isset(PROJECT_STATUSES[$_POST['status'] ?? '']) ? $_POST['status'] : $project['status'],
            'start_date'  => $d('start_date') ?: $project['start_date'],
            'target_date' => $d('target_date'),
            'lead_staff_id' => $s('lead_staff_id', 64) ?: null,
        ];
        if ($patch['name'] === '') return ['Give the project a name.', null];
        $res = supabase_request('PATCH', '/rest/v1/client_projects?id=eq.' . urlencode($project['id']), $patch, $token, ['Prefer: return=minimal']);
        if ($res['error']) return [db_error_message($res['error']), null];
        audit_log($token, 'project_updated', 'client_project', $project['id'], $patch['name'] . ' — ' . PROJECT_STATUSES[$patch['status']]);
        // A lead who just got the project should hear about it.
        if ($patch['lead_staff_id'] && $patch['lead_staff_id'] !== ($project['lead_staff_id'] ?? null)) {
            notify($token, 'You are now leading ' . $patch['name'],
                'Build and keep the roadmap for ' . ($project['client']['full_name'] ?? 'this client') . '.',
                $patch['lead_staff_id'], 'all', '/work.php?project=' . $project['id']);
        }
        if ($patch['status'] !== $project['status']) {
            notify($token, 'Project ' . strtolower(PROJECT_STATUSES[$patch['status']]) . ': ' . $patch['name'],
                'Your project status changed to ' . PROJECT_STATUSES[$patch['status']] . '.',
                $project['client_id'], 'all', '/dashboard-client.php#roadmap');
        }
        return [null, 'Project saved.'];
    }

    // ---- milestones ----
    if ($act === 'milestone_add') {
        $title = $s('title', 200);
        if ($title === '') return ['Name the milestone.', null];
        $rows = project_milestone_rows($token, $project['id']);
        $pos  = $rows ? max(array_column($rows, 'position')) + 10 : 10;
        $res  = supabase_request('POST', '/rest/v1/project_milestones', [
            'project_id' => $project['id'], 'title' => $title, 'detail' => $s('detail') ?: null,
            'position' => $pos, 'start_date' => $d('start_date'), 'target_date' => $d('target_date'),
            'owner' => $s('owner', 80) ?: null,
        ], $token, ['Prefer: return=minimal']);
        if ($res['error']) return [db_error_message($res['error']), null];
        audit_log($token, 'milestone_added', 'client_project', $project['id'], 'Milestone "' . $title . '" added to ' . $project['name']);
        return [null, 'Milestone added.'];
    }

    $mid = $s('milestone_id', 64);
    if (in_array($act, ['milestone_save', 'milestone_move', 'milestone_delete', 'milestone_status'], true)) {
        if ($mid === '') return ['Milestone not found.', null];
        $cur = supabase_request('GET', '/rest/v1/project_milestones?select=*&id=eq.' . urlencode($mid) . '&project_id=eq.' . urlencode($project['id']) . '&limit=1', null, $token)['body'][0] ?? null;
        if (!$cur) return ['Milestone not found.', null];

        if ($act === 'milestone_delete') {
            // Its tasks stay on the project; they just lose the milestone.
            supabase_request('PATCH', '/rest/v1/client_tasks?milestone_id=eq.' . urlencode($mid), ['milestone_id' => null], $token, ['Prefer: return=minimal']);
            $res = supabase_request('DELETE', '/rest/v1/project_milestones?id=eq.' . urlencode($mid), null, $token, ['Prefer: return=minimal']);
            if ($res['error']) return [db_error_message($res['error']), null];
            audit_log($token, 'milestone_removed', 'client_project', $project['id'], 'Milestone "' . $cur['title'] . '" removed from ' . $project['name']);
            return [null, 'Milestone removed. Its tasks are still on the project.'];
        }

        if ($act === 'milestone_move') {
            $rows = project_milestone_rows($token, $project['id']);
            $idx  = array_search($mid, array_column($rows, 'id'), true);
            $swap = ($_POST['dir'] ?? 'up') === 'up' ? $idx - 1 : $idx + 1;
            if ($idx === false || !isset($rows[$swap])) return [null, null];   // already at the end
            supabase_request('PATCH', '/rest/v1/project_milestones?id=eq.' . urlencode($rows[$idx]['id']), ['position' => (int) $rows[$swap]['position']], $token, ['Prefer: return=minimal']);
            supabase_request('PATCH', '/rest/v1/project_milestones?id=eq.' . urlencode($rows[$swap]['id']), ['position' => (int) $rows[$idx]['position']], $token, ['Prefer: return=minimal']);
            return [null, null];
        }

        if ($act === 'milestone_status') {
            $to = (string) ($_POST['status'] ?? '');
            if (!isset(MILESTONE_STATUSES[$to])) return ['Unknown status.', null];
            $patch = ['status' => $to, 'completed_on' => $to === 'completed' ? ($d('completed_on') ?: date('Y-m-d')) : null];
            $res = supabase_request('PATCH', '/rest/v1/project_milestones?id=eq.' . urlencode($mid), $patch, $token, ['Prefer: return=minimal']);
            if ($res['error']) return [db_error_message($res['error']), null];
            audit_log($token, 'milestone_status', 'client_project', $project['id'],
                $project['name'] . ' — "' . $cur['title'] . '" ' . task_status_label($cur['status']) . ' → ' . task_status_label($to));
            notify($token, 'Milestone ' . strtolower(task_status_label($to)) . ': ' . $cur['title'],
                $project['name'] . ' — your roadmap moved.', $project['client_id'], 'all', '/dashboard-client.php#roadmap');
            return [null, 'Milestone is now ' . task_status_label($to) . '.'];
        }

        // milestone_save
        $patch = [
            'title'  => $s('title', 200), 'detail' => $s('detail') ?: null,
            'start_date' => $d('start_date'), 'target_date' => $d('target_date'), 'owner' => $s('owner', 80) ?: null,
        ];
        if ($patch['title'] === '') return ['Name the milestone.', null];
        $res = supabase_request('PATCH', '/rest/v1/project_milestones?id=eq.' . urlencode($mid), $patch, $token, ['Prefer: return=minimal']);
        if ($res['error']) return [db_error_message($res['error']), null];
        // Keep the task record's free-text milestone in step, so reports
        // built from client_tasks keep naming the phase correctly.
        if ($patch['title'] !== $cur['title']) {
            supabase_request('PATCH', '/rest/v1/client_tasks?milestone_id=eq.' . urlencode($mid), ['milestone' => $patch['title']], $token, ['Prefer: return=minimal']);
        }
        return [null, 'Milestone saved.'];
    }

    // ---- a task straight onto a milestone ----
    if ($act === 'task_add') {
        $title = $s('title', 200);
        if ($title === '') return ['Give the task a title.', null];
        $mid = $s('milestone_id', 64) ?: null;
        $ms  = $mid ? (supabase_request('GET', '/rest/v1/project_milestones?select=title&id=eq.' . urlencode($mid) . '&limit=1', null, $token)['body'][0] ?? null) : null;
        $res = supabase_request('POST', '/rest/v1/client_tasks', [
            'client_id' => $project['client_id'], 'project_id' => $project['id'], 'milestone_id' => $mid,
            'package_id' => $project['package_id'], 'milestone' => $ms['title'] ?? null,
            'title' => $title, 'assignee' => $s('assignee', 80) ?: null, 'target_date' => $d('target_date'),
            'assigned_on' => date('Y-m-d'), 'created_by' => $me, 'source' => ($_POST['source'] ?? 'team') === 'client' ? 'client' : 'team',
        ], $token, ['Prefer: return=representation']);
        if ($res['error'] || empty($res['body'][0])) return [db_error_message($res['error']) ?: 'Could not add the task.', null];
        task_log($token, $res['body'][0], 'update', 'Task created on the roadmap under "' . ($ms['title'] ?? 'the project') . '".');
        return [null, $res['body'][0]['task_code'] . ' added.'];
    }

    // ---- a client's request: accept (becomes a task) or decline ----
    if ($act === 'request_decide') {
        $rid = $s('request_id', 64);
        $req = $rid ? (supabase_request('GET', '/rest/v1/project_requests?select=*&id=eq.' . urlencode($rid) . '&limit=1', null, $token)['body'][0] ?? null) : null;
        if (!$req || $req['status'] !== 'open') return ['That request was already answered.', null];
        $accept = ($_POST['decision'] ?? '') === 'accept';
        $note   = $s('decision_note', 500);
        $taskId = null;

        if ($accept) {
            $mid = $s('milestone_id', 64) ?: ($req['milestone_id'] ?? null);
            $ms  = $mid ? (supabase_request('GET', '/rest/v1/project_milestones?select=title&id=eq.' . urlencode($mid) . '&limit=1', null, $token)['body'][0] ?? null) : null;
            $res = supabase_request('POST', '/rest/v1/client_tasks', [
                'client_id' => $project['client_id'], 'project_id' => $project['id'], 'milestone_id' => $mid,
                'package_id' => $project['package_id'], 'milestone' => $ms['title'] ?? null,
                'title' => $req['title'], 'scope_ref' => 'Client request ' . date('Y-m-d', strtotime($req['created_at'])),
                'work_performed' => null, 'assignee' => $s('assignee', 80) ?: null, 'target_date' => $d('target_date'),
                'assigned_on' => date('Y-m-d'), 'created_by' => $me, 'source' => 'client',
            ], $token, ['Prefer: return=representation']);
            if ($res['error'] || empty($res['body'][0])) return [db_error_message($res['error']) ?: 'Could not turn that request into a task.', null];
            $taskId = $res['body'][0]['id'];
            task_log($token, $res['body'][0], 'update', 'Created from the client\'s request: ' . mb_substr((string) $req['detail'], 0, 500));
        } elseif ($note === '') {
            return ['Tell the client why it is not being taken on.', null];
        }

        supabase_request('PATCH', '/rest/v1/project_requests?id=eq.' . urlencode($rid), [
            'status' => $accept ? 'accepted' : 'declined', 'decision_note' => $note ?: null,
            'decided_by' => $me, 'decided_at' => date('c'), 'task_id' => $taskId,
        ], $token, ['Prefer: return=minimal']);
        audit_log($token, 'request_' . ($accept ? 'accepted' : 'declined'), 'client_project', $project['id'],
            'Client request "' . $req['title'] . '" ' . ($accept ? 'accepted onto' : 'declined for') . ' ' . $project['name']);
        notify($token, ($accept ? 'Request accepted: ' : 'Request answered: ') . $req['title'],
            $accept ? 'It is on your roadmap now.' : ($note ?: 'See your project for details.'),
            $req['client_id'], 'all', '/dashboard-client.php#roadmap');
        return [null, $accept ? 'Request accepted and added to the roadmap.' : 'Request declined.'];
    }

    return ['Unknown action.', null];
}

/**
 * The client's own actions: ask for work, or withdraw the ask.
 * Returns null when the post was not one of them, else [error, success].
 */
function project_client_action(string $token, array $profile): ?array
{
    $act = (string) ($_POST['roadmap_action'] ?? '');
    if ($act === '') return null;

    $s   = fn(string $k, int $max = 2000) => mb_substr(trim((string) ($_POST[$k] ?? '')), 0, $max);
    $pid = $s('project_id', 64);
    $project = $pid ? project_get($token, $pid) : null;
    if (!$project || $project['client_id'] !== $profile['id']) return ['That project is not yours.', null];

    if ($act === 'request_add') {
        $title = $s('title', 200);
        if ($title === '') return ['Say what you would like done.', null];
        $mid = $s('milestone_id', 64) ?: null;
        if ($mid) {
            $own = supabase_request('GET', '/rest/v1/project_milestones?select=id&id=eq.' . urlencode($mid)
                . '&project_id=eq.' . urlencode($project['id']) . '&limit=1', null, $token);
            if ($own['error'] || empty($own['body'])) $mid = null;
        }
        $res = supabase_request('POST', '/rest/v1/project_requests', [
            'project_id' => $project['id'], 'milestone_id' => $mid, 'client_id' => $profile['id'],
            'title' => $title, 'detail' => $s('detail') ?: null,
        ], $token, ['Prefer: return=minimal']);
        if ($res['error']) return [db_error_message($res['error']) ?: 'Could not send that request.', null];

        audit_log($token, 'request_opened', 'client_project', $project['id'], 'Client asked for "' . $title . '" on ' . $project['name']);
        $to = $project['lead_staff_id'] ?? null;
        notify($token, 'Client request: ' . $title,
            ($profile['full_name'] ?? 'A client') . ' asked for work on ' . $project['name'] . '.',
            $to, $to ? 'all' : 'admin', '/work.php?project=' . $project['id']);
        return [null, 'Sent. Your KAM Team contact will answer it on the roadmap.'];
    }

    if ($act === 'request_withdraw') {
        $rid = $s('request_id', 64);
        $res = supabase_request('PATCH', '/rest/v1/project_requests?id=eq.' . urlencode($rid)
            . '&client_id=eq.' . urlencode($profile['id']) . '&status=eq.open',
            ['status' => 'withdrawn'], $token, ['Prefer: return=representation']);
        if ($res['error'] || empty($res['body'])) return ['That request can no longer be withdrawn.', null];
        return [null, 'Request withdrawn.'];
    }

    return ['Unknown action.', null];
}

// ---------------------------------------------------------------------
// Rendering — shared pieces
// ---------------------------------------------------------------------

function project_status_pill(string $s): string
{
    $cls = ['active' => 'active', 'completed' => 'completed', 'on_hold' => 'pending', 'cancelled' => 'failed'][$s] ?? 'pending';
    return '<span class="pill ' . $cls . '">' . e(PROJECT_STATUSES[$s] ?? $s) . '</span>';
}

function project_bar(array $p): string
{
    return '<div class="lifecycle-bar" style="max-width:220px"><div class="lifecycle-fill ok" style="width:' . (int) $p['pct'] . '%"></div></div>'
        . '<span class="hint">' . (int) $p['done'] . ' of ' . (int) $p['total'] . ' tasks complete · ' . (int) $p['pct'] . '%</span>';
}

function project_date(?string $d, string $empty = '—'): string
{
    return $d ? date('M j, Y', strtotime($d)) : $empty;
}

// ---------------------------------------------------------------------
// Rendering — staff / admin
// ---------------------------------------------------------------------

/** The list of every project, with its progress. */
function project_list_html(string $token, array $profile, string $csrf, array $clients, array $packages, array $staff): string
{
    $rows     = projects_list($token);
    $opts     = function (array $map, string $blank): string {
        $out = '<option value="">' . e($blank) . '</option>';
        foreach ($map as $k => $v) $out .= '<option value="' . e($k) . '">' . e($v) . '</option>';
        return $out;
    };

    $out = '<section class="card" style="margin-bottom:12px"><h2>Projects &amp; roadmaps</h2>'
        . '<p class="hint" style="margin:0 0 10px">A project opens by itself when an admin approves a client\'s 50% advance deposit, with a starting roadmap of ' . count(PROJECT_TEMPLATE) . ' milestones. Open one to shape the roadmap, hang tasks off each milestone and answer what the client asks for — they watch the same board.</p>';

    if (!$rows) {
        $out .= '<p class="empty">No projects yet. One opens automatically on the next approved deposit, or start one below.</p>';
    } else {
        $out .= '<div class="table-scroll" tabindex="0" role="region" aria-label="Projects"><table><thead><tr>'
            . '<th>Project</th><th>Client</th><th>Lead</th><th>Progress</th><th>Target</th><th>Status</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $tasks = project_task_rows($token, $r['id']);
            $prog  = project_progress($tasks);
            $open  = count(array_filter(project_request_rows($token, $r['id']), fn($q) => $q['status'] === 'open'));
            $out .= '<tr><td><a href="/work.php?project=' . e($r['id']) . '"><strong>' . e($r['name']) . '</strong></a>'
                . ($open ? ' <span class="chat-badge">' . $open . '</span>' : '')
                . '<br><span class="hint">' . e($r['packages']['name'] ?? 'No package') . '</span></td>'
                . '<td style="font-size:12px">' . e($r['client']['full_name'] ?? '—') . '</td>'
                . '<td style="font-size:12px">' . e($r['lead']['full_name'] ?? 'Not assigned') . '</td>'
                . '<td>' . project_bar($prog) . '</td>'
                . '<td style="font-size:12px">' . e(project_date($r['target_date'])) . '</td>'
                . '<td>' . project_status_pill($r['status']) . '</td></tr>';
        }
        $out .= '</tbody></table></div>';
    }
    $out .= '</section>';

    // Start one by hand (a project with no deposit behind it).
    $out .= '<section class="card"><h2>+ New project</h2>';
    if (!$clients) {
        $out .= '<p class="empty">No approved client accounts yet.</p>';
    } else {
        $out .= '<form method="post"><input type="hidden" name="csrf" value="' . e($csrf) . '">'
            . '<input type="hidden" name="project_action" value="project_new">'
            . '<div class="row"><div class="field"><label>Client</label><select name="client_id" required>' . $opts($clients, 'Select client…') . '</select></div>'
            . '<div class="field"><label>Service package</label><select name="package_id">' . $opts($packages, 'Select package…') . '</select></div></div>'
            . '<div class="row"><div class="field"><label>Project name</label><input name="name" required maxlength="200" placeholder="e.g. SEO Growth Package — Phase 1"></div>'
            . '<div class="field"><label>Project lead <span class="hint">(staff)</span></label><select name="lead_staff_id">' . $opts($staff, 'Not assigned yet') . '</select></div></div>'
            . '<div class="row"><div class="field"><label>Start date</label><input type="date" name="start_date" value="' . date('Y-m-d') . '"></div>'
            . '<div class="field"><label>Target completion</label><input type="date" name="target_date"></div></div>'
            . '<div class="field"><label>Summary <span class="hint">(the client reads this)</span></label><textarea name="summary" rows="2"></textarea></div>'
            . '<label class="terms" style="margin-bottom:10px"><input type="checkbox" name="seed" value="1" checked><span>Start it with the ' . count(PROJECT_TEMPLATE) . '-milestone roadmap</span></label>'
            . '<button class="btn btn-sm btn-solid" type="submit">Create project →</button></form>';
    }
    return $out . '</section>';
}

/** One project: the editable board. */
function project_board_html(string $token, array $profile, string $csrf, array $project, array $staff): string
{
    $mids     = project_milestone_rows($token, $project['id']);
    $tasks    = project_task_rows($token, $project['id']);
    $requests = project_request_rows($token, $project['id']);
    $prog     = project_progress($tasks);
    $hid      = fn(array $f) => '<input type="hidden" name="csrf" value="' . e($csrf) . '">'
        . implode('', array_map(fn($k, $v) => '<input type="hidden" name="' . e($k) . '" value="' . e((string) $v) . '">', array_keys($f), $f));
    $sel      = function (array $map, ?string $cur, string $blank = ''): string {
        $out = $blank !== '' ? '<option value="">' . e($blank) . '</option>' : '';
        foreach ($map as $k => $v) $out .= '<option value="' . e($k) . '"' . ((string) $cur === (string) $k ? ' selected' : '') . '>' . e($v) . '</option>';
        return $out;
    };
    $msMap = [];
    foreach ($mids as $m) $msMap[$m['id']] = $m['title'];

    // ---- header ----
    $out = '<p><a href="/work.php?view=projects">← All projects</a></p>'
        . '<section class="card" style="margin-bottom:12px">'
        . '<div class="edit-head"><h2>' . e($project['name']) . '</h2>' . project_status_pill($project['status']) . '</div>'
        . '<p class="hint" style="margin:0 0 10px">' . e($project['client']['full_name'] ?? 'Client')
        . ($project['client']['company'] ?? '' ? ' · ' . e($project['client']['company']) : '')
        . ' · ' . e($project['packages']['name'] ?? 'No package')
        . ' · ' . e(project_date($project['start_date'])) . ' → ' . e(project_date($project['target_date'], 'no target date'))
        . '</p>' . project_bar($prog)
        . '<details class="biz-self" style="margin-top:10px"><summary class="btn btn-sm btn-ghost">Edit project</summary>'
        . '<form method="post" style="margin-top:10px">' . $hid(['project_action' => 'project_save', 'project_id' => $project['id']])
        . '<div class="row"><div class="field"><label>Project name</label><input name="name" required maxlength="200" value="' . e($project['name']) . '"></div>'
        . '<div class="field"><label>Status</label><select name="status">' . $sel(PROJECT_STATUSES, $project['status']) . '</select></div></div>'
        . '<div class="row"><div class="field"><label>Project lead <span class="hint">(staff)</span></label><select name="lead_staff_id">' . $sel($staff, $project['lead_staff_id'], 'Not assigned yet') . '</select></div>'
        . '<div class="field"><label>Target completion</label><input type="date" name="target_date" value="' . e($project['target_date'] ?? '') . '"></div></div>'
        . '<div class="row"><div class="field"><label>Start date</label><input type="date" name="start_date" value="' . e($project['start_date'] ?? '') . '"></div><div class="field"></div></div>'
        . '<div class="field"><label>Summary <span class="hint">(the client reads this)</span></label><textarea name="summary" rows="2">' . e($project['summary'] ?? '') . '</textarea></div>'
        . '<button class="btn btn-sm btn-solid" type="submit">Save project →</button></form></details>'
        . '</section>';

    // ---- client requests waiting ----
    $open = array_values(array_filter($requests, fn($r) => $r['status'] === 'open'));
    if ($open) {
        $out .= '<section class="card" style="margin-bottom:12px"><h2>Client requests <span class="chat-badge">' . count($open) . '</span></h2>'
            . '<p class="hint" style="margin:0 0 10px">What the client asked for. Accepting one puts it on the roadmap as a task; declining one tells them why.</p>';
        foreach ($open as $r) {
            $out .= '<div class="road-request"><div><strong>' . e($r['title']) . '</strong>'
                . ($r['detail'] ? '<p class="hint" style="margin:2px 0 0">' . nl2br(e($r['detail'])) . '</p>' : '')
                . '<p class="hint" style="margin:2px 0 0">Asked ' . e(project_date($r['created_at']))
                . ($r['milestone_id'] && isset($msMap[$r['milestone_id']]) ? ' · for "' . e($msMap[$r['milestone_id']]) . '"' : '') . '</p></div>'
                . '<form method="post" class="road-decide">' . $hid(['project_action' => 'request_decide', 'project_id' => $project['id'], 'request_id' => $r['id']])
                . '<select name="milestone_id" aria-label="Milestone"><option value="">No milestone</option>' . $sel($msMap, $r['milestone_id']) . '</select>'
                . '<input name="assignee" list="people" maxlength="80" placeholder="Assignee (tag)">'
                . '<input type="date" name="target_date" aria-label="Target date">'
                . '<input name="decision_note" maxlength="500" placeholder="Note to the client (required to decline)">'
                . '<button class="btn btn-sm btn-solid" type="submit" name="decision" value="accept">Accept →</button>'
                . '<button class="btn btn-sm" type="submit" name="decision" value="decline">Decline</button>'
                . '</form></div>';
        }
        $out .= '</section>';
    }

    // ---- the roadmap ----
    $out .= '<section class="card" style="margin-bottom:12px"><h2>Roadmap</h2>';
    if (!$mids) {
        $out .= '<p class="empty">No milestones yet. Add the first phase below.</p>';
    }

    foreach ($mids as $i => $m) {
        $mtasks = project_tasks_of($tasks, $m['id']);
        $mprog  = project_progress($mtasks);
        $late   = $m['target_date'] && $m['target_date'] < date('Y-m-d') && !in_array($m['status'], ['completed', 'cancelled'], true);

        $out .= '<div class="road-step">'
            . '<div class="road-step-head">'
            .   '<span class="road-num">' . str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) . '</span>'
            .   '<div class="road-step-title"><strong>' . e($m['title']) . '</strong>'
            .     '<span class="hint">' . e(project_date($m['start_date'], 'no start')) . ' → '
            .     '<span' . ($late ? ' style="color:var(--red);font-weight:600"' : '') . '>' . e(project_date($m['target_date'], 'no target')) . '</span>'
            .     ($m['owner'] ? ' · ' . e($m['owner']) : '') . '</span></div>'
            .   status_pill($m['status'])
            . '</div>'
            . '<div class="road-step-body">' . ($m['detail'] ? '<p class="hint" style="margin:0 0 8px">' . nl2br(e($m['detail'])) . '</p>' : '')
            . project_bar($mprog);

        if ($mtasks) {
            $out .= '<div class="table-scroll" style="margin-top:8px"><table><thead><tr><th>Task</th><th>Assignee</th><th>Target</th><th>Status</th></tr></thead><tbody>';
            foreach ($mtasks as $t) {
                $out .= '<tr><td><a href="/work.php?task=' . e($t['id']) . '"><strong>' . e($t['task_code']) . '</strong> ' . e($t['title']) . '</a>'
                    . ($t['source'] === 'client' ? ' <span class="pill">client request</span>' : '') . '</td>'
                    . '<td style="font-size:12px">' . e($t['assignee'] ?: '—') . '</td>'
                    . '<td style="font-size:12px">' . e($t['target_date'] ? date('M j', strtotime($t['target_date'])) : '—') . '</td>'
                    . '<td>' . status_pill($t['status']) . '</td></tr>';
            }
            $out .= '</tbody></table></div>';
        } else {
            $out .= '<p class="empty" style="margin:8px 0 0">No tasks on this milestone yet.</p>';
        }

        // quick add + status + edit + move
        $out .= '<form method="post" class="road-add">' . $hid(['project_action' => 'task_add', 'project_id' => $project['id'], 'milestone_id' => $m['id']])
            . '<input name="title" required maxlength="200" placeholder="+ Add a task to this milestone">'
            . '<input name="assignee" list="people" maxlength="80" placeholder="Assignee (tag)">'
            . '<input type="date" name="target_date" aria-label="Target date">'
            . '<button class="btn btn-sm" type="submit">Add</button></form>'

            . '<div class="road-step-tools">'
            . '<form method="post" class="road-inline">' . $hid(['project_action' => 'milestone_status', 'project_id' => $project['id'], 'milestone_id' => $m['id']])
            .   '<select name="status" aria-label="Milestone status">' . $sel(array_map(fn($x) => $x[0], MILESTONE_STATUSES), $m['status']) . '</select>'
            .   '<button class="btn btn-sm" type="submit">Set status</button></form>'
            . '<form method="post" class="road-inline">' . $hid(['project_action' => 'milestone_move', 'project_id' => $project['id'], 'milestone_id' => $m['id'], 'dir' => 'up'])
            .   '<button class="btn btn-sm btn-ghost" type="submit" title="Move up" aria-label="Move up">↑</button></form>'
            . '<form method="post" class="road-inline">' . $hid(['project_action' => 'milestone_move', 'project_id' => $project['id'], 'milestone_id' => $m['id'], 'dir' => 'down'])
            .   '<button class="btn btn-sm btn-ghost" type="submit" title="Move down" aria-label="Move down">↓</button></form>'
            . '<details class="road-edit"><summary class="btn btn-sm btn-ghost">Edit</summary>'
            . '<form method="post" style="margin-top:8px">' . $hid(['project_action' => 'milestone_save', 'project_id' => $project['id'], 'milestone_id' => $m['id']])
            .   '<div class="field"><label>Title</label><input name="title" required maxlength="200" value="' . e($m['title']) . '"></div>'
            .   '<div class="field"><label>What happens in this phase <span class="hint">(the client reads this)</span></label><textarea name="detail" rows="2">' . e($m['detail'] ?? '') . '</textarea></div>'
            .   '<div class="row"><div class="field"><label>Start</label><input type="date" name="start_date" value="' . e($m['start_date'] ?? '') . '"></div>'
            .   '<div class="field"><label>Target</label><input type="date" name="target_date" value="' . e($m['target_date'] ?? '') . '"></div></div>'
            .   '<div class="field"><label>Owner <span class="hint">(tag)</span></label><input name="owner" list="people" maxlength="80" value="' . e($m['owner'] ?? '') . '"></div>'
            .   '<button class="btn btn-sm btn-solid" type="submit">Save milestone →</button></form>'
            . '<form method="post" style="margin-top:8px" onsubmit="return confirm(\'Remove this milestone? Its tasks stay on the project.\')">'
            .   $hid(['project_action' => 'milestone_delete', 'project_id' => $project['id'], 'milestone_id' => $m['id']])
            .   '<button class="btn btn-sm btn-ghost" type="submit">Remove milestone</button></form>'
            . '</details></div></div></div>';
    }

    // loose tasks
    $loose = project_loose_tasks($tasks);
    if ($loose) {
        $out .= '<div class="road-step"><div class="road-step-head"><span class="road-num">··</span>'
            . '<div class="road-step-title"><strong>Not on a milestone</strong><span class="hint">Tasks on this project that are not filed under a phase yet</span></div></div>'
            . '<div class="road-step-body"><div class="table-scroll"><table><thead><tr><th>Task</th><th>Assignee</th><th>Status</th></tr></thead><tbody>';
        foreach ($loose as $t) {
            $out .= '<tr><td><a href="/work.php?task=' . e($t['id']) . '"><strong>' . e($t['task_code']) . '</strong> ' . e($t['title']) . '</a></td>'
                . '<td style="font-size:12px">' . e($t['assignee'] ?: '—') . '</td><td>' . status_pill($t['status']) . '</td></tr>';
        }
        $out .= '</tbody></table></div></div></div>';
    }

    // add a milestone
    $out .= '<details class="road-new"><summary class="btn btn-sm btn-solid">+ Add milestone</summary>'
        . '<form method="post" style="margin-top:10px">' . $hid(['project_action' => 'milestone_add', 'project_id' => $project['id']])
        . '<div class="row"><div class="field"><label>Title</label><input name="title" required maxlength="200" placeholder="e.g. Phase 2 — Content build"></div>'
        . '<div class="field"><label>Owner <span class="hint">(tag)</span></label><input name="owner" list="people" maxlength="80"></div></div>'
        . '<div class="row"><div class="field"><label>Start</label><input type="date" name="start_date"></div>'
        . '<div class="field"><label>Target</label><input type="date" name="target_date"></div></div>'
        . '<div class="field"><label>What happens in this phase <span class="hint">(the client reads this)</span></label><textarea name="detail" rows="2"></textarea></div>'
        . '<button class="btn btn-sm btn-solid" type="submit">Add milestone →</button></form></details>';

    $out .= '</section>';

    // ---- answered requests, for the record ----
    $closed = array_values(array_filter($requests, fn($r) => $r['status'] !== 'open'));
    if ($closed) {
        $out .= '<section class="card"><h2>Answered requests</h2><div class="table-scroll"><table><thead><tr>'
            . '<th>Request</th><th>Asked</th><th>Answer</th><th>Note</th></tr></thead><tbody>';
        foreach ($closed as $r) {
            $out .= '<tr><td style="font-size:12px">' . e($r['title']) . '</td>'
                . '<td style="font-size:12px">' . e(project_date($r['created_at'])) . '</td>'
                . '<td><span class="pill ' . ($r['status'] === 'accepted' ? 'active' : 'failed') . '">' . e(REQUEST_STATUSES[$r['status']] ?? $r['status']) . '</span></td>'
                . '<td style="font-size:12px">' . e($r['decision_note'] ?? '—') . '</td></tr>';
        }
        $out .= '</tbody></table></div></section>';
    }

    return $out;
}

// ---------------------------------------------------------------------
// Rendering — "This week", the same board for all three roles
// ---------------------------------------------------------------------

/**
 * The week's tasks across every project (admin / staff) or one client's
 * (the client's own dashboard): what is due this week, what is overdue,
 * and what is coming next week.
 *
 * @param ?string $clientId  limit to one client (the client's own view)
 * @param ?string $assignee  highlight one person's name tag (staff view)
 */
function project_week_rows(string $token, ?string $clientId = null): array
{
    $from = date('Y-m-d', strtotime('monday this week'));
    $to   = date('Y-m-d', strtotime('sunday this week'));
    $next = date('Y-m-d', strtotime('sunday next week'));

    $res = supabase_request('GET', '/rest/v1/client_tasks?select=id,task_code,title,status,assignee,target_date,'
        . 'completed_on,week_no,source,project_id,milestone_id,client_id,'
        . 'project:client_projects!client_tasks_project_id_fkey(name,client_id),'
        . 'client:profiles!client_tasks_client_id_fkey(full_name)'
        . '&project_id=not.is.null'
        . ($clientId ? '&client_id=eq.' . urlencode($clientId) : '')
        . '&status=not.in.(cancelled)'
        . '&target_date=lte.' . $next
        . '&order=target_date.asc&limit=300', null, $token);
    $rows = (!$res['error'] && is_array($res['body'])) ? $res['body'] : [];

    $out = ['overdue' => [], 'week' => [], 'next' => [], 'done' => []];
    foreach ($rows as $t) {
        $d = (string) ($t['target_date'] ?? '');
        if ($t['status'] === 'completed') {
            // Only this week's completions are worth showing.
            if (($t['completed_on'] ?? '') >= $from) $out['done'][] = $t;
            continue;
        }
        if ($d < $from)      $out['overdue'][] = $t;
        elseif ($d <= $to)   $out['week'][] = $t;
        else                 $out['next'][] = $t;
    }
    return $out;
}

/**
 * The week board. $editable adds the task links staff and admins use;
 * a client gets the same four columns, read-only.
 */
function project_week_html(array $groups, bool $editable, bool $showClient = true): string
{
    $block = function (string $title, string $note, array $tasks, string $tone) use ($editable, $showClient): string {
        $h = '<div class="week-col week-' . e($tone) . '"><div class="week-col-head"><strong>' . e($title) . '</strong>'
            . '<span class="chat-badge">' . count($tasks) . '</span></div>'
            . '<p class="hint" style="margin:0 0 8px">' . e($note) . '</p>';
        if (!$tasks) {
            $h .= '<p class="empty" style="margin:0">Nothing here.</p>';
        } else {
            $h .= '<ul class="week-list">';
            foreach ($tasks as $t) {
                $label = (!empty($t['week_no']) ? 'W' . (int) $t['week_no'] . ' · ' : '') . ($t['title'] ?? '');
                $h .= '<li>' . ($editable
                        ? '<a href="/work.php?task=' . e($t['id']) . '">' . e($label) . '</a>'
                        : '<span>' . e($label) . '</span>')
                    . '<span class="hint">'
                    . ($showClient ? e($t['client']['full_name'] ?? '') . ' · ' : '')
                    . e($t['project']['name'] ?? '')
                    . (!empty($t['assignee']) ? ' · ' . e($t['assignee']) : '')
                    . (!empty($t['target_date']) ? ' · ' . e(date('D M j', strtotime($t['target_date']))) : '')
                    . '</span>' . status_pill((string) ($t['status'] ?? 'not_started')) . '</li>';
            }
            $h .= '</ul>';
        }
        return $h . '</div>';
    };

    return '<div class="week-board">'
        . $block('Overdue', 'Past its target date and not complete.', $groups['overdue'], 'late')
        . $block('This week', 'Due between Monday and Sunday.', $groups['week'], 'now')
        . $block('Next week', 'Coming up, so nothing arrives as a surprise.', $groups['next'], 'soon')
        . $block('Completed this week', 'Verified complete since Monday.', $groups['done'], 'done')
        . '</div>';
}

// ---------------------------------------------------------------------
// Rendering — the client's own roadmap
// ---------------------------------------------------------------------

/** Everything this client is allowed to see of their projects, plus the ask-for-work form. */
function project_client_html(string $token, array $profile, string $csrf): string
{
    $projects = projects_list($token, $profile['id']);
    if (!$projects) {
        return '<p class="empty">No project yet. One opens as soon as an admin confirms the 50% advance deposit on a service package — you will see every milestone here as it moves.</p>';
    }

    $requests = project_request_rows($token, null, $profile['id']);
    $hid = fn(array $f) => '<input type="hidden" name="csrf" value="' . e($csrf) . '">'
        . implode('', array_map(fn($k, $v) => '<input type="hidden" name="' . e($k) . '" value="' . e((string) $v) . '">', array_keys($f), $f));

    $out = '';
    foreach ($projects as $project) {
        $mids   = project_milestone_rows($token, $project['id']);
        $tasks  = project_task_rows($token, $project['id']);
        $prog   = project_progress($tasks);
        $mine   = array_values(array_filter($requests, fn($r) => $r['project_id'] === $project['id']));

        $out .= '<section class="card" style="margin-bottom:12px">'
            . '<div class="edit-head"><h2>' . e($project['name']) . '</h2>' . project_status_pill($project['status']) . '</div>'
            . '<p class="hint" style="margin:0 0 8px">' . e($project['packages']['name'] ?? 'Service package')
            . ' · started ' . e(project_date($project['start_date']))
            . ' · target ' . e(project_date($project['target_date'], 'to be confirmed'))
            . ($project['lead']['full_name'] ?? '' ? ' · your KAM Team lead: ' . e($project['lead']['full_name']) : '') . '</p>'
            . ($project['summary'] ? '<p style="margin:0 0 10px;font-size:13px">' . nl2br(e($project['summary'])) . '</p>' : '')
            . project_bar($prog);

        if (!$mids) {
            $out .= '<p class="empty" style="margin-top:10px">Your KAM Team lead is putting the roadmap together — the milestones appear here shortly.</p>';
        } else {
            $out .= '<ol class="road-client">';
            foreach ($mids as $m) {
                $mtasks = project_tasks_of($tasks, $m['id']);
                $mprog  = project_progress($mtasks);
                $out .= '<li class="road-client-step is-' . e($m['status']) . '">'
                    . '<div class="road-client-head"><strong>' . e($m['title']) . '</strong>' . status_pill($m['status']) . '</div>'
                    . '<span class="hint">' . e(project_date($m['start_date'], 'start to be set')) . ' → ' . e(project_date($m['target_date'], 'target to be set'))
                    . ($m['completed_on'] ? ' · completed ' . e(project_date($m['completed_on'])) : '') . '</span>'
                    . ($m['detail'] ? '<p style="margin:6px 0 0;font-size:13px">' . nl2br(e($m['detail'])) . '</p>' : '')
                    . ($mtasks ? '<div style="margin-top:8px">' . project_bar($mprog) . '</div>' : '');

                if ($mtasks) {
                    $out .= '<ul class="road-client-tasks">';
                    foreach ($mtasks as $t) {
                        $out .= '<li><span>' . e($t['title']) . '</span>'
                            . ($t['source'] === 'client' ? '<span class="pill">your request</span>' : '')
                            . status_pill($t['status'])
                            . '<span class="hint">' . ($t['status'] === 'completed' && $t['completed_on']
                                ? 'completed ' . e(project_date($t['completed_on']))
                                : ($t['target_date'] ? 'due ' . e(project_date($t['target_date'])) : '')) . '</span></li>';
                    }
                    $out .= '</ul>';
                }
                $out .= '</li>';
            }
            $out .= '</ol>';
        }

        // Ask for work
        $msMap = [];
        foreach ($mids as $m) $msMap[$m['id']] = $m['title'];
        $msOpts = '<option value="">Anywhere on the roadmap</option>';
        foreach ($msMap as $k => $v) $msOpts .= '<option value="' . e($k) . '">' . e($v) . '</option>';

        $out .= '<details class="road-ask" style="margin-top:12px"><summary class="btn btn-sm btn-solid">Request work on this project</summary>'
            . '<form method="post" style="margin-top:10px">' . $hid(['roadmap_action' => 'request_add', 'project_id' => $project['id']])
            . '<div class="row"><div class="field"><label>What would you like done?</label><input name="title" required maxlength="200" placeholder="e.g. Add a second landing page for the Manila branch"></div>'
            . '<div class="field"><label>Which part of the roadmap?</label><select name="milestone_id">' . $msOpts . '</select></div></div>'
            . '<div class="field"><label>Any detail that helps <span class="hint">(optional)</span></label><textarea name="detail" rows="3"></textarea></div>'
            . '<button class="btn btn-sm btn-solid" type="submit">Send request →</button>'
            . '<p class="hint" style="margin:8px 0 0">Your KAM Team lead answers it on the roadmap. Accepted requests appear above as tasks; work outside your package may be quoted separately.</p>'
            . '</form></details>';

        if ($mine) {
            $out .= '<div class="table-scroll" style="margin-top:10px"><table><thead><tr><th>Your request</th><th>Sent</th><th>Answer</th><th></th></tr></thead><tbody>';
            foreach ($mine as $r) {
                $out .= '<tr><td style="font-size:12px"><strong>' . e($r['title']) . '</strong>'
                    . ($r['detail'] ? '<br><span class="hint">' . e(mb_substr($r['detail'], 0, 120)) . '</span>' : '') . '</td>'
                    . '<td style="font-size:12px">' . e(project_date($r['created_at'])) . '</td>'
                    . '<td><span class="pill ' . ['open' => 'pending', 'accepted' => 'active', 'declined' => 'failed', 'withdrawn' => ''][$r['status']] . '">'
                    . e(REQUEST_STATUSES[$r['status']] ?? $r['status']) . '</span>'
                    . ($r['decision_note'] ? '<br><span class="hint">' . e($r['decision_note']) . '</span>' : '') . '</td>'
                    . '<td>' . ($r['status'] === 'open'
                        ? '<form method="post">' . $hid(['roadmap_action' => 'request_withdraw', 'project_id' => $project['id'], 'request_id' => $r['id']])
                          . '<button class="btn btn-sm btn-ghost" type="submit">Withdraw</button></form>'
                        : '') . '</td></tr>';
            }
            $out .= '</tbody></table></div>';
        }

        $out .= '</section>';
    }
    return $out;
}
