<?php use App\Core\Qty; use App\Services\ReportService; ?>
<div class="page-head"><div class="grow"><h2 class="mono" style="margin:0"><?= e($ret['return_no']) ?> <span class="badge <?= $ret['return_type'] === 'void' ? 'badge-danger' : 'badge-primary' ?>"><?= e($ret['return_type']) ?></span></h2>
  <p><?= e(fmt_date($ret['created_at'])) ?> · by <?= e($ret['created_by_name']) ?> · against <a class="mono" href="<?= e(url('/sales/' . $ret['original_sale_id'])) ?>"><?= e($ret['original_invoice_no']) ?></a> · reason: <?= e($ret['reason']) ?></p></div>
  <a class="btn btn-primary" href="<?= e(url('/returns/' . $ret['id'], ['print' => 1])) ?>" target="_blank" rel="noopener"><?= icon('printer') ?> Print credit note</a>
  <?php if ($ret['exchange']): ?><a class="btn" href="<?= e(url('/sales/' . $ret['exchange']['new_sale_id'])) ?>">Replacement bill</a><?php endif; ?></div>
<div class="grid g-main">
  <div class="card"><div class="table-wrap"><table class="table"><thead><tr><th>Item</th><th class="num">Qty</th><th>Condition</th><th class="num">Taxable</th><th class="num">GST</th><th class="num">Credit</th></tr></thead><tbody>
    <?php foreach ($ret['items'] as $i): ?><tr><td><div class="cell-title"><?= e($i['product_name']) ?></div><div class="cell-sub mono"><?= e($i['sku']) ?> <?= e($i['variant_label']) ?></div></td>
      <td class="num"><?= e(Qty::format((int)$i['qty_milli'], $i['unit'], true)) ?></td><td><span class="badge <?= $i['item_condition'] === 'damaged' ? 'badge-danger' : 'badge-success' ?>"><?= e($i['item_condition']) ?></span></td>
      <td class="num"><?= money($i['taxable_paise']) ?></td><td class="num"><?= money($i['tax_paise']) ?></td><td class="num"><?= money($i['amount_paise']) ?></td></tr><?php endforeach; ?></tbody></table></div></div>
  <div class="card card-pad"><h3>Settlement</h3><dl class="kv">
    <dt>Credit value</dt><dd><?= money($ret['total_paise']) ?> <span class="muted small">(round-off <?= money($ret['round_off_paise']) ?>)</span></dd>
    <dt>Used in exchange</dt><dd><?= money($ret['exchange_credit_paise']) ?></dd><dt>Refunded</dt><dd><?= money($ret['refund_paise']) ?></dd>
    <?php foreach ($ret['refunds'] as $f): ?><dt>· <?= e(ReportService::methodLabel($f['method'])) ?></dt><dd><?= money($f['amount_paise']) ?> <?= e($f['reference']) ?></dd><?php endforeach; ?>
    <?php if ($ret['exchange']): ?><dt>Exchange difference</dt><dd><?= money($ret['exchange']['difference_paise']) ?> <span class="muted small">(+ customer paid / − refunded)</span></dd><?php endif; ?>
  </dl></div>
</div>
