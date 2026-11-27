<?php
// =====================================================================
// KAM Team // Client intake form responses
// ---------------------------------------------------------------------
// The intake questionnaire is a Google Form; its answers land in a
// Google Sheet. A sync pulls that sheet's published CSV, matches each
// response to a client account and to the Growth Partner who referred
// them, and keeps it here so all three sides can keep tabs on it:
//
//   client          fills the form, then sees their own answers
//   growth partner  sees what the clients they referred submitted
//   staff / admin   see everything and work it: new -> reviewed ->
//                   contacted -> closed
//
// Nothing is retyped and nothing duplicates: each sheet row carries a
// fingerprint (row_key), and a sync upserts on it.
// =====================================================================

require_once __DIR__ . '/supabase.php';

const INTAKE_STATUSES = [
    'new'       => 'New',
    'reviewed'  => 'Reviewed',
    'contacted' => 'Contacted',
    'closed'    => 'Closed',
    'spam'      => 'Spam',
];

const INTAKE_SETTING_KEYS = ['intake_form_url', 'intake_sheet_url', 'intake_csv_url', 'intake_synced_at'];

// How a sheet column maps onto one of our own. Matching is loose on
// purpose — a question can be reworded in the form without breaking the
// sync, as long as the keyword survives.
const INTAKE_COLUMN_HINTS = [
    'full_name'       => ['full name'],
    'company'         => ['company', 'business name'],
    'job_title'       => ['position', 'job title'],
    'email'           => ['email'],
    'phone'           => ['contact number', 'phone'],
    'country'         => ['country'],
    'website'         => ['website'],
    'industry'        => ['industry'],
    'packages_wanted' => ['preferred package'],
    'budget_setup'    => ['setup budget'],
    'budget_monthly'  => ['monthly budget'],
    'preferred_start' => ['preferred project start'],
    'decision_maker'  => ['decision-maker', 'decision maker'],
    'heard_from'      => ['how did you hear'],
    'partner_name'    => ['referring partner'],
    'partner_code'    => ['partner id', 'referral code'],
    'consult_when'    => ['preferred consultation date'],
];

function intake_settings(string $token): array
{
    return get_settings($token, INTAKE_SETTING_KEYS);
}

// ---------------------------------------------------------------------
// The sync
// ---------------------------------------------------------------------

/** Reads the published CSV. Returns [error, rows] with rows as header => value. */
function intake_fetch_csv(string $url): array
{
    if (!preg_match('#^https://docs\.google\.com/#', $url)) {
        return ['The response sheet URL must be a docs.google.com link.', []];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_USERAGENT      => 'KAMTEAM-portal/1.0',
    ]);
    $raw    = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err    = curl_error($ch);
    curl_close($ch);

    if ($raw === false)  return ['Could not reach the response sheet: ' . $err, []];
    if ($status >= 400)  return ['The response sheet refused the request (HTTP ' . $status . '). Make sure link sharing is on and the link is its CSV export.', []];
    if (stripos(ltrim($raw), '<!doctype') === 0 || stripos(ltrim($raw), '<html') === 0) {
        return ['That link returned a web page, not CSV. Use the sheet\'s export link (…/export?format=csv).', []];
    }

    $fh = fopen('php://temp', 'r+');
    fwrite($fh, $raw);
    rewind($fh);
    $header = fgetcsv($fh);
    if (!$header) { fclose($fh); return ['The response sheet is empty.', []]; }

    $rows = [];
    while (($line = fgetcsv($fh)) !== false) {
        if (count(array_filter($line, fn($v) => trim((string) $v) !== '')) === 0) continue;
        $row = [];
        foreach ($header as $i => $name) {
            $name = trim((string) $name);
            if ($name === '') continue;
            // A form can ask two questions with the same label (this one
            // asks "Full name" twice): keep the first, number the rest.
            $key = isset($row[$name]) ? $name . ' (2)' : $name;
            $row[$key] = trim((string) ($line[$i] ?? ''));
        }
        $rows[] = $row;
    }
    fclose($fh);
    return [null, $rows];
}

