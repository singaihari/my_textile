<?php
/** @var array $sale @var array $shop @var string $format */
use App\Core\Qty;
use App\Services\Barcode;
use App\Services\ReportService;

$isA4 = $format === 'a4';
$showTax = ($shop['show_tax_breakup'] ?? '1') === '1';
$isB2B = !empty($sale['customer_gstin']);
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><title><?= e($sale['invoice_no']) ?></title>
<link rel="stylesheet" href="<?= asset('css/receipt.css') ?>">
</head>
<body class="<?= $isA4 ? 'a4' : 'thermal' ?>">
<div class="toolbar no-print">
  <a href="?format=80mm&kind=<?= e($kind) ?>">80 mm</a> · <a href="?format=a4&kind=<?= e($kind) ?>">A4</a>
  <button type="button" data-print>Print</button> <span>Preview — printing logs a reprint; the bill itself never changes.</span>
</div>
<main class="receipt">
  <header class="r-head">
    <div class="r-shop"><?= e($shop['shop_name']) ?></div>
    <?php if ($shop['shop_address']): ?><div><?= nl2br(e($shop['shop_address'])) ?></div><?php endif; ?>
    <?php if ($shop['shop_phone']): ?><div>Ph: <?= e($shop['shop_phone']) ?></div><?php endif; ?>
    <?php if ($shop['shop_gstin']): ?><div>GSTIN: <?= e($shop['shop_gstin']) ?> · State: <?= e($shop['shop_state_name']) ?> (<?= e($shop['shop_state_code']) ?>)</div><?php endif; ?>
    <div class="r-title"><?= $shop['shop_gstin'] ? 'TAX INVOICE' : 'INVOICE' ?><?= $sale['status'] === 'voided' ? ' — VOIDED' : '' ?><?= !empty($isDuplicate) ? ' (DUPLICATE COPY)' : '' ?></div>
  </header>
  <section class="r-meta">
    <div><span>Bill No</span><strong><?= e($sale['invoice_no']) ?></strong></div>
    <div><span>Date</span><?= e(date('d-m-Y h:i A', strtotime($sale['created_at']))) ?></div>
    <div><span>Cashier</span><?= e($sale['cashier_name']) ?></div>
    <?php if ($sale['customer_name']): ?><div><span>Customer</span><?= e($sale['customer_name']) ?><?= $sale['customer_phone'] ? ' · ' . e(substr($sale['customer_phone'], 0, 2) . '******' . substr($sale['customer_phone'], -2)) : '' ?></div><?php endif; ?>
    <?php if ($isB2B): ?><div><span>Cust. GSTIN</span><?= e($sale['customer_gstin']) ?></div><?php endif; ?>
    <?php if ($sale['is_interstate']): ?><div><span>Supply</span>Inter-state (IGST)</div><?php endif; ?>
    <?php if (!empty($exchange)): ?><div><span>Exchange</span>against <?= e($exchange['original_invoice']) ?> / <?= e($exchange['return_no']) ?></div><?php endif; ?>
  </section>
  <table class="r-items">
    <thead><tr><th class="l">Item</th><?php if ($isA4): ?><th>HSN</th><?php endif; ?><th>Qty</th><th>Rate</th><?php if ($isA4): ?><th>Disc</th><th>GST%</th><?php endif; ?><th>Amount</th></tr></thead>
    <tbody>
    <?php foreach ($sale['items'] as $it): $disc = (int)$it['line_discount_paise'] + (int)$it['bill_discount_alloc_paise']; ?>
      <tr>
        <td class="l"><?= e($it['product_name']) ?><?php if ($it['variant_label']): ?><div class="sub"><?= e($it['variant_label']) ?></div><?php endif; ?>
          <div class="sub"><?= e($it['sku']) ?><?php if (!$isA4 && $disc): ?> · disc <?= money($disc, false) ?><?php endif; ?><?php if (!$isA4 && $it['hsn_code']): ?> · HSN <?= e($it['hsn_code']) ?><?php endif; ?></div></td>
        <?php if ($isA4): ?><td><?= e($it['hsn_code']) ?></td><?php endif; ?>
        <td><?= e(Qty::format((int)$it['qty_milli'], $it['unit'])) ?><?= $it['unit'] === 'm' ? ' m' : '' ?></td>
        <td><?= money($it['unit_price_paise'], false) ?></td>
        <?php if ($isA4): ?><td><?= $disc ? money($disc, false) : '-' ?></td><td><?= bp_pct($it['tax_rate_bp']) ?></td><?php endif; ?>
        <td><?= money($it['line_total_paise'], false) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <section class="r-totals">
    <div><span>Items / Qty</span><span><?= (int)$sale['items_count'] ?> / <?= e(Qty::format((int)$sale['qty_total_milli'], 'pcs')) ?></span></div>
    <div><span>Gross</span><span><?= money($sale['gross_paise']) ?></span></div>
    <?php if ((int)$sale['line_discount_paise'] + (int)$sale['bill_discount_paise'] > 0): ?><div><span>Discount</span><span>-<?= money((int)$sale['line_discount_paise'] + (int)$sale['bill_discount_paise']) ?></span></div><?php endif; ?>
    <div><span>Taxable value</span><span><?= money($sale['taxable_paise']) ?></span></div>
    <?php if ($sale['is_interstate']): ?><div><span>IGST</span><span><?= money($sale['igst_paise']) ?></span></div>
    <?php else: ?><div><span>CGST</span><span><?= money($sale['cgst_paise']) ?></span></div><div><span>SGST</span><span><?= money($sale['sgst_paise']) ?></span></div><?php endif; ?>
    <?php if ((int)$sale['round_off_paise'] !== 0): ?><div><span>Round off</span><span><?= money($sale['round_off_paise']) ?></span></div><?php endif; ?>
    <div class="grand"><span>TOTAL</span><span><?= money($sale['total_paise']) ?></span></div>
    <div class="sub"><?= $sale['prices_include_tax'] ? 'Prices are inclusive of GST.' : 'GST added to listed prices.' ?></div>
  </section>
  <?php if ($showTax && $taxSummary): ?>
  <table class="r-tax">
    <thead><tr><th>GST%</th><th>Taxable</th><?php if ($sale['is_interstate']): ?><th>IGST</th><?php else: ?><th>CGST</th><th>SGST</th><?php endif; ?></tr></thead>
    <tbody><?php foreach ($taxSummary as $t): ?><tr><td><?= bp_pct($t['rate_bp']) ?></td><td><?= money($t['taxable'], false) ?></td>
      <?php if ($sale['is_interstate']): ?><td><?= money($t['igst'], false) ?></td><?php else: ?><td><?= money($t['cgst'], false) ?></td><td><?= money($t['sgst'], false) ?></td><?php endif; ?></tr><?php endforeach; ?></tbody>
  </table>
  <?php endif; ?>
  <section class="r-pay">
    <?php foreach ($sale['payments'] as $p): ?>
      <div><span><?= e(ReportService::methodLabel($p['method'])) ?><?= $p['reference'] ? ' (' . e($p['reference']) . ')' : '' ?></span><span><?= money($p['tendered_paise'] ?: $p['amount_paise']) ?></span></div>
    <?php endforeach; ?>
    <?php if ((int)$sale['change_paise'] > 0): ?><div><span>Change returned</span><span><?= money($sale['change_paise']) ?></span></div><?php endif; ?>
    <?php if ((int)$sale['credit_paise'] > 0): ?><div><strong>Credit (pay later)</strong><strong><?= money($sale['credit_paise']) ?></strong></div><?php endif; ?>
  </section>
  <?php if (!empty($shop['upi_vpa'])): ?><div class="r-note">UPI: <?= e($shop['upi_vpa']) ?></div><?php endif; ?>
  <div class="r-barcode"><?= Barcode::svg($sale['invoice_no'], 38, $isA4 ? 1.4 : 1.1) ?></div>
  <?php if ($shop['return_policy_text']): ?><div class="r-note"><?= nl2br(e($shop['return_policy_text'])) ?></div><?php endif; ?>
  <footer class="r-foot"><?= nl2br(e($shop['receipt_footer'])) ?></footer>
</main>
<script src="<?= asset('js/receipt.js') ?>"></script>
</body></html>
