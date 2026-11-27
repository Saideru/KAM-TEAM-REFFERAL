<?php
require_once __DIR__ . '/lib/supabase.php';
require_once __DIR__ . '/lib/logo.php';
require_once __DIR__ . '/lib/work.php';
require_once __DIR__ . '/lib/ui.php';

// Printable documents. Two kinds, so the app keeps one print-styled page:
//   ?id=…               a client report (SOP-OPS-001 Appendix B). Admins and
//                       staff see any version; a client sees only their own
//                       delivered ones.
//   ?doc=components     the program reference for admins and staff.
// Print → Save as PDF uses the document's file name as the page title.
['token' => $token, 'profile' => $profile] = require_role(['admin', 'staff', 'client']);
$csrf = csrf_token();

if (($_GET['doc'] ?? '') === 'components') {
    require_once __DIR__ . '/lib/docs.php';
    if (!is_team($profile)) { http_response_code(403); header('Location: ' . dashboard_url($profile['role'])); exit; }
    $back = $profile['role'] === 'admin' ? '/dashboard-admin.php' : '/work.php';
    ?><?= ui_head([
    'title'       => 'KAM-TEAM_Program-Components_v' . PROGRAM_DOC_VERSION,
    'description' => 'What every account type can do inside the KAM Growth Partnership Program.',
    'body_class'  => 'report-page',
    'csrf'        => $csrf,
    'idle'        => true,
]) ?>
<main class="wrap report-doc program-doc" id="main" tabindex="-1">
  <div class="report-actions no-print">
    <a class="btn btn-sm btn-ghost" href="<?= e($back) ?>">← Back</a>
    <button class="btn btn-sm btn-solid" type="button" onclick="window.print()">Print / save as PDF</button>
  </div>
  <header class="report-head">
    <?= kam_lockup() ?>
    <div><span class="label">KAM Growth Partnership Program · internal reference</span>
      <h1><?= e(PROGRAM_DOC_TITLE) ?></h1>
      <p class="hint">Version <?= e(PROGRAM_DOC_VERSION) ?> · <?= e(PROGRAM_DOC_DATE) ?> · What every account type can do inside the program</p></div>
  </header>
  <?= program_doc_html() ?>
  <p class="hint" style="margin-top:20px">KAM TEAM · Program components v<?= e(PROGRAM_DOC_VERSION) ?> · generated from the live program.</p>
</main>
<?= ui_foot() ?>
<?php
    exit;
}

// ?contract=<id>   the signed client onboarding pack (both parties see it)
// ?contract=preview&package=<id>   the blank terms, before anyone signs
if (isset($_GET['contract'])) {
    require_once __DIR__ . '/lib/contract.php';
    $cid = (string) $_GET['contract'];

    if ($cid === 'preview') {
        $pkg = supabase_request('GET', '/rest/v1/packages?select=id,name,price,monthly_price,description&kind=eq.service&id=eq.'
            . urlencode((string) ($_GET['package'] ?? '')) . '&limit=1', null, $token)['body'][0] ?? null;
        if (!$pkg) { http_response_code(404); header('Location: ' . dashboard_url($profile['role'])); exit; }
        $contract = contract_preview_row($pkg);
        $title    = 'KAMTEAM_ContractPack_' . preg_replace('/[^A-Za-z0-9]+/', '', $pkg['name']) . '_PREVIEW';
        $who      = $pkg['name'];
    } else {
        $contract = contract_get($token, $cid);
        // A client sees only their own pack; admins and staff see any.
        if (!$contract || (!is_team($profile) && $contract['client_id'] !== $profile['id'])) {
            http_response_code(404);
            flash('error', 'Agreement not found.');
            header('Location: ' . dashboard_url($profile['role']));
            exit;
        }
        $title = 'KAMTEAM_ContractPack_' . $contract['reference'];
        $who   = ($contract['client']['full_name'] ?? 'Client') . ' · ' . ($contract['packages']['name'] ?? '');
        if (!is_team($profile)) audit_log($token, 'contract_viewed', 'contract', $contract['id'], $contract['reference'] . ' opened by the client');
    }
    $back = is_team($profile) ? '/dashboard-admin.php#contracts' : '/dashboard-client.php#agreement';
    ?><?= ui_head([
    'title'       => $title,
    'description' => 'KAM Team client onboarding and contract pack.',
    'body_class'  => 'report-page',
    'csrf'        => $csrf,
    'idle'        => true,
]) ?>
<main class="wrap report-doc" id="main" tabindex="-1">
  <div class="report-actions no-print">
    <a class="btn btn-sm btn-ghost" href="<?= e($back) ?>">← Back</a>
    <button class="btn btn-sm btn-solid" type="button" onclick="window.print()">Print / save as PDF</button>
  </div>
  <?php if (($contract['status'] ?? '') === 'draft'): ?>
    <div class="alert alert-warn no-print">Preview — nothing is signed and nothing is charged. Sign it from your dashboard.</div>
  <?php elseif (($contract['status'] ?? '') === 'submitted'): ?>
    <div class="alert alert-warn no-print">Signed by the client, waiting for KAM Team's counter-signature.</div>
  <?php endif; ?>
  <header class="report-head">
    <?= kam_lockup() ?>
    <div><span class="label">Client onboarding / contract pack</span>
      <h1><?= e($who) ?></h1>
      <p class="hint"><?= e((string) ($contract['reference'] ?? '')) ?> · <?= e(CONTRACT_STATUSES[$contract['status'] ?? 'draft'] ?? '') ?></p></div>
  </header>
  <?= contract_doc_html($contract) ?>
  <p class="hint" style="margin-top:20px">KAM TEAM · <?= e($title) ?>.pdf · Electronic signatures are recorded with the signer's name, time and IP address.</p>
</main>
<?= ui_foot() ?>
<?php
    exit;
}