// Finds the answer whose question contains one of the hints.
function intake_pick(array $row, array $hints): ?string
{
    foreach ($row as $question => $value) {
        $q = mb_strtolower($question);
        foreach ($hints as $hint) {
            if (str_contains($q, $hint)) return ($value === '') ? null : $value;
        }
    }
    return null;
}

// "KT-000123", "kt000123" and "000123" are the same code.
function intake_normalise_code(?string $raw): ?string
{
    $c = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $raw));
    $c = preg_replace('/^KT/', '', $c);
    // A real code has digits in it. "n/a", "none" and the like are not
    // codes, and must not turn into one that matches nobody.
    if ($c === '' || !preg_match('/^[A-Z0-9]{3,16}$/', $c) || !preg_match('/\d/', $c)) return null;
    return 'KT-' . $c;
}

/**
 * Pulls the sheet and upserts every response.
 * @return array{0: ?string, 1: array{rows:int,new:int,matched:int}}
 */
function intake_sync(string $token, ?string $actorId = null): array
{
    $cfg = intake_settings($token);
    $url = $cfg['intake_csv_url'] !== '' ? $cfg['intake_csv_url'] : $cfg['intake_sheet_url'];
    // An /edit link is the same sheet — turn it into its CSV export.
    if (str_contains($url, '/edit')) $url = preg_replace('#/edit.*$#', '/export?format=csv', $url);
    if (trim($url) === '') return ['No response sheet is configured yet.', ['rows' => 0, 'new' => 0, 'matched' => 0]];

    [$err, $rows] = intake_fetch_csv($url);
    if ($err) return [$err, ['rows' => 0, 'new' => 0, 'matched' => 0]];

    // Who we can match against: every member account, once.
    $dir = supabase_request('GET', '/rest/v1/profiles?select=id,full_name,email,role,referral_code&deleted_at=is.null&limit=2000', null, $token);
    $people = (!$dir['error'] && is_array($dir['body'])) ? $dir['body'] : [];
    $byEmail = $byCode = $byName = [];
    foreach ($people as $p) {
        if (!empty($p['email']))         $byEmail[mb_strtolower($p['email'])] = $p;
        if (!empty($p['referral_code'])) $byCode[strtoupper($p['referral_code'])] = $p;
        if (!empty($p['full_name']))     $byName[mb_strtolower(trim($p['full_name']))] = $p;
    }

    $existing = supabase_request('GET', '/rest/v1/intake_responses?select=row_key,client_id,status&limit=5000', null, $token);
    $known    = array_column((!$existing['error'] && is_array($existing['body'])) ? $existing['body'] : [], 'status', 'row_key');

    $payload = [];
    $fresh   = 0;
    $matched = 0;
    foreach ($rows as $row) {
        $get  = fn(string $field) => intake_pick($row, INTAKE_COLUMN_HINTS[$field]);
        $when = intake_pick($row, ['timestamp']) ?: null;
        $mail = $get('email');
        $name = $get('full_name');
        $key  = sha1(trim((string) $when) . '|' . mb_strtolower(trim((string) $mail)) . '|' . mb_strtolower(trim((string) $name)));

        $code    = intake_normalise_code($get('partner_code'));
        $partner = $code && isset($byCode[$code]) ? $byCode[$code] : null;
        if (!$partner && ($pn = $get('partner_name'))) $partner = $byName[mb_strtolower(trim($pn))] ?? null;

        $client = $mail ? ($byEmail[mb_strtolower($mail)] ?? null) : null;
        $how    = $client ? 'email' : null;
        if (!$client && $name && isset($byName[mb_strtolower(trim($name))])
            && in_array($byName[mb_strtolower(trim($name))]['role'] ?? '', ['client', 'growth_partner'], true)) {
            $client = $byName[mb_strtolower(trim($name))];
            $how    = 'name';
        }
        if ($client) $matched++;
        if (!isset($known[$key])) $fresh++;

        $payload[] = [
            'row_key'      => $key,
            'submitted_at' => ($when && strtotime($when)) ? date('c', strtotime($when)) : null,
            'full_name'    => $name, 'company' => $get('company'), 'job_title' => $get('job_title'),
            'email'        => $mail, 'phone' => $get('phone'), 'country' => $get('country'),
            'website'      => $get('website'), 'industry' => $get('industry'),
            'packages_wanted' => $get('packages_wanted'),
            'budget_setup' => $get('budget_setup'), 'budget_monthly' => $get('budget_monthly'),
            'preferred_start' => $get('preferred_start'), 'decision_maker' => $get('decision_maker'),
            'heard_from'   => $get('heard_from'),
            'partner_name' => $get('partner_name'), 'partner_code' => $code,
            'consult_when' => $get('consult_when'),
            'answers'      => $row,
            'client_id'    => $client['id'] ?? null,
            'partner_id'   => $partner['id'] ?? null,
            'matched_by'   => $how,
        ];
    }

    if ($payload) {
        // Upsert on row_key. A response already being worked keeps its
        // status, note and handler — only the answers are refreshed.
        foreach (array_chunk($payload, 100) as $chunk) {
            $res = supabase_request('POST', '/rest/v1/intake_responses?on_conflict=row_key', $chunk, $token,
                ['Prefer: resolution=merge-duplicates,return=minimal']);
            if ($res['error']) return [db_error_message($res['error']), ['rows' => count($payload), 'new' => $fresh, 'matched' => $matched]];
        }
    }

    set_setting($token, 'intake_synced_at', date('c'));
    audit_log($token, 'intake_synced', 'intake_response', null,
        'Pulled ' . count($payload) . ' intake response' . (count($payload) === 1 ? '' : 's') . ' (' . $fresh . ' new)');

    // Tell the team about genuinely new ones, once.
    if ($fresh > 0) {
        notify($token, $fresh . ' new client intake form' . ($fresh === 1 ? '' : 's'),
            'Pulled from the response sheet. Review and match them to accounts.', null, 'staff', '/work.php?view=intake');
    }
    return [null, ['rows' => count($payload), 'new' => $fresh, 'matched' => $matched]];
}

