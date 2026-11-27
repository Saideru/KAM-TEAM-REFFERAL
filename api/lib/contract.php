<?php
// =====================================================================
// KAM Team // Client onboarding contract pack + e-signature
// ---------------------------------------------------------------------
// The path a client takes to avail a service:
//
//   package  ->  sign the agreement  ->  SENT TO ADMIN  ->  admin
//   completes the open terms and counter-signs  ->  approved  ->  the
//   50% advance deposit unlocks  ->  approving it opens the project.
//
// One contract row is one pack: master service agreement + order form +
// the selected service schedule + onboarding authorisation + both
// signatures. The commercial figures and the schedule are frozen into
// the row at signing, so a later price change never rewrites a signed
// agreement.
//
// This file holds the document itself (clauses and schedules), the two
// POST handlers (client side, admin side) and the renderers.
// =====================================================================

require_once __DIR__ . '/supabase.php';
require_once __DIR__ . '/chat.php';

const CONTRACT_STATUSES = [
    'draft'     => 'Not sent yet',
    'submitted' => 'Signed — waiting for KAM Team',
    'approved'  => 'Approved and counter-signed',
    'declined'  => 'Returned by KAM Team',
    'void'      => 'Void',
];

// A drawn signature is a PNG data URI. Anything bigger than this is not
// a signature.
const SIGNATURE_MAX_BYTES = 400000;

// ---------------------------------------------------------------------
// The document (KAM TEAM / Client onboarding — contract pack)
// ---------------------------------------------------------------------

const MSA_CLAUSES = [
    ['Agreement structure', 'The parties agree that this master agreement, the signed order form, the selected service schedule(s), and any signed amendments define the engagement. Only selected services are included. Specific signed order terms prevail over general terms for the relevant project.'],
    ['Scope of work', 'KAM TEAM will perform the selected setup and monthly management services with reasonable professional care, subject to the agreed specifications, dependencies and client approvals.'],
    ['Fees and payment', 'All package prices are in USD. A 50% down payment is required before starting any one-time setup service. 10% of this down payment will be allocated to KAM / Affiliate Marketing. This allocation is part of the down payment, not an additional client fee. The remaining setup balance, monthly billing commencement, invoice due dates, taxes and approved third-party costs are specified in the order form.'],
    ['Delivery and acceptance', 'The parties agree in writing on milestones, deliverables, acceptance criteria, review periods and included revisions. KAM TEAM will notify the client when work is ready for review. The client will identify any material deviation from agreed specifications in writing; KAM TEAM will address confirmed in-scope deficiencies. Changes to requirements are handled under clause 6.'],
    ['Client responsibilities', 'The client will provide accurate business information, lawful content, necessary account permissions, a designated approver and timely feedback. The client is responsible for verifying business claims and authorizing publication, advertising spend and outreach. Delayed dependencies may require a revised schedule agreed by the parties.'],
    ['Changes and exclusions', 'Work beyond the selected schedule or agreed limits requires a written change order describing the additional scope, fees and timing before work begins. Advertising spend, hosting, domains, software subscriptions, messaging usage and other external costs are not assumed included unless expressly stated in the order form.'],
    ['Monthly services and support', 'Monthly management covers the tasks in the selected schedule and any agreed limits. Support hours, response targets, reporting frequency and maintenance coverage are documented in the order form. New features and major redesigns require separate approval.'],
    ['Performance expectations', 'KAM TEAM does not guarantee search rankings, AI citations, leads, sales, revenue, platform approvals or uninterrupted third-party services. Results depend on market conditions, client inputs, budgets and external platforms.'],
    ['Confidentiality', 'Each party will protect nonpublic business, technical and customer information received from the other, use it only for the engagement, and disclose it only to authorized persons with appropriate confidentiality duties or when legally required. Public or independently obtained information is excluded.'],
    ['Data protection and access', 'The parties will use authorized access, least-privilege permissions where feasible, and reasonable safeguards. The client confirms authority to provide data and instruct processing. KAM TEAM will notify the client without undue delay of known incidents affecting client data. Where required, the parties will execute a separate data processing agreement before processing personal data. Sensitive or regulated information must not be shared without agreed safeguards.'],
    ['Intellectual property and licenses', 'Each party retains its pre-existing materials. Ownership or licensing of custom deliverables, source code, reusable components and handover materials is specified in the order form. Third-party software remains subject to its own license terms. The client grants KAM TEAM permission to use supplied materials solely to deliver the engagement.'],
    ['Compliance and platform rules', 'Each party will comply with applicable laws and relevant platform terms. The client is responsible for lawful business claims, marketing permissions, consent records and, where applicable, the legality of its commission or network marketing plan. KAM TEAM will not knowingly implement unlawful instructions.'],
    ['Liability and disputes', 'Any negotiated liability limits, exclusions and exceptions are recorded in the order form or an addendum, subject to applicable law. The parties will first attempt to resolve disputes through good-faith discussion. Governing law and dispute venue are stated in the order form.'],
    ['Term and termination', 'The service term, renewal, notice, cancellation, refund and suspension rules are agreed in the order form. On termination, the parties will settle amounts properly due and coordinate authorized data export, access removal and handover under the agreed exit terms. Confidentiality and applicable ownership obligations survive termination.'],
];

