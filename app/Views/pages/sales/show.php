<?php use App\Core\Qty; use App\Services\ReportService; App\Core\View::script('js/invoice.js'); ?>
<div class="page-head">
  <div class="grow">
    <h2 class="mono" style="margin:0"><?= e($sale['invoice_no']) ?>
      <?= $sale['status'] === 'voided' ? '<span class="badge badge-danger">Voided</span>' : '<span class="badge badge-success">Completed</span>' ?>
      <?= $sync && $sync['status'] === 'acked' ? '<span class="badge badge-success">Synced ' . e(fmt_date($sync['acked_at'])) . '</span>' : '<span class="badge badge-warning">' . e(ucfirst($sync['status'] ?? 'pending')) . ' sync</span>' ?></h2>
    <p><?= e(fmt_date($sale['created_at'])) ?> · by <?= e($sale['cashier_name']) ?> · printed <?= (int)$sale['print_count'] ?>×</p>
  </div>
  <div class="row no-print">
    <?php if (can('pos.reprint')): ?><button class="btn btn-primary" type="button" data-reprint="<?= e($sale['id']) ?>" data-format="<?= e(setting('printer_format')) ?>"><?= icon('printer') ?> Reprint <kbd>Ctrl+P</kbd></button>
    <button class="btn" type="button" data-reprint="<?= e($sale['id']) ?>" data-format="a4">A4 copy</button><?php endif; ?>
    <a class="btn" href="<?= e(url('/sales/' . $sale['id'] . '/receipt', ['format' => setting('printer_format')])) ?>" target="_blank" rel="noopener">Preview</a>
    <?php if ($sale['status'] === 'completed' && can('return.create')): ?><a class="btn" href="<?= e(url('/returns/sale/' . $sale['id'])) ?>"><?= icon('return') ?> Return / exchange</a><?php endif; ?>
  </div>
