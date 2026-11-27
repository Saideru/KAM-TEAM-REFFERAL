<?php
// =====================================================================
// KAM Team // Chat
// ---------------------------------------------------------------------
// One private conversation per pair of accounts, and a fixed rule about
// which pairs may exist at all:
//
//   Admin          <-> Client            always
//   Admin          <-> Staff             always
//   Admin          <-> Growth Partner    always
//   Staff          <-> Client            only when an admin assigned them
//   Staff          <-> Growth Partner    only when an admin assigned them
//
// Everything else (client <-> growth partner, admin <-> admin, staff <->
// staff) has no conversation: it is not offered, and a posted message is
// refused. Assignments live in staff_assignments and are managed by
// admins in the Assignments panel (migration 20261209_chat.sql).
//
// Access is decided here, in PHP, with the service key — the same way the
// rest of the app works (api/lib/supabase.php).
// =====================================================================

require_once __DIR__ . '/supabase.php';

const CHAT_BODY_MAX = 4000;

// Roles that can own a conversation, and the member roles an admin
// assigns staff to.
const CHAT_ROLES   = ['admin', 'staff', 'client', 'growth_partner'];
const CHAT_MEMBERS = ['client', 'growth_partner'];

// ---------------------------------------------------------------------
// The rule table
// ---------------------------------------------------------------------

// Legacy rows (role 'affiliate', pre-migration) behave like a client.
function chat_role(?array $profile): string
{
    $role = (string) ($profile['role'] ?? '');
    return $role === 'affiliate' ? 'client' : $role;
}

// 'open' (anyone, any time) | 'assigned' (needs a staff_assignments row)
// | null (no conversation exists between these two roles).
function chat_pair_rule(string $roleA, string $roleB): ?string
{
    $pair = [$roleA, $roleB];
    sort($pair);
    return [
        'admin|client'         => 'open',
        'admin|growth_partner' => 'open',
        'admin|staff'          => 'open',
        'client|staff'         => 'assigned',
        'growth_partner|staff' => 'assigned',
    ][implode('|', $pair)] ?? null;
}

// An account that can be reached at all: approved, not in the recycle bin.
function chat_account_open(?array $profile): bool
{
    if (!$profile || !in_array(chat_role($profile), CHAT_ROLES, true)) return false;
    if (!empty($profile['deleted_at'])) return false;
    return is_team($profile) || ($profile['status'] ?? '') === 'accepted';
}

// Is this staff member assigned to this client / growth partner?
function chat_is_assigned(string $token, string $staffId, string $memberId): bool
{
    $res = supabase_request('GET', '/rest/v1/staff_assignments?select=id&staff_id=eq.'
        . urlencode($staffId) . '&member_id=eq.' . urlencode($memberId) . '&limit=1', null, $token);
    return !$res['error'] && !empty($res['body']);
}

/**
 * May these two accounts hold a conversation?
 * Returns null when they may, or the reason why they may not.
 */
function chat_blocked_reason(string $token, array $me, array $peer): ?string
{
    if (($me['id'] ?? '') === ($peer['id'] ?? '')) return 'You cannot message yourself.';
    if (!chat_account_open($me) || !chat_account_open($peer)) return 'That account is not available for messages.';
    // An admin and their own linked Growth Partner row are one person.
    if (!empty($me['linked_profile_id']) && $me['linked_profile_id'] === $peer['id']) return 'That is your own other account.';

    $rule = chat_pair_rule(chat_role($me), chat_role($peer));
    if ($rule === null) return 'Messages are not open between these two account types.';
    if ($rule === 'open') return null;

    // 'assigned': exactly one side is staff.
    $staff  = chat_role($me) === 'staff' ? $me : $peer;
    $member = chat_role($me) === 'staff' ? $peer : $me;
    return chat_is_assigned($token, $staff['id'], $member['id'])
        ? null
        : 'An admin has to assign this staff member to this account before they can message each other.';
}

function chat_can(string $token, array $me, array $peer): bool
{
    return chat_blocked_reason($token, $me, $peer) === null;
}

// ---------------------------------------------------------------------
// Contacts
// ---------------------------------------------------------------------