$id  = (string) ($_GET['id'] ?? '');
$rep = supabase_request('GET', '/rest/v1/client_reports?select=*,packages(name),client:profiles!client_reports_client_id_fkey(full_name)&id=eq.' . urlencode($id), null, $token)['body'][0] ?? null;

$isAdmin = is_team($profile);   // admins and staff see every version
if (!$rep || (!$isAdmin && ($rep['client_id'] !== $profile['id'] || $rep['status'] !== 'delivered'))) {
    http_response_code(404);
    flash('error', 'Report not found.');
    header('Location: ' . dashboard_url($profile['role']));
    exit;
}

$cName = $rep['client']['full_name'] ?? 'Client';
$pName = $rep['packages']['name'] ?? '';
$data  = $rep['status'] === 'draft' || !$rep['snapshot'] ? report_compile($token, $rep) : $rep['snapshot'];
if (!$isAdmin) audit_log($token, 'report_viewed', 'client_report', $rep['id'], $cName . ' opened v' . $rep['version']);
?>
<?= ui_head([
    'title'       => report_filename($rep, $cName, $pName),
    'description' => 'KAM Team client report.',
    'body_class'  => 'report-page',
    'csrf'        => $csrf,
    'idle'        => true,
]) ?>
<main class="wrap report-doc" id="main" tabindex="-1">
  <div class="report-actions no-print">
    <a class="btn btn-sm btn-ghost" href="<?= $isAdmin ? '/work.php?report=' . e($rep['id']) : '/dashboard-client.php#reports' ?>">← Back</a>
    <button class="btn btn-sm btn-solid" type="button" onclick="window.print()">Print / save as PDF</button>
  </div>
  <?php if ($rep['status'] === 'draft'): ?><div class="alert alert-warn no-print">DRAFT — not approved. Accomplishment tables reflect the live tracker.</div><?php endif; ?>
  <header class="report-head">
    <?= kam_lockup() ?>
    <div><span class="label"><?= e(REPORT_TYPES[$rep['report_type']] ?? 'Client report') ?></span>
      <h1><?= e($cName) ?></h1>
      <p class="hint"><?= e($pName ?: 'All services') ?> · <?= date('M j', strtotime($rep['period_start'])) ?> – <?= date('M j, Y', strtotime($rep['period_end'])) ?> · v<?= str_pad((string) $rep['version'], 2, '0', STR_PAD_LEFT) ?></p></div>
  </header>
  <?= report_body_html($rep, $cName, $pName, $data) ?>
  <p class="hint" style="margin-top:20px">KAM TEAM · Prepared under <?= SOP_ID ?> v<?= SOP_VERSION ?> · <?= e(report_filename($rep, $cName, $pName)) ?>.pdf</p>
</main>
<?= ui_foot() ?>
