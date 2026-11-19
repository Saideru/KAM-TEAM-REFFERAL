<?php
// =====================================================================
// Client Work Documentation & Reporting — SOP-OPS-001 v1.0
//
// Every client task is traceable to a scope, assigned to an owner,
// supported by evidence, verified before it is Completed, and included
// in the right client report. Roles (assignee, reviewer, coordinator,
// preparer, verifier, approver) are free-text name tags — a formality
// recorded on the task/report, not a permission.
// =====================================================================

const SOP_ID      = 'SOP-OPS-001';
const SOP_VERSION = '1.0';

// §6 — the only seven statuses allowed.
const TASK_STATUSES = [
    'not_started' => ['Not Started', 'Assigned, but work has not begun.'],
    'in_progress' => ['In Progress', 'Authorized work has begun but is not ready for review.'],
    'for_review'  => ['For Review',  'Delivered internally and awaiting verification or approval.'],
    'completed'   => ['Completed',   'Meets its acceptance criteria and has been verified. Only this status is reported as an accomplishment.'],
    'blocked'     => ['Blocked',     'Cannot continue because of a documented dependency.'],
    'on_hold'     => ['On Hold',     'Paused temporarily by an authorized decision.'],
    'cancelled'   => ['Cancelled',   'Formally withdrawn. Reason and approver recorded.'],
];

// Kinds of entry in a task's running log.
const TASK_LOG_KINDS = [
    'update'     => 'Progress update',
    'evidence'   => 'Evidence attached',
    'blocker'    => 'Blocker flagged',
    'completion' => 'Completion note',
    'review'     => 'Review / QA note',
];

const REPORT_TYPES = [
    'weekly'    => 'Weekly progress report',
    'monthly'   => 'Monthly accomplishment report',
    'milestone' => 'Milestone / project completion report',
    'incident'  => 'Incident, change or exception update',
];

// §12 — every box must be confirmed before a report can be Approved.
const REPORT_QA = [
    'traceable'  => 'Every reported accomplishment can be found in the tracker and has evidence.',
    'in_period'  => 'Task completion dates fall inside the reporting period.',
    'in_scope'   => 'Service claims match the contracted scope and approved change requests.',
    'metrics'    => 'Metrics reconcile to named platform records; there are no unsupported percentages or causal claims.',
    'disclosed'  => 'Outstanding work and blockers are disclosed and correctly classified.',
    'safe'       => 'The report contains no credentials, unnecessary sensitive data or internal-only commentary.',
    'links'      => 'Evidence links open for the intended recipients and are formatted correctly.',
    'authorized' => 'The named approver has authorized the final external version.',
];

// §7 — phrases that are too vague to accept as a task description.
const VAGUE_ENTRIES = ['seo done', 'worked on automation', 'marketing managed', 'website updated', 'automation fixed', 'social media completed', 'seo optimized', 'done', 'completed', 'fixed', 'updated'];

function task_status_label(string $s): string { return TASK_STATUSES[$s][0] ?? $s; }

// §5 — fields every task must have before it can move to For Review.
function task_missing_fields(array $t): array
{
    $need = [
        'title' => 'Task title', 'scope_ref' => 'Scope reference', 'assignee' => 'Assignee',
        'reviewer' => 'Reviewer', 'target_date' => 'Target date', 'work_performed' => 'Work performed',
        'deliverable' => 'Deliverable', 'evidence' => 'Evidence',
    ];
    $missing = [];
    foreach ($need as $k => $label) if (trim((string) ($t[$k] ?? '')) === '') $missing[] = $label;
    if (empty($t['package_id'])) $missing[] = 'Service package';
    return $missing;
}

// Rejects descriptions like "SEO done" (§7).
function is_vague(string $text): bool
{
    $t = strtolower(trim(preg_replace('/[^a-z ]+/i', ' ', $text)));
    return $t === '' || in_array(trim(preg_replace('/\s+/', ' ', $t)), VAGUE_ENTRIES, true) || str_word_count($t) < 4;
}

