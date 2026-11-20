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
const PROGRAM_DOC_VERSION = '1.1';
const PROGRAM_DOC_DATE    = 'November 21, 2026';

// [ id, title, subtitle, intro, rows => [[component, what it does], …], note ]
function program_doc_sections(): array
{
    return [
        [
            'id'       => 'accounts',
            'title'    => 'Account types',
            'subtitle' => 'Who exists in the program',
            'intro'    => 'Three kinds of account. Admins and staff are KAM TEAM\'s own people and are registered by an admin. Everyone else signs up through the public form and becomes a member.',
            'rows'     => [
                ['Admin', 'Runs the program. Full access to every panel, plus a Growth Partner account of their own that never expires.'],
                ['Staff / employee', 'Does client work. Lands in Client work and nothing else: no program terms to accept, no expiry, no recycle bin.'],
                ['Member', 'One account with two sides: a permanent <strong>Client</strong> account for buying KAM TEAM services, and a <strong>Growth Partner</strong> account for referring clients. Sign-up asks for no account type — both are created together.'],
            ],
            'note'     => 'Only the Growth Partner side carries a term. The Client side never expires, so an expired partner term stops commission but never locks anyone out.',
        ],
        [
            'id'       => 'shared',
            'title'    => 'Shared — everyone',
            'subtitle' => 'Sign-up, access and security',
            'intro'    => '',
            'rows'     => [
                ['Registration', 'Name, username, contact, password, business profile and an optional referral code. No account type to choose.'],
                ['Rules and guidelines', 'Every manual section is listed by title and opens in one popup. The single acceptance box unlocks only once the popup has been read to the end, and the server refuses a sign-up without it.'],
                ['Approval wall', 'A new account waits on a holding page until an admin approves it. The page rechecks every minute.'],
                ['Terms re-acceptance', 'When the manual version changes, members must accept the new version before any dashboard opens.'],
                ['Plan gate', 'After approval, a member picks Basic or Standard before the dashboard opens. Verify ID stays reachable, since Standard needs approved identity documents.'],
                ['Notifications', 'A bell in the top bar with unread count: approvals, payments, commissions, reports, expiry reminders.'],
                ['Identity (KYC)', 'Upload identity documents; an admin approves or rejects with a note.'],
                ['Sign-in security', 'The session cookie is encrypted and tamper-checked, and expires 30 days after sign-in. Signed-in pages are never kept in the browser cache, so Back after logout lands on the sign-in page.'],
            ],
            'note'     => '',
        ],
        [
            'id'       => 'admin',
            'title'    => 'Admin',
            'subtitle' => '/dashboard-admin.php',
            'intro'    => '',
            'rows'     => [
                ['Users', 'Every live account: role, status, lifecycle and referral code. Edit details, password, business profile, Growth Partner plan and term. Change role or status, or trash an account into the recycle bin.'],
                ['+ Register staff / employee', 'Creates an approved Staff or Admin account on the spot — no application, no program terms, no expiry.'],
                ['Approvals', 'Account applications (approve or reject with a reason) and payments to verify: Growth Partner Standard plans and client 50% deposits.'],
                ['Roulette', 'Round-robin assignment of each approved account to an admin, who keeps it for good. Admins can be paused.'],
                ['KYC', 'Identity queue: approve or reject with a note.'],
                ['Payment', 'The payment QR code, account details and instructions that members pay against.'],
                ['Referral network', 'Every referrer → referred pair, with level and status.'],
                ['Packages', 'Create and edit Growth Partner plans and client service packages (one-time price, monthly fee, commission). Edits are scheduled 1–7 days ahead and announced to everyone.'],
                ['Transactions', 'All activations, deposits, commissions and payouts.'],
                ['Services', 'Per-client service records.'],
                ['Notify', 'Broadcast to Everyone, Members, Staff or Admins.'],
                ['Audit logs', 'Categorised: Login &amp; terms, Client task log, Weekly/monthly reports, Accounts &amp; approvals, Payments &amp; commissions, KYC, Packages, Roulette.'],
                ['Recycle bin', 'Admin-only. Deleted accounts, cancelled or rejected payments, cancelled client tasks and retired packages — each with Restore. Nothing is erased.'],
                ['Client work →', 'Everything staff can do, plus approving and delivering client reports.'],
                ['My referral account →', 'The admin\'s own Growth Partner account: referral code and link, commission ledger and downline. Tier <strong>Unlimited</strong> — no plan to buy, nothing to renew, no expiry.'],
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
            'intro'    => '',
            'rows'     => [
                ['Overview', 'Active packages, tasks in progress, tasks completed, reports, and the member\'s assigned KAM TEAM contact.'],
                ['Service packages', 'The six KAM TEAM packages with one-time and monthly prices. Pay the 50% advance deposit by QR, submit the receipt reference, or cancel a pending deposit.'],
                ['Work progress', 'Paid packages plus milestone progress bars taken from the task tracker.'],
                ['Reports', 'Delivered weekly and monthly reports, opened or printed to PDF.'],
                ['Your business', 'Nature of business, struggles and notes — editable at any time.'],
                ['Verify ID', 'Identity documents and their status.'],
                ['Account switcher', 'Client account ↔ Growth Partner, showing the referral code and current plan.'],
            ],
            'note'     => 'The Client account is permanent: no expiry, no plan, never locked out.',
        ],
        [
            'id'       => 'partner',
            'title'    => 'Member — Growth Partner side',
            'subtitle' => '/dashboard-affiliate.php',
            'intro'    => '',
            'rows'     => [
                ['Plan chooser (gate)', 'Basic — free, starts immediately, 3-month term. Standard — US$100 for 2 years plus a 3-month activation bonus, verified by an admin. The dashboard stays closed until one is chosen.'],
                ['Partner plan', 'Afterwards: upgrade Basic → Standard, or renew Standard (+2 years, payments stack).'],
                ['Term countdown', 'Days remaining, with a warning at 7 days and throughout the grace period (Basic 7 days, Standard 30).'],
                ['Referral link', 'Their KT-XXXXXX code and a copyable sign-up link.'],
                ['Earnings', 'Commission ledger — 10% of each referred client\'s 50% advance deposit — with totals.'],
                ['Your downline', 'Unlimited-depth graph: who signed up with their code, who signed up with those people\'s codes, and so on, with per-level counts.'],
                ['Expiry notices', 'An in-app notification at 7 days left, and again once the term lapses: refer a client (+3 months) or take Standard.'],
            ],
            'note'     => 'Commission is earned only on the 50% advance deposit of a package\'s one-time setup fee. Monthly recurring fees are never commissionable, and no income is guaranteed.',
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
              . ($s['intro'] !== '' ? '<p class="doc-intro">' . e($s['intro']) . '</p>' : '')
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