// Every account that can hold a conversation, keyed by id. One query: the
// contact list, the role rules and the "is this a team partner row" test
// all read from it.
function chat_directory(string $token): array
{
    static $dir = null;
    if ($dir !== null) return $dir;

    $res = supabase_request('GET', '/rest/v1/profiles?select=id,full_name,username,company,role,status,referral_code,linked_profile_id,deleted_at'
        . '&role=in.(' . implode(',', CHAT_ROLES) . ')&deleted_at=is.null&order=full_name.asc', null, $token);
    $rows = (!$res['error'] && is_array($res['body'])) ? $res['body'] : [];

    $dir = [];
    foreach ($rows as $row) if (chat_account_open($row)) $dir[$row['id']] = $row;
    return $dir;
}

// A growth_partner row that belongs to an admin or a staff member is that
// person's own second account, not someone to talk to.
function chat_is_team_partner_row(array $dir, array $row): bool
{
    return chat_role($row) === 'growth_partner'
        && !empty($row['linked_profile_id'])
        && in_array(chat_role($dir[$row['linked_profile_id']] ?? null), ['admin', 'staff'], true);
}

// The ids this staff member is assigned to, or the staff assigned to this
// member — whichever way round the caller is.
function chat_assigned_ids(string $token, array $me): array
{
    $col = chat_role($me) === 'staff' ? 'staff_id' : 'member_id';
    $sel = $col === 'staff_id' ? 'member_id' : 'staff_id';
    $res = supabase_request('GET', '/rest/v1/staff_assignments?select=' . $sel . '&' . $col . '=eq.' . urlencode($me['id']), null, $token);
    return (!$res['error'] && is_array($res['body'])) ? array_column($res['body'], $sel) : [];
}

/**
 * Who this account may message, newest conversation first.
 * Each entry: profile row + thread (or null) + unread + last message.
 */
function chat_contacts(string $token, array $me): array
{
    $dir  = chat_directory($token);
    $role = chat_role($me);
    $out  = [];

    if ($role === 'admin') {
        // Admins reach every client, Growth Partner and staff member.
        foreach ($dir as $row) {
            if ($row['id'] === $me['id']) continue;
            if (!in_array(chat_role($row), ['staff', 'client', 'growth_partner'], true)) continue;
            if (chat_is_team_partner_row($dir, $row)) continue;
            $out[$row['id']] = $row;
        }
    } else {
        // Staff, clients and Growth Partners always reach the admins, plus
        // whoever an admin assigned them to.
        foreach ($dir as $row) {
            if (chat_role($row) === 'admin' && $row['id'] !== $me['id']) $out[$row['id']] = $row;
        }
        foreach (chat_assigned_ids($token, $me) as $id) {
            if (isset($dir[$id]) && $id !== $me['id'] && chat_pair_rule($role, chat_role($dir[$id])) !== null) {
                $out[$id] = $dir[$id];
            }
        }
    }

    // An admin viewing their own linked Growth Partner row is still one person.
    if (!empty($me['linked_profile_id'])) unset($out[$me['linked_profile_id']]);

    // Hang each contact's conversation off it.
    $threads = chat_my_threads($token, $me['id']);
    $stats   = chat_thread_stats($token, array_column($threads, 'id'), $me['id']);
    $byPeer  = [];
    foreach ($threads as $t) {
        $peer = $t['a_id'] === $me['id'] ? $t['b_id'] : $t['a_id'];
        $byPeer[$peer] = $t;
    }

    $list = [];
    foreach ($out as $id => $row) {
        $t = $byPeer[$id] ?? null;
        $list[] = [
            'profile' => $row,
            'thread'  => $t,
            'unread'  => $t ? (int) ($stats[$t['id']]['unread'] ?? 0) : 0,
            'last'    => $t ? ($stats[$t['id']]['last'] ?? null) : null,
            'at'      => $t['last_message_at'] ?? null,
        ];
    }

    // Live conversations first (most recent on top), then the rest A-Z.
    usort($list, function ($x, $y) {
        if (($x['at'] === null) !== ($y['at'] === null)) return $x['at'] === null ? 1 : -1;
        if ($x['at'] !== null) return strcmp((string) $y['at'], (string) $x['at']);
        return strcasecmp((string) $x['profile']['full_name'], (string) $y['profile']['full_name']);
    });
    return $list;
}

// ---------------------------------------------------------------------
// Threads and messages
// ---------------------------------------------------------------------

function chat_my_threads(string $token, string $userId): array
{
    $res = supabase_request('GET', '/rest/v1/chat_threads?select=id,a_id,b_id,last_message_at'
        . '&or=(a_id.eq.' . urlencode($userId) . ',b_id.eq.' . urlencode($userId) . ')'
        . '&order=last_message_at.desc.nullslast', null, $token);
    return (!$res['error'] && is_array($res['body'])) ? $res['body'] : [];
}

