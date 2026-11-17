<?php
use App\Services\ReportService;
App\Core\View::script('vendor/chartjs/chart.umd.js');
App\Core\View::script('js/dashboard.js');
$pay = array_column($payments, 'amount', 'method');
$rangeLabel = $from === $to ? ($from === today() ? 'Today' : fmt_date($from, false)) : fmt_date($from, false) . ' – ' . fmt_date($to, false);
?>
<div class="page-head">
  <div class="grow"><h2 style="margin:0"><?= e($rangeLabel) ?></h2><p>All figures are calculated from bills saved on this PC.</p></div>
  <form class="filters" method="get">
    <input class="input" type="date" name="from" value="<?= e($from) ?>" aria-label="From">
    <input class="input" type="date" name="to" value="<?= e($to) ?>" aria-label="To">
    <button class="btn">Apply</button>
    <a class="btn btn-ghost" href="<?= e(url('/')) ?>">Today</a>
  </form>
</div>

<div class="grid g4">
  <div class="card stat"><div class="stat-label"><?= icon('receipt') ?> Sales</div><div class="stat-value"><?= money($stats['sales']) ?></div><div class="stat-foot"><?= (int)$stats['bills'] ?> bills · avg <?= money($stats['bills'] ? intdiv($stats['sales'], $stats['bills']) : 0) ?></div></div>
  <div class="card stat"><div class="stat-label"><?= icon('shirt') ?> Items sold</div><div class="stat-value"><?= qty($stats['pieces']) ?> <small class="muted">pcs</small></div><div class="stat-foot"><?= qty($stats['metres'], 'm') ?> metres of fabric</div></div>
  <?php if ($profit): ?>
  <div class="card stat"><div class="stat-label"><?= icon('chart') ?> Gross profit (est.)</div><div class="stat-value"><?= money($profit['gross']) ?></div><div class="stat-foot">Margin <?= bp_pct($profit['gross_margin_bp']) ?> on net sales <?= money($profit['netSales']) ?> (ex-GST)</div></div>
  <?php else: ?>
  <div class="card stat"><div class="stat-label"><?= icon('percent') ?> Discounts</div><div class="stat-value"><?= money($stats['discounts']) ?></div><div class="stat-foot">given on bills</div></div>
  <?php endif; ?>
  <div class="card stat"><div class="stat-label"><?= icon('return') ?> Returns</div><div class="stat-value"><?= money($stats['returns']) ?></div><div class="stat-foot"><?= (int)$stats['returns_count'] ?> credit notes<?= $profit ? ' · discounts ' . money($stats['discounts']) : '' ?></div></div>
</div>

<div class="grid g4" style="margin-top:16px">
  <?php foreach (['cash' => 'cash', 'upi' => 'wallet', 'card' => 'wallet', 'credit' => 'user'] as $m => $ic): ?>
    <div class="card stat"><div class="stat-label"><?= icon($ic) ?> <?= e(ReportService::methodLabel($m)) ?></div><div class="stat-value" style="font-size:1.25rem"><?= money((int)($pay[$m] ?? 0)) ?></div></div>
  <?php endforeach; ?>
</div>

