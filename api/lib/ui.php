<?php
// =====================================================================
// KAM Team // UI component layer
// ---------------------------------------------------------------------
// Presentation only: these functions render markup and know nothing about
// Supabase, permissions or money. Page files keep the data + business
// logic; everything that is "how a page looks" lives here, so a change to
// the shell, the topbar or a card is made once (SOP §2.2, §2.4).
//
// Every component returns a string — never echoes — so callers stay in
// control of the order of output.
// =====================================================================

require_once __DIR__ . '/supabase.php';
require_once __DIR__ . '/logo.php';

// ---------------------------------------------------------------------
// Assets
// ---------------------------------------------------------------------

// Cache-busts by file mtime, so a deployed CSS/JS change is picked up
// without hand-editing a ?v= string on nine pages (SOP §2.7).
function asset(string $path): string
{
    static $stamps = [];
    $path = '/' . ltrim($path, '/');
    if (!isset($stamps[$path])) {
        $file = dirname(__DIR__, 2) . $path;
        $stamps[$path] = (string) (@filemtime($file) ?: '1');
    }
    return $path . '?v=' . $stamps[$path];
}

// ---------------------------------------------------------------------
// Page shell
// ---------------------------------------------------------------------

/**
 * Opens a page: <head>, stylesheet, shared script, skip link.
 *
 * @param array{
 *   title:string, description?:string, body_class?:string,
 *   csrf?:string, idle?:bool, head?:string, refresh?:string
 * } $o  idle=true arms the inactivity watchdog (needs csrf).
 */
function ui_head(array $o): string
{
    $title   = $o['title'] ?? 'KAM Team';
    $desc    = $o['description'] ?? 'KAM Growth Partnership Program — buy KAM Team services, refer clients, and track both from one dashboard.';
    $body    = trim(($o['body_class'] ?? '') . (!empty($o['idle']) ? ' has-idle-watch' : ''));
    $idleAttr = '';

    if (!empty($o['idle'])) {
        $idleAttr = ' data-idle-timeout="' . IDLE_TIMEOUT . '" data-idle-warning="' . IDLE_WARNING . '"';
    }

    $html = '<!doctype html>' . "\n"
        . '<html lang="en">' . "\n<head>\n"
        . '  <meta charset="utf-8">' . "\n"
        . '  <meta name="viewport" content="width=device-width, initial-scale=1">' . "\n"
        . '  <meta name="color-scheme" content="light">' . "\n"
        . '  <meta name="theme-color" content="#1A1917">' . "\n"
        . '  <meta name="description" content="' . e($desc) . '">' . "\n"
        . '  <title>' . e($title) . '</title>' . "\n"
        . (isset($o['refresh']) ? '  <meta http-equiv="refresh" content="' . e($o['refresh']) . '">' . "\n" : '')
        . '  <link rel="icon" type="image/png" href="/assets/img/kam-team-mark.png">' . "\n"
        . '  <link rel="preconnect" href="https://fonts.googleapis.com">' . "\n"
        . '  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n"
        . '  <link rel="stylesheet" href="' . e(asset('/assets/styles.css')) . '">' . "\n"
        . '  <script src="' . e(asset('/assets/js/app.js')) . '" defer></script>' . "\n"
        . ($o['head'] ?? '')
        . "</head>\n"
        . '<body' . ($body !== '' ? ' class="' . e($body) . '"' : '') . $idleAttr . ">\n"
        ;

    if (!empty($o['idle']) && !empty($o['csrf'])) {
        $html .= ui_idle_watchdog($o['csrf']);
    }

    return $html;
}

// Closes a page. $scripts: extra page-specific markup before </body>.
function ui_foot(string $scripts = ''): string
{
    return $scripts . "\n</body>\n</html>\n";
}

// ---------------------------------------------------------------------
// Topbar
// ---------------------------------------------------------------------

/**
 * @param array{
 *   csrf:string, bell?:string, role?:string, role_class?:string,
 *   links?:array<array{label:string,href:string}>,
 *   switch?:array{label:string, href:string, note?:string}
 * } $o
 *
 * `switch` renders the one-and-only connective link between the Client
 * and Growth Partner sides. It lives in the topbar so the dashboards
 * themselves stay fully segregated (no cross-nav in the sidebar, no
 * account-switch tab bar inside the page).
 */
function ui_topbar(array $o): string
{
    $right = $o['bell'] ?? '';

    if (!empty($o['switch'])) {
        $sw   = $o['switch'];
        $note = !empty($sw['note']) ? '<small>' . e($sw['note']) . '</small>' : '';
        $right .= '<a class="acct-switch" href="' . e($sw['href']) . '">'
            . '<span class="acct-switch-label">' . e($sw['label']) . '</span>'
            . $note . '<span aria-hidden="true">→</span></a>';
    }

    foreach ($o['links'] ?? [] as $link) {
        if (!empty($link['post'])) {
            // POST form styled as the same small button. Used for things
            // that mutate state from the topbar (e.g. switching sessions
            // back to a base account after an admin → GP switch).
            $params = '';
            foreach ($link['params'] ?? [] as $k => $v) {
                $params .= '<input type="hidden" name="' . e($k) . '" value="' . e((string) $v) . '">';
            }
            $right .= '<form class="topbar-post" method="post" action="' . e($link['href']) . '">'
                . '<input type="hidden" name="csrf" value="' . e($link['csrf'] ?? $o['csrf']) . '">'
                . $params
                . '<button class="btn btn-ghost btn-sm" type="submit">' . e($link['label']) . '</button></form>';
        } else {
            $right .= '<a class="btn btn-ghost btn-sm" href="' . e($link['href']) . '">' . e($link['label']) . '</a>';
        }
    }

    if (!empty($o['role'])) {
        $right .= '<span class="role-label ' . e($o['role_class'] ?? '') . '">' . e($o['role']) . '</span>';
    }

    $right .= '<form class="topbar-logout" method="post" action="/logout.php">'
        . '<input type="hidden" name="csrf" value="' . e($o['csrf']) . '">'
        . '<button class="btn btn-ghost btn-sm" type="submit">Logout</button></form>';

    return '<header class="topbar">' . "\n"
        . '  <div class="topbar-inner">' . kam_lockup()
        . (!empty($o['welcome']) ? '<span class="topbar-welcome">Welcome <em>' . e((string) $o['welcome']) . '</em></span>' : '')
        . '<div class="topbar-actions">' . $right . '</div></div>' . "\n"
        . "</header>\n";
}

