<?php
$q = request()->allQuery();
$link = fn(array $extra) => e(url(request()->path, array_merge($q, ['from' => $report['from'], 'to' => $report['to']], $extra)));
$chart = $report['chart'] ?? null;
if ($chart && $report['rows']) { App\Core\View::script('vendor/chartjs/chart.umd.js'); App\Core\View::script('js/report.js'); }
$quick = ['Today' => [today(), today()], 'Yesterday' => [date('Y-m-d', strtotime('-1 day')), date('Y-m-d', strtotime('-1 day'))],
    'This week' => [date('Y-m-d', strtotime('monday this week')), today()], 'This month' => [date('Y-m-01'), today()],
    'Last month' => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last day of last month'))], 'This year' => [date('Y-01-01'), today()]];
?>
<div class="page-head">
  <form class="filters grow" method="get">
    <input class="input" type="date" name="from" value="<?= e($report['from']) ?>" aria-label="From"><input class="input" type="date" name="to" value="<?= e($report['to']) ?>" aria-label="To">
    <?php if ($report['key'] === 'sales_summary'): ?><select name="group" aria-label="Group by"><?php foreach (['day' => 'Daily', 'week' => 'Weekly', 'month' => 'Monthly', 'year' => 'Yearly'] as $g => $l): ?><option value="<?= $g ?>" <?= $opt['group'] === $g ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select><?php endif; ?>
    <?php if (str_starts_with($report['key'], 'by_')): ?><select name="sort" aria-label="Sort"><option value="">Top by value</option><option value="qty" <?= $opt['sort'] === 'qty' ? 'selected' : '' ?>>Top by quantity</option></select><?php endif; ?>
    <button class="btn">Apply</button>
    <?php foreach ($quick as $l => [$f, $t]): ?><a class="btn btn-sm btn-ghost" href="<?= $link(['from' => $f, 'to' => $t]) ?>"><?= e($l) ?></a><?php endforeach; ?>
  </form>
  <a class="btn" href="<?= $link(['format' => 'csv']) ?>"><?= icon('download') ?> CSV</a>
  <a class="btn" href="<?= $link(['format' => 'print']) ?>" target="_blank" rel="noopener"><?= icon('printer') ?> Print</a>
</div>
<?php if (!empty($report['note'])): ?><div class="alert alert-info"><?= icon('info') ?><div><?= e($report['note']) ?></div></div><?php endif; ?>
<?php if ($chart && $report['rows']): ?>
<div class="card card-pad" style="margin-bottom:16px"><div class="chart-box"><canvas id="reportChart" role="img" aria-label="Chart of <?= e($report['title']) ?>"></canvas></div></div>
<script type="application/json" id="report-chart"><?= json_encode(['type' => $chart['type'], 'labels' => array_map(fn($r) => (string)$r[$chart['label']], array_slice($report['rows'], 0, $chart['limit'] ?? 60)),
    'values' => array_map(fn($r) => (int)$r[$chart['value']], array_slice($report['rows'], 0, $chart['limit'] ?? 60)), 'label' => $report['columns'][$chart['value']][0]], JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php endif; ?>
<div class="card"><?= App\Core\View::partial('pages/reports/_table', ['report' => $report]) ?></div>
<p class="hint"><?= $chart ? 'Chart and table come from the same query. ' : '' ?>Period: <?= e(fmt_date($report['from'], false)) ?> – <?= e(fmt_date($report['to'], false)) ?>.</p>
