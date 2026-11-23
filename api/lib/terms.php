<?php
// =====================================================================
// KAM TEAM Growth Partnership Program — Terms checklist
//
// Every section in TERMS_SECTIONS is listed by title and shown in full in
// one popup; a single box (unlocked once the popup is read to the end)
// accepts them all before an account can be created. Each acceptance is stored in public.consents with the
// version, the sections accepted, the date/time, IP address and device
// (Manual v1.7, Appendix A).
//
// TO ADD A SECTION: add one entry to TERMS_SECTIONS below. The sign-up
// form, server-side validation and the consent record pick it up
// automatically. Entries are shown in section-number order. If the
// manual itself changes, bump TERMS_VERSION / TERMS_EFFECTIVE too.
// =====================================================================

const TERMS_PROGRAM   = 'KAM Growth Partnership Program';
const TERMS_VERSION   = '1.7';
const TERMS_EFFECTIVE = 'December 19, 2026';

const TERMS_SECTIONS = [
    'overview' => [
        'number' => '1',
        'title'  => 'Program Overview',
        'rules'  => [
            '1.1 Purpose — The Program lets individuals and businesses enrolled as Growth Partners refer potential clients to KAM TEAM services and earn commissions when the conditions of this agreement are met.',
            '1.2 Commission principle — A Growth Partner earns an Outright Commission of 10% of the qualifying 50% setup Advance Deposit. A direct sponsor earns a Uni-Level Commission equal to 5% of the Co-growth Partner\'s Outright Commission. KAM TEAM handles the sales conversation after referral. These rates never apply to monthly fees.',
            '1.3 Account types — Basic Account: free to join, subject to the Basic term rules in Section 4.1. Standard Account: US$100 per two-year term, with the activation bonus and renewal rules in Section 4.2.',
            'Important: Outright and Uni-Level Commissions arise only from a client\'s 50% Advance Deposit on a package\'s One-Time Setup Fee. The remaining setup balance and all Monthly Recurring Fees are non-commissionable. Earnings require actual client payments; no income is guaranteed.',
        ],
        'agree'  => 'I understand that commissions come only from a client\'s 50% setup Advance Deposit and that no income is guaranteed.',
    ],
    'definitions' => [
        'number' => '2',
        'title'  => 'Definitions',
        'rules'  => [
            '2.1 Qualified Client — a referred prospect whose details the Growth Partner submits through the official Lead Form, and who pays the 50% Advance Deposit on the One-Time Setup Fee of a KAM TEAM package.',
            '2.1A Lead Form — the official KAM TEAM form in the Growth Partner dashboard. The referring Growth Partner, not the prospect, submits it.',
            '2.2 Outright Commission — 7.5% of the Qualified Client\'s 50% Advance Deposit on the One-Time Setup Fee, payable to the referring Growth Partner.',
            '2.3 Uni-Level Commission — 2.5% of the same Advance Deposit, payable to the referring Growth Partner\'s direct sponsor (upline). Together the two make 10% of the deposit.',
            '2.4 Growth Partner — an enrolled individual or business who identifies and refers prospective KAM TEAM clients.',
            '2.4A Co-growth Partner — a Growth Partner enrolled via another Growth Partner\'s link, making the referring partner their immediate sponsor (Level 1).',
            '2.5 Account Term — how long a Growth Partner account stays active before it expires.',
            '2.6 Grace Period — the period after expiry during which an account may be saved under Section 4.3.',
            '2.7 Roll-Up — transfer of a terminated Growth Partner\'s direct Co-growth Partners to that partner\'s own upline.',
            '2.8 Purse — a separate pool for Major Contributors, funded by the three streams in Section 11.',
            '2.9 Major Contributor — a Founder, Core Executive or Strategic Partner listed in Appendix C.',
            '2.10 Master Growth Partner Link — the KAM TEAM company Growth Partner link; partners joining through it are direct Co-growth Partners of the Master Link.',
            '2.11 Commission states — Pending: deposit received, not cleared. Approved: cleared and eligible for withdrawal under Section 5. Paid: sent to your designated payment method.',
            '2.12 One-Time Setup Fee — the one-time setup/development price of the relevant KAM TEAM service package.',
            '2.13 Advance Deposit — the required initial payment of 50% of the One-Time Setup Fee before setup work begins.',
            '2.14 Monthly Recurring Fee — the monthly management/maintenance charge, which is not commissionable.',
        ],
    ],
    'commission' => [
        'number' => '3',
        'title'  => 'Commission Structure',
        'rules'  => [
            '3.1 Outright Commission = Actual One-Time Setup Fee Invoiced × 50% × 7.5%. It becomes Pending when the Advance Deposit is received and Approved once the payment is confirmed.',
            '3.2 Uni-Level Commission = Actual One-Time Setup Fee Invoiced × 50% × 2.5%, paid to the referring Growth Partner\'s direct sponsor (upline). It applies to direct Level 1 only — there is no Level 2 or deeper payout. No Uni-Level Commission arises from joining fees or Standard Account payments.',
            '3.3 Excluded payments — no commission is paid on any Monthly Recurring Fee (first or later months, monthly renewals or upgrades), the remaining 50% of a setup fee, add-ons, change requests, extra pages, advertising spend, third-party charges, or work quoted outside the listed package. A commission is earned once per Qualified Client package purchase, on its qualifying Advance Deposit only.',
            '3.4 Commission schedule — the examples below are in US dollars, gross before payment fees and withholding. The actual invoiced One-Time Setup Fee controls the real calculation.',
            '3.5 Worked example — Complete Google SEO Setup & Optimization, US$1,000 setup: US$1,000 × 50% = US$500 Advance Deposit. Outright Commission = US$500 × 7.5% = US$37.50. Sponsor Uni-Level = US$500 × 2.5% = US$12.50. Total commission = US$50 (10%). The remaining US$500 balance and the US$600 monthly fee earn no commission.',
        ],
        'table'  => [
            'head' => ['Package', 'Setup', '50% deposit', 'Outright 7.5%', 'Upline 2.5%', 'Total 10%', 'Monthly (excluded)'],
            'rows' => [
                ['GHL Business Systems Development & Automation', '$2,000', '$1,000', '$75.00', '$25.00', '$100.00', '$1,000/mo'],
                ['Complete Google SEO Setup & Optimization', '$1,000', '$500', '$37.50', '$12.50', '$50.00', '$600/mo'],
                ['Full-Service Digital Marketing Package', '$3,000', '$1,500', '$112.50', '$37.50', '$150.00', '$1,500/mo'],
                ['Custom Business Operations Management System', '$4,000', '$2,000', '$150.00', '$50.00', '$200.00', '$1,500/mo'],
                ['Customized Integrated Digital & Network Marketing Business System', '$4,500', '$2,250', '$168.75', '$56.25', '$225.00', '$2,000/mo'],
                ['Complete AI SEO, AEO & GEO Optimization', '$1,500', '$750', '$56.25', '$18.75', '$75.00', '$750/mo'],
            ],
        ],
        'agree'  => 'I understand how Outright and Uni-Level Commissions are calculated and which payments are excluded.',
    ],
    'retention' => [
        'number' => '4',
        'title'  => 'Account Retention & Renewal',
        'rules'  => [
            '4.1 Basic Account — a 3-month base term from activation; each Qualified Client you refer adds 3 months. Basic cannot be renewed by payment — it is extended only by Qualified Clients or by upgrading to Standard. Expiry = Activation Date + 3 months + (3 months × Qualified Clients Referred).',
            '4.2 Standard Account — your first US$100 payment grants a 3-month activation bonus and a 2-year term. Each additional US$100 payment adds another 2 years, stacked onto the current expiry, and each Qualified Client adds 3 months. Renewal is unlimited. Expiry = First Payment Date + 3 months + (2 years × number of US$100 payments) + (3 months × Qualified Clients Referred). Example: first payment January 1, 2027 and two Qualified Clients → expiry October 1, 2029.',
            '4.3 Grace Period — Basic: 7 days after expiry; save the account by referring one Qualified Client or upgrading to Standard for US$100. Standard: 30 days after expiry, during which renewal costs US$100.',
        ],
        'agree'  => 'I understand how my account term is calculated, extended and renewed, and that it expires if I do not act within the grace period.',
    ],
    'withdrawals' => [
        'number' => '5',
        'title'  => 'Withdrawals, Payment Methods & Taxes',
        'rules'  => [
            '5.1 Minimum withdrawal — your combined Approved Outright and Uni-Level balance must reach US$100 gross before you can withdraw.',
            '5.2 Basic batch rule — to withdraw Uni-Level Commissions, a Basic Growth Partner must have referred at least one new Qualified Client since their last successful withdrawal. Outright Commissions are exempt. The batch counter resets on each successful withdrawal.',
            '5.3 Standard withdrawals — no batch requirement; you may withdraw while Active or during the 30-day grace period, subject to the US$100 minimum.',
            '5.4 Payout calendar — cutoff is every Wednesday at 11:59 p.m. US Eastern Time (Thursday 12:59 p.m. Philippine Time under EST, or 11:59 a.m. under EDT). Processing takes 2–3 business days after cutoff.',
            '5.5 Payment methods — bank transfer (Philippines, United States, Canada, Australia), PayPal, or a USDT wallet. Keep one valid method in your dashboard. KAM TEAM is not responsible for payments sent to incorrect details you provide.',
            '5.6 Fees — bank, payment-service, exchange and network fees are deducted. Net Payment = Gross Commission − Transaction Fees − Applicable Tax Withholding. Actual fees are set at payment time.',
            '5.7 Taxes — Growth Partners are independent contractors responsible for their own taxes and reporting. KAM TEAM withholds and remits tax where the law requires (Philippine examples: 5% or 10% for specified independent agents). Once annual earnings reach US$600, KAM TEAM will request the applicable tax form (US W-9, non-US W-8BEN, or Philippine BIR Form 2303); payouts are held until it is provided.',
        ],
        'agree'  => 'I understand the US$100 withdrawal minimum, the payout schedule, the fees deducted, and that I am responsible for my own taxes.',
    ],
    'termination' => [
        'number' => '6',
        'title'  => 'Termination, Upgrade & Lifetime Ban',
        'rules'  => [
            '6.1 Automatic termination — a Basic Account that passes its 7-day grace period without cure, or a Standard Account that passes its 30-day grace period without renewal, is deleted; its Pending commissions are forfeited and its direct Co-growth Partners roll up to its upline.',
            '6.2 Voluntary termination — give 7 days\' written notice through the dashboard. Your final Approved balance is paid in the next payout cycle. Roll-Up applies on termination.',
            '6.3 Termination by KAM TEAM — 14 days\' notice for a breach of Section 8; immediately and without notice for fraud.',
            '6.4 Basic to Standard upgrade — during the 7-day Basic grace period you may pay US$100 to upgrade. New expiry = Payment Date + 3 months + 2 years.',
            '6.5 One account per person, for life. Duplicate accounts are blocked, all associated funds are forfeited, and there is no appeal.',
        ],
        'agree'  => 'I accept the termination rules and confirm this is my only KAM TEAM account. I understand duplicate accounts lead to a lifetime ban with no appeal.',
    ],
    'attribution' => [
        'number' => '7',
        'title'  => 'Lead Attribution, Tracking & Disputes',
        'rules'  => [
            '7.1 First submission wins — the first Growth Partner to submit a valid official Lead Form for a prospect is credited for 90 days from submission, provided the prospect pays the qualifying Advance Deposit within that period.',
            '7.2 Duplicate prospects — a Lead Form for a prospect already registered to another Growth Partner is automatically blocked.',
            '7.3 Existing CRM leads — no commission is earned if the prospect was in the KAM TEAM CRM in the preceding 12 months.',
            '7.4 Conversion trigger — commission arises only when the 50% setup Advance Deposit is received, never from the balance or recurring charges.',
            '7.5 Disputes — submit with supporting proof through the dashboard within 14 days. KAM TEAM Admin investigates and responds within 5 business days. The Admin decision is final within the Program, subject to mediation in Manila, Philippines before any court proceedings.',
        ],
        'agree'  => 'I understand how leads are attributed and that disputes must be raised with proof within 14 days.',
    ],
    'conduct' => [
        'number' => '8',
        'title'  => 'Partner Conduct & Prohibited Actions',
        'rules'  => [
            '8.1 Allowed channels — email, social media, blogs, paid advertising and direct outreach, within the law and the rules below.',
            '8.2 Prohibited — spam, misleading claims, bidding on KAM TEAM branded search terms, duplicate accounts, self-referrals and fraud. You may not guarantee unauthorized rankings or revenue, imply unrepresentative earnings, describe the Program as an investment or passive income, or imply commissions apply to monthly recurring fees.',
            '8.2A Disclosure — every promotion must clearly disclose the commission relationship near the link or recommendation, e.g. "I earn a commission if you hire KAM TEAM through my link." (US FTC Endorsement Guides, 16 CFR Part 255.)',
            '8.3 Violations — immediate termination, forfeiture of commissions and a lifetime ban.',
        ],
        'agree'  => 'I will promote KAM TEAM only through allowed channels, will always disclose that I may earn a commission, and will not make false claims.',
    ],
    'privacy' => [
        'number' => '9',
        'title'  => 'Privacy, Data & Consent',
        'rules'  => [
            '9.1 Data collected — at enrollment KAM TEAM collects your name, email, phone, postal address, payment account details, government ID, IP address and device ID for identity verification, fraud prevention and payouts.',
            '9.2 Use — your data is used for commission processing, legal compliance, fraud prevention and program communications. KAM TEAM will not sell your data to third parties.',
            '9.3 Security — industry-standard security practices under the Philippine Data Privacy Act of 2012 (Republic Act No. 10173). No system is entirely secure.',
            '9.4 Communications — enrollment includes consent to email and SMS about account status, payouts and program updates. You may opt out of marketing, but not transactional notices.',
            '9.5 Access, correction, deletion — write to support@kam-team.com. A deletion request is treated as termination under Section 6.2, subject to legal retention duties.',
            '9.6 Additional rights — EU and California residents have additional rights on written request.',
        ],
        'agree'  => 'I consent to KAM TEAM collecting and processing my personal data as described, and to receiving account, payout and program messages.',
    ],
    'support' => [
        'number' => '10',
        'title'  => 'KAM TEAM Marketing Support',
        'rules'  => [
            '10.1 Dashboard — track clicks, submitted leads, commissions and payouts.',
            '10.2 Promotional materials — banners, email templates, social media captions and a sales presentation deck.',
            '10.3 Training — weekly onboarding and a Facebook community.',
            '10.4 Sales ownership — KAM TEAM\'s sales team handles sales conversations and closes referred leads.',
        ],
    ],
    'purse' => [
        'number' => '11',
        'title'  => 'Purse System — Major Contributors',
        'rules'  => [
            '11.1 Purpose — the Purse is a separate pool for Major Contributors, funded from three streams and divided equally.',
            '11.2 Eligibility — only Founders, Core Executives and Strategic Partners listed in Appendix C qualify. Changing the locked registry needs a 75% vote of Major Contributors.',
            '11.3 Funding — Stream 1: 100% of Uni-Level Commissions from the Master Growth Partner Link\'s direct Level 1 Co-growth Partners (no deeper levels). Stream 2: 100% of US$100 Standard subscription and renewal payments. Stream 3: 10% of monthly Program Net Profit, deposited on the 5th of each month.',
            '11.4 Distribution — each Active Major Contributor receives (Stream 1 + Stream 2 + Stream 3) ÷ number of Active Major Contributors, monthly on the 15th, with a statement.',
            '11.5 Exit and succession — voluntary exit needs 30 days\' notice; involuntary removal and heir transfers need a 75% vote. A departing share is reallocated among remaining contributors. Shares may not be sold.',
            '11.6 Separate from partner pay — the Purse does not reduce or replace Growth Partner commissions.',
        ],
    ],
    'general' => [
        'number' => '12',
        'title'  => 'General Provisions',
        'rules'  => [
            '12.1 Amendment — KAM TEAM may change these terms with 30 days\' written notice by email and dashboard. Continuing after the notice period means you accept the change.',
            '12.2 Governing law — the laws of the Republic of the Philippines.',
            '12.3 Venue — after Section 7.5 mediation, courts in Makati City, Philippines.',
            '12.4 Independent status — Growth Partners are independent contractors, not employees. Enrollment creates no partnership or joint venture.',
            '12.5 Force majeure — KAM TEAM is not liable for failures caused by acts of God, internet failures, war or government action.',
            '12.6 Entire agreement — Version 1.7 and Appendices A–C are the full agreement and supersede earlier versions, including v1.6.',
            '12.7 Severability — if a clause is invalid, the rest continues to apply as far as the law allows.',
        ],
        'agree'  => 'I accept Philippine law, Makati City venue, and that I join as an independent contractor.',
    ],
    'appendix-b' => [
        'number' => 'B',
        'title'  => 'Development & Accounting Rules',
        'rules'  => [
            'Records kept: partner accounts, direct Level 1 sponsor links, leads, clients, packages (setup and recurring fees kept separately), deposits, consent logs, the commission ledger and the Purse ledger.',
            'Automated jobs: expiry and grace-period monitoring, Roll-Up, the weekly payout cutoff, Stream 3 funding on the 5th and Purse payments on the 15th.',
            'Wallet: commission entries arise only from qualifying setup deposits and move Pending → Approved → Paid, with the US$100 threshold, the Basic batch counter, fees and taxes applied.',
            'Statements show gross commission, any tax withheld, transaction fees, amount paid and the transaction reference.',
            'Duplicate controls match accounts by ID, device, IP and payment data, with a lifetime-ban flag, subject to privacy law.',
        ],
    ],
    'appendix-c' => [
        'number' => 'C',
        'title'  => 'Major Contributors Registry',
        'rules'  => [
            'The registry is locked: any change requires approval by 75% of Major Contributors.',
            'No Major Contributors have been entered yet. The registry will be published here once approved.',
        ],
    ],
];