// ---------------------------------------------------------------------
// Reading
// ---------------------------------------------------------------------

const INTAKE_LIST_COLS = 'id,row_key,submitted_at,full_name,company,email,phone,country,industry,'
    . 'packages_wanted,budget_setup,budget_monthly,preferred_start,partner_name,partner_code,consult_when,'
    . 'client_id,partner_id,matched_by,status,note,handled_at';

function intake_list(string $token, array $filter = []): array
{
    $q = '/rest/v1/intake_responses?select=' . INTAKE_LIST_COLS
        . ',client:profiles!intake_responses_client_id_fkey(full_name),'
        . 'partner:profiles!intake_responses_partner_id_fkey(full_name,referral_code)';
    if (!empty($filter['status']))     $q .= '&status=eq.' . urlencode($filter['status']);
    if (!empty($filter['client_id']))  $q .= '&client_id=eq.' . urlencode($filter['client_id']);
    if (!empty($filter['partner_id'])) $q .= '&partner_id=eq.' . urlencode($filter['partner_id']);
    $q .= '&order=submitted_at.desc.nullslast&limit=' . (int) ($filter['limit'] ?? 300);

    $res = supabase_request('GET', $q, null, $token);
    return (!$res['error'] && is_array($res['body'])) ? $res['body'] : [];
}

function intake_get(string $token, string $id): ?array
{
    $res = supabase_request('GET', '/rest/v1/intake_responses?select=*,'
        . 'client:profiles!intake_responses_client_id_fkey(full_name),'
        . 'partner:profiles!intake_responses_partner_id_fkey(full_name,referral_code)'
        . '&id=eq.' . urlencode($id) . '&limit=1', null, $token);
    return (!$res['error'] && !empty($res['body'][0])) ? $res['body'][0] : null;
}