// Unread count and last message for each of the given threads, in one go.
function chat_thread_stats(string $token, array $threadIds, string $meId): array
{
    if (!$threadIds) return [];
    $res = supabase_request('GET', '/rest/v1/chat_messages?select=thread_id,sender_id,body,created_at,read_at'
        . '&thread_id=in.(' . implode(',', array_map('urlencode', $threadIds)) . ')'
        . '&order=created_at.desc&limit=500', null, $token);
    $rows = (!$res['error'] && is_array($res['body'])) ? $res['body'] : [];

    $stats = [];
    foreach ($rows as $m) {
        $tid = $m['thread_id'];
        if (!isset($stats[$tid])) $stats[$tid] = ['unread' => 0, 'last' => $m];
        if ($m['read_at'] === null && $m['sender_id'] !== $meId) $stats[$tid]['unread']++;
    }
    return $stats;
}

// The conversation between two accounts. $create opens one on first message.
function chat_thread(string $token, string $idA, string $idB, bool $create = false): ?array
{
    [$a, $b] = $idA < $idB ? [$idA, $idB] : [$idB, $idA];
    $find = fn() => supabase_request('GET', '/rest/v1/chat_threads?select=*&a_id=eq.' . urlencode($a)
        . '&b_id=eq.' . urlencode($b) . '&limit=1', null, $token);

    $res = $find();
    if (!$res['error'] && !empty($res['body'][0])) return $res['body'][0];
    if (!$create) return null;

    $new = supabase_request('POST', '/rest/v1/chat_threads', ['a_id' => $a, 'b_id' => $b], $token, ['Prefer: return=representation']);
    if (!$new['error'] && !empty($new['body'][0])) return $new['body'][0];

    // Lost a race with another request: the row exists now.
    return $find()['body'][0] ?? null;
}

function chat_messages(string $token, string $threadId, int $limit = 200): array
{
    $res = supabase_request('GET', '/rest/v1/chat_messages?select=id,sender_id,body,created_at,read_at&thread_id=eq.'
        . urlencode($threadId) . '&order=created_at.desc&limit=' . $limit, null, $token);
    $rows = (!$res['error'] && is_array($res['body'])) ? $res['body'] : [];
    return array_reverse($rows);
}

// Everything the other side wrote in this thread is now read.
function chat_mark_read(string $token, string $threadId, string $meId): void
{
    supabase_request('PATCH', '/rest/v1/chat_messages?thread_id=eq.' . urlencode($threadId)
        . '&sender_id=neq.' . urlencode($meId) . '&read_at=is.null',
        ['read_at' => date('c')], $token, ['Prefer: return=minimal']);
}

// Unread messages across every conversation — the sidebar badge.
function chat_unread_total(string $token, array $me): int
{
    $threads = chat_my_threads($token, $me['id']);
    if (!$threads) return 0;
    $stats = chat_thread_stats($token, array_column($threads, 'id'), $me['id']);
    return (int) array_sum(array_column($stats, 'unread'));
}

// Where this role reads its messages (used for notification links).
function chat_inbox_url(string $role, ?string $peerId = null): string
{
    $peer = $peerId ? urlencode($peerId) : null;
    switch ($role === 'affiliate' ? 'client' : $role) {
        case 'admin':          return '/dashboard-admin.php' . ($peer ? '?peer=' . $peer : '') . '#messages';
        case 'staff':          return '/work.php?view=messages' . ($peer ? '&peer=' . $peer : '');
        case 'growth_partner': return '/dashboard-affiliate.php?view=messages' . ($peer ? '&peer=' . $peer : '');
        default:               return '/dashboard-client.php' . ($peer ? '?peer=' . $peer : '') . '#messages';
    }
}

/** Sends a message. Returns an error message, or null on success. */
function chat_send(string $token, array $me, array $peer, string $body): ?string
{
    $body = trim(mb_substr($body, 0, CHAT_BODY_MAX));
    if ($body === '') return 'Write a message first.';
    if ($reason = chat_blocked_reason($token, $me, $peer)) return $reason;

    $thread = chat_thread($token, $me['id'], $peer['id'], true);
    if (!$thread) return 'Could not open that conversation. Please try again.';

    $res = supabase_request('POST', '/rest/v1/chat_messages',
        ['thread_id' => $thread['id'], 'sender_id' => $me['id'], 'body' => $body], $token, ['Prefer: return=minimal']);
    if ($res['error']) return db_error_message($res['error']) ?: 'Could not send that message.';

    notify($token, 'New message from ' . ($me['full_name'] ?? 'KAM Team'),
        mb_substr($body, 0, 140), $peer['id'], 'all', chat_inbox_url(chat_role($peer), $me['id']));
    return null;
}

