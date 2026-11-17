<?php
use App\Core\Money;
use App\Core\Qty;
$p = $purchase ?? [];
$draft = !$purchase || $p['status'] === 'draft';
$val = fn($k, $d = '') => old($k, $p[$k] ?? $d);
?>
<?php if ($purchase && !$draft): ?><div class="alert alert-<?= $p['status'] === 'posted' ? 'success' : 'warning' ?>"><?= icon('info') ?><div>This purchase is <strong><?= e($p['status']) ?></strong><?= $p['posted_at'] ? ' on ' . e(fmt_date($p['posted_at'])) : '' ?> and can no longer be edited. Use <a href="<?= e(url('/supplier-returns/new')) ?>">Return to supplier</a> or a stock adjustment for corrections.</div></div><?php endif; ?>
<form class="card" method="post" action="<?= e(url('/purchases/save')) ?>" autocomplete="off" data-once>
  <?= csrf_field() ?><input type="hidden" name="id" value="<?= e($p['id'] ?? '') ?>">
  <div class="card-head"><h3><?= $purchase ? e($p['purchase_no']) : 'New purchase' ?></h3></div>
  <fieldset class="card-body" <?= $draft ? '' : 'disabled' ?> style="border:0;margin:0">
    <div class="form-grid">
      <div class="field"><label for="supplier_id">Supplier *</label><select id="supplier_id" name="supplier_id" required><option value="">Choose…</option><?php foreach ($suppliers as $s): ?><option value="<?= e($s['id']) ?>" <?= $val('supplier_id') === $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?></select>
        <div class="hint"><a href="<?= e(url('/suppliers')) ?>">Add supplier</a></div></div>
      <div class="field"><label for="sin">Supplier invoice no</label><input class="input" id="sin" name="supplier_invoice_no" value="<?= e($val('supplier_invoice_no')) ?>"></div>
      <div class="field"><label for="sid">Invoice date</label><input class="input" type="date" id="sid" name="supplier_invoice_date" value="<?= e($val('supplier_invoice_date', today())) ?>"></div>
      <div class="field"><label for="freight">Freight / landed charges ₹</label><input class="input num" id="freight" name="freight" value="<?= e(isset($p['freight_paise']) ? Money::toDecimal((int)$p['freight_paise']) : old('freight', '0')) ?>"><div class="hint">Spread over items by value into cost</div></div>
      <div class="field"><label for="paid">Amount paid ₹</label><input class="input num" id="paid" name="amount_paid" value="<?= e(isset($p['amount_paid_paise']) ? Money::toDecimal((int)$p['amount_paid_paise']) : old('amount_paid', '0')) ?>"></div>
      <div class="field"><label>&nbsp;</label><input type="hidden" name="tax_claimable" value="0"><label class="check"><input type="checkbox" name="tax_claimable" value="1" <?= (int)($p['tax_claimable'] ?? 1) ? 'checked' : '' ?>> Input GST claimable (excluded from cost)</label></div>
    </div>
    <div class="picker searchbar" style="max-width:560px"><?= icon('barcode') ?><input class="input" id="purPick" placeholder="Scan or search item to add (create new items under Products first)"></div>
    <table class="table" style="margin-top:12px" data-item-table="purchase" data-picker-input="#purPick">
      <thead><tr><th>Item</th><th style="width:120px">Qty</th><th style="width:140px">Unit cost ₹ (ex-GST)</th><th style="width:100px">GST %</th><th class="num">Line value</th><?php if (!$draft): ?><th class="num">Landed unit cost</th><?php endif; ?><th></th></tr></thead>
      <tbody>
        <?php foreach ($items as $i => $it): ?><tr data-variant="<?= e($it['variant_id']) ?>" data-unit="<?= e($it['unit']) ?>">
          <td><input type="hidden" name="items[<?= $i ?>][variant_id]" value="<?= e($it['variant_id']) ?>"><div class="cell-title"><?= e($it['name']) ?></div><div class="cell-sub mono"><?= e($it['sku']) ?></div></td>
          <td><input class="input input-sm num" name="items[<?= $i ?>][qty]" data-f="qty" value="<?= e(Qty::format((int)$it['qty_milli'], $it['unit'])) ?>"></td>
          <td><input class="input input-sm num" name="items[<?= $i ?>][unit_cost]" data-f="unit_cost" value="<?= e(Money::toDecimal((int)$it['unit_cost_paise'])) ?>"></td>
          <td><input class="input input-sm num" name="items[<?= $i ?>][tax_pct]" data-f="tax_pct" value="<?= e(rtrim(rtrim(sprintf('%.2f', $it['tax_bp'] / 100), '0'), '.')) ?>"></td>
          <td class="num" data-out="line"><?= money($it['line_subtotal_paise']) ?></td><?php if (!$draft): ?><td class="num"><?= money($it['landed_unit_cost_paise']) ?></td><?php endif; ?>
          <td><?php if ($draft): ?><button class="btn btn-ghost btn-sm" type="button" data-remove-row>Remove</button><?php endif; ?></td></tr><?php endforeach; ?>
      </tbody>
      <?php if (!$items): ?><tfoot><tr data-empty><td colspan="6" class="muted">Scan the first item. Press Enter in a field to go back to scanning.</td></tr></tfoot><?php endif; ?>
      <template><tr><td><input type="hidden" name="items[__i__][variant_id]" data-fill="id"><div class="cell-title" data-fill="name"></div><div class="cell-sub mono" data-fill="sku"></div></td>
        <td><input class="input input-sm num" name="items[__i__][qty]" data-f="qty" value="1"></td><td><input class="input input-sm num" name="items[__i__][unit_cost]" data-f="unit_cost" data-fill="cost"></td>
        <td><input class="input input-sm num" name="items[__i__][tax_pct]" data-f="tax_pct" value="5"></td><td class="num" data-out="line"></td><td><button class="btn btn-ghost btn-sm" type="button" data-remove-row>Remove</button></td></tr></template>
    </table>
    <p class="small" data-purchase-totals></p>
    <div class="field"><label for="notes">Notes</label><textarea id="notes" name="notes" rows="2"><?= e($val('notes')) ?></textarea></div>
  </fieldset>
  <?php if ($draft): ?><div class="modal-foot">
    <?php if ($purchase): ?><button class="btn btn-danger" type="submit" form="cancelForm">Cancel draft</button><span style="flex:1"></span><?php endif; ?>
    <button class="btn" name="action" value="draft">Save draft</button>
    <button class="btn btn-primary" name="action" value="post" data-confirm="Post this purchase? Stock and average cost will be updated and the purchase locked.">Save &amp; post to stock</button>
  </div><?php elseif ($purchase): ?><div class="card-body"><dl class="kv"><dt>Goods</dt><dd><?= money($p['subtotal_paise']) ?></dd><dt>Input tax</dt><dd><?= money($p['tax_paise']) ?></dd><dt>Freight</dt><dd><?= money($p['freight_paise']) ?></dd><dt>Total</dt><dd><?= money($p['total_paise']) ?></dd><dt>Paid</dt><dd><?= money($p['amount_paid_paise']) ?></dd></dl></div><?php endif; ?>
</form>
<?php if ($purchase && $draft): ?><form id="cancelForm" method="post" action="<?= e(url('/purchases/' . $p['id'] . '/cancel')) ?>" data-confirm="Cancel this draft?"><?= csrf_field() ?></form><?php endif; ?>