// Checks a requested status change against the SOP. Returns errors.
function task_transition_errors(array $task, string $to, array $input): array
{
    $from = $task['status'];
    $t    = array_merge($task, $input);
    $err  = [];
    if (!isset(TASK_STATUSES[$to])) return ['Unknown status.'];
    if ($to === $from) return [];

    if (in_array($to, ['for_review', 'completed'], true)) {
        foreach (task_missing_fields($t) as $f) $err[] = $f . ' is required before ' . task_status_label($to) . ' (SOP §5).';
        if (trim((string) ($t['work_performed'] ?? '')) !== '' && is_vague($t['work_performed'])) {
            $err[] = 'Describe the work in specific, measurable terms — what changed, where, and how completion was confirmed (SOP §7).';
        }
    }
    if ($to === 'completed') {
        if ($from !== 'for_review') $err[] = 'A task must be For Review and verified by its reviewer before it is Completed (SOP §4 step 5).';
        if (empty($t['completed_on'])) $err[] = 'Enter the actual completion date.';
    }
    if ($to === 'blocked' && trim((string) ($t['blockers'] ?? '')) === '') $err[] = 'Document the blocker / dependency.';
    if ($to === 'on_hold' && trim((string) ($input['note'] ?? '')) === '') $err[] = 'Note who authorized the hold and why.';
    if ($to === 'cancelled' && (trim((string) ($t['cancel_reason'] ?? '')) === '' || trim((string) ($t['cancel_approved_by'] ?? '')) === '')) {
        $err[] = 'Record the cancellation reason and who approved it (SOP §6).';
    }
    return $err;
}

// Milestone status reconciled from its tasks (team lead view).
function milestone_status(array $tasks): string
{
    $live = array_filter($tasks, fn($t) => $t['status'] !== 'cancelled');
    if (!$live) return 'cancelled';
    $st = array_column($live, 'status');
    if (count(array_filter($st, fn($s) => $s === 'completed')) === count($st)) return 'completed';
    if (in_array('blocked', $st, true))    return 'blocked';
    if (in_array('for_review', $st, true)) return 'for_review';
    if (array_intersect(['in_progress', 'completed'], $st)) return 'in_progress';
    if (count(array_filter($st, fn($s) => $s === 'on_hold')) === count($st)) return 'on_hold';
    return 'not_started';
}

// Groups a client's tasks into milestones per package.
function milestones(array $tasks): array
{
    $groups = [];
    foreach ($tasks as $t) {
        $key = ($t['packages']['name'] ?? 'No package') . ' › ' . (trim((string) $t['milestone']) ?: 'General');
        $groups[$key][] = $t;
    }
    ksort($groups);
    $out = [];
    foreach ($groups as $name => $ts) {
        $live = array_filter($ts, fn($t) => $t['status'] !== 'cancelled');
        $done = count(array_filter($live, fn($t) => $t['status'] === 'completed'));
        $out[] = ['name' => $name, 'status' => milestone_status($ts), 'done' => $done, 'total' => count($live),
                  'pct' => $live ? (int) round($done / count($live) * 100) : 0];
    }
    return $out;
}

function status_pill(string $s): string
{
    $cls = ['completed' => 'active', 'blocked' => 'failed', 'cancelled' => 'failed', 'for_review' => 'pending', 'on_hold' => 'pending'][$s] ?? '';
    return '<span class="pill ' . $cls . '">' . e(task_status_label($s)) . '</span>';
}

// Appends to a task's running log and the admin audit log.
function task_log(string $token, array $task, string $kind, string $note, ?string $from = null, ?string $to = null, ?string $authorTag = null): void
{
    $session = &_session();
    supabase_request('POST', '/rest/v1/client_task_updates', [
        'task_id' => $task['id'], 'kind' => $kind, 'note' => mb_substr($note, 0, 2000),
        'status_from' => $from, 'status_to' => $to, 'author_tag' => $authorTag ?: null,
        'author_id' => $session['sb']['user_id'] ?? null,
    ], $token, ['Prefer: return=minimal']);
    audit_log($token, 'task_' . ($to ? 'status' : $kind), 'client_task', $task['id'],
        $task['task_code'] . ' ' . $task['title'] . ($to ? ' — ' . task_status_label($from ?? '') . ' → ' . task_status_label($to) : ' — ' . TASK_LOG_KINDS[$kind] . ': ' . mb_substr($note, 0, 120)));
}