// What each service schedule includes, from the contract pack. Keyed by
// the package name in the database; a package with no entry here falls
// back to its own description.
const SERVICE_SCHEDULES = [
    'GHL Business Systems Development & Automation' => [
        'setup' => ['Website development', 'SEO / AEO / GEO', 'Landing page / sales funnel', 'Email and SMS automation', 'CRM setup and pipeline configuration', 'Lead nurturing'],
        'monthly' => ['Basic CRM maintenance', 'Workflow monitoring and troubleshooting', 'CRM and marketing automation management', 'Dedicated GHL system management'],
    ],
    'Complete Google SEO Setup & Optimization' => [
        'setup' => ['Google Search Console and GA4', 'Google Business Profile optimization, where eligible', 'Technical SEO audit and agreed fixes', 'Keyword and competitor research', 'On-page optimization for up to 10 pages', 'Sitemap, indexing, and schema configuration', 'Internal linking and performance improvement', 'Final SEO report and handover'],
        'monthly' => ['Monitoring', 'Reporting', 'Content optimization', 'Technical maintenance'],
    ],
    'Full-Service Digital Marketing Package' => [
        'setup' => ['Complete digital marketing strategy', 'Professional business website', 'Google SEO and Search Console setup', 'Google Business Profile optimization, if eligible', 'Google Ads and Meta Ads setup', 'Social media branding and account optimization', 'GHL CRM, pipelines, and lead management', 'Email and SMS marketing automation', 'AI lead qualification and automation follow-up'],
        'monthly' => ['Ongoing SEO', 'Google and Meta Ads management', 'Defined social media content production', 'Email marketing', 'CRM monitoring', 'Reporting', 'Optimization'],
    ],
    'Custom Business Operations Management System' => [
        'setup' => ['Planning, software development, testing, and deployment', 'Business operations dashboard', 'Employee and user management', 'Role-based permissions', 'Workflow and task automation', 'Client and customer management', 'Internal reporting and analytics', 'Database and API integration', 'Basic security controls', 'Deployment and administrator training'],
        'monthly' => ['Routine monitoring', 'Security patches', 'Bug fixes', 'Backup oversight', 'Limited technical support'],
    ],
    'Customized Integrated Digital & Network Marketing Business System' => [
        'setup' => ['System planning and architecture', 'Digital marketing, CRM, and automation', 'Network marketing and commission system', 'Member and administrator dashboards', 'Database and integrations', 'Testing, deployment, and training'],
        'monthly' => ['Routine system monitoring', 'Backup oversight', 'Security patches', 'Bug fixes', 'Limited technical support'],
    ],
    'Complete AI SEO, AEO & GEO Optimization' => [
        'setup' => ['Complete technical Google SEO setup', 'Search Console, GA4, and tracking', 'Keyword research and competitor analysis', 'On-page optimization for up to 10 pages', 'Schema markup and structured data', 'Content optimization for AI-generated answers', 'Business entity and online presence optimization', 'AI search visibility baseline and monitoring setup', 'Final audit report and optimization roadmap'],
        'monthly' => ['Defined ongoing technical monitoring', 'Content improvement', 'AI visibility checks', 'Performance analysis', 'Reporting'],
    ],
];

// 04 / Onboarding authorisation — the client ticks each one.
const ACCESS_ACKS = [
    'invites'   => 'I will provide role-based account invitations where applicable.',
    'configure' => 'I authorize agreed configuration and testing within approved accounts.',
    'approval'  => 'I understand my approval is required before public launch, paid campaigns or live outreach.',
    'consent'   => 'I will confirm applicable consent, privacy and data handling requirements.',
];

// ---------------------------------------------------------------------
// Reading
// ---------------------------------------------------------------------

// Everything except the two signature images — list views never need the
// pixels, and they are the big part of the row.
const CONTRACT_LIST_COLS = 'id,reference,client_id,package_id,status,setup_fee,monthly_fee,down_payment,setup_balance,'
    . 'client_legal_name,client_signed_name,client_signed_title,client_signed_at,kam_signed_name,kam_signed_at,'
    . 'decline_note,created_at,updated_at';

function contract_get(string $token, string $id): ?array
{
    $res = supabase_request('GET', '/rest/v1/contracts?select=*,packages(name,price,monthly_price,description),'
        . 'client:profiles!contracts_client_id_fkey(id,full_name,company,email,phone,username)'
        . '&id=eq.' . urlencode($id) . '&limit=1', null, $token);
    return (!$res['error'] && !empty($res['body'][0])) ? $res['body'][0] : null;
}

/** The live contract for one client + package, whatever its state. */
function contract_for_package(string $token, string $clientId, string $packageId): ?array
{
    $res = supabase_request('GET', '/rest/v1/contracts?select=' . CONTRACT_LIST_COLS
        . '&client_id=eq.' . urlencode($clientId) . '&package_id=eq.' . urlencode($packageId)
        . '&status=in.(draft,submitted,approved)&order=created_at.desc&limit=1', null, $token);
    return (!$res['error'] && !empty($res['body'][0])) ? $res['body'][0] : null;
}

/**
 * The most recent contract for one client + package whatever its state —
 * so a returned (declined) one can still show the client what to change.
 */
function contracts_last_for_package(string $token, string $clientId, string $packageId): ?array
{
    $res = supabase_request('GET', '/rest/v1/contracts?select=' . CONTRACT_LIST_COLS
        . '&client_id=eq.' . urlencode($clientId) . '&package_id=eq.' . urlencode($packageId)
        . '&order=created_at.desc&limit=1', null, $token);
    return (!$res['error'] && !empty($res['body'][0])) ? $res['body'][0] : null;
}

/** Every contract for one client, newest first (no signature images). */
function contracts_for_client(string $token, string $clientId): array
{
    $res = supabase_request('GET', '/rest/v1/contracts?select=' . CONTRACT_LIST_COLS . ',packages(name)'
        . '&client_id=eq.' . urlencode($clientId) . '&order=created_at.desc', null, $token);
    return (!$res['error'] && is_array($res['body'])) ? $res['body'] : [];
}

/**
 * One client's contracts keyed by package id, newest first — so a page
 * that shows every package asks the database once, not once per card.
 * Pass the rows from contracts_for_client() to reuse that same fetch.
 */
function contracts_by_package(array $rows): array
{
    $out = [];
    foreach ($rows as $c) {
        $pid = (string) ($c['package_id'] ?? '');
        if ($pid === '' || isset($out[$pid])) continue;   // newest wins
        $out[$pid] = $c;
    }
    return $out;
}

/** The admin queue. $status filters; omit for everything. */
function contracts_list(string $token, ?string $status = null): array
{
    $res = supabase_request('GET', '/rest/v1/contracts?select=' . CONTRACT_LIST_COLS . ',packages(name),'
        . 'client:profiles!contracts_client_id_fkey(full_name,company)'
        . ($status ? '&status=eq.' . urlencode($status) : '')
        . '&order=created_at.desc&limit=200', null, $token);
    return (!$res['error'] && is_array($res['body'])) ? $res['body'] : [];
}

function contracts_pending_count(string $token): int
{
    return count(contracts_list($token, 'submitted'));
}

/** Is this package unlocked for this client — i.e. is the pack signed by both sides? */
function contract_approved_for(string $token, string $clientId, string $packageId): bool
{
    $c = contract_for_package($token, $clientId, $packageId);
    return $c !== null && $c['status'] === 'approved';
}

function contract_schedule_of(array $package): array
{
    $name = (string) ($package['name'] ?? '');
    $sch  = SERVICE_SCHEDULES[$name] ?? null;
    return [
        'name'    => $name,
        'setup_fee'   => (float) ($package['price'] ?? 0),
        'monthly_fee' => (float) ($package['monthly_price'] ?? 0),
        'setup'   => $sch['setup']   ?? array_values(array_filter(array_map('trim', explode('.', (string) ($package['description'] ?? ''))))),
        'monthly' => $sch['monthly'] ?? [],
    ];
}

/** The Growth Partner who referred this client, for the record. */
function contract_referrer_name(string $token, array $profile): ?string
{
    if (empty($profile['referred_by'])) return null;
    $res = supabase_request('GET', '/rest/v1/profiles?select=full_name,referral_code&id=eq.'
        . urlencode((string) $profile['referred_by']) . '&limit=1', null, $token);
    $who = (!$res['error'] && !empty($res['body'][0])) ? $res['body'][0] : null;
    return $who ? trim($who['full_name'] . (!empty($who['referral_code']) ? ' (' . $who['referral_code'] . ')' : '')) : null;
}

