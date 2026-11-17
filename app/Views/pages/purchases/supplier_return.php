<form class="card" method="post" action="<?= e(url('/supplier-returns')) ?>" autocomplete="off" data-confirm="Return these goods to the supplier? Stock will be reduced." data-once>
  <?= csrf_field() ?>
  <div class="card-body">
    <div class="grid g3">
      <div class="field"><label for="supplier_id">Supplier *</label><select id="supplier_id" name="supplier_id" required><option value="">Choose…</option><?php foreach ($suppliers as $s): ?><option value="<?= e($s['id']) ?>"><?= e($s['name']) ?></option><?php endforeach; ?></select></div>
      <div class="field" style="grid-column:span 2"><label for="reason">Reason *</label><input class="input" id="reason" name="reason" required placeholder="e.g. Colour defect in lot 23"></div>
    </div>
    <div class="picker searchbar" style="max-width:560px"><?= icon('barcode') ?><input class="input" id="srPick" placeholder="Scan or search item"></div>
    <table class="table" style="margin-top:12px" data-item-table="sreturn" data-picker-input="#srPick">
      <thead><tr><th>Item</th><th style="width:130px">Qty</th><th style="width:160px">From location</th><th></th></tr></thead><tbody></tbody>
      <tfoot><tr data-empty><td colspan="4" class="muted">Goods leave stock at their current average cost.</td></tr></tfoot>
      <template><tr><td><input type="hidden" name="items[__i__][variant_id]" data-fill="id"><div class="cell-title" data-fill="name"></div><div class="cell-sub mono" data-fill="sku"></div></td>
        <td><input class="input input-sm num" name="items[__i__][qty]" data-f="qty" value="1"></td>
        <td><select class="input-sm" name="items[__i__][location]"><option value="MAIN">Shop floor</option><option value="DAMAGED">Damaged</option></select></td>
        <td><button class="btn btn-ghost btn-sm" type="button" data-remove-row>Remove</button></td></tr></template>
    </table>
  </div>
  <div class="modal-foot"><a class="btn" href="<?= e(url('/purchases')) ?>">Cancel</a><button class="btn btn-primary">Save supplier return</button></div>
</form>