// ---------------------------------------------------------------------
// POST handlers
// ---------------------------------------------------------------------

/**
 * Handles the composer on every dashboard. Call it after csrf_check().
 * Returns null when the request was not a chat post, else [error, success].
 */
function chat_action(string $token, array $me): ?array
{
    if (($_POST['chat_action'] ?? '') !== 'send') return null;

    $peerId = (string) ($_POST['peer_id'] ?? '');
    $peer   = chat_directory($token)[$peerId] ?? null;
    if (!$peer) return ['That account is not available for messages.', null];

    $err = chat_send($token, $me, $peer, (string) ($_POST['body'] ?? ''));
    return $err ? [$err, null] : [null, 'Message sent.'];
}

/**
 * Admin only: assign a staff member to a client / Growth Partner, or take
 * the assignment away. Returns null when it was not an assignment post.
 */
function chat_assign_action(string $token, array $admin): ?array
{
    $act = (string) ($_POST['assign_action'] ?? '');
    if (!in_array($act, ['assign', 'unassign'], true)) return null;
    if (chat_role($admin) !== 'admin') return ['Only an admin can change assignments.', null];

    $dir      = chat_directory($token);
    $staffId  = (string) ($_POST['staff_id'] ?? '');
    $memberId = (string) ($_POST['member_id'] ?? '');
    $staff    = $dir[$staffId] ?? null;
    $member   = $dir[$memberId] ?? null;

    if (!$staff || chat_role($staff) !== 'staff') return ['Choose a staff member.', null];
    if (!$member || !in_array(chat_role($member), CHAT_MEMBERS, true)) return ['Choose a client or Growth Partner.', null];

    if ($act === 'unassign') {
        $res = supabase_request('DELETE', '/rest/v1/staff_assignments?staff_id=eq.' . urlencode($staffId)
            . '&member_id=eq.' . urlencode($memberId), null, $token, ['Prefer: return=minimal']);
        if ($res['error']) return [db_error_message($res['error']), null];
        audit_log($token, 'staff_unassigned', 'profile', $memberId,
            $staff['full_name'] . ' is no longer assigned to ' . $member['full_name']);
        return [null, $staff['full_name'] . ' is no longer assigned to ' . $member['full_name'] . '.'];
    }

    $res = supabase_request('POST', '/rest/v1/staff_assignments',
        ['staff_id' => $staffId, 'member_id' => $memberId, 'assigned_by' => $admin['id']], $token, ['Prefer: return=minimal']);
    if ($res['status'] === 409) return [null, $staff['full_name'] . ' was already assigned to ' . $member['full_name'] . '.'];
    if ($res['error']) return [db_error_message($res['error']), null];

    audit_log($token, 'staff_assigned', 'profile', $memberId,
        $staff['full_name'] . ' assigned to ' . $member['full_name'] . ' (' . role_name(chat_role($member)) . ')');
    notify($token, 'Your KAM Team contact', $staff['full_name'] . ' is now your contact — you can message them from your dashboard.',
        $memberId, 'all', chat_inbox_url(chat_role($member), $staffId));
    notify($token, 'New account assigned to you', 'You are now the contact for ' . $member['full_name'] . '.',
        $staffId, 'all', chat_inbox_url('staff', $memberId));
    return [null, $staff['full_name'] . ' now handles ' . $member['full_name'] . '.'];
}

// ---------------------------------------------------------------------
// Rendering
// ---------------------------------------------------------------------

function chat_role_pill(array $profile): string
{
    $role = chat_role($profile);
    return '<span class="role-badge ' . e($role) . '">' . e(role_name($role)) . '</span>';
}

function chat_when(string $ts): string
{
    $t = strtotime($ts);
    return date('Y-m-d', $t) === date('Y-m-d') ? date('H:i', $t) : date('M j', $t);
}

// The ?peer= the pages pass to chat_panel_html, taken safely off the query
// string (a crafted ?peer[]= is simply "nobody picked").
function chat_peer_param(): ?string
{
    $peer = $_GET['peer'] ?? null;
    return is_string($peer) && $peer !== '' ? $peer : null;
}

function chat_initial(?string $name): string
{
    return e(mb_strtoupper(mb_substr(trim((string) $name) !== '' ? (string) $name : '?', 0, 1)));
}

