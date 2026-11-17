<?php
use App\Services\Barcode;
$w = (int)$shop['label_width_mm']; $h = (int)$shop['label_height_mm']; $cols = (int)$shop['label_columns'];
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>Barcode labels</title><link rel="stylesheet" href="<?= asset('css/receipt.css') ?>">
<style>.labels{grid-template-columns:repeat(<?= $cols ?>, <?= $w ?>mm)}.label{width:<?= $w ?>mm;height:<?= $h ?>mm}</style></head>
<body>
<div class="toolbar no-print"><button type="button" data-print>Print labels</button><span><?= array_sum(array_column($labels, 'n')) ?> labels · <?= $w ?>×<?= $h ?> mm, <?= $cols ?> per row (change in Settings → Printing). Test-scan one label before printing many.</span></div>
<?php if (!$labels): ?><p style="padding:20px">No items selected.</p><?php endif; ?>
<div class="labels">
<?php foreach ($labels as $l): $v = $l['v']; for ($i = 0; $i < $l['n']; $i++): ?>
  <div class="label"><div class="ln"><?= e($shop['shop_name']) ?></div><div class="ln"><?= e($v['name']) ?></div><div><?= e($v['label']) ?></div>
    <?= Barcode::svg($v['barcode'], 30, 1, true) ?><div class="lp"><?= money($v['price_paise']) ?><?= (int)$v['mrp_paise'] > (int)$v['price_paise'] ? ' <small><s>MRP ' . money($v['mrp_paise']) . '</s></small>' : '' ?></div></div>
<?php endfor; endforeach; ?>
</div>
<script src="<?= asset('js/receipt.js') ?>"></script></body></html>
