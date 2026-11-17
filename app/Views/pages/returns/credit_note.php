<?php use App\Core\Qty; use App\Services\ReportService; ?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><title><?= e($ret['return_no']) ?></title><link rel="stylesheet" href="<?= asset('css/receipt.css') ?>"></head>
<body class="<?= ($shop['printer_format'] ?? '80mm') === 'a4' ? 'a4' : 'thermal' ?>">
<div class="toolbar no-print"><button type="button" data-print>Print</button></div>
<main class="receipt">
  <header class="r-head"><div class="r-shop"><?= e($shop['shop_name']) ?></div><?php if ($shop['shop_gstin']): ?><div>GSTIN: <?= e($shop['shop_gstin']) ?></div><?php endif; ?>
    <div class="r-title">CREDIT NOTE<?= $ret['return_type'] === 'void' ? ' (BILL VOIDED)' : '' ?></div></header>
  <section class="r-meta"><div><span>No</span><strong><?= e($ret['return_no']) ?></strong></div><div><span>Date</span><?= e(date('d-m-Y h:i A', strtotime($ret['created_at']))) ?></div>
    <div><span>Against</span><?= e($ret['original_invoice_no']) ?></div><div><span>Reason</span><?= e($ret['reason']) ?></div></section>
  <table class="r-items"><thead><tr><th class="l">Item</th><th>Qty</th><th>Amount</th></tr></thead><tbody>
    <?php foreach ($ret['items'] as $i): ?><tr><td class="l"><?= e($i['product_name']) ?><div class="sub"><?= e($i['sku']) ?> <?= e($i['variant_label']) ?> · GST <?= bp_pct($i['tax_rate_bp']) ?></div></td><td><?= e(Qty::format((int)$i['qty_milli'], $i['unit'])) ?></td><td><?= money($i['amount_paise'], false) ?></td></tr><?php endforeach; ?>
  </tbody></table>
  <section class="r-totals"><div><span>Taxable</span><span><?= money($ret['taxable_paise']) ?></span></div>
    <?php if ((int)$ret['igst_paise']): ?><div><span>IGST</span><span><?= money($ret['igst_paise']) ?></span></div><?php else: ?><div><span>CGST</span><span><?= money($ret['cgst_paise']) ?></span></div><div><span>SGST</span><span><?= money($ret['sgst_paise']) ?></span></div><?php endif; ?>
    <div class="grand"><span>CREDIT</span><span><?= money($ret['total_paise']) ?></span></div></section>
  <section class="r-pay"><?php foreach ($ret['refunds'] as $f): ?><div><span>Refunded by <?= e(ReportService::methodLabel($f['method'])) ?></span><span><?= money($f['amount_paise']) ?></span></div><?php endforeach; ?>
    <?php if ((int)$ret['exchange_credit_paise']): ?><div><span>Adjusted in exchange</span><span><?= money($ret['exchange_credit_paise']) ?></span></div><?php endif; ?></section>
  <div class="r-barcode"><?= App\Services\Barcode::svg($ret['return_no'], 34, 1.1) ?></div>
</main><script src="<?= asset('js/receipt.js') ?>"></script></body></html>
