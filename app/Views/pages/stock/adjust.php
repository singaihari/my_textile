<form class="card" method="post" action="<?= e(url('/stock/adjust')) ?>" autocomplete="off" data-confirm="Post this stock adjustment? It cannot be edited afterwards (post a new correction if needed)." data-once>
  <?= csrf_field() ?>
  <div class="card-head"><h3>New adjustment</h3></div>
  <div class="card-body">
    <div class="grid g3">
      <div class="field"><label for="reason_code">Reason *</label><select id="reason_code" name="reason_code" required>
        <option value="count_correction">Stock count correction (+/−)</option><option value="opening">Opening stock</option><option value="damaged">Write off damaged</option>
        <option value="transfer_damaged">Move sellable → damaged location</option><option value="lost">Lost / theft</option><option value="found">Found</option><option value="other">Other</option></select></div>
      <div class="field" style="grid-column:span 2"><label for="notes">Note * (who counted, why)</label><input class="input" id="notes" name="notes" required></div>
    </div>
    <div class="picker searchbar" style="max-width:560px"><?= icon('barcode') ?><input class="input" id="adjPick" placeholder="Scan or search item to add"></div>
    <table class="table" style="margin-top:12px" data-item-table="adjust" data-picker-input="#adjPick">
      <thead><tr><th>Item</th><th style="width:150px">Qty change (+ in / − out)</th><th style="width:140px">Location</th><?php if (can('product.view_cost')): ?><th style="width:140px">Unit cost ₹ (for + only)</th><?php endif; ?><th></th></tr></thead>
      <tbody></tbody>
      <tfoot><tr data-empty><td colspan="5" class="muted">Scan items above. Use a minus sign to reduce stock, e.g. -2.</td></tr></tfoot>
      <template><tr><td><input type="hidden" name="items[__i__][variant_id]" data-fill="id"><div class="cell-title" data-fill="name"></div><div class="cell-sub mono" data-fill="sku"></div></td>
        <td><input class="input input-sm num" name="items[__i__][qty]" data-f="qty" required></td>
        <td><select class="input-sm" name="items[__i__][location]"><option value="MAIN">Shop floor</option><option value="DAMAGED">Damaged</option></select></td>
        <?php if (can('product.view_cost')): ?><td><input class="input input-sm num" name="items[__i__][cost]" data-fill="cost"></td><?php endif; ?>
        <td><button class="btn btn-ghost btn-sm" type="button" data-remove-row>Remove</button></td></tr></template>
    </table>
  </div>
  <div class="modal-foot"><button class="btn btn-primary">Post adjustment</button></div>
</form>
<div class="card"><div class="card-head"><h3>Recent adjustments</h3></div>
  <?php if (!$recent): ?><div class="empty small">None yet.</div><?php else: ?><table class="table table-compact"><thead><tr><th>No</th><th>When</th><th>Reason</th><th>Note</th><th class="num">Lines</th><th>By</th></tr></thead><tbody>
  <?php foreach ($recent as $a): ?><tr><td class="mono"><?= e($a['adj_no']) ?></td><td><?= e(fmt_date($a['created_at'])) ?></td><td><?= e(str_replace('_', ' ', $a['reason_code'])) ?></td><td><?= e($a['notes']) ?></td><td class="num"><?= (int)$a['lines'] ?></td><td><?= e($a['full_name']) ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?>
</div>