/**
 * The whole Messages page: contact list, conversation, composer.
 *
 * @param string $base  this page's own URL ('/work.php?view=messages')
 * @param string $hash  anchor appended to contact links ('#messages' on the
 *                      single-page client dashboard, '' elsewhere)
 */
function chat_panel_html(string $token, array $me, string $csrf, string $base, ?string $peerId = null, string $hash = ''): string
{
    $contacts = chat_contacts($token, $me);
    $link     = fn(string $id): string => $base . (str_contains($base, '?') ? '&' : '?') . 'peer=' . urlencode($id) . $hash;

    $peer = null;
    foreach ($contacts as $c) if ($c['profile']['id'] === $peerId) $peer = $c;
    // Nobody picked yet: open the most recent conversation.
    if (!$peer && ($peerId === null || $peerId === '') && $contacts && $contacts[0]['thread']) $peer = $contacts[0];

    // The list -------------------------------------------------------
    $rows = '';
    foreach ($contacts as $c) {
        $p   = $c['profile'];
        $on  = $peer && $p['id'] === $peer['profile']['id'];
        $sub = $c['last']
            ? (($c['last']['sender_id'] === $me['id'] ? 'You: ' : '') . mb_substr((string) $c['last']['body'], 0, 60))
            : role_name(chat_role($p)) . (!empty($p['company']) ? ' · ' . $p['company'] : '');
        $rows .= '<a class="chat-contact' . ($on ? ' is-on' : '') . '" href="' . e($link($p['id'])) . '">'
            . '<span class="chat-avatar" aria-hidden="true">' . chat_initial($p['full_name'] ?? '') . '</span>'
            . '<span class="chat-contact-text"><strong>' . e($p['full_name']) . '</strong><span>' . e($sub) . '</span></span>'
            . ($c['unread'] ? '<span class="chat-badge">' . (int) $c['unread'] . '</span>'
                : ($c['at'] ? '<time>' . e(chat_when($c['at'])) . '</time>' : ''))
            . '</a>';
    }
    if (!$rows) {
        $rows = '<p class="empty" style="margin:12px">' . (chat_role($me) === 'staff'
            ? 'No one to message yet. An admin assigns the clients and Growth Partners you look after.'
            : 'No one to message yet.') . '</p>';
    }

    $list = '<div class="chat-list" data-live="chat-contacts">'
        . '<div class="chat-list-head"><span class="label"><span class="dot"></span>Conversations</span></div>'
        . $rows . '</div>';

    // The conversation ----------------------------------------------
    if (!$peer) {
        return '<div class="chat">' . $list
            . '<div class="chat-main"><p class="empty" style="margin:auto">Pick a name on the left to start a conversation.</p></div></div>';
    }

    $p      = $peer['profile'];
    $thread = $peer['thread'] ?: null;
    $msgs   = $thread ? chat_messages($token, $thread['id']) : [];
    if ($thread && $peer['unread']) chat_mark_read($token, $thread['id'], $me['id']);

    $log = '';
    $day = '';
    foreach ($msgs as $m) {
        $d = date('Y-m-d', strtotime($m['created_at']));
        if ($d !== $day) {
            $day  = $d;
            $log .= '<div class="chat-day"><span>' . e(date('M j, Y', strtotime($m['created_at']))) . '</span></div>';
        }
        $mine = $m['sender_id'] === $me['id'];
        $log .= '<div class="chat-msg' . ($mine ? ' is-mine' : '') . '">'
            . '<div class="chat-bubble">' . nl2br(e((string) $m['body'])) . '</div>'
            . '<time>' . e(date('H:i', strtotime($m['created_at']))) . ($mine && $m['read_at'] ? ' · read' : '') . '</time>'
            . '</div>';
    }
    if (!$log) $log = '<p class="empty" style="margin:auto">No messages yet. Say hello.</p>';

    $why = chat_pair_rule(chat_role($me), chat_role($p)) === 'assigned'
        ? '<span class="hint">Assigned by an admin</span>' : '';

    $main = '<div class="chat-main">'
        . '<div class="chat-head">'
        .   '<span class="chat-avatar" aria-hidden="true">' . chat_initial($p['full_name'] ?? '') . '</span>'
        .   '<div class="chat-head-text"><strong>' . e($p['full_name']) . '</strong>' . $why . '</div>'
        .   chat_role_pill($p)
        . '</div>'
        . '<div class="chat-log" data-live="chat-log">' . $log . '</div>'
        . '<form class="chat-composer" method="post" action="' . e($link($p['id'])) . '">'
        .   '<input type="hidden" name="csrf" value="' . e($csrf) . '">'
        .   '<input type="hidden" name="chat_action" value="send">'
        .   '<input type="hidden" name="peer_id" value="' . e($p['id']) . '">'
        .   '<textarea name="body" rows="2" maxlength="' . CHAT_BODY_MAX . '" required'
        .     ' placeholder="Write a message to ' . e(explode(' ', (string) $p['full_name'])[0]) . '…"></textarea>'
        .   '<button class="btn btn-solid btn-sm" type="submit">Send →</button>'
        . '</form>'
        . '</div>';

    return '<div class="chat">' . $list . $main . '</div>';
}