function intake_counts(string $token): array
{
    $res = supabase_request('GET', '/rest/v1/intake_responses?select=status&limit=5000', null, $token);
    $rows = (!$res['error'] && is_array($res['body'])) ? $res['body'] : [];
    return array_merge(array_fill_keys(array_keys(INTAKE_STATUSES), 0), array_count_values(array_column($rows, 'status')));
}

function intake_new_count(string $token): int
{
    return intake_counts($token)['new'] ?? 0;
}

function intake_status_pill(string $s): string
{
    $cls = ['new' => 'pending', 'reviewed' => '', 'contacted' => 'active', 'closed' => 'completed', 'spam' => 'failed'][$s] ?? '';
    return '<span class="pill ' . $cls . '">' . e(INTAKE_STATUSES[$s] ?? $s) . '</span>';
}

// ---------------------------------------------------------------------
// POST handlers
// ---------------------------------------------------------------------

/** Staff / admin: sync, work a response, match it by hand, save the links. */
function intake_team_action(string $token, array $profile): ?array
{
    $act = (string) ($_POST['intake_action'] ?? '');
    if ($act === '') return null;
    if (!in_array($profile['role'] ?? '', ['admin', 'staff'], true)) return ['Only KAM Team can do that.', null];
    $s = fn(string $k, int $max = 2000) => mb_substr(trim((string) ($_POST[$k] ?? '')), 0, $max);

    if ($act === 'sync') {
        [$err, $stat] = intake_sync($token, $profile['id']);
        if ($err) return [$err, null];
        return [null, 'Pulled ' . $stat['rows'] . ' response' . ($stat['rows'] === 1 ? '' : 's') . ' — '
            . $stat['new'] . ' new, ' . $stat['matched'] . ' matched to an account.'];
    }

    if ($act === 'settings') {
        if (($profile['role'] ?? '') !== 'admin') return ['Only an admin changes the form links.', null];
        foreach (['intake_form_url', 'intake_sheet_url', 'intake_csv_url'] as $k) {
            $v = $s($k, 500);
            if ($v !== '' && !preg_match('#^https://#i', $v)) return ['Each link must start with https://', null];
            set_setting($token, $k, $v !== '' ? $v : null);
        }
        audit_log($token, 'intake_settings', 'app_settings', null, 'Updated the intake form and response sheet links');
        return [null, 'Form links saved.'];
    }

    $id  = $s('response_id', 64);
    $row = $id ? intake_get($token, $id) : null;
    if (!$row) return ['Response not found.', null];

    if ($act === 'update') {
        $status = isset(INTAKE_STATUSES[$_POST['status'] ?? '']) ? $_POST['status'] : $row['status'];
        $patch  = ['status' => $status, 'note' => $s('note', 1000) ?: null,
                   'handled_by' => $profile['id'], 'handled_at' => date('c')];

        // Matching it to an account by hand, when the email did not line up.
        $clientId = $s('client_id', 64);
        if ($clientId !== '' && $clientId !== ($row['client_id'] ?? '')) {
            $patch['client_id']  = $clientId;
            $patch['matched_by'] = 'manual';
        } elseif ($clientId === '' && !empty($row['client_id']) && ($_POST['unmatch'] ?? '') === '1') {
            $patch['client_id']  = null;
            $patch['matched_by'] = null;
        }

        $res = supabase_request('PATCH', '/rest/v1/intake_responses?id=eq.' . urlencode($row['id']), $patch, $token, ['Prefer: return=minimal']);
        if ($res['error']) return [db_error_message($res['error']), null];
        audit_log($token, 'intake_updated', 'intake_response', $row['id'],
            ($row['full_name'] ?? 'Response') . ' — ' . (INTAKE_STATUSES[$status] ?? $status));
        return [null, 'Response updated.'];
    }

    return ['Unknown action.', null];
}

// ---------------------------------------------------------------------
// Rendering
// ---------------------------------------------------------------------