// Appendix A — the final e-signature, always shown last.
const TERMS_SIGNATURE = 'By ticking "I agree" I confirm that I am at least 18 years old; have read and agree to Version 1.7 of the KAM Growth Partnership Program Manual; consent to data processing under Section 9; and accept the one-account rule, governing law, commission limitations and all other terms. I understand that commission is earned only on a qualifying client\'s 50% Advance Deposit against a One-Time Setup Fee, never on Monthly Recurring Fees, and that no earnings are guaranteed. My electronic acceptance is binding under the Philippine Electronic Commerce Act (Republic Act No. 8792); the date, time, IP address and document version are recorded.';

// Sections in display order: numbered sections first, then appendices.
function terms_sections(): array
{
    $s = TERMS_SECTIONS;
    uasort($s, function ($a, $b) {
        $an = ctype_digit($a['number']); $bn = ctype_digit($b['number']);
        if ($an !== $bn) return $an ? -1 : 1;
        return strnatcmp($a['number'], $b['number']);
    });
    return $s;
}

// Checks the posted checklist. Returns the missing items as error messages.
// The form's single box (terms[all]) accepts every section and the e-signature.
function terms_missing(array $posted): array
{
    return empty($posted['all']) ? ['all sections of the rules and guidelines'] : [];
}

