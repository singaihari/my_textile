<?php
use App\Core\Money;
use App\Core\Qty;
App\Core\View::script('js/product-form.js');
$p = $product ?? [];
$v = fn(string $k, $d = '') => old($k, $p[$k] ?? $d);
$variants = $p['variants'] ?? [];
$canCost = can('product.edit_cost') || !$product;
$canPrice = can('product.edit_price') || !$product;
$sizesBySys = [];
foreach ($sizes as $s) { $sizesBySys[$s['size_system']][] = $s; }
?>
<script type="application/json" id="pf-data"><?= json_encode(['sizes' => $sizes, 'colors' => $colors], JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<form method="post" action="<?= e(url('/products/save')) ?>" id="productForm" autocomplete="off" enctype="multipart/form-data" data-once>
<?= csrf_field() ?><input type="hidden" name="id" value="<?= e($p['id'] ?? '') ?>">
<div class="card">
  <div class="card-head"><h3>Product family</h3><span class="muted small">Shared by all sizes and colours</span></div>
  <div class="card-body form-grid">
    <div class="field" style="grid-column:span 2"><label for="name">Name *</label><input class="input" id="name" name="name" value="<?= e($v('name')) ?>" required autofocus placeholder="e.g. Men's cotton formal shirt"></div>
    <div class="field"><label for="code">Product code *</label><input class="input mono" id="code" name="code" value="<?= e($v('code')) ?>" required placeholder="e.g. MSH-101"></div>
    <div class="field"><label for="unit">Selling unit *</label><select id="unit" name="unit"><?php foreach (Qty::UNITS as $u => $l): ?><option value="<?= $u ?>" <?= $v('unit', 'pcs') === $u ? 'selected' : '' ?>><?= e($l) ?><?= $u === 'm' ? ' (fabric, decimals allowed)' : '' ?></option><?php endforeach; ?></select></div>
    <div class="field"><label for="category_id">Category</label><select id="category_id" name="category_id"><option value="">—</option><?php foreach ($categories as $c): ?><option value="<?= e($c['id']) ?>" <?= $v('category_id') === $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label for="brand_id">Brand</label><select id="brand_id" name="brand_id"><option value="">—</option><?php foreach ($brands as $b): ?><option value="<?= e($b['id']) ?>" <?= $v('brand_id') === $b['id'] ? 'selected' : '' ?>><?= e($b['name']) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label for="gender">For</label><select id="gender" name="gender"><?php foreach (['' => '—', 'men' => 'Men', 'women' => 'Women', 'kids' => 'Kids', 'unisex' => 'Unisex'] as $g => $l): ?><option value="<?= $g ?>" <?= $v('gender') === $g ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
    <div class="field"><label for="fabric">Fabric / material</label><input class="input" id="fabric" name="fabric" value="<?= e($v('fabric')) ?>" placeholder="Cotton, silk, rayon…"></div>
    <div class="field"><label for="collection">Collection / style</label><input class="input" id="collection" name="collection" value="<?= e($v('collection')) ?>"></div>
    <div class="field"><label for="tax_rule_id">Tax rule *</label><select id="tax_rule_id" name="tax_rule_id"><option value="">No tax</option><?php foreach ($taxRules as $t): ?><option value="<?= e($t['id']) ?>" <?= $v('tax_rule_id') === $t['id'] ? 'selected' : '' ?>><?= e($t['code'] . ' — ' . bp_pct($t['rate_bp']) . ($t['threshold_paise'] !== null ? ' / ' . bp_pct($t['above_rate_bp']) . ' above ' . money($t['threshold_paise']) : '')) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label for="hsn_code">HSN code</label><input class="input mono" id="hsn_code" name="hsn_code" value="<?= e($v('hsn_code')) ?>" inputmode="numeric" maxlength="8"></div>
    <div class="field"><label for="reorder_level">Reorder level (per variant)</label><input class="input num" id="reorder_level" name="reorder_level" value="<?= e($product ? Qty::format((int)$p['reorder_level_milli'], $p['unit']) : old('reorder_level', '2')) ?>"></div>
    <div class="field"><label>&nbsp;</label><input type="hidden" name="discount_allowed" value="0"><label class="check"><input type="checkbox" name="discount_allowed" value="1" <?= (int)$v('discount_allowed', 1) ? 'checked' : '' ?>> Discount allowed</label>
      <label class="check"><input type="checkbox" name="is_unique_piece" value="1" <?= (int)$v('is_unique_piece', 0) ? 'checked' : '' ?>> Unique pieces (e.g. designer sarees)</label></div>
    <div class="field" style="grid-column:1/-1"><label for="description">Description</label><textarea id="description" name="description" rows="2"><?= e($v('description')) ?></textarea></div>
    <div class="field" style="grid-column:1/-1"><label for="image">Product photo</label>
      <div class="row" style="align-items:flex-start">
        <img class="thumb thumb-lg" src="<?= e(product_image_url($p['image_path'] ?? null)) ?>" alt="" id="imgPreview">
        <div><input class="input" type="file" id="image" name="image" accept="image/jpeg,image/png,image/webp" style="max-width:340px">
          <div class="hint">JPG, PNG or WebP, up to 3 MB. A square photo on a plain background looks best.</div>
          <?php if (!empty($p['image_path'])): ?><label class="check"><input type="checkbox" name="remove_image" value="1"> Remove photo</label><?php endif; ?></div>
      </div></div>
  </div>
</div>

<div class="card">
  <div class="card-head"><h3>Variant matrix</h3><span class="muted small">Each row is one sellable SKU with its own barcode and stock</span></div>
  <div class="card-body">
    <details class="card card-pad" style="margin-bottom:12px" <?= $variants ? '' : 'open' ?>>
      <summary><strong>Generate rows from sizes × colours</strong></summary>
      <div class="grid g2" style="margin-top:12px">
        <div><div class="label">Sizes</div><?php foreach ($sizesBySys as $sys => $list): ?><div class="small muted" style="margin-top:6px"><?= e(ucfirst($sys)) ?></div><div class="row"><?php foreach ($list as $s): ?><label class="check" style="margin:2px 8px 2px 0"><input type="checkbox" data-gen-size value="<?= e($s['id']) ?>"> <?= e($s['label']) ?></label><?php endforeach; ?></div><?php endforeach; ?></div>
        <div><div class="label">Colours</div><div class="row"><?php foreach ($colors as $c): ?><label class="check" style="margin:2px 8px 2px 0"><input type="checkbox" data-gen-color value="<?= e($c['id']) ?>"> <span class="swatch" style="background:<?= e($c['hex'] ?: '#fff') ?>"></span> <?= e($c['name']) ?></label><?php endforeach; ?></div></div>
      </div>
      <div class="row" style="margin-top:12px"><div class="field" style="margin:0;width:140px"><label>MRP ₹</label><input class="input num" id="genMrp"></div><div class="field" style="margin:0;width:140px"><label>Price ₹</label><input class="input num" id="genPrice"></div>
        <?php if ($canCost): ?><div class="field" style="margin:0;width:140px"><label>Cost ₹</label><input class="input num" id="genCost"></div><?php endif; ?>
        <button class="btn" type="button" id="genRows" style="align-self:flex-end">Add combinations</button></div>
    </details>
    <div class="table-wrap"><table class="table variant-grid">
      <thead><tr><th>Size</th><th>Colour</th><th>Design no</th><th>SKU <span class="muted">(auto)</span></th><th>Barcode <span class="muted">(auto)</span></th><th>MRP ₹</th><th>Price ₹</th><?php if (can('product.view_cost')): ?><th>Cost ₹</th><?php endif; ?><?php if (!$product || true): ?><th>Opening qty</th><?php endif; ?><th>Status</th><th></th></tr></thead>
      <tbody id="variantRows">
      <?php $rows = $variants ?: [[]]; foreach ($rows as $i => $row): ?>
        <?= App\Core\View::partial('pages/products/_variant_row', ['i' => $i, 'row' => $row, 'sizes' => $sizes, 'colors' => $colors, 'canCost' => $canCost, 'canPrice' => $canPrice, 'isExisting' => !empty($row['id'])]) ?>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <template id="variantTpl"><?= App\Core\View::partial('pages/products/_variant_row', ['i' => '__i__', 'row' => [], 'sizes' => $sizes, 'colors' => $colors, 'canCost' => $canCost, 'canPrice' => true, 'isExisting' => false]) ?></template>
    <div class="row" style="margin-top:8px"><button class="btn btn-sm" type="button" id="addRow"><?= icon('plus') ?> Add row</button>
      <span class="hint">Leave SKU / barcode empty to auto-generate (internal EAN-13). Supplier barcodes can be typed or scanned in. Opening qty applies to new rows only.</span></div>
    <?php if (!$canPrice): ?><p class="hint">You cannot change prices; price fields are read-only.</p><?php endif; ?>
  </div>
</div>
<div class="form-actions card card-pad">
  <button class="btn btn-primary btn-lg">Save product <kbd>Ctrl+S</kbd></button>
  <?php if (!$product): ?><button class="btn" name="next" value="new">Save &amp; add another</button><?php endif; ?>
  <a class="btn btn-ghost" href="<?= e(url($product ? '/products/' . $p['id'] : '/products')) ?>">Cancel</a>
</div>
</form>