// Tasks for one client (optionally one package), newest first.
function client_tasks(string $token, string $clientId, ?string $packageId = null): array
{
    $q = '/rest/v1/client_tasks?select=*,packages(name)&client_id=eq.' . urlencode($clientId)
       . ($packageId ? '&package_id=eq.' . urlencode($packageId) : '') . '&order=created_at.desc';
    $r = supabase_request('GET', $q, null, $token);
    return is_array($r['body']) && !$r['error'] ? $r['body'] : [];
}

// §6 step 6 — build the period's report body from verified records only.
//   done: Completed tasks whose completion date is inside the period.
//   open: For Review / Blocked / On Hold / In Progress / Not Started tasks.
function report_compile(string $token, array $report): array
{
    $tasks = client_tasks($token, $report['client_id'], $report['package_id'] ?? null);
    $done = array_values(array_filter($tasks, fn($t) => $t['status'] === 'completed' && $t['completed_on']
        && $t['completed_on'] >= $report['period_start'] && $t['completed_on'] <= $report['period_end']));
    $open = array_values(array_filter($tasks, fn($t) => in_array($t['status'], ['for_review', 'blocked', 'on_hold', 'in_progress', 'not_started'], true)));
    usort($done, fn($a, $b) => strcmp($a['completed_on'], $b['completed_on']));
    $keep = fn($t) => array_intersect_key($t, array_flip(['task_code', 'title', 'status', 'completed_on', 'work_performed', 'deliverable', 'evidence', 'blockers', 'next_action', 'next_owner', 'next_date', 'milestone', 'assignee', 'target_date']))
        + ['package' => $t['packages']['name'] ?? ''];
    return ['done' => array_map($keep, $done), 'open' => array_map($keep, $open), 'milestones' => milestones($tasks)];
}

// §13 file naming: KAMTEAM_ClientName_PackageOrProject_ReportType_YYYY-MM-DD_v01
function report_filename(array $r, string $client, string $package): string
{
    $slug = fn($s) => trim(preg_replace('/[^A-Za-z0-9]+/', '', ucwords(strtolower($s))), '') ?: 'NA';
    return 'KAMTEAM_' . $slug($client) . '_' . $slug($package ?: 'AllServices') . '_' . ucfirst($r['report_type']) . 'Report_'
        . $r['report_date'] . '_v' . str_pad((string) $r['version'], 2, '0', STR_PAD_LEFT);
}

