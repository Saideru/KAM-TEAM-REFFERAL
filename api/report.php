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
