<?php
// =====================================================================
// Program reference — what each kind of account can do.
//
// Rendered inside the app at /report.php?doc=components (admins and
// staff), where "Print / save as PDF" turns it into the handout in
// docs/KAM-Team-Program-Components.pdf.
//
// TO UPDATE: edit the arrays below. Bump PROGRAM_DOC_VERSION / _DATE
// whenever the components themselves change.
// =====================================================================

const PROGRAM_DOC_TITLE   = 'Program components by account type';
const PROGRAM_DOC_VERSION = '1.3';
const PROGRAM_DOC_DATE    = 'November 21, 2026';

// [ id, title, subtitle, intro, rows => [[component, what it does], …], note ]
function program_doc_sections(): array
{
    return [
        [
            'id'       => 'accounts',
            'title'    => 'Account types',
            'subtitle' => 'Who exists in the program',
            'intro'    => 'Four account TYPES, each a separate row. Admins and staff are KAM TEAM\'s own people and are registered by an admin. Clients and Growth Partners sign up through the public form. One person can own more than one row — e.g. a Client who also wants to refer gets a second, linked Growth Partner row. Linked rows share a session via a one-click switch.',
            'rows'     => [
                ['Admin', 'Runs the program. Full access to every panel. Carries no referral code on this row — their partner side lives on a dedicated linked Growth Partner row. The <strong>main admin</strong> (<code>admin</code>) is linked to <code>admin-gp</code>, which holds <code>KT-000001</code> at tier Unlimited and sits first in the roulette basket.'],
                ['Staff / employee', 'Does client work. Lands in Client work and nothing else: no program terms to accept, no expiry, no recycle bin.'],
                ['Client', 'Buys KAM TEAM services (50% advance deposit to start). Permanent — no expiry, no plan, no commission side. Can create a linked Growth Partner account later if they want to refer.'],
                ['Growth Partner', 'Refers clients, earns commission. Must carry an upline\'s referral code at sign-up. Chooses Basic (free, 3-month term) or Standard ($100, 2 years + 3-month bonus). Does not buy services on this row. Can create a linked Client account later if they want services.'],
            ],
            'note'     => 'Only the Growth Partner row carries a term. The Client row never expires, so an expired partner term stops commission but never locks anyone out of their Client account.',
        ],
        [
            'id'       => 'shared',
            'title'    => 'Shared — everyone',
            'subtitle' => 'Sign-up, access and security',
            'intro'    => '',
            'rows'     => [
                ['Registration — pick your type', 'A choice at the top of the form: <strong>Client</strong> creates a Client row and nothing else; <strong>Growth Partner</strong> creates a Growth Partner row and nothing else. Each is its own account. A linked companion row can be added later from the matching dashboard.'],
                ['Session switching', 'When two accounts are linked, the dashboard shows a one-click switch to the other. The current user_id flips on the server, the original login is remembered as the "base", and "Back to [other account]" always returns to it. CSRF-protected; only linked pairs can be swapped into.'],
                ['Referral code', 'Required for Growth Partner sign-ups — every new partner must enter the code of the partner who invited them, so each new partner joins an existing downline. The field is hidden for Client sign-ups and any value posted is ignored. A visitor landing on <code>?ref=KT-XXXXXX</code> is pre-selected as Growth Partner.'],
                ['Rules and guidelines', 'Every manual section is listed by title and opens in one popup. The single acceptance box unlocks only once the popup has been read to the end, and the server refuses a sign-up without it.'],
                ['Approval wall', 'A new account waits on a holding page until an admin approves it. The page rechecks every minute and shows the referrer\'s code and name so you can confirm the right upline was recorded.'],
                ['Terms re-acceptance', 'When the manual version changes, members must accept the new version before any dashboard opens.'],
                ['Plan gate', 'After approval, a member picks Basic or Standard before the Growth Partner dashboard opens. Verify ID stays reachable, since Standard needs approved identity documents.'],
                ['Notifications', 'A bell in the top bar with unread count: approvals, payments, commissions, reports, expiry reminders.'],
                ['Identity (KYC)', 'Upload identity documents; an admin approves or rejects with a note.'],
                ['Inactivity sign-out', 'Every signed-in account is signed out automatically after <strong>5 minutes without activity</strong>. A warning dialog appears 60 seconds before the cut-off with "Stay signed in" and "Sign out now" buttons; activity in any open tab counts for all of them.'],
                ['Sign-in security', 'The session cookie is AES-256-GCM sealed and tamper-checked, HttpOnly, SameSite=Lax, Secure over HTTPS, and expires 30 days after sign-in. Signed-in pages are never kept in the browser cache, so Back after logout lands on the sign-in page. Every state-changing request carries a CSRF token.'],
            ],
            'note'     => '',
        ],
        [
            'id'       => 'admin',
            'title'    => 'Admin',
            'subtitle' => '/dashboard-admin.php',
            'intro'    => 'Twelve in-page panels, driven by the sidebar. Only Admins see this dashboard.',
            'rows'     => [
                ['Users', 'Every live account: role, status, lifecycle, referral code and account mode (Client / Growth Partner only). Edit details, password, business profile, Growth Partner plan and term. Change role or status, or trash an account into the recycle bin.'],
                ['+ Register staff / employee', 'Creates an approved Staff or Admin account on the spot — no application, no program terms, no expiry.'],
                ['Approvals', 'Account applications (approve or reject with a reason) and payments to verify: Growth Partner Standard plans and client 50% deposits. Unread count shown as a sidebar badge.'],
                ['Roulette', 'Round-robin assignment of each approved account to an admin, who keeps it for good. Admins can be paused. The main admin (<code>admin</code>) sits at position 0.'],
                ['KYC', 'Identity queue: approve or reject with a note. Badge counts pending submissions.'],
                ['Payment', 'The payment QR code, account details and instructions that members pay against.'],
                ['Referral network', 'Every referrer → referred pair, with level and status. Rooted at <code>KT-000001</code>.'],
                ['Packages', 'Create and edit Growth Partner plans and client service packages (one-time price, monthly fee, commission). Edits are scheduled 1–7 days ahead and announced to everyone.'],
                ['Transactions', 'All activations, deposits, commissions and payouts.'],
                ['Services', 'Per-client service records.'],
                ['Notify', 'Broadcast to Everyone, Members, Staff or Admins.'],
                ['Audit logs', 'Categorised: Login &amp; terms, Client task log, Weekly/monthly reports, Accounts &amp; approvals, Payments &amp; commissions, KYC, Packages, Roulette.'],
                ['Recycle bin', 'Admin-only. Deleted accounts, cancelled or rejected payments, cancelled client tasks and retired packages — each with Restore. Nothing is erased.'],
                ['Client work →', 'Everything staff can do, plus approving and delivering client reports.'],
                ['My referral account →', 'The admin\'s own Growth Partner account: referral code and link, commission ledger and downline. Tier <strong>Unlimited</strong> — no plan to buy, nothing to renew, no expiry.'],
                ['Program docs →', 'This document.'],
            ],
            'note'     => 'A restored account returns as Pending, so it goes back through Approvals. A restored payment returns to pending in Approvals; a restored task returns as In Progress with its log intact.',
        ],
        [
            'id'       => 'staff',
            'title'    => 'Staff / employee',
            'subtitle' => '/work.php — Client Work Documentation &amp; Reporting (SOP-OPS-001)',
            'intro'    => '',
            'rows'     => [
                ['Task tracker', 'Every client task, filtered by client and by the seven SOP statuses, with counts.'],
                ['New task (intake)', 'Client, service package, title, scope reference, milestone, target date, and assignee and reviewer tags.'],
                ['Task record', 'The §5 mandatory fields: work performed, deliverable, evidence, blockers, and next action, owner and date.'],
                ['Task log', 'Dated entries — update, evidence, blocker, completion note — each with a "by" tag.'],
                ['Status changes', 'The SOP is enforced: Completed requires For Review first, plus a reviewer, evidence and a completion date.'],
                ['Milestones', 'Reconciled from the tasks underneath them: Completed only when every live task is verified.'],
                ['Client reports', 'Draft weekly, monthly or milestone reports compiled from verified tasks only, with the §12 QA checklist and the §13 file name.'],
                ['Printable report', 'Open and print any version.'],
                ['Role tags', 'Assignee, reviewer, coordinator, prepared/verified/approved by are name tags for the record — they are a formality, not permissions.'],
            ],
            'note'     => 'Staff cannot approve or deliver a report — they see "An admin approves the report once the QA checklist is complete." The admin dashboard and the recycle bin are not reachable.',
        ],
        [
            'id'       => 'client',
            'title'    => 'Member — Client side',
            'subtitle' => '/dashboard-client.php',
            'intro'    => 'Opens for Client sign-ups the moment an admin approves them, and for affiliate-only members after they click "Activate Client account" on the Growth Partner dashboard. The sidebar is purely Client content — no referral code, no commissions, no partner tier. The only link to the Growth Partner side is a single pill-shaped switch in the top bar.',
            'rows'     => [
                ['Overview', 'Active packages, tasks in progress, tasks completed, reports, and the member\'s assigned KAM TEAM contact.'],
                ['Service packages', 'Every active KAM TEAM package with one-time and monthly prices. Pay the 50% advance deposit by QR, submit the receipt reference, or cancel a pending deposit.'],
                ['Work progress', 'Paid packages plus milestone progress bars taken from the task tracker.'],
                ['Reports', 'Delivered weekly and monthly reports, opened or printed to PDF.'],
                ['Your business', 'Nature of business, struggles and notes — editable at any time.'],
                ['Verify ID', 'Identity documents and their status.'],
                ['Top-bar switch', 'A single pill chip at the top right — "Growth Partner · <em>tier or No plan yet</em>" — goes to the partner dashboard. The only connective link between the two sides from this page.'],
            ],
            'note'     => 'The Client account is permanent: no expiry, no plan, never locked out. Affiliate-only members do not see this dashboard at all until they activate it.',
        ],
        [
            'id'       => 'partner',
            'title'    => 'Member — Growth Partner side',
            'subtitle' => '/dashboard-affiliate.php',
            'intro'    => 'Opens as soon as a member picks a Basic or Standard plan. The sidebar is purely Growth Partner content — no service packages, no client work. The only link to the Client side is a single pill-shaped switch in the top bar (dual-side members only); affiliate-only members get an "Activate Client account" card on the page instead.',
            'rows'     => [
                ['Plan chooser (gate)', 'Basic — free, starts immediately, 3-month term. Standard — US$100 for 2 years plus a 3-month activation bonus, verified by an admin. The dashboard stays closed until one is chosen.'],
                ['Overview', 'Term countdown — days, hours, minutes and seconds remaining, with a lifecycle bar that turns warn/critical as the end approaches.'],
                ['Partner plan', 'Afterwards: upgrade Basic → Standard, or renew Standard (+2 years, payments stack).'],
                ['Referral link', 'Their KT-XXXXXX code and a copyable sign-up link. A Growth Partner sign-up through this link records the inviter as upline; a Client sign-up through this link ignores the code (clients carry no upline).'],
                ['Earnings', 'Commission ledger — 10% of each referred client\'s 50% advance deposit — with totals.'],
                ['Your downline', 'Unlimited-depth graph: who signed up with their code, who signed up with those people\'s codes, and so on, with per-level counts.'],
                ['Your business', 'Nature of business, struggles and notes.'],
                ['Verify ID', 'Identity documents and their status (required for Standard).'],
                ['Activate Client account', 'Affiliate-only members only. One button opens the Client side; a welcome flash lands them on the Client dashboard.'],
                ['Top-bar switch', 'Dual-side members only: "Client account · KAM Team services" chip to the Client dashboard.'],
                ['Expiry notices', 'An in-app notification at 7 days left, and again once the term lapses: refer a client (+3 months) or take Standard.'],
            ],
            'note'     => 'Commission is earned only on the 50% advance deposit of a package\'s one-time setup fee. Monthly recurring fees are never commissionable, and no income is guaranteed. Growth Partner sign-ups must carry a referrer\'s code — there is no partner without an upline.',
        ],
        [
            'id'       => 'frontend',
            'title'    => 'Frontend standards',
            'subtitle' => 'How the interface is built',
            'intro'    => 'The app follows the KAM TEAM Frontend Standard Principles SOP. The practical effect for everyone is a consistent, accessible interface — details in <code>docs/FRONTEND-STANDARDS.md</code>.',
            'rows'     => [
                ['One stylesheet, one script', 'All CSS lives in <code>assets/styles.css</code> built from design tokens (spacing, type, colour, radius, shadow, motion). All behaviour lives in <code>assets/js/app.js</code> as opt-in modules; new behaviour goes there, never inline. Both are cache-busted by file mtime, so a deploy is picked up without hand-edited version strings.'],
                ['Shared component layer', '<code>api/lib/ui.php</code> renders the page shell: head, topbar (with role badge and optional account switch), alerts, page title, stat grid, terminal headers, scrollable tables, and the inactivity watchdog. Every page is assembled from these pieces, so a change to the shell is made once.'],
                ['Accessibility', 'Semantic landmarks on every page; one focus ring defined once; alerts in a live region with a problem count; active navigation marked with <code>aria-current</code>; scrollable tables focusable and labelled; touch targets at least 44×44 on coarse pointers; <code>prefers-reduced-motion</code> disables animation globally.'],
                ['Responsive', 'Mobile-first. Breakpoints at 480px and 900px; the sidebar collapses into a horizontal tab strip on narrow screens; stat grids reflow 4 → 2 → 1.'],
                ['Progressive enhancement', 'Critical interactions (auth tabs, terms-popup gate) carry a small inline fallback so they keep working even if <code>app.js</code> fails to load.'],
            ],
            'note'     => '',
        ],
    ];
}

// The document body, shared by the on-screen page and the PDF.
function program_doc_html(): string
{
    $out = '';
    foreach (program_doc_sections() as $s) {
        $out .= '<section class="doc-section" id="' . e($s['id']) . '">'
              . '<h2>' . e($s['title']) . '</h2>'
              . '<p class="doc-sub">' . $s['subtitle'] . '</p>'
              . ($s['intro'] !== '' ? '<p class="doc-intro">' . $s['intro'] . '</p>' : '')
              . '<table><thead><tr><th style="width:28%">Component</th><th>What it does</th></tr></thead><tbody>';
        foreach ($s['rows'] as [$name, $what]) {
            $out .= '<tr><td><strong>' . e($name) . '</strong></td><td>' . $what . '</td></tr>';
        }
        $out .= '</tbody></table>'
              . ($s['note'] !== '' ? '<p class="doc-note">' . $s['note'] . '</p>' : '')
              . '</section>';
    }
    return $out;
}