// Renders the 7 required report sections (§10). $data comes from the frozen
// snapshot once approved, or from report_compile() while still a draft.
function report_body_html(array $r, string $clientName, string $pkgName, array $data): string
{
    $row = fn(string $k, ?string $v) => '<div class="profile-row"><span class="label">' . e($k) . '</span><span>' . ($v !== null && $v !== '' ? nl2br(e($v)) : '—') . '</span></div>';
    $link = function (?string $v): string {
        $v = trim((string) $v);
        if ($v === '') return '—';
        return implode('<br>', array_map(fn($l) => preg_match('#^https?://#i', $l) ? '<a href="' . e($l) . '" target="_blank" rel="noopener">' . e($l) . '</a>' : e($l), preg_split('/\R+/', $v)));
    };

    $h  = '<section class="report-sec"><h2>1 · Report header</h2><div class="profile-info">'
        . $row('Client', $clientName) . $row('Package / project', $pkgName ?: 'All services')
        . $row('Report type', REPORT_TYPES[$r['report_type']] ?? $r['report_type'])
        . $row('Reporting period', date('M j, Y', strtotime($r['period_start'])) . ' – ' . date('M j, Y', strtotime($r['period_end'])))
        . $row('Report date', date('M j, Y', strtotime($r['report_date']))) . $row('KAM TEAM contact', $r['owner'] ?? '')
        . '</div></section>';

    $h .= '<section class="report-sec"><h2>2 · Executive summary</h2><p>' . (trim((string) $r['summary']) !== '' ? nl2br(e($r['summary'])) : '<em>—</em>') . '</p></section>';

    $h .= '<section class="report-sec"><h2>3 · Accomplishments</h2>';
    if (!$data['done']) {
        $h .= '<p class="empty">No tasks were verified as Completed in this period.</p>';
    } else {
        $h .= '<div class="table-scroll"><table><thead><tr><th>Task ID</th><th>Service / workstream</th><th>Action performed</th><th>Deliverable</th><th>Status</th><th>Completed</th><th>Evidence</th></tr></thead><tbody>';
        foreach ($data['done'] as $t) {
            $h .= '<tr><td>' . e($t['task_code']) . '</td><td style="font-size:12px">' . e($t['package']) . ($t['milestone'] ? '<br><span class="hint">' . e($t['milestone']) . '</span>' : '') . '</td>'
                . '<td style="font-size:12px"><strong>' . e($t['title']) . '</strong><br>' . nl2br(e($t['work_performed'] ?? '')) . '</td>'
                . '<td style="font-size:12px">' . $link($t['deliverable']) . '</td><td>' . status_pill($t['status']) . '</td>'
                . '<td style="font-size:12px">' . e($t['completed_on'] ? date('M j, Y', strtotime($t['completed_on'])) : '') . '</td><td style="font-size:12px">' . $link($t['evidence']) . '</td></tr>';
        }
        $h .= '</tbody></table></div>';
    }
    $h .= '</section>';

    $h .= '<section class="report-sec"><h2>4 · Performance and results</h2><p>' . (trim((string) $r['results']) !== '' ? nl2br(e($r['results'])) : '<em>No metrics reported for this period.</em>') . '</p>'
        . '<p class="hint">Each metric states its source platform and date range. Actuals are labelled separately from estimates, targets and projections; a completed task does not by itself prove business growth (SOP §11).</p></section>';

    $h .= '<section class="report-sec"><h2>5 · Pending work and blockers</h2>';
    if (!$data['open']) {
        $h .= '<p class="empty">Nothing outstanding.</p>';
    } else {
        $h .= '<div class="table-scroll"><table><thead><tr><th>Task ID</th><th>Task</th><th>Status</th><th>Reason / dependency</th><th>Next owner &amp; action</th></tr></thead><tbody>';
        foreach ($data['open'] as $t) {
            $h .= '<tr><td>' . e($t['task_code']) . '</td><td style="font-size:12px">' . e($t['title']) . '</td><td>' . status_pill($t['status']) . '</td>'
                . '<td style="font-size:12px">' . nl2br(e($t['blockers'] ?? '')) . '</td>'
                . '<td style="font-size:12px">' . e(trim(($t['next_owner'] ?? '') . ($t['next_action'] ? ' — ' . $t['next_action'] : '') . ($t['next_date'] ? ' (' . date('M j', strtotime($t['next_date'])) . ')' : ''))) . '</td></tr>';
        }
        $h .= '</tbody></table></div>';
    }
    if (trim((string) $r['client_decisions']) !== '') $h .= '<p><strong>Decisions needed from you:</strong><br>' . nl2br(e($r['client_decisions'])) . '</p>';
    $h .= '</section>';

    if (!empty($data['milestones'])) {
        $h .= '<section class="report-sec"><h2>Milestone status</h2><div class="table-scroll"><table><thead><tr><th>Milestone</th><th>Progress</th><th>Status</th></tr></thead><tbody>';
        foreach ($data['milestones'] as $m) {
            $h .= '<tr><td style="font-size:12px">' . e($m['name']) . '</td><td style="font-size:12px">' . $m['done'] . ' / ' . $m['total'] . ' (' . $m['pct'] . '%)</td><td>' . status_pill($m['status']) . '</td></tr>';
        }
        $h .= '</tbody></table></div></section>';
    }

    $h .= '<section class="report-sec"><h2>6 · Next-period plan</h2><p>' . (trim((string) $r['next_plan']) !== '' ? nl2br(e($r['next_plan'])) : '<em>—</em>') . '</p></section>';

    $h .= '<section class="report-sec"><h2>7 · Verification and approval</h2><div class="profile-info">'
        . $row('Prepared by', $r['prepared_by']) . $row('Verified by', $r['verified_by']) . $row('Approved by', $r['approved_by'])
        . $row('Version / date', 'v' . str_pad((string) $r['version'], 2, '0', STR_PAD_LEFT) . ' · ' . date('M j, Y', strtotime($r['report_date'])))
        . ($r['revision_note'] ? $row('Revision note', $r['revision_note']) : '')
        . '</div></section>';
    return $h;
}