// One response, every question and answer as the form asked them.
function intake_answers_html(array $row): string
{
    $answers = is_array($row['answers'] ?? null) ? $row['answers'] : [];
    if (!$answers) return '<p class="empty">No answers stored for this response.</p>';
    $h = '<div class="profile-info">';
    foreach ($answers as $question => $value) {
        if (trim((string) $value) === '') continue;
        $h .= '<div class="profile-row"><span class="label">' . e((string) $question) . '</span><span>' . nl2br(e((string) $value)) . '</span></div>';
    }
    return $h . '</div>';
}

function intake_when(?string $ts): string
{
    return $ts ? date('M j, Y H:i', strtotime($ts)) : '—';
}

/**
 * The team viewer: sync, filter, work each response.
 * Admins also get the form links to edit.
 */
function intake_team_html(string $token, array $profile, string $csrf, array $clients, ?string $status = null, ?string $openId = null): string
{
    $cfg    = intake_settings($token);
    $counts = intake_counts($token);
    $rows   = intake_list($token, $status ? ['status' => $status] : []);
    $isAdmin = ($profile['role'] ?? '') === 'admin';
    $hid    = fn(array $f) => '<input type="hidden" name="csrf" value="' . e($csrf) . '">'
        . implode('', array_map(fn($k, $v) => '<input type="hidden" name="' . e($k) . '" value="' . e((string) $v) . '">', array_keys($f), $f));

    $out = '<section class="card" style="margin-bottom:12px">'
        . '<div class="edit-head"><h2>Client intake forms</h2>'
        . '<form method="post" style="margin:0">' . $hid(['intake_action' => 'sync'])
        . '<button class="btn btn-sm btn-solid" type="submit">Sync responses now →</button></form></div>'
        . '<p class="hint" style="margin:0 0 10px">Answers come from the intake form\'s response sheet. Syncing is safe to repeat — each response is matched on its own fingerprint, so nothing is ever pulled in twice, and anything already being worked keeps its status and note.'
        . ($cfg['intake_synced_at'] !== '' ? ' Last sync ' . e(intake_when($cfg['intake_synced_at'])) . '.' : ' Not synced yet.') . '</p>'
        . '<p class="hint" style="margin:0 0 10px">'
        . ($cfg['intake_form_url'] !== '' ? '<a href="' . e($cfg['intake_form_url']) . '" target="_blank" rel="noopener">Open the form →</a>' : '')
        . ($cfg['intake_sheet_url'] !== '' ? ' · <a href="' . e($cfg['intake_sheet_url']) . '" target="_blank" rel="noopener">Open the response sheet →</a>' : '')
        . '</p>'
        . '<div class="status-strip"><a href="/work.php?view=intake" class="' . ($status ? '' : 'on') . '">All <b>' . array_sum($counts) . '</b></a>';
    foreach (INTAKE_STATUSES as $k => $label) {
        $out .= '<a href="/work.php?view=intake&amp;status=' . e($k) . '" class="' . ($status === $k ? 'on' : '') . '">' . e($label) . ' <b>' . (int) $counts[$k] . '</b></a>';
    }
    $out .= '</div></section>';

    if ($isAdmin) {
        $out .= '<details class="card" style="margin-bottom:12px"><summary class="btn btn-sm btn-ghost">Form &amp; sheet links</summary>'
            . '<form method="post" style="margin-top:10px">' . $hid(['intake_action' => 'settings'])
            . '<div class="field"><label>Intake form (the link clients fill)</label><input name="intake_form_url" maxlength="500" value="' . e($cfg['intake_form_url']) . '"></div>'
            . '<div class="field"><label>Response sheet (the link your team opens)</label><input name="intake_sheet_url" maxlength="500" value="' . e($cfg['intake_sheet_url']) . '"></div>'
            . '<div class="field"><label>Response sheet CSV export <span class="hint">(…/export?format=csv — this is what the sync reads)</span></label><input name="intake_csv_url" maxlength="500" value="' . e($cfg['intake_csv_url']) . '"></div>'
            . '<button class="btn btn-sm btn-solid" type="submit">Save links →</button>'
            . '<p class="hint" style="margin:8px 0 0">The sheet must be shared as "anyone with the link can view" for the sync to read it.</p>'
            . '</form></details>';
    }

    $out .= '<section class="card"><h2>Responses</h2>';
    if (!$rows) {
        $out .= '<p class="empty">' . ($status ? 'Nothing with that status.' : 'No responses yet. Sync once the first client has filled the form.') . '</p>';
        return $out . '</section>';
    }

    foreach ($rows as $r) {
        $open = $openId === $r['id'];
        $out .= '<details class="intake-row"' . ($open ? ' open' : '') . '><summary>'
            . '<strong>' . e($r['full_name'] ?: 'Unnamed') . '</strong>'
            . ($r['company'] ? ' · ' . e($r['company']) : '')
            . ' <span class="hint">' . e(intake_when($r['submitted_at'])) . '</span> '
            . intake_status_pill($r['status'])
            . ($r['client_id'] ? ' <span class="pill active">account matched</span>' : ' <span class="pill">no account yet</span>')
            . '</summary><div class="intake-body">'
            . '<div class="intake-top">'
            .   '<div><span class="label">Contact</span><p style="margin:2px 0 0;font-size:13px">' . e($r['email'] ?: '—')
            .     ($r['phone'] ? '<br>' . e($r['phone']) : '') . ($r['country'] ? '<br>' . e($r['country']) : '') . '</p></div>'
            .   '<div><span class="label">Wants</span><p style="margin:2px 0 0;font-size:13px">' . e($r['packages_wanted'] ?: '—')
            .     ($r['budget_setup'] ? '<br><span class="hint">setup ' . e($r['budget_setup']) . '</span>' : '')
            .     ($r['budget_monthly'] ? ' <span class="hint">· monthly ' . e($r['budget_monthly']) . '</span>' : '') . '</p></div>'
            .   '<div><span class="label">Referred by</span><p style="margin:2px 0 0;font-size:13px">'
            .     e($r['partner']['full_name'] ?? ($r['partner_name'] ?: '—'))
            .     ($r['partner_code'] ? '<br><span class="hint">' . e($r['partner_code']) . '</span>' : '') . '</p></div>'
            .   '<div><span class="label">Account</span><p style="margin:2px 0 0;font-size:13px">'
            .     e($r['client']['full_name'] ?? 'Not matched')
            .     ($r['matched_by'] ? '<br><span class="hint">matched by ' . e($r['matched_by']) . '</span>' : '') . '</p></div>'
            . '</div>'
            . intake_answers_html(intake_get($token, $r['id']) ?: $r)
            . '<form method="post" class="intake-work">' . $hid(['intake_action' => 'update', 'response_id' => $r['id']])
            .   '<select name="status" aria-label="Status">';
        foreach (INTAKE_STATUSES as $k => $label) {
            $out .= '<option value="' . e($k) . '"' . ($r['status'] === $k ? ' selected' : '') . '>' . e($label) . '</option>';
        }
        $out .= '</select>'
            . '<select name="client_id" aria-label="Match to a client account"><option value="">' . ($r['client_id'] ? 'Keep ' . e($r['client']['full_name'] ?? 'current') : 'Match to an account…') . '</option>';
        foreach ($clients as $cid => $cname) {
            $out .= '<option value="' . e($cid) . '"' . (($r['client_id'] ?? '') === $cid ? ' selected' : '') . '>' . e($cname) . '</option>';
        }
        $out .= '</select>'
            . '<input name="note" maxlength="1000" placeholder="Note (what was agreed, what is next)" value="' . e((string) ($r['note'] ?? '')) . '">'
            . '<button class="btn btn-sm btn-solid" type="submit">Save</button>'
            . '</form></div></details>';
    }
    return $out . '</section>';
}

