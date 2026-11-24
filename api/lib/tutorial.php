<?php
// Topbar "?" button + Tutorial dialog. One chapter list per account type;
// commission rates are read live so the text matches the admin settings.

function tutorial_chapters(string $role, string $out, string $up): array
{
    $basics = ['Getting around', [
        'The <strong>sidebar</strong> is grouped. A row with an arrow opens a drawer with its pages; <strong>Back</strong> returns to the main list. On a phone, the ☰ button opens the menu.',
        'The <strong>bell</strong> shows your notifications: approvals, payments, payouts and announcements. Opening it marks them read.',
        'This <strong>?</strong> button opens this tutorial on every page.',
        'For your security you are signed out after a period without activity; a warning appears first so you can stay signed in.',
    ]];

    $commission = 'When a client you referred pays the <strong>50% advance deposit</strong> on a service package, you earn <strong>' . $out . '% of that deposit</strong> (Outright). '
        . 'Your direct upline earns <strong>' . $up . '% of your Outright</strong> (Uni-Level), taken out of your Outright, e.g. a $500 deposit gives a $50 Outright: your upline gets $2.50 and you keep $47.50. If the upline is an admin account or there is none, that share goes to the KAM Main Basket. Monthly fees and the remaining balance are never commissionable.';

    switch ($role) {
        case 'admin':
            return [$basics,
                ['People', [
                    '<strong>Users</strong>: every account, with role, status and who approved it. Edit, suspend or move an account to the recycle bin.',
                    '<strong>Approvals</strong>: new account applications and payments to verify (Growth Partner Standard plans and client deposits). A client deposit can only be approved once that client\'s <strong>KYC is approved</strong>; you\'ll be told if it isn\'t.',
                    '<strong>KYC</strong>: review ID documents. Growth Partners need approved KYC before referring; clients need it before claiming a package.',
                    'Your full name is recorded on every approval and kept in the audit log.',
                ]],
                ['Referrals', [
                    '<strong>Referral pipeline</strong>: each referred client moves <em>Pending → Converted (package) → Approved</em>, or <em>Rejected</em> with a reason. Approving releases the commission.',
                    '<strong>Referral network</strong>: All / Growth Partners / Clients views with search and filters; "View tree" shows a partner\'s whole downline.',
                    '<strong>Roulette</strong>: the rotation of admins who receive new sign-ups without a code.',
                    '<strong>Compression</strong>: when a deal closes and the referrer\'s upline is suspended, rejected, removed or expired, the Uni-Level share skips to the next active Growth Partner above. The referrer is then <strong>permanently re-linked</strong> to that partner. This is logged as "upline re-linked".',
                ]],
                ['Money', [
                    '<strong>Client payments</strong>: clients who paid 50%, and a separate Fully paid view showing who marked each one.',
                    '<strong>Payouts</strong>: the payout queue. Mark requests paid or reject them. Payouts close automatically every <strong>Wednesday 11:59 PM US Eastern</strong> (Thursday in the Philippines); only an admin can reopen them, with their password. When marking a payout paid, enter the fees and any tax withheld so the receipt shows the net amount (Terms §5.6).',
                    '<strong>Transactions</strong>: every activation, deposit, commission, Main Basket credit and payout.',
                    '<strong>Payment QR</strong>: the QR code and rates members pay with, including the commission rates: ' . $out . '% Outright of the deposit, and ' . $up . '% of that Outright to the upline.',
                ]],
                ['Catalog', [
                    '<strong>Packages</strong>: Growth Partner plans and client service packages. Price edits are scheduled ahead and announced.',
                    '<strong>Services</strong>: per-client service records.',
                ]],
                ['System', [
                    '<strong>Notify</strong>: broadcast to everyone, members, staff or admins.',
                    '<strong>Audit logs</strong>: who did what and when, by full name, filtered by category.',
                    '<strong>Recycle bin</strong>: nothing is erased; everything removed can be restored.',
                ]],
                ['Client work & your referral account', [
                    '<strong>Client work</strong>: the task tracker, client reports and staff payouts (SOP-OPS-001). Admins also approve and deliver reports.',
                    'The <strong>Admin ⇄ Growth Partner</strong> switch in the top bar opens your own Unlimited Growth Partner account (code KT-000001). Switch back the same way.',
                ]],
            ];

        case 'staff':
            return [$basics,
                ['Task tracker', [
                    'Every task traces to a client scope, has an owner, carries evidence and is verified before it is marked Completed.',
                    'Names in role fields (assignee, reviewer, coordinator…) are tags only; they do not grant permissions.',
                    'Each status change is logged with your name.',
                ]],
                ['Client reports', [
                    'Draft weekly and monthly reports from completed work. An admin approves and delivers them to the client.',
                ]],
                ['Payouts', [
                    'Work the payout queue: mark Growth Partner payout requests paid (with a reference) or reject them with a reason. The bar fills as requests are handled.',
                    'Payouts close every Wednesday 11:59 PM US Eastern (Thursday in the Philippines); an admin reopens them. Enter fees and tax withheld when marking a payout paid.',
                ]],
            ];

        case 'client':
            return [$basics,
                ['1. Verify your ID', [
                    'Open <strong>Account → Verify ID</strong> and upload your documents. Packages can be claimed once your ID is approved.',
                ]],
                ['2. Choose a service package', [
                    'Under <strong>My services → Service packages</strong>, pick a package and pay the <strong>50% advance deposit</strong> by scanning the payment QR, then submit your reference.',
                    'An admin verifies the payment; you\'ll get a notification when it is approved. The remaining 50% is settled later and marked Fully paid.',
                ]],
                ['3. Follow the work', [
                    '<strong>Work progress</strong> shows the tasks KAM Team is doing for you.',
                    '<strong>Reports</strong> holds your weekly and monthly reports to open or download.',
                ]],
                ['Your client code', [
                    'Your <strong>KT-</strong> code is under Account. Friends can enter it when they sign up as a client. Client accounts do not earn commission; only Growth Partners do.',
                ]],
            ];

        default: // growth_partner
            return [$basics,
                ['1. Your plan', [
                    '<strong>Basic</strong> starts free; <strong>Standard</strong> costs US$100. Your plan sets your term; see <strong>Account → Partner plan</strong> to renew or upgrade.',
                ]],
                ['2. Verify your ID', [
                    'Your ID must be approved before you can refer. Use <strong>Account → Verify ID</strong>.',
                ]],
                ['3. Refer clients', [
                    'Share your <strong>KT-</strong> code or referral link from <strong>Referrals → Referral link</strong>. Clients enter only the characters after KT-.',
                    'Growth Partners can join under you only with your code (never a client\'s code); they become your downline.',
                ]],
                ['4. Track your referrals', [
                    '<strong>Clients referred</strong> shows each client\'s stage: <em>Pending → Converted (package) → Approved</em>, or <em>Rejected</em> with the admin\'s reason.',
                    '<strong>Your downline</strong> shows the partners below you.',
                    'If your upline is suspended or their term ends, the next active partner above them becomes your <strong>permanent upline</strong> the next time one of your clients pays. You\'ll get a notification when that happens.',
                ]],
                ['5. How you earn', [
                    $commission,
                    'The <strong>Forecast</strong> on Overview estimates your monthly and yearly total (Outright + Uni-Level).',
                ]],
                ['6. Get paid', [
                    'On <strong>Payout</strong>, press <strong>Payout now</strong> and choose a bank transfer (Philippines, US, Canada or Australia), PayPal or USDT once your balance reaches US$100. Fees and any tax withheld are deducted; the receipt shows gross and net.',
                    'Basic partners: Uni-Level can be withdrawn only after you refer a new Qualified Client since your last payout (Outright is always withdrawable). Standard partners can also withdraw during their 30-day grace period.',
                    'Payouts close every <strong>Wednesday 11:59 PM US Eastern</strong> (Thursday in the Philippines) while the team processes them, and reopen within 2–3 business days.',
                    'The Commission ledger and Payout receipts open as tables; every row downloads as a PDF receipt.',
                ]],
            ];
    }
}