function contract_status_pill(string $s): string
{
    $cls = ['approved' => 'active', 'submitted' => 'pending', 'declined' => 'failed', 'void' => 'failed'][$s] ?? '';
    return '<span class="pill ' . $cls . '">' . e(CONTRACT_STATUSES[$s] ?? $s) . '</span>';
}

function contract_money(float $n): string { return '$' . number_format($n, 2); }

// A drawn signature: a PNG data URI, and not a megabyte of one.
function contract_signature_ok(string $data): bool
{
    return $data !== '' && preg_match('#^data:image/(png|jpeg);base64,[A-Za-z0-9+/=]+$#', $data) === 1
        && strlen($data) <= SIGNATURE_MAX_BYTES;
}

// ---------------------------------------------------------------------
// The client's side: complete and e-sign, then send it to the admin
// ---------------------------------------------------------------------

/**
 * Handles the client's agreement form. Call after csrf_check().
 * Returns null when the post was not a contract action, else [error, success].
 */
function contract_client_action(string $token, array $profile): ?array
{
    $act = (string) ($_POST['contract_action'] ?? '');
    if ($act === '') return null;
    if (chat_role($profile) !== 'client') return ['Only a client signs this agreement.', null];

    $s = fn(string $k, int $max = 2000) => mb_substr(trim((string) ($_POST[$k] ?? '')), 0, $max);

    if ($act === 'withdraw') {
        $id  = $s('contract_id', 64);
        $res = supabase_request('PATCH', '/rest/v1/contracts?id=eq.' . urlencode($id)
            . '&client_id=eq.' . urlencode($profile['id']) . '&status=eq.submitted',
            ['status' => 'void', 'decline_note' => 'Withdrawn by the client before review.'], $token, ['Prefer: return=representation']);
        if ($res['error'] || empty($res['body'])) return ['That agreement can no longer be withdrawn.', null];
        audit_log($token, 'contract_withdrawn', 'contract', $id, 'Client withdrew their signed agreement');
        return [null, 'Agreement withdrawn. You can sign a new one any time.'];
    }

    if ($act !== 'sign') return ['Unknown action.', null];

    // ---- the package being availed ----
    $pkgId = $s('package_id', 64);
    $pkg   = $pkgId ? (supabase_request('GET', '/rest/v1/packages?select=id,name,price,monthly_price,description,kind,active&id=eq.'
        . urlencode($pkgId) . '&limit=1', null, $token)['body'][0] ?? null) : null;
    if (!$pkg || $pkg['kind'] !== 'service' || empty($pkg['active'])) return ['That service package is not available.', null];

    $existing = contract_for_package($token, $profile['id'], $pkgId);
    if ($existing && $existing['status'] === 'approved')  return ['This agreement is already approved — you can pay the deposit.', null];
    if ($existing && $existing['status'] === 'submitted') return ['You already sent this agreement. KAM Team is reviewing it.', null];

    // ---- what they filled in ----
    $sig  = (string) ($_POST['client_signature'] ?? '');
    $name = $s('client_signed_name', 160);
    $acks = array_keys(array_intersect_key((array) ($_POST['ack'] ?? []), ACCESS_ACKS));

    $errors = [];
    if ($s('client_legal_name', 200) === '') $errors[] = 'Enter the client legal name / company name.';
    if ($s('client_rep', 160) === '')        $errors[] = 'Enter the authorized representative and title.';
    if ($s('client_address', 300) === '')    $errors[] = 'Enter the client business address.';
    if ($s('client_email', 160) === '' && $s('client_phone', 60) === '') $errors[] = 'Enter an email address or a phone number.';
    if ($s('business_profile', 300) === '')  $errors[] = 'Enter the business name, website and industry.';
    if (count($acks) < count(ACCESS_ACKS))   $errors[] = 'Tick all four onboarding authorizations.';
    if (($_POST['agree'] ?? '') !== '1')     $errors[] = 'Confirm that you agree to the master service agreement, order form and selected schedule.';
    if ($name === '')                        $errors[] = 'Type your full legal name as your signature.';
    if (!contract_signature_ok($sig))        $errors[] = 'Draw your signature in the box.';
    if ($errors) return [implode(' ', $errors), null];

    $setup   = (float) $pkg['price'];
    $monthly = (float) $pkg['monthly_price'];
    $down    = round($setup * 0.5, 2);

    $row = [
        'client_id'   => $profile['id'],
        'package_id'  => $pkg['id'],
        'status'      => 'submitted',
        'setup_fee'   => $setup,
        'monthly_fee' => $monthly,
        'down_payment' => $down,
        'setup_balance' => round($setup - $down, 2),
        'kam_legal_name' => 'KAM TEAM',
        'client_legal_name' => $s('client_legal_name', 200),
        'client_address'    => $s('client_address', 300),
        'client_rep'        => $s('client_rep', 160),
        'client_email'      => $s('client_email', 160) ?: ($profile['email'] ?? null),
        'client_phone'      => $s('client_phone', 60) ?: ($profile['phone'] ?? null),
        'business_profile'  => $s('business_profile', 300),
        'contacts'          => $s('contacts', 300) ?: null,
        'audience_goals'    => $s('audience_goals') ?: null,
        'brand_assets'      => $s('brand_assets') ?: null,
        'existing_platforms' => $s('existing_platforms') ?: null,
        'access_restrictions' => $s('access_restrictions') ?: null,
        'access_ack'        => array_fill_keys($acks, true),
        'kickoff_date'      => ($k = $s('kickoff_date', 20)) !== '' && strtotime($k) ? date('Y-m-d', strtotime($k)) : null,
        'schedule'          => contract_schedule_of($pkg),
        // Who the client has actually been dealing with at KAM TEAM.
        'kam_contacts'      => chat_people_who_dealt_with($token, $profile['id']),
        'referred_by_name'  => contract_referrer_name($token, $profile),
        'client_signature'  => $sig,
        'client_signed_name' => $name,
        'client_signed_title' => $s('client_signed_title', 120) ?: null,
        'client_signed_at'  => date('c'),
        'client_sign_ip'    => client_ip(),
        'client_sign_agent' => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 300),
    ];

    if ($existing) {
        $res = supabase_request('PATCH', '/rest/v1/contracts?id=eq.' . urlencode($existing['id']), $row, $token, ['Prefer: return=representation']);
    } else {
        $res = supabase_request('POST', '/rest/v1/contracts', $row, $token, ['Prefer: return=representation']);
    }
    if ($res['error'] || empty($res['body'][0])) {
        return [db_error_message($res['error']) ?: 'Could not send the agreement. Please try again.', null];
    }
    $contract = $res['body'][0];

    // The Growth Partner who referred this client is part of the record,
    // and signing is the moment the referral is Converted (Manual §4).
    require_once __DIR__ . '/pipeline.php';
    referral_stage_set($token, $profile['id'], 'converted', ['stage_package_id' => $pkg['id']]);

    audit_log($token, 'contract_signed', 'contract', $contract['id'],
        $contract['reference'] . ' — ' . $pkg['name'] . ' signed by ' . $name . ' and sent for approval');
    notify($token, 'Agreement to approve: ' . $pkg['name'],
        ($profile['full_name'] ?? 'A client') . ' signed the contract pack (' . $contract['reference'] . '). Review it, complete the open order-form terms and counter-sign.',
        null, 'admin', '/dashboard-admin.php?contract=' . $contract['id'] . '#contracts');

    return [null, 'Agreement signed and sent to KAM Team. You can pay the deposit once it is counter-signed.'];
}