<div class="grid g-main" style="margin-top:16px">
  <div class="card">
    <div class="card-head"><h3>Sales trend</h3>
      <select id="chartDays" class="input-sm" style="width:auto" aria-label="Range"><option value="7">7 days</option><option value="30" selected>30 days</option><option value="90">90 days</option><option value="365">12 months</option></select></div>
    <div class="card-body"><div class="chart-box"><canvas id="cTrend" aria-label="Daily sales chart" role="img"></canvas></div></div>
  </div>
  <div class="card">
    <div class="card-head"><h3>Sync &amp; alerts</h3></div>
    <div class="card-body">
      <ul class="list-plain">
        <li><span class="status-dot <?= $sync['pending'] + $sync['failed'] ? 'warn' : 'ok' ?>"></span><span style="flex:1">Pending sync</span><a href="<?= e(url('/sync')) ?>"><strong><?= (int)($sync['pending'] + $sync['failed']) ?></strong></a></li>
        <li><span class="status-dot <?= $sync['open_conflicts'] ? 'bad' : 'ok' ?>"></span><span style="flex:1">Open sync conflicts</span><strong><?= (int)$sync['open_conflicts'] ?></strong></li>
        <li><span class="status-dot <?= $stats['low_stock'] ? 'warn' : 'ok' ?>"></span><span style="flex:1">Low-stock SKUs</span><a href="<?= e(url('/stock', ['filter' => 'low'])) ?>"><strong><?= (int)$stats['low_stock'] ?></strong></a></li>
      </ul>
      <?php if ($lowItems): ?><h3 style="margin-top:16px">Reorder soon</h3>
        <ul class="list-plain small"><?php foreach ($lowItems as $l): ?><li><span style="flex:1"><?= e($l['product']) ?> <span class="muted"><?= e(trim($l['size'] . ' ' . $l['colour'])) ?></span></span><span class="badge badge-warning plain"><?= qty($l['stock'], $l['unit']) ?> / <?= qty($l['reorder'], $l['unit']) ?></span></li><?php endforeach; ?></ul>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="grid g3" style="margin-top:16px">
  <div class="card"><div class="card-head"><h3>Peak hours</h3></div><div class="card-body"><div class="chart-box sm"><canvas id="cHour" role="img" aria-label="Sales by hour"></canvas></div></div></div>
  <div class="card"><div class="card-head"><h3>Category mix</h3></div><div class="card-body"><div class="chart-box sm"><canvas id="cCat" role="img" aria-label="Sales by category"></canvas></div></div></div>
  <div class="card"><div class="card-head"><h3>Top brands</h3></div><div class="card-body"><div class="chart-box sm"><canvas id="cBrand" role="img" aria-label="Sales by brand"></canvas></div></div></div>
</div>

<div class="grid g-main" style="margin-top:16px">
  <div class="card">
    <div class="card-head"><h3>Latest bills</h3><a class="btn btn-sm" href="<?= e(url('/sales')) ?>">All invoices</a></div>
    <?php if (!$latest): ?><div class="empty"><?= icon('receipt') ?><h3>No bills yet</h3><p>Open <a href="<?= e(url('/pos')) ?>">POS Billing</a> (F2) to make the first sale.</p></div>
    <?php else: ?><div class="table-wrap"><table class="table"><thead><tr><th>Invoice</th><th>Time</th><th>Customer</th><th>Cashier</th><th class="num">Total</th><th>Sync</th></tr></thead><tbody>
      <?php foreach ($latest as $s): ?><tr class="row-link" data-href="<?= e(url('/sales/' . $s['id'])) ?>" tabindex="0">
        <td class="mono"><?= e($s['invoice_no']) ?> <?= $s['status'] === 'voided' ? '<span class="badge badge-danger">Voided</span>' : '' ?></td><td><?= e(fmt_date($s['created_at'])) ?></td>
        <td><?= e($s['customer_name'] ?: 'Walk-in') ?></td><td><?= e($s['cashier']) ?></td><td class="num"><?= money($s['total_paise']) ?></td>
        <td><?= $s['sync_status'] === 'acked' ? '<span class="badge badge-success">Synced</span>' : ($s['sync_status'] === 'conflict' ? '<span class="badge badge-danger">Conflict</span>' : ($s['sync_status'] === 'failed' ? '<span class="badge badge-danger">Failed</span>' : '<span class="badge badge-warning">Pending</span>')) ?></td></tr>
      <?php endforeach; ?></tbody></table></div><?php endif; ?>
  </div>
  <div class="card"><div class="card-head"><h3>Top products (30 days)</h3></div><div class="card-body"><div class="chart-box"><canvas id="cTop" role="img" aria-label="Top products"></canvas></div></div></div>
</div>
<?php if (can('report.profit')): ?>
<div class="card" style="margin-top:16px"><div class="card-head"><h3>Net sales vs cost of goods (12 months)</h3><span class="muted small">Gross margin only — see Profit &amp; loss report for net profit</span></div>
  <div class="card-body"><div class="chart-box"><canvas id="cProfit" role="img" aria-label="Profit trend"></canvas></div></div></div>
<?php endif; ?>
<p class="help-strip" style="margin-top:16px"><span><kbd>F2</kbd> POS</span><span><kbd>F1</kbd> Help</span><span><kbd>/</kbd> Search on list pages</span></p>