function client_ip(): string
{
    $raw = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    return trim(explode(',', $raw)[0]) ?: 'unknown';
}

// The checklist block shown at sign-up and on /terms.php.
// $checked: the posted terms[] values, to keep the tick after a failed submit.
// Lists every section by title, one "Read" button opens all the rules in a
// popup, and a single checkbox unlocks once the popup has been read to the
// end. Ticking it accepts every section plus the e-signature.
function terms_checklist(array $checked, string $kicker = '04 // Program terms', string $submitId = ''): string
{
    $list = $body = '';
    foreach (terms_sections() as $sec) {
        $isApp = !ctype_digit($sec['number']);
        $tag   = $isApp ? 'App. ' . $sec['number'] : '§' . $sec['number'];
        $head  = $isApp ? 'Appendix ' . $sec['number'] . ' — ' : '§' . $sec['number'] . ' ';
        $list .= '<li><b>' . e($tag) . '</b> ' . e($sec['title']) . '</li>';
        $rules = '';
        foreach ($sec['rules'] as $rule) $rules .= '<li>' . e($rule) . '</li>';
        $table = '';
        if (!empty($sec['table'])) {
            $th = '';
            foreach ($sec['table']['head'] as $h) $th .= '<th scope="col">' . e($h) . '</th>';
            $tr = '';
            foreach ($sec['table']['rows'] as $row) {
                $tr .= '<tr>';
                foreach ($row as $i => $cell) $tr .= $i === 0 ? '<th scope="row">' . e($cell) . '</th>' : '<td>' . e($cell) . '</td>';
                $tr .= '</tr>';
            }
            $table = '<div class="terms-table-wrap"><table class="terms-table"><thead><tr>' . $th . '</tr></thead><tbody>' . $tr . '</tbody></table></div>';
        }
        $agree = !empty($sec['agree']) ? '<p class="terms-dlg-agree">' . e($sec['agree']) . '</p>' : '';
        $body .= '<section><h4>' . e($head . $sec['title']) . '</h4><ul>' . $rules . '</ul>' . $table . $agree . '</section>';
    }
    $list .= '<li><b>App. A</b> E-signature &amp; consent</li>';
    $body .= '<section><h4>Appendix A — E-signature &amp; consent</h4><p>' . e(TERMS_SIGNATURE) . '</p></section>';
    $ok = !empty($checked['all']);

    return '<fieldset class="biz-fieldset terms-checklist" id="terms-checklist"' . ($submitId !== '' ? ' data-submit="' . e($submitId) . '"' : '') . '>'
        . '<legend><span class="label kicker">' . e($kicker) . '</span>Rules and guidelines</legend>'
        . '<p class="terms-meta">' . e(TERMS_PROGRAM) . ' · Manual v' . e(TERMS_VERSION) . ' · Effective ' . e(TERMS_EFFECTIVE) . ' · Philippine law</p>'
        . '<ol class="terms-sections">' . $list . '</ol>'
        . '<button type="button" class="btn btn-sm terms-read" data-open>' . ($ok ? 'Read again' : 'Read all rules &amp; guidelines →') . '</button>'
        . '<label class="terms terms-accept"><input type="checkbox" name="terms[all]" value="1" data-terms' . ($ok ? ' checked' : ' disabled') . '>'
        . '<span>I am 18 or older, I have read every section above, and I accept the ' . e(TERMS_PROGRAM) . ' rules and guidelines.</span></label>'
        . '<p class="terms-progress">Read the rules to the end to unlock the checkbox. We record the date, time, IP address and version you accept.</p>'
        . '<dialog class="terms-dialog" id="terms-dlg" aria-labelledby="terms-ttl">'
        .   '<div class="terms-dlg-head"><span class="label">' . e(TERMS_PROGRAM) . ' · v' . e(TERMS_VERSION) . '</span><h3 id="terms-ttl">Rules and guidelines</h3></div>'
        .   '<div class="terms-dlg-body">' . $body . '</div>'
        .   '<div class="terms-dlg-foot"><span class="terms-dlg-hint">Scroll to the end to continue</span>'
        .   '<button type="button" class="btn btn-sm btn-ghost" data-close>Close</button>'
        .   '<button type="button" class="btn btn-sm btn-solid" data-agree disabled>I agree</button></div>'
        . '</dialog></fieldset>'
        // Inline fallback so the Read-rules button and the accept checkbox
        // work even if /assets/js/app.js is still cached, is blocked, or
        // 404s. app.js's termsChecklist does the same thing on top of this;
        // both call refresh() on change, so running twice is harmless.
        . terms_checklist_inline_script($submitId);
}