</div>
<?php if ($exchange): ?><div class="alert alert-info"><?= icon('info') ?><div>Exchange bill against <a href="<?= e(url('/sales/' . $exchange['original_sale_id'])) ?>"><?= e($exchange['original_invoice']) ?></a>; difference <?= money($exchange['difference_paise']) ?>.</div></div><?php endif; ?>
<?php if ($sale['status'] === 'voided'): ?><div class="alert alert-error"><?= icon('alert') ?><div>Voided on <?= e(fmt_date($sale['voided_at'])) ?>: <?= e($sale['void_reason']) ?>. Stock was restored and the refund recorded.</div></div><?php endif; ?>
<div class="grid g-main">
  <div class="card">
    <div class="table-wrap"><table class="table">
      <thead><tr><th>#</th><th>Item</th><th class="num">Qty</th><th class="num">Rate</th><th class="num">Discount</th><th class="num">Taxable</th><th class="num">GST</th><th class="num">Total</th><th class="num">Returned</th></tr></thead>
      <tbody><?php foreach ($sale['items'] as $it): ?><tr>
        <td><?= (int)$it['line_no'] ?></td><td><div class="cell-title"><?= e($it['product_name']) ?></div><div class="cell-sub"><span class="mono"><?= e($it['sku']) ?></span> <?= e($it['variant_label']) ?> <?= $it['hsn_code'] ? '· HSN ' . e($it['hsn_code']) : '' ?></div></td>
        <td class="num"><?= e(Qty::format((int)$it['qty_milli'], $it['unit'], true)) ?></td><td class="num"><?= money($it['unit_price_paise']) ?></td>
        <td class="num"><?= money((int)$it['line_discount_paise'] + (int)$it['bill_discount_alloc_paise']) ?></td><td class="num"><?= money($it['taxable_paise']) ?></td>
        <td class="num"><?= money($it['tax_paise']) ?> <span class="cell-sub"><?= bp_pct($it['tax_rate_bp']) ?></span></td><td class="num"><strong><?= money($it['line_total_paise']) ?></strong></td>
        <td class="num"><?= (int)$it['returned_qty_milli'] ? e(Qty::format((int)$it['returned_qty_milli'], $it['unit'])) : '—' ?></td></tr><?php endforeach; ?></tbody>
      <tfoot><tr><td colspan="4">Total</td><td class="num"><?= money((int)$sale['line_discount_paise'] + (int)$sale['bill_discount_paise']) ?></td><td class="num"><?= money($sale['taxable_paise']) ?></td><td class="num"><?= money($sale['tax_paise']) ?></td><td class="num"><?= money($sale['total_paise']) ?></td><td></td></tr></tfoot>
    </table></div>
  </div>
  <div class="stack">
    <div class="card card-pad"><h3>Summary</h3><dl class="kv">
      <dt>Customer</dt><dd><?php if ($sale['customer_id']): ?><a href="<?= e(url('/customers/' . $sale['customer_id'])) ?>"><?= e($sale['customer_name']) ?></a><?php else: ?>Walk-in<?php endif; ?></dd>
      <dt>Gross</dt><dd><?= money($sale['gross_paise']) ?></dd><dt>CGST / SGST</dt><dd><?= money($sale['cgst_paise']) ?> / <?= money($sale['sgst_paise']) ?></dd>
      <dt>IGST</dt><dd><?= money($sale['igst_paise']) ?></dd><dt>Round off</dt><dd><?= money($sale['round_off_paise']) ?></dd>
      <dt>Total</dt><dd><strong><?= money($sale['total_paise']) ?></strong></dd>
      <?php if (can('report.profit')): ?><dt>Cost (snapshot)</dt><dd><?= money($sale['cogs_paise']) ?></dd><dt>Gross margin</dt><dd><?= money((int)$sale['taxable_paise'] - (int)$sale['cogs_paise']) ?></dd><?php endif; ?>
    </dl></div>
    <div class="card card-pad"><h3>Payments</h3><ul class="list-plain"><?php foreach ($sale['payments'] as $p): ?>
      <li><span style="flex:1"><?= e(ReportService::methodLabel($p['method'])) ?> <span class="muted mono"><?= e($p['reference']) ?></span></span><strong><?= money($p['amount_paise']) ?></strong><?= $p['tendered_paise'] ? '<span class="muted small">tendered ' . money($p['tendered_paise']) . '</span>' : '' ?></li>
    <?php endforeach; ?></ul></div>
    <?php if ($returns): ?><div class="card card-pad"><h3>Returns</h3><ul class="list-plain"><?php foreach ($returns as $r): ?>
      <li><a class="mono" href="<?= e(url('/returns/' . $r['id'])) ?>"><?= e($r['return_no']) ?></a><span class="badge"><?= e($r['return_type']) ?></span><span style="flex:1" class="muted small"><?= e($r['reason']) ?></span><strong><?= money($r['total_paise']) ?></strong></li>
    <?php endforeach; ?></ul></div><?php endif; ?>
    <div class="card card-pad"><h3>Print log</h3><?php if (!$prints): ?><p class="muted small">Not printed yet.</p><?php endif; ?><ul class="list-plain small"><?php foreach ($prints as $p): ?><li><?= e(fmt_date($p['created_at'])) ?> · <?= e($p['kind']) ?> · <?= e($p['format']) ?> · <?= e($p['full_name']) ?></li><?php endforeach; ?></ul></div>
    <?php if ($sale['status'] === 'completed'): ?>
    <form class="card card-pad no-print" method="post" action="<?= e(url('/sales/' . $sale['id'] . '/void')) ?>" data-confirm="Void this bill? Stock is restored and the full amount is recorded as refunded. This cannot be undone." data-once>
      <?= csrf_field() ?><h3>Void bill</h3>
      <?php if (!can('sale.void')): ?><p class="small muted">Requires manager. Use Returns for partial returns.</p><?php else: ?>
      <div class="field"><label for="reason">Reason *</label><input class="input" id="reason" name="reason" required></div>
      <button class="btn btn-danger">Void bill</button><?php endif; ?>
    </form><?php endif; ?>
  </div>
</div>
<iframe id="printFrame" title="print" style="position:absolute;width:0;height:0;border:0;left:-9999px"></iframe>