/** The client's own: the form to fill, and their answers once they are in. */
function intake_client_html(string $token, array $profile): string
{
    $cfg  = intake_settings($token);
    $mine = intake_list($token, ['client_id' => $profile['id'], 'limit' => 10]);

    $out = '';
    if (!$mine) {
        $out .= '<p class="hint" style="margin:0 0 10px">Tell us about your business before your first consultation. It takes a few minutes, and your KAM Team contact works from your answers.</p>';
        $out .= $cfg['intake_form_url'] !== ''
            ? '<a class="btn btn-solid" href="' . e($cfg['intake_form_url']) . '" target="_blank" rel="noopener">Open the intake form →</a>'
                . '<p class="hint" style="margin:10px 0 0">Use the same email address as this account — that is how your answers find their way to your file here. They appear on this page once your KAM Team contact pulls them in.</p>'
            : '<p class="empty">The intake form is not published yet. Your KAM Team contact will send it to you.</p>';
        return $out;
    }

    foreach ($mine as $r) {
        $full = intake_get($token, $r['id']) ?: $r;
        $out .= '<div class="intake-mine"><div class="edit-head"><h3 style="margin:0">Submitted ' . e(intake_when($r['submitted_at'])) . '</h3>'
            . intake_status_pill($r['status']) . '</div>'
            . ($r['note'] ? '<p class="hint" style="margin:4px 0 0">KAM Team note: ' . e($r['note']) . '</p>' : '')
            . '<details style="margin-top:8px"><summary class="btn btn-sm btn-ghost">See the answers you sent</summary>'
            . intake_answers_html($full) . '</details></div>';
    }
    if ($cfg['intake_form_url'] !== '') {
        $out .= '<p class="hint" style="margin:10px 0 0">Something changed? <a href="' . e($cfg['intake_form_url']) . '" target="_blank" rel="noopener">Send an updated form →</a></p>';
    }
    return $out;
}

