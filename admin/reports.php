<?php
require __DIR__ . '/../app/bootstrap.php';
$me = require_perm('reports.view');
ReservationService::expireHolds();

$key = get('report', 'occupancy');
if (!isset(ReportService::REPORTS[$key])) {
    $key = 'occupancy';
}
[$title, $perm, $range] = ReportService::REPORTS[$key];
require_perm($perm);
[$dFrom, $dTo] = ReportService::defaultRange($range);
$f = [
    'from' => valid_date(get('from')) ? get('from') : $dFrom,
    'to' => valid_date(get('to')) ? get('to') : $dTo,
    'area_id' => int_in('area_id'),
    'type' => get('type'),
    'status' => get('status'),
    'exclude_demo' => get('exclude_demo') === '1',
    'include_expired' => get('include_expired') === '1',
];
$error = null;
if ($f['from'] > $f['to']) {
    $error = 'The start date is after the end date, so the dates were swapped.';
    [$f['from'], $f['to']] = [$f['to'], $f['from']];
}
$maxDays = $key === 'occupancy' ? 93 : ($range === 'all' ? 100000 : 731);
if (days_until($f['to']) - days_until($f['from']) > $maxDays) {
    $error = "This report covers at most $maxDays days at a time; the end date was shortened.";
    $f['to'] = date('Y-m-d', strtotime($f['from'] . " +$maxDays days"));
}
$report = ReportService::run($key, $f);
$periodText = $range === 'all' && $f['from'] === $dFrom ? 'All time' : ($f['from'] === $f['to'] ? fmt_date($f['from'], 'l, F j, Y') : fmt_date($f['from']) . ' – ' . fmt_date($f['to']));

if (get('format') === 'csv') {
    if ($key === 'customers') {
        require_perm('customers.export');
    }
    [$tTitle, $cols, $rows] = $report['tables'][0];
    log_activity('Reports', 'Export', "$title ($periodText) exported to CSV");
    send_csv($key . '_' . $f['from'] . '_' . $f['to'] . '.csv', $cols, $rows);
}

// Human-readable list of the filters in use (printed on the PDF and the print view).
$areaNames = [];
foreach (q_all('SELECT area_id, area_name FROM resort_areas') as $a) $areaNames[(int) $a['area_id']] = $a['area_name'];
$filterParts = [];
if (!in_array($key, ['payments', 'customers'], true)) {
    $filterParts[] = 'Area: ' . ($f['area_id'] && isset($areaNames[$f['area_id']]) ? $areaNames[$f['area_id']] : 'All');
    $filterParts[] = 'Session: ' . (in_array($f['type'], ['DAYTIME', 'OVERNIGHT'], true) ? type_short($f['type']) : 'All');
}
if ($f['status'] !== '') $filterParts[] = 'Status: ' . ucwords(strtolower($f['status']));
if ($key === 'cancellations') $filterParts[] = $f['include_expired'] ? 'Expired holds included' : 'Expired holds excluded';
$filterParts[] = $f['exclude_demo'] ? 'Demo data excluded' : 'Demo data included';
$filterText = implode(' · ', $filterParts);

if (get('format') === 'pdf') {
    if ($key === 'customers') {
        require_perm('customers.export');
    }
    log_activity('Reports', 'Export', "$title ($periodText) exported to PDF");
    $pdf = new PdfReport(
        $report['title'],
        ['Period: ' . $periodText, 'Filters: ' . $filterText, 'Generated ' . date('M j, Y g:i A') . ' by ' . $me['full_name'] . ' (Administrator) - website management'],
        array_map(fn($s) => [(string) $s[0], (string) $s[1]], $report['summary']),
        (string) $report['description'],
        $report['tables'],
        (string) setting('resort_name', "Agie's Resort")
    );
    $pdf->send('Agies-Resort_' . $key . '_' . $f['from'] . '_to_' . $f['to'] . '.pdf');
}

if (get('format') === 'print') {
    log_activity('Reports', 'Print', "$title ($periodText)");
    ?><!doctype html><html lang="en"><head><meta charset="utf-8"><title><?= e($report['title']) ?></title>
    <link rel="stylesheet" href="<?= e(url('assets/css/app.css')) ?>"></head><body style="padding:1.5rem">
    <h1 style="font-size:1.5rem"><?= e(setting('resort_name', "Agie's Resort")) ?> — <?= e($report['title']) ?></h1>
    <p><strong>Period:</strong> <?= e($periodText) ?> · <strong>Generated:</strong> <?= e(date('M j, Y g:i A')) ?> by <?= e($me['full_name']) ?><br><strong>Filters:</strong> <?= e($filterText) ?></p>
    <p style="font-size:.9rem"><?= e($report['description']) ?></p>
    <p><?php foreach ($report['summary'] as [$l, $v]): ?><strong><?= e($l) ?>:</strong> <?= e($v) ?> &nbsp; <?php endforeach; ?></p>
    <?php foreach ($report['tables'] as [$tt, $cols, $rows]): ?>
      <h2 style="font-size:1.1rem;margin-top:1rem"><?= e($tt) ?></h2>
      <?php if (!$rows): ?><p>None.</p><?php else: ?><table><thead><tr><?php foreach ($cols as $c): ?><th><?= e($c) ?></th><?php endforeach; ?></tr></thead>
      <tbody><?php foreach ($rows as $r): ?><tr><?php foreach ($r as $v): ?><td><?= e($v) ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody></table><?php endif; ?>
    <?php endforeach; ?>
    <p class="no-print" style="margin-top:1rem"><button class="btn" type="button" data-print>Print</button> <a href="?<?= e(http_build_query(array_diff_key($_GET, ['format' => 1]))) ?>">Back</a></p>
    <script src="<?= e(url('assets/js/app.js')) ?>"></script><script>window.addEventListener('load', function () { window.print(); });</script>
    </body></html><?php
    exit;
}