/**
 * Admin only: who looks after whom. This is the one place assignments are
 * changed — staff and members only see the result.
 */
function chat_assignments_html(string $token, array $admin, string $csrf): string
{
    $dir = chat_directory($token);
    $res = supabase_request('GET', '/rest/v1/staff_assignments?select=id,staff_id,member_id,created_at&order=created_at.desc', null, $token);
    $all = (!$res['error'] && is_array($res['body'])) ? $res['body'] : [];

    $staff   = array_filter($dir, fn($p) => chat_role($p) === 'staff');
    $members = array_filter($dir, fn($p) => in_array(chat_role($p), CHAT_MEMBERS, true) && !chat_is_team_partner_row($dir, $p));

    $byStaff = [];
    foreach ($all as $a) $byStaff[$a['staff_id']][] = $a;

    $opts = function (array $rows, bool $withRole = false): string {
        $out = '';
        foreach ($rows as $r) {
            $out .= '<option value="' . e($r['id']) . '">' . e($r['full_name'])
                . ($withRole ? ' — ' . e(role_name(chat_role($r))) : '') . '</option>';
        }
        return $out;
    };
    $hid = fn(array $f) => '<input type="hidden" name="csrf" value="' . e($csrf) . '">'
        . implode('', array_map(fn($k, $v) => '<input type="hidden" name="' . e($k) . '" value="' . e((string) $v) . '">', array_keys($f), $f));

    $out = '<section class="card" style="margin-bottom:12px">'
        . '<h2>Assign staff to accounts</h2>'
        . '<p class="hint" style="margin:0 0 10px">A staff member can only message the clients and Growth Partners you assign to them, and those accounts can only message their assigned staff. Admins reach everyone either way.</p>';

    if (!$staff || !$members) {
        $out .= '<p class="empty">' . (!$staff
            ? 'No staff accounts yet — register one in Users.'
            : 'No approved clients or Growth Partners yet.') . '</p>';
    } else {
        $out .= '<form method="post" class="inline-form" style="flex-wrap:wrap;gap:8px">' . $hid(['assign_action' => 'assign'])
            . '<select name="staff_id" required aria-label="Staff member"><option value="">Staff member…</option>' . $opts($staff) . '</select>'
            . '<select name="member_id" required aria-label="Client or Growth Partner"><option value="">Client or Growth Partner…</option>' . $opts($members, true) . '</select>'
            . '<button class="btn btn-sm btn-solid" type="submit">Assign →</button></form>';
    }
    $out .= '</section><section class="card"><h2>Current assignments</h2>';

    if (!$staff) {
        $out .= '<p class="empty">No staff accounts yet.</p>';
    } else {
        foreach ($staff as $s) {
            $mine = $byStaff[$s['id']] ?? [];
            $out .= '<div class="assign-row"><div class="assign-staff"><strong>' . e($s['full_name']) . '</strong>'
                . '<span class="hint">' . count($mine) . ' account' . (count($mine) === 1 ? '' : 's') . '</span></div>'
                . '<div class="assign-tags">';
            if (!$mine) {
                $out .= '<span class="hint">No accounts assigned.</span>';
            } else {
                foreach ($mine as $a) {
                    $m = $dir[$a['member_id']] ?? null;
                    $out .= '<form method="post" class="assign-tag">'
                        . $hid(['assign_action' => 'unassign', 'staff_id' => $s['id'], 'member_id' => $a['member_id']])
                        . '<span>' . e($m['full_name'] ?? 'Removed account')
                        . '<small>' . e($m ? role_name(chat_role($m)) : '—') . '</small></span>'
                        . '<button class="assign-x" type="submit" title="Remove assignment" aria-label="Remove assignment">&times;</button></form>';
                }
            }
            $out .= '</div></div>';
        }
    }
    return $out . '</section>';
}
