<?php
require_once __DIR__ . '/lib/supabase.php';
require_once __DIR__ . '/lib/logo.php';
require_once __DIR__ . '/lib/work.php';

// Printable client report (SOP-OPS-001 Appendix B). Admins see any version;
// a client sees only their own delivered reports. Print → Save as PDF uses
// the SOP file name as the page title.
['token' => $token, 'profile' => $profile] = require_role(['admin', 'staff', 'client']);
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
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e(report_filename($rep, $cName, $pName)) ?></title>
  <link rel="icon" type="image/png" href="/assets/img/kam-team-mark.png">
  <link rel="stylesheet" href="/assets/styles.css">
</head>
<body class="report-page">
<main class="wrap report-doc">
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
</body>
</html>