/** A Growth Partner's own record of what the clients they referred sent in. */
function intake_partner_html(string $token, array $profile): string
{
    $cfg  = intake_settings($token);
    $code = (string) ($profile['referral_code'] ?? '');
    $rows = intake_list($token, ['partner_id' => $profile['id'], 'limit' => 200]);

    $out = '<p class="hint" style="margin:0 0 10px">Every intake form submitted under your code. The client fills it in before their consultation; you see that it arrived, and where KAM Team has got to with it. You never see their budget figures or the rest of their answers.</p>';
    if ($cfg['intake_form_url'] !== '') {
        $out .= '<div class="share share-link" style="margin-bottom:12px"><input id="intake-link" type="text" readonly value="' . e($cfg['intake_form_url']) . '" aria-label="Client intake form link">'
            . '<button class="btn btn-sm btn-solid" type="button" data-copy="#intake-link">Copy form link</button></div>'
            . '<p class="hint" style="margin:-4px 0 12px">Send this to a client you are referring. Ask them to put <strong>' . e($code !== '' ? $code : 'your code') . '</strong> in "Partner ID / referral code" so the form lands under your name.</p>';
    }

    if (!$rows) {
        return $out . '<p class="empty">No intake forms under your code yet.</p>';
    }

    $out .= '<div class="table-scroll" tabindex="0" role="region" aria-label="Intake forms from your referrals"><table><thead><tr>'
        . '<th>Client</th><th>Submitted</th><th>Interested in</th><th>Consultation</th><th>Where it stands</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        $out .= '<tr><td><strong>' . e($r['full_name'] ?: '—') . '</strong>' . ($r['company'] ? '<br><span class="hint">' . e($r['company']) . '</span>' : '') . '</td>'
            . '<td style="font-size:12px">' . e(intake_when($r['submitted_at'])) . '</td>'
            . '<td style="font-size:12px">' . e($r['packages_wanted'] ?: '—') . '</td>'
            . '<td style="font-size:12px">' . e($r['consult_when'] ?: '—') . '</td>'
            . '<td>' . intake_status_pill($r['status']) . ($r['client_id'] ? '<br><span class="hint">account opened</span>' : '') . '</td></tr>';
    }
    return $out . '</tbody></table></div>';
}