function terms_checklist_inline_script(string $submitId): string
{
    $sid = $submitId !== '' ? json_encode($submitId) : 'null';
    return <<<JS
<script>
(function () {
  var root = document.getElementById('terms-checklist');
  if (!root || root.dataset.wired) return;
  root.dataset.wired = '1';
  var box    = root.querySelector('input[data-terms]');
  var open   = root.querySelector('[data-open]');
  var dlg    = document.getElementById('terms-dlg');
  var body   = dlg && dlg.querySelector('.terms-dlg-body');
  var agree  = dlg && dlg.querySelector('[data-agree]');
  var close  = dlg && dlg.querySelector('[data-close]');
  var sid    = {$sid};
  var submit = sid && document.getElementById(sid);

  function refresh() {
    root.classList.toggle('done', box.checked);
    if (submit) submit.disabled = !box.checked;
  }
  function doClose() { if (dlg.close) dlg.close(); else dlg.removeAttribute('open'); }
  function checkRead() {
    if (body.scrollTop + body.clientHeight >= body.scrollHeight - 8) {
      agree.disabled = false;
      dlg.classList.add('is-read');
    }
  }

  if (open && dlg) {
    open.addEventListener('click', function () {
      if (dlg.showModal) dlg.showModal(); else dlg.setAttribute('open', '');
      body.scrollTop = 0;
      checkRead();
    });
    body.addEventListener('scroll', checkRead);
    if (close) close.addEventListener('click', doClose);
    dlg.addEventListener('click', function (ev) { if (ev.target === dlg) doClose(); });
    agree.addEventListener('click', function () {
      box.disabled = false;
      box.checked = true;
      open.textContent = 'Read again';
      doClose();
      refresh();
    });
  }
  // A locked box opens the rules instead of silently doing nothing.
  box.closest('label').addEventListener('click', function (ev) {
    if (box.disabled && open) { ev.preventDefault(); open.click(); }
  });
  box.addEventListener('change', refresh);
  refresh();
})();
</script>
JS;
}

// Only the known checklist keys from a POST.
function terms_posted(): array
{
    return array_intersect_key((array) ($_POST['terms'] ?? []), TERMS_SECTIONS + ['signature' => 1, 'all' => 1]);
}

// The row stored in public.consents for a user who accepted everything.
function terms_consent_record(string $userId): array
{
    $sections = [];
    foreach (terms_sections() as $key => $sec) $sections[$key] = 'Section ' . $sec['number'] . ' — ' . $sec['title'];
    return [
        'user_id'       => $userId,
        'program'       => TERMS_PROGRAM,
        'terms_version' => TERMS_VERSION,
        'sections'      => $sections,
        'signature'     => TERMS_SIGNATURE,
        'ip_address'    => client_ip(),
        'user_agent'    => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500) ?: null,
    ];
}