// ---------------------------------------------------------------------
// The admin's side: complete the open terms, counter-sign or return it
// ---------------------------------------------------------------------

function contract_admin_action(string $token, array $profile): ?array
{
    $act = (string) ($_POST['contract_admin_action'] ?? '');
    if ($act === '') return null;
    if (($profile['role'] ?? '') !== 'admin') return ['Only an admin approves an agreement.', null];

    $s  = fn(string $k, int $max = 2000) => mb_substr(trim((string) ($_POST[$k] ?? '')), 0, $max);
    $d  = fn(string $k) => ($v = trim((string) ($_POST[$k] ?? ''))) !== '' && strtotime($v) ? date('Y-m-d', strtotime($v)) : null;
    $id = $s('contract_id', 64);
    $contract = $id ? contract_get($token, $id) : null;
    if (!$contract) return ['Agreement not found.', null];
    if ($contract['status'] !== 'submitted') return ['That agreement is not waiting for review.', null];

    if ($act === 'decline') {
        $note = $s('decline_note', 1000);
        if ($note === '') return ['Tell the client what to change.', null];
        supabase_request('PATCH', '/rest/v1/contracts?id=eq.' . urlencode($contract['id']), [
            'status' => 'declined', 'decline_note' => $note,
            'reviewed_by' => $profile['id'], 'reviewed_at' => date('c'),
        ], $token, ['Prefer: return=minimal']);
        audit_log($token, 'contract_declined', 'contract', $contract['id'], $contract['reference'] . ' returned to the client: ' . mb_substr($note, 0, 120));
        notify($token, 'Agreement returned: ' . ($contract['packages']['name'] ?? 'your package'),
            $note, $contract['client_id'], 'all', '/dashboard-client.php#agreement');
        return [null, 'Agreement returned to the client.'];
    }

    if ($act !== 'approve') return ['Unknown action.', null];

    $sig  = (string) ($_POST['kam_signature'] ?? '');
    $name = $s('kam_signed_name', 160);
    if ($name === '')                 return ['Type your full name as the KAM TEAM signature.', null];
    if (!contract_signature_ok($sig)) return ['Draw the KAM TEAM signature in the box.', null];

    $patch = [
        'status' => 'approved',
        'kam_legal_name' => $s('kam_legal_name', 200) ?: ($contract['kam_legal_name'] ?? 'KAM TEAM'),
        'kam_address'    => $s('kam_address', 300) ?: null,
        'balance_due_terms'  => $s('balance_due_terms', 300) ?: null,
        'billing_start'      => $s('billing_start', 200) ?: null,
        'taxes_payment'      => $s('taxes_payment', 300) ?: null,
        'kickoff_date'       => $d('kickoff_date') ?: ($contract['kickoff_date'] ?? null),
        'target_launch'      => $d('target_launch'),
        'deliverable_limits' => $s('deliverable_limits') ?: null,
        'acceptance_criteria' => $s('acceptance_criteria') ?: null,
        'content_cadence'    => $s('content_cadence') ?: null,
        'support_terms'      => $s('support_terms') ?: null,
        'term_renewal'       => $s('term_renewal') ?: null,
        'refund_exit'        => $s('refund_exit') ?: null,
        'ownership_terms'    => $s('ownership_terms') ?: null,
        'liability_terms'    => $s('liability_terms') ?: null,
        'governing_law'      => $s('governing_law', 200) ?: null,
        'package_exclusions' => $s('package_exclusions') ?: null,
        'kam_signature'   => $sig,
        'kam_signed_name' => $name,
        'kam_signed_title' => $s('kam_signed_title', 120) ?: null,
        'kam_signed_at'   => date('c'),
        'kam_signed_by'   => $profile['id'],
        'reviewed_by'     => $profile['id'],
        'reviewed_at'     => date('c'),
        'decline_note'    => null,
    ];
    $res = supabase_request('PATCH', '/rest/v1/contracts?id=eq.' . urlencode($contract['id']) . '&status=eq.submitted',
        $patch, $token, ['Prefer: return=representation']);
    if ($res['error'] || empty($res['body'])) return [db_error_message($res['error']) ?: 'Could not approve that agreement.', null];

    audit_log($token, 'contract_approved', 'contract', $contract['id'],
        $contract['reference'] . ' counter-signed by ' . $name . ' — ' . ($contract['packages']['name'] ?? 'package')
        . ' · deposit ' . contract_money((float) $contract['down_payment']) . ' unlocked');
    notify($token, 'Agreement approved — you can pay the deposit',
        'Your ' . ($contract['packages']['name'] ?? 'service package') . ' agreement is counter-signed. The 50% advance deposit of '
        . contract_money((float) $contract['down_payment']) . ' is now open in your dashboard.',
        $contract['client_id'], 'all', '/dashboard-client.php#packages');

    return [null, 'Agreement counter-signed. The client can now pay the deposit.'];
}

// ---------------------------------------------------------------------
// Rendering — shared pieces
// ---------------------------------------------------------------------

/** The signature pad: a canvas plus the hidden field it writes into. */
function contract_sign_pad(string $field, string $label): string
{
    return '<div class="field"><label>' . e($label) . '</label>'
        . '<div class="sign-pad" data-sign-pad>'
        .   '<canvas width="600" height="180" aria-label="Signature drawing area"></canvas>'
        .   '<input type="hidden" name="' . e($field) . '" data-sign-value>'
        .   '<div class="sign-pad-tools"><span class="hint">Draw your signature with a mouse, pen or finger</span>'
        .     '<button type="button" class="btn btn-sm btn-ghost" data-sign-clear>Clear</button></div>'
        . '</div></div>';
}