// ---------------------------------------------------------------------
// Feedback
// ---------------------------------------------------------------------

// One place that renders flash messages and validation errors, announced
// to screen readers as they appear (SOP §2.6).
function ui_alerts(?array $notice = null, array $errors = []): string
{
    $html = '<div class="alert-stack" role="status" aria-live="polite">';

    if ($notice) {
        $html .= '<div class="alert alert-' . e($notice['type']) . '">' . e($notice['message']) . '</div>';
    }
    if ($errors) {
        $html .= '<div class="alert alert-error" role="alert"><p class="alert-title">'
            . (count($errors) === 1 ? 'There is 1 problem to fix' : 'There are ' . count($errors) . ' problems to fix')
            . '</p><ul>';
        foreach ($errors as $err) $html .= '<li>' . e($err) . '</li>';
        $html .= '</ul></div>';
    }

    return $html . '</div>';
}

// ---------------------------------------------------------------------
// Content blocks
// ---------------------------------------------------------------------

// Page heading: kicker line + H1 with the serif accent half.
function ui_page_title(string $kicker, string $title, string $accent = '', string $dotColor = ''): string
{
    return '<span class="label"><span class="dot"' . ($dotColor ? ' style="background:' . e($dotColor) . '"' : '') . '></span>' . $kicker . '</span>'
        . '<h1>' . e($title) . ($accent !== '' ? ' <span class="accent">' . e($accent) . '</span>' : '') . '</h1>';
}

/**
 * Stat tiles. Each: ['label' => string, 'value' => string, 'tone' => ''|'cyan'|'orange'|'green', 'note' => ?string]
 * Numbered 01 // … automatically so the sequence never drifts out of order.
 */
function ui_stat_grid(array $stats, string $id = ''): string
{
    $cards = '';
    foreach (array_values($stats) as $i => $s) {
        $cards .= '<div class="card stat-card">'
            . '<span class="label">' . str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) . ' // ' . e($s['label']) . '</span>'
            . '<div class="big ' . e($s['tone'] ?? '') . '">' . e((string) $s['value']) . '</div>'
            . (isset($s['note']) ? '<span class="stat-note">' . $s['note'] . '</span>' : '')
            . '</div>';
    }
    return '<section class="grid"' . ($id ? ' id="' . e($id) . '"' : '') . ' aria-label="Summary">' . $cards . '</section>';
}

// Terminal-styled section header used across the dashboards.
function ui_terminal_head(string $caption): string
{
    return '<div class="terminal-header"><span class="dots" aria-hidden="true"><span></span><span></span><span></span></span><span>' . e($caption) . '</span></div>';
}

function ui_empty(string $message): string
{
    return '<p class="empty">' . e($message) . '</p>';
}

// Horizontally scrollable table wrapper — keyboard focusable so the scroll
// is reachable without a pointer, and labelled for screen readers.
function ui_table(string $inner, string $label = 'Data table'): string
{
    return '<div class="table-scroll" tabindex="0" role="region" aria-label="' . e($label) . '">' . $inner . '</div>';
}

// ---------------------------------------------------------------------
// Inactivity watchdog
// ---------------------------------------------------------------------

// The markup half of the 5-minute idle sign-out. assets/js/app.js drives it;
// api/lib/supabase.php enforces it server-side whatever the browser does.
function ui_idle_watchdog(string $csrf): string
{
    $mins = (int) round(IDLE_TIMEOUT / 60);

    return '<form id="idle-logout" method="post" action="/logout.php" hidden>'
        . '<input type="hidden" name="csrf" value="' . e($csrf) . '">'
        . '<input type="hidden" name="reason" value="timeout"></form>' . "\n"
        . '<div class="idle-dialog" id="idle-dialog" role="alertdialog" aria-modal="true"'
        . ' aria-labelledby="idle-title" aria-describedby="idle-desc" hidden>' . "\n"
        . '  <div class="idle-card">'
        . '<span class="label"><span class="dot pulse"></span>Session about to expire</span>'
        . '<h2 id="idle-title">Still there?</h2>'
        . '<p id="idle-desc">For your security you are signed out after ' . $mins . ' minutes without activity. '
        . 'You will be signed out in <strong><span data-idle-countdown>60</span>s</strong>.</p>'
        . '<div class="idle-actions">'
        . '<button type="button" class="btn btn-solid" data-idle-stay>Stay signed in</button>'
        . '<button type="button" class="btn btn-ghost" data-idle-out>Sign out now</button>'
        . '</div></div>' . "\n"
        . "</div>\n";
}