function tutorial_html(string $role, ?string $token = null): string
{
    $out = $token ? pct_label(commission_pct($token)) : '7.5';
    $up  = $token ? pct_label(unilevel_pct($token)) : '2.5';
    $titles = ['admin' => 'Admin', 'staff' => 'Staff', 'client' => 'Client', 'growth_partner' => 'Growth Partner'];
    $who = $titles[$role] ?? 'Growth Partner';

    $body = '';
    foreach (tutorial_chapters($role, $out, $up) as $i => [$title, $points]) {
        $body .= '<details class="tut-ch"' . ($i === 0 ? ' open' : '') . '><summary>' . e($title) . '</summary><ul>';
        foreach ($points as $p) $body .= '<li>' . $p . '</li>';
        $body .= '</ul></details>';
    }

    return '<button type="button" class="help-btn" aria-haspopup="dialog" aria-controls="tutorial" title="Tutorial" onclick="document.getElementById(\'tutorial\').showModal()">'
        . '<span aria-hidden="true">?</span><span class="sr-only">Open the tutorial</span></button>'
        . '<dialog class="tree-dialog po-dialog tut-dialog" id="tutorial" aria-labelledby="tutorial-t">'
        . '<div class="tree-dlg-head"><div><span class="label">Tutorial · ' . e($who) . '</span><h3 id="tutorial-t">How the program works</h3></div>'
        . '<form method="dialog"><button class="btn btn-sm btn-ghost" type="submit">Close</button></form></div>'
        . '<div class="tree-dlg-body tut-body">' . $body . '</div></dialog>';
}