/** Who at KAM TEAM has dealt with this client — for the pack and the review. */
function contract_contacts_html(array $rows, bool $compact = false): string
{
    if (!$rows) return '<p class="hint" style="margin:0">No KAM Team member has messaged this client yet.</p>';
    $out = '<ul class="contract-contacts">';
    foreach ($rows as $r) {
        $when = !empty($r['last_at']) ? date('M j, Y', strtotime($r['last_at'])) : null;
        $out .= '<li><strong>' . e($r['full_name'] ?? '—') . '</strong>'
            . '<span class="role-badge ' . e($r['role'] ?? '') . '">' . e(role_name((string) ($r['role'] ?? ''))) . '</span>'
            . '<span class="hint">' . (!empty($r['assigned']) ? 'assigned to this account' : 'messaged this client')
            . (!empty($r['messages']) ? ' · ' . (int) $r['messages'] . ' message' . ((int) $r['messages'] === 1 ? '' : 's') : '')
            . ($when ? ' · last ' . e($when) : ' · no messages yet') . '</span></li>';
    }
    return $out . '</ul>';
}

// ---------------------------------------------------------------------
// Rendering — the client's agreement page
// ---------------------------------------------------------------------

/**
 * The client's agreement for one package: the state it is in, and the
 * form to complete and e-sign when there is nothing signed yet.
 */
function contract_client_form_html(string $token, array $profile, string $csrf, array $pkg, ?array $contract): string
{
    $setup = (float) $pkg['price'];
    $down  = round($setup * 0.5, 2);
    $sch   = contract_schedule_of($pkg);
    $v     = fn(string $k, string $fallback = '') => e((string) ($contract[$k] ?? $fallback));

    // Already signed and sent, or approved: show where it stands.
    if ($contract && in_array($contract['status'], ['submitted', 'approved'], true)) {
        $out = '<div class="contract-state">' . contract_status_pill($contract['status'])
            . '<span class="hint">' . e($contract['reference']) . ' · signed by ' . e((string) $contract['client_signed_name'])
            . ' on ' . e(date('M j, Y H:i', strtotime((string) $contract['client_signed_at']))) . '</span>'
            . '<a class="btn btn-sm" href="/report.php?contract=' . e($contract['id']) . '">Open the signed pack →</a>';
        if ($contract['status'] === 'submitted') {
            $out .= '<form method="post" onsubmit="return confirm(\'Withdraw this agreement before KAM Team reviews it?\')">'
                . '<input type="hidden" name="csrf" value="' . e($csrf) . '">'
                . '<input type="hidden" name="contract_action" value="withdraw">'
                . '<input type="hidden" name="contract_id" value="' . e($contract['id']) . '">'
                . '<button class="btn btn-sm btn-ghost" type="submit">Withdraw</button></form>';
        }
        $out .= '</div>';
        if ($contract['status'] === 'approved') {
            $out .= '<p class="hint" style="margin:8px 0 0">Counter-signed by ' . e((string) $contract['kam_signed_name'])
                . ' on ' . e(date('M j, Y', strtotime((string) $contract['kam_signed_at'])))
                . '. The ' . e(contract_money((float) $contract['down_payment'])) . ' advance deposit is open below.</p>';
        }
        return $out;
    }

    $out = '';
    if ($contract && $contract['status'] === 'declined' && !empty($contract['decline_note'])) {
        $out .= '<div class="alert alert-warn" style="margin:0 0 10px"><strong>KAM Team returned your last agreement.</strong> '
            . e($contract['decline_note']) . ' Make the change below and sign again.</div>';
    }

    $list = function (array $items): string {
        if (!$items) return '';
        return '<ul class="contract-list">' . implode('', array_map(fn($i) => '<li>' . e($i) . '</li>', $items)) . '</ul>';
    };

    $out .= '<details class="contract-form"><summary class="btn btn-solid">Review &amp; sign the agreement →</summary>'
        . '<div class="contract-form-body">'
        . '<p class="hint">This is the KAM TEAM client onboarding pack: the master service agreement, the order form for this package, its service schedule and the onboarding authorisation. Complete your side, sign it, and it goes to an admin for counter-signature. <a href="/report.php?contract=preview&amp;package=' . e($pkg['id']) . '" target="_blank" rel="noopener">Open the full terms in a new tab →</a></p>'

        // what they are agreeing to buy
        . '<div class="contract-figures"><div><span class="label">One-time setup</span><strong>' . e(contract_money($setup)) . '</strong></div>'
        . '<div><span class="label">50% down payment</span><strong class="cyan">' . e(contract_money($down)) . '</strong></div>'
        . '<div><span class="label">Remaining balance</span><strong>' . e(contract_money($setup - $down)) . '</strong></div>'
        . '<div><span class="label">Monthly</span><strong>' . e(contract_money((float) $pkg['monthly_price'])) . '</strong></div></div>'
        . '<div class="cols" style="margin-top:10px">'
        .   '<div><span class="label">Included in setup</span>' . $list($sch['setup']) . '</div>'
        .   '<div><span class="label">Included monthly</span>' . $list($sch['monthly']) . '</div>'
        . '</div>'

        . '<form method="post" class="contract-fields">'
        . '<input type="hidden" name="csrf" value="' . e($csrf) . '">'
        . '<input type="hidden" name="contract_action" value="sign">'
        . '<input type="hidden" name="package_id" value="' . e($pkg['id']) . '">'

        . '<h3>01 · The client</h3>'
        . '<div class="row"><div class="field"><label>Client legal name / company name</label><input name="client_legal_name" required maxlength="200" value="' . $v('client_legal_name', (string) ($profile['company'] ?: $profile['full_name'])) . '"></div>'
        . '<div class="field"><label>Authorized representative / title</label><input name="client_rep" required maxlength="160" value="' . $v('client_rep', (string) $profile['full_name']) . '"></div></div>'
        . '<div class="field"><label>Client business address</label><input name="client_address" required maxlength="300" value="' . $v('client_address') . '"></div>'
        . '<div class="row"><div class="field"><label>Email</label><input type="email" name="client_email" maxlength="160" value="' . $v('client_email', (string) ($profile['email'] ?? '')) . '"></div>'
        . '<div class="field"><label>Phone</label><input name="client_phone" maxlength="60" value="' . $v('client_phone', (string) ($profile['phone'] ?? '')) . '"></div></div>'

        . '<h3>02 · Onboarding authorisation</h3>'
        . '<p class="hint" style="margin:0 0 8px">Provide account invitations through secure channels. <strong>Never write passwords on this form.</strong></p>'
        . '<div class="field"><label>Business name / website / industry</label><input name="business_profile" required maxlength="300" value="' . $v('business_profile') . '"></div>'
        . '<div class="field"><label>Primary contact / decision maker / billing contact</label><input name="contacts" maxlength="300" value="' . $v('contacts') . '"></div>'
        . '<div class="row"><div class="field"><label>Target audience / service areas / business goals</label><textarea name="audience_goals" rows="2">' . $v('audience_goals') . '</textarea></div>'
        . '<div class="field"><label>Brand assets / guidelines / approved claims</label><textarea name="brand_assets" rows="2">' . $v('brand_assets') . '</textarea></div></div>'
        . '<div class="field"><label>Existing platforms — website / CRM / analytics / advertising accounts</label><textarea name="existing_platforms" rows="2">' . $v('existing_platforms') . '</textarea></div>'
        . '<div class="row"><div class="field"><label>Preferred kickoff date</label><input type="date" name="kickoff_date" value="' . $v('kickoff_date') . '"></div>'
        . '<div class="field"><label>Special access restrictions / approval process</label><input name="access_restrictions" maxlength="300" value="' . $v('access_restrictions') . '"></div></div>';

    foreach (ACCESS_ACKS as $k => $text) {
        $out .= '<label class="terms"><input type="checkbox" name="ack[' . e($k) . ']" value="1"><span>' . e($text) . '</span></label>';
    }

    $out .= '<h3>03 · Signature</h3>'
        . '<p class="hint" style="margin:0 0 8px">By signing you agree to the master service agreement, this order form and the selected service schedule, and you acknowledge the 50% setup down payment and the KAM / Affiliate Marketing allocation described in clause 3. Your name, the time, and your IP address are recorded with the signature.</p>'
        . '<label class="terms"><input type="checkbox" name="agree" value="1"><span>I have read and agree to the agreement, the order form and the service schedule above.</span></label>'
        . '<div class="row"><div class="field"><label>Full legal name (typed)</label><input name="client_signed_name" required maxlength="160" placeholder="' . e((string) $profile['full_name']) . '"></div>'
        . '<div class="field"><label>Title <span class="hint">(optional)</span></label><input name="client_signed_title" maxlength="120"></div></div>'
        . contract_sign_pad('client_signature', 'Signature')
        . '<button class="btn btn-solid" type="submit">Sign &amp; send to KAM Team →</button>'
        . '<p class="hint" style="margin:8px 0 0">Nothing is charged by signing. The deposit opens once an admin counter-signs.</p>'
        . '</form></div></details>';

    return $out;
}

