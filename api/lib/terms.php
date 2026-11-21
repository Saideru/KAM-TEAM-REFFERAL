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
    'retention' => [
        'number' => '4',
        'title'  => 'Account Retention & Renewal',
        'rules'  => [
            'Basic account: 3-month base term from activation, plus 3 months for every Qualified Client you refer. Basic cannot be renewed — it is extended only by referrals or by upgrading to Standard.',
            'Standard account: US$100 per 2-year term. Your first payment adds a 3-month activation bonus; every US$100 payment adds 2 years (payments stack), plus 3 months for every Qualified Client you refer. Renewal is unlimited.',
            'Grace period: Basic 7 days (refer 1 Qualified Client or upgrade to Standard for US$100 to save the account); Standard 30 days (renew for US$100).',
        ],
        'agree'  => 'I understand how my account term is calculated, extended and renewed, and that it expires if I do not act within the grace period.',
    ],
    'termination' => [
        'number' => '6',
        'title'  => 'Termination, Upgrade & Lifetime Ban',
        'rules'  => [
            'Automatic termination: an account that expires and is not saved within its grace period is deleted, its Pending commissions are forfeited, and its Level 1 Growth Partners roll up to its upline.',
            'Voluntary termination: 7 days\' written notice via the dashboard. Your final Approved balance is paid in the next payout cycle; roll-up applies immediately.',
            'Termination by KAM TEAM: 14 days\' notice for a breach of Section 8; immediate and without notice for fraud.',
            'Basic to Standard upgrade: during the 7-day Basic grace period you may pay US$100 to upgrade. New expiry = payment date + 3 months + 2 years.',
            'One account per person, for life. Duplicate accounts are automatically blocked, all associated funds are forfeited, and there is no appeal.',
        ],
        'agree'  => 'I accept the termination rules and confirm this is my only KAM TEAM account. I understand duplicate accounts lead to a lifetime ban with no appeal.',
    ],
    'conduct' => [
        'number' => '8',
        'title'  => 'Growth Partner Responsibilities & Prohibited Actions',
        'rules'  => [
            'Allowed: email, social media, blogging, paid ads and direct outreach.',
            'Prohibited: spam, false claims, brand bidding, duplicate accounts, self-referrals and fraud.',
            'False claims include promising results KAM TEAM has not authorized (e.g. guaranteed rankings or revenue), implying atypical income or presenting the program as an investment or passive income — including implying commission on clients\' monthly recurring fees.',
            'You must clearly disclose, close to your link or recommendation, that you may earn a commission (e.g. "I earn a commission if you hire KAM TEAM through my link"), in line with the US FTC Endorsement Guides (16 CFR Part 255).',
            'Violation: immediate termination, forfeiture of commissions and a lifetime ban.',
        ],
        'agree'  => 'I will promote KAM TEAM only through allowed channels, will always disclose that I may earn a commission, and will not make false claims.',
    ],
    'privacy' => [
        'number' => '9',
        'title'  => 'Privacy, Data & Consent',
        'rules'  => [
            'KAM TEAM collects your name, email, phone, address, bank or PayPal details, government ID, IP address and device ID for identity verification (KYC), fraud prevention and payouts.',
            'Your data is used to process commissions, comply with the law, prevent fraud and send program updates. KAM TEAM will not sell your data to third parties.',
            'Data is processed under the Philippine Data Privacy Act of 2012 (Republic Act No. 10173). No system is 100% secure; you use the dashboard at your own risk.',
            'You will receive emails and SMS about your account status, payouts and program updates. You may opt out of marketing messages, but not transactional ones.',
            'You may request access to, correction of, or deletion of your data at support@kam-team.com. A deletion request terminates your account under Section 6.2. EU and California residents have additional rights on written request.',
        ],
        'agree'  => 'I consent to KAM TEAM collecting and processing my personal data as described, and to receiving account, payout and program messages.',
    ],
];

// Appendix A — the final e-signature, always shown last.
const TERMS_SIGNATURE = 'I confirm that I am 18 years of age or older and that I agree to all of the terms above. I understand that commissions are earned only on the 50% advance deposit of a package\'s one-time setup fee, that monthly recurring fees are not commissionable, that commissions depend on actual client payments, and that no income is guaranteed. My electronic acceptance is binding under the Philippine Electronic Commerce Act (Republic Act No. 8792).';

// Sections in display order (by section number).
function terms_sections(): array
{
    $s = TERMS_SECTIONS;
    uasort($s, fn($a, $b) => strnatcmp($a['number'], $b['number']));
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
        $list .= '<li><b>§' . e($sec['number']) . '</b> ' . e($sec['title']) . '</li>';
        $rules = '';
        foreach ($sec['rules'] as $rule) $rules .= '<li>' . e($rule) . '</li>';
        $body .= '<section><h4>§' . e($sec['number']) . ' ' . e($sec['title']) . '</h4><ul>' . $rules . '</ul><p class="terms-dlg-agree">' . e($sec['agree']) . '</p></section>';
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
        . '</dialog></fieldset>';
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
