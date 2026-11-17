<?php
use App\Core\Money;
$n = fn($k) => 'variants[' . $i . '][' . $k . ']';
$amt = fn($k) => isset($row[$k]) ? Money::toDecimal((int)$row[$k]) : '';
?>
<tr>
  <td><input type="hidden" name="<?= $n('id') ?>" value="<?= e($row['id'] ?? '') ?>">
    <select name="<?= $n('size_id') ?>" aria-label="Size"><option value="">—</option><?php foreach ($sizes as $s): ?><option value="<?= e($s['id']) ?>" <?= ($row['size_id'] ?? '') === $s['id'] ? 'selected' : '' ?>><?= e($s['label']) ?></option><?php endforeach; ?></select></td>
  <td><select name="<?= $n('color_id') ?>" aria-label="Colour"><option value="">—</option><?php foreach ($colors as $c): ?><option value="<?= e($c['id']) ?>" <?= ($row['color_id'] ?? '') === $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></td>
  <td><input class="input" name="<?= $n('design_no') ?>" value="<?= e($row['design_no'] ?? '') ?>" style="width:90px" aria-label="Design no"></td>
  <td><input class="input mono" name="<?= $n('sku') ?>" value="<?= e($row['sku'] ?? '') ?>" style="width:140px" aria-label="SKU"></td>
  <td><input class="input mono" name="<?= $n('barcode') ?>" value="<?= e($row['barcode'] ?? '') ?>" style="width:140px" aria-label="Barcode"></td>
  <td><input class="input num" name="<?= $n('mrp') ?>" value="<?= e($amt('mrp_paise')) ?>" style="width:90px" aria-label="MRP" <?= $canPrice ? '' : 'readonly' ?>></td>
  <td><input class="input num" name="<?= $n('price') ?>" value="<?= e($amt('price_paise')) ?>" style="width:90px" aria-label="Price" <?= $canPrice ? '' : 'readonly' ?>></td>
  <?php if (can('product.view_cost')): ?><td><input class="input num" name="<?= $n('cost') ?>" value="<?= e($amt('cost_paise')) ?>" style="width:90px" aria-label="Cost" <?= $canCost ? '' : 'readonly' ?>></td><?php endif; ?>
  <td><input class="input num" name="<?= $n('opening_qty') ?>" value="" style="width:80px" aria-label="Opening qty" <?= $isExisting ? 'disabled placeholder="—"' : '' ?>></td>
  <td><select name="<?= $n('status') ?>" aria-label="Status"><option value="active">Active</option><option value="archived" <?= ($row['status'] ?? '') === 'archived' ? 'selected' : '' ?>>Archived</option></select></td>
  <td><?php if (!$isExisting): ?><button class="btn btn-ghost btn-sm btn-icon" type="button" data-remove-variant aria-label="Remove row"><?= icon('x') ?></button><?php endif; ?></td>
</tr>