$areas = q_all('SELECT area_id, area_name FROM resort_areas ORDER BY sort_order, area_id');
$qs = $_GET;
$pageTitle = $title;
$active = 'reports';
require __DIR__ . '/../app/views/admin_header.php';
?>
<h1>Reports</h1>
<nav class="filters no-print" aria-label="Reports">
  <?php foreach (ReportService::REPORTS as $k => [$t, $pp]): if (!can($pp)) continue; ?>
    <a href="?report=<?= e($k) ?>" aria-current="<?= $k === $key ? 'true' : 'false' ?>"><?= e($t) ?></a>
  <?php endforeach; ?>
</nav>

<form class="toolbar panel no-print" method="get">
  <input type="hidden" name="report" value="<?= e($key) ?>">
  <div class="field"><label for="from">From</label><input type="date" id="from" name="from" value="<?= e($f['from']) ?>"></div>
  <div class="field"><label for="to">To</label><input type="date" id="to" name="to" value="<?= e($f['to']) ?>"></div>
  <?php if (!in_array($key, ['payments', 'customers'], true)): ?>
    <div class="field"><label for="area_id">Area</label><select id="area_id" name="area_id"><option value="">All</option><?php foreach ($areas as $a): ?><option value="<?= (int) $a['area_id'] ?>"<?= $f['area_id'] === (int) $a['area_id'] ? ' selected' : '' ?>><?= e($a['area_name']) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label for="type">Session</label><select id="type" name="type"><option value="">All</option><option value="DAYTIME"<?= $f['type'] === 'DAYTIME' ? ' selected' : '' ?>>Daytime</option><option value="OVERNIGHT"<?= $f['type'] === 'OVERNIGHT' ? ' selected' : '' ?>>Overnight</option></select></div>
  <?php endif; ?>
  <?php $statusOpts = match ($key) {
      'reservations' => ReservationService::ALL, 'reschedules' => ['PENDING', 'APPROVED', 'REJECTED'],
      'payments' => ['PENDING VERIFICATION', 'VERIFIED', 'REJECTED'], 'customers' => ['ACTIVE', 'INACTIVE'], default => [] };
  if ($statusOpts): ?>
    <div class="field"><label for="status">Status</label><select id="status" name="status"><option value="">All</option><?php foreach ($statusOpts as $s): ?><option value="<?= e($s) ?>"<?= $f['status'] === $s ? ' selected' : '' ?>><?= e(ucwords(strtolower($s))) ?></option><?php endforeach; ?></select></div>
  <?php endif; ?>
  <?php if ($key === 'cancellations'): ?><label class="check"><input type="checkbox" name="include_expired" value="1"<?= $f['include_expired'] ? ' checked' : '' ?>> Include expired holds</label><?php endif; ?>
  <label class="check"><input type="checkbox" name="exclude_demo" value="1"<?= $f['exclude_demo'] ? ' checked' : '' ?>> Exclude demo data</label>
  <button class="btn" type="submit">Run report</button>
  <a class="btn btn-ghost" href="?report=<?= e($key) ?>">Reset to default period</a>
</form>
<?php if ($error): ?><div class="flash flash-info"><?= e($error) ?></div><?php endif; ?>

<section class="panel">
  <div class="cal-head">
    <div><h2 style="margin:0"><?= e($report['title']) ?> — <?= e($periodText) ?></h2><p class="hint" style="margin:.2rem 0 0">Filters: <?= e($filterText) ?></p></div>
    <div class="actions no-print" style="margin:0">
      <?php if ($key !== 'customers' || can('customers.export')): ?><a class="btn btn-success btn-small" data-pdf-export data-filename="<?= e('Agies-Resort_' . $key . '_' . $f['from'] . '_to_' . $f['to'] . '.pdf') ?>" href="?<?= e(http_build_query(['report' => $key, 'format' => 'pdf'] + $qs)) ?>">Export Report (PDF)</a><?php endif; ?>
      <a class="btn btn-ghost btn-small" href="?<?= e(http_build_query($qs + ['report' => $key, 'format' => 'print'])) ?>" target="_blank" rel="noopener">Print view</a>
      <?php if ($key !== 'customers' || can('customers.export')): ?><a class="btn btn-ghost btn-small" href="?<?= e(http_build_query($qs + ['report' => $key, 'format' => 'csv'])) ?>">Download CSV</a><?php endif; ?>
    </div>
  </div>
  <p class="muted" style="margin-top:.6rem"><?= e($report['description']) ?></p>
  <div class="metrics">
    <?php foreach ($report['summary'] as [$l, $v]): ?><div class="metric"><b><?= e($v) ?></b><span><?= e($l) ?></span></div><?php endforeach; ?>
  </div>
  <?php foreach ($report['tables'] as $i => [$tt, $cols, $rows]): ?>
    <h3><?= e($tt) ?></h3>
    <?php if (!$rows): ?><p class="muted">No records for this period.</p><?php else: ?>
    <div class="table-wrap" style="margin-bottom:1.2rem"><table>
      <thead><tr><?php foreach ($cols as $c): ?><th><?= e($c) ?></th><?php endforeach; ?></tr></thead>
      <tbody><?php foreach ($rows as $r): ?><tr><?php foreach ($r as $v): ?><td><?= e($v) ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody>
    </table></div><?php endif; ?>
    <?php if ($i === 0 && count($report['tables']) > 1): ?><p class="hint">CSV export contains this first table.</p><?php endif; ?>
  <?php endforeach; ?>
</section>
<?php require __DIR__ . '/../app/views/admin_footer.php'; ?>