// ---------------------------------------------------------------------
// Rendering — the admin review panel
// ---------------------------------------------------------------------

function contract_admin_html(string $token, array $profile, string $csrf, ?string $openId = null): string
{
    $all      = contracts_list($token);
    $waiting  = array_values(array_filter($all, fn($c) => $c['status'] === 'submitted'));
    $hid      = fn(array $f) => '<input type="hidden" name="csrf" value="' . e($csrf) . '">'
        . implode('', array_map(fn($k, $v) => '<input type="hidden" name="' . e($k) . '" value="' . e((string) $v) . '">', array_keys($f), $f));

    $out = '<section class="card" style="margin-bottom:12px"><h2>Service agreements</h2>'
        . '<p class="hint" style="margin:0 0 10px">A client signs the contract pack before they can pay for a package. Review what they signed, fill in the terms the order form leaves open, and counter-sign — that unlocks their 50% advance deposit.</p>';

    if (!$waiting) {
        $out .= '<p class="empty">Nothing waiting for signature.</p>';
    }

    foreach ($waiting as $row) {
        $c = contract_get($token, $row['id']);
        if (!$c) continue;
        $open = $openId === $c['id'] || count($waiting) === 1;
        $out .= '<details class="contract-review"' . ($open ? ' open' : '') . '><summary>'
            . '<strong>' . e($c['reference']) . '</strong> · ' . e($c['client']['full_name'] ?? '—')
            . ' · ' . e($c['packages']['name'] ?? 'package') . ' · ' . e(contract_money((float) $c['setup_fee']))
            . ' <span class="hint">signed ' . e(date('M j, Y H:i', strtotime((string) $c['client_signed_at']))) . '</span></summary>'
            . '<div class="contract-review-body">'
            . '<p><a class="btn btn-sm" href="/report.php?contract=' . e($c['id']) . '" target="_blank" rel="noopener">Open the full pack →</a></p>'

            . '<div class="cols">'
            .   '<div><span class="label">Client</span><p style="margin:4px 0 0;font-size:13px">' . e((string) $c['client_legal_name'])
            .     '<br>' . e((string) $c['client_rep']) . '<br>' . e((string) $c['client_address'])
            .     '<br>' . e(trim((string) $c['client_email'] . ' ' . (string) $c['client_phone'])) . '</p></div>'
            .   '<div><span class="label">Signed</span><p style="margin:4px 0 0;font-size:13px">' . e((string) $c['client_signed_name'])
            .     ($c['client_signed_title'] ? ', ' . e((string) $c['client_signed_title']) : '')
            .     '<br>' . e(date('M j, Y H:i', strtotime((string) $c['client_signed_at'])))
            .     '<br><span class="hint">IP ' . e((string) ($c['client_sign_ip'] ?? '—')) . '</span></p>'
            .     ($c['client_signature'] ? '<img class="sign-img" src="' . e((string) $c['client_signature']) . '" alt="Client signature">' : '')
            .   '</div>'
            . '</div>'

            . '<span class="label" style="display:block;margin-top:10px">KAM Team people who have dealt with this client</span>'
            . contract_contacts_html(is_array($c['kam_contacts'] ?? null) ? $c['kam_contacts'] : [])

            . '<form method="post" style="margin-top:12px">' . $hid(['contract_admin_action' => 'approve', 'contract_id' => $c['id']])
            . '<h3>Order form — terms to confirm</h3>'
            . '<div class="row"><div class="field"><label>KAM TEAM legal contracting name</label><input name="kam_legal_name" maxlength="200" value="' . e((string) ($c['kam_legal_name'] ?? 'KAM TEAM')) . '"></div>'
            .   '<div class="field"><label>KAM TEAM business address</label><input name="kam_address" maxlength="300" value="' . e((string) ($c['kam_address'] ?? '')) . '"></div></div>'
            . '<div class="row"><div class="field"><label>Remaining setup balance due date / milestone</label><input name="balance_due_terms" maxlength="300" value="' . e((string) ($c['balance_due_terms'] ?? '')) . '"></div>'
            .   '<div class="field"><label>Monthly billing start / invoice due date</label><input name="billing_start" maxlength="200" value="' . e((string) ($c['billing_start'] ?? '')) . '"></div></div>'
            . '<div class="row"><div class="field"><label>Kickoff date</label><input type="date" name="kickoff_date" value="' . e((string) ($c['kickoff_date'] ?? '')) . '"></div>'
            .   '<div class="field"><label>Target launch</label><input type="date" name="target_launch" value="' . e((string) ($c['target_launch'] ?? '')) . '"></div></div>'
            . '<div class="field"><label>Taxes / approved third-party charges / payment method</label><input name="taxes_payment" maxlength="300" value="' . e((string) ($c['taxes_payment'] ?? '')) . '"></div>'
            . '<div class="row"><div class="field"><label>Deliverable quantities, page limits, integrations, revision limits</label><textarea name="deliverable_limits" rows="2">' . e((string) ($c['deliverable_limits'] ?? '')) . '</textarea></div>'
            .   '<div class="field"><label>Acceptance criteria / client review period</label><textarea name="acceptance_criteria" rows="2">' . e((string) ($c['acceptance_criteria'] ?? '')) . '</textarea></div></div>'
            . '<div class="row"><div class="field"><label>Monthly content quantities / reporting cadence</label><textarea name="content_cadence" rows="2">' . e((string) ($c['content_cadence'] ?? '')) . '</textarea></div>'
            .   '<div class="field"><label>Support hours / response targets / maintenance limits</label><textarea name="support_terms" rows="2">' . e((string) ($c['support_terms'] ?? '')) . '</textarea></div></div>'
            . '<div class="row"><div class="field"><label>Initial term / renewal / cancellation notice</label><textarea name="term_renewal" rows="2">' . e((string) ($c['term_renewal'] ?? '')) . '</textarea></div>'
            .   '<div class="field"><label>Refund / suspension / exit and handover</label><textarea name="refund_exit" rows="2">' . e((string) ($c['refund_exit'] ?? '')) . '</textarea></div></div>'
            . '<div class="row"><div class="field"><label>Deliverable ownership / source code / license rights</label><textarea name="ownership_terms" rows="2">' . e((string) ($c['ownership_terms'] ?? '')) . '</textarea></div>'
            .   '<div class="field"><label>Liability limits and exceptions, if agreed</label><textarea name="liability_terms" rows="2">' . e((string) ($c['liability_terms'] ?? '')) . '</textarea></div></div>'
            . '<div class="row"><div class="field"><label>Governing law / dispute venue</label><input name="governing_law" maxlength="200" value="' . e((string) ($c['governing_law'] ?? '')) . '"></div>'
            .   '<div class="field"><label>Package-specific specifications / exclusions</label><input name="package_exclusions" maxlength="500" value="' . e((string) ($c['package_exclusions'] ?? '')) . '"></div></div>'

            . '<h3>Counter-signature</h3>'
            . '<div class="row"><div class="field"><label>Full name (typed)</label><input name="kam_signed_name" required maxlength="160" value="' . e((string) ($profile['full_name'] ?? '')) . '"></div>'
            .   '<div class="field"><label>Title</label><input name="kam_signed_title" maxlength="120" placeholder="Authorized representative"></div></div>'
            . contract_sign_pad('kam_signature', 'KAM TEAM signature')
            . '<button class="btn btn-solid btn-sm" type="submit">Counter-sign &amp; unlock the ' . e(contract_money((float) $c['down_payment'])) . ' deposit →</button>'
            . '</form>'

            . '<form method="post" class="contract-decline">' . $hid(['contract_admin_action' => 'decline', 'contract_id' => $c['id']])
            . '<input name="decline_note" maxlength="1000" required placeholder="What does the client need to change?">'
            . '<button class="btn btn-sm btn-ghost" type="submit">Return to client</button></form>'

            . '</div></details>';
    }
    $out .= '</section>';

    // The register: everything, whatever its state.
    $out .= '<section class="card"><h2>All agreements</h2>';
    if (!$all) {
        $out .= '<p class="empty">No agreements yet.</p>';
    } else {
        $out .= '<div class="table-scroll" tabindex="0" role="region" aria-label="Agreements"><table><thead><tr>'
            . '<th>Reference</th><th>Client</th><th>Package</th><th>Setup / deposit</th><th>Signed</th><th>Status</th><th></th></tr></thead><tbody>';
        foreach ($all as $c) {
            $out .= '<tr><td><strong>' . e($c['reference']) . '</strong></td>'
                . '<td style="font-size:12px">' . e($c['client']['full_name'] ?? '—') . '</td>'
                . '<td style="font-size:12px">' . e($c['packages']['name'] ?? '—') . '</td>'
                . '<td style="font-size:12px">' . e(contract_money((float) $c['setup_fee'])) . '<br><span class="hint">' . e(contract_money((float) $c['down_payment'])) . ' down</span></td>'
                . '<td style="font-size:12px">' . ($c['client_signed_at'] ? e(date('M j, Y', strtotime((string) $c['client_signed_at']))) : '—')
                . ($c['kam_signed_at'] ? '<br><span class="hint">counter-signed ' . e(date('M j', strtotime((string) $c['kam_signed_at']))) . '</span>' : '') . '</td>'
                . '<td>' . contract_status_pill($c['status']) . '</td>'
                . '<td><a class="btn btn-sm" href="/report.php?contract=' . e($c['id']) . '" target="_blank" rel="noopener">Open</a></td></tr>';
        }
        $out .= '</tbody></table></div>';
    }
    return $out . '</section>';
}

