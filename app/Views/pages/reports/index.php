<?php
$groups = ['Sales' => ['sales_summary', 'hourly', 'weekday', 'payments', 'discounts', 'returns', 'by_cashier'],
    'What sells' => ['by_product', 'by_variant', 'by_size', 'by_color', 'by_category', 'by_brand'],
    'Stock & purchasing' => ['low_stock', 'valuation', 'slow_movers', 'stock_movements', 'purchases'],
    'Money' => ['profit', 'expenses']];
$desc = ['profit' => 'Net sales, cost of goods, gross profit and net operating profit — with every formula explained.', 'sales_summary' => 'Daily / weekly / monthly / yearly totals.',
    'hourly' => 'Which hours are busiest.', 'weekday' => 'Which days are busiest.', 'slow_movers' => 'Items not selling, with stock age.', 'valuation' => 'Stock value at weighted-average cost.'];
?>
<div class="grid g2">
<?php foreach ($groups as $g => $keys): $keys = array_filter($keys, fn($k) => isset($reports[$k])); if (!$keys) continue; ?>
  <div class="card"><div class="card-head"><h3><?= e($g) ?></h3></div><ul class="list-plain" style="padding:0 20px">
    <?php foreach ($keys as $k): ?><li><?= icon($k === 'profit' ? 'chart' : 'activity') ?><div style="flex:1"><a class="cell-title" href="<?= e(url('/reports/' . $k)) ?>"><?= e($reports[$k][0]) ?></a><?php if (isset($desc[$k])): ?><div class="cell-sub"><?= e($desc[$k]) ?></div><?php endif; ?></div></li><?php endforeach; ?>
  </ul></div>
<?php endforeach; ?>
</div>