// ---------------------------------------------------------------------
// Rendering — the printable pack (report.php?contract=…)
// ---------------------------------------------------------------------

/**
 * The whole pack as a printable document. $contract may be a signed row,
 * or — for the preview a client opens before signing — a package-only
 * skeleton built by contract_preview_row().
 */
function contract_doc_html(array $c): string
{
    $row  = fn(string $k, ?string $v) => '<div class="profile-row"><span class="label">' . e($k) . '</span><span>'
        . ($v !== null && trim($v) !== '' ? nl2br(e($v)) : '—') . '</span></div>';
    $sch  = is_array($c['schedule'] ?? null) ? $c['schedule'] : [];
    $acks = is_array($c['access_ack'] ?? null) ? $c['access_ack'] : [];
    $list = fn(array $items) => $items ? '<ul class="contract-list">' . implode('', array_map(fn($i) => '<li>' . e($i) . '</li>', $items)) . '</ul>' : '<p class="empty">—</p>';

    // 01 — master service agreement
    $h = '<section class="report-sec"><h2>01 · Service agreement</h2><div class="profile-info">'
        . $row('KAM TEAM legal contracting name', $c['kam_legal_name'] ?? 'KAM TEAM')
        . $row('KAM TEAM business address', $c['kam_address'] ?? null)
        . $row('Client legal name / company name', $c['client_legal_name'] ?? null)
        . $row('Client business address', $c['client_address'] ?? null)
        . $row('Authorized client representative / title', $c['client_rep'] ?? null)
        . $row('Client email / phone', trim((string) ($c['client_email'] ?? '') . ' / ' . (string) ($c['client_phone'] ?? '')))
        . $row('Effective date / project reference', ($c['client_signed_at'] ? date('M j, Y', strtotime((string) $c['client_signed_at'])) : 'on signature') . ' / ' . (string) ($c['reference'] ?? '—'))
        . '</div>';
    foreach (MSA_CLAUSES as $i => [$title, $text]) {
        $h .= '<h3>' . ($i + 1) . '. ' . e($title) . '</h3><p>' . e($text) . '</p>';
    }
    $h .= '</section>';

    // 02 — order form
    $h .= '<section class="report-sec"><h2>02 · Order form</h2><div class="profile-info">'
        . $row('Selected package', $sch['name'] ?? ($c['packages']['name'] ?? null))
        . $row('Total setup fee', contract_money((float) ($c['setup_fee'] ?? 0)))
        . $row('50% down payment', contract_money((float) ($c['down_payment'] ?? 0)))
        . $row('Remaining setup balance', contract_money((float) ($c['setup_balance'] ?? 0)))
        . $row('Total monthly fee', contract_money((float) ($c['monthly_fee'] ?? 0)))
        . $row('Remaining setup balance due date / milestone', $c['balance_due_terms'] ?? null)
        . $row('Monthly billing start / recurring invoice due date', $c['billing_start'] ?? null)
        . $row('Taxes / approved third-party charges / payment method', $c['taxes_payment'] ?? null)
        . $row('Kickoff date / target launch', trim(($c['kickoff_date'] ? date('M j, Y', strtotime((string) $c['kickoff_date'])) : '—')
            . ' / ' . ($c['target_launch'] ? date('M j, Y', strtotime((string) $c['target_launch'])) : '—')))
        . $row('Deliverable quantities, page limits, integrations and revision limits', $c['deliverable_limits'] ?? null)
        . $row('Acceptance criteria / client review period', $c['acceptance_criteria'] ?? null)
        . $row('Monthly content quantities / reporting cadence', $c['content_cadence'] ?? null)
        . $row('Support hours / response targets / maintenance limits', $c['support_terms'] ?? null)
        . $row('Initial term / renewal terms / cancellation notice', $c['term_renewal'] ?? null)
        . $row('Refund / suspension / exit and handover terms', $c['refund_exit'] ?? null)
        . $row('Deliverable ownership / source code / license rights', $c['ownership_terms'] ?? null)
        . $row('Liability limits and exceptions, if agreed', $c['liability_terms'] ?? null)
        . $row('Governing law / dispute venue', $c['governing_law'] ?? null)
        . '</div></section>';

    // 03 — service schedule
    $h .= '<section class="report-sec"><h2>03 · Service schedule — ' . e($sch['name'] ?? '') . '</h2>'
        . '<p class="hint">One-time setup ' . e(contract_money((float) ($c['setup_fee'] ?? 0)))
        . ' | Monthly ' . e(contract_money((float) ($c['monthly_fee'] ?? 0))) . '</p>'
        . '<h3>I. One-time setup / development</h3>' . $list($sch['setup'] ?? [])
        . '<h3>II. Monthly maintenance / management</h3>' . $list($sch['monthly'] ?? [])
        . '<div class="profile-info">' . $row('Agreed package-specific specifications / exclusions', $c['package_exclusions'] ?? null) . '</div></section>';

    // 04 — onboarding authorisation
    $h .= '<section class="report-sec"><h2>04 · Onboarding authorization</h2><div class="profile-info">'
        . $row('Business name / website / industry', $c['business_profile'] ?? null)
        . $row('Primary contact / decision maker / billing contact', $c['contacts'] ?? null)
        . $row('Target audience / service areas / business goals', $c['audience_goals'] ?? null)
        . $row('Brand assets / guidelines / approved claims', $c['brand_assets'] ?? null)
        . $row('Existing platforms / website / CRM / analytics / advertising', $c['existing_platforms'] ?? null)
        . $row('Special access restrictions / approval process', $c['access_restrictions'] ?? null)
        . '</div><ul class="contract-list contract-acks">';
    foreach (ACCESS_ACKS as $k => $text) {
        $h .= '<li>[' . (!empty($acks[$k]) ? 'x' : ' ') . '] ' . e($text) . '</li>';
    }
    $h .= '</ul></section>';

    // 05 — who dealt with the client, then the signatures
    $contacts = is_array($c['kam_contacts'] ?? null) ? $c['kam_contacts'] : [];
    $h .= '<section class="report-sec"><h2>05 · KAM TEAM contact record</h2>'
        . '<div class="profile-info">' . $row('Referred by (Growth Partner)', $c['referred_by_name'] ?? null) . '</div>'
        . '<p class="hint">The KAM TEAM people assigned to this account and those who have exchanged messages with the client since the referral, as at signing.</p>'
        . contract_contacts_html($contacts) . '</section>';

    $sig = function (?string $img, ?string $name, ?string $title, ?string $at, ?string $ip = null): string {
        return '<div class="sign-block">'
            . ($img ? '<img class="sign-img" src="' . e($img) . '" alt="Signature">' : '<div class="sign-img sign-empty">Not signed</div>')
            . '<div class="sign-meta"><strong>' . e($name ?: '—') . '</strong>'
            . ($title ? '<span>' . e($title) . '</span>' : '')
            . '<span>' . ($at ? e(date('M j, Y H:i', strtotime($at))) : '—') . '</span>'
            . ($ip ? '<span class="hint">Signed electronically from ' . e($ip) . '</span>' : '')
            . '</div></div>';
    };

    $h .= '<section class="report-sec"><h2>06 · Execution</h2>'
        . '<p>By signing below, each authorized representative agrees to the master service agreement, the completed order form, the selected service schedule and any expressly incorporated signed addenda. The parties acknowledge the 50% setup down payment requirement and the KAM / Affiliate Marketing allocation described in clause 3. Electronic signatures and counterparts may be used where permitted by applicable law.</p>'
        . '<div class="sign-row">'
        .   '<div><span class="label">Client authorized representative</span>'
        .     $sig($c['client_signature'] ?? null, $c['client_signed_name'] ?? null, $c['client_signed_title'] ?? null, $c['client_signed_at'] ?? null, $c['client_sign_ip'] ?? null) . '</div>'
        .   '<div><span class="label">KAM TEAM authorized representative</span>'
        .     $sig($c['kam_signature'] ?? null, $c['kam_signed_name'] ?? null, $c['kam_signed_title'] ?? null, $c['kam_signed_at'] ?? null) . '</div>'
        . '</div></section>';

    return $h;
}

/** A pack with nothing filled in, so a client can read the terms first. */
function contract_preview_row(array $pkg): array
{
    $setup = (float) ($pkg['price'] ?? 0);
    return [
        'reference' => 'PREVIEW', 'status' => 'draft',
        'kam_legal_name' => 'KAM TEAM',
        'setup_fee' => $setup, 'down_payment' => round($setup * 0.5, 2),
        'setup_balance' => round($setup - round($setup * 0.5, 2), 2),
        'monthly_fee' => (float) ($pkg['monthly_price'] ?? 0),
        'schedule' => contract_schedule_of($pkg),
        'access_ack' => [], 'kam_contacts' => [],
    ];
}
