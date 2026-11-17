<?php use App\Core\Qty; $unit = $product['unit']; ?>
<div class="page-head">
  <img class="thumb" src="<?= e(product_image_url($product['image_path'] ?? null)) ?>" alt="" style="width:96px;height:96px;border-radius:14px">
  <div class="grow"><h2 style="margin:0"><?= e($product['name']) ?> <?= $product['status'] === 'archived' ? '<span class="badge">Archived</span>' : '<span class="badge badge-success">Active</span>' ?></h2>
    <p class="mono"><?= e($product['code']) ?> · <?= e($product['category_name'] ?? 'No category') ?> · <?= e($product['brand_name'] ?? 'No brand') ?> · <?= e(Qty::UNITS[$unit]) ?> · tax <?= e($product['tax_code'] ?? 'none') ?> · HSN <?= e($product['hsn_code'] ?? '—') ?></p></div>
  <?php if (can('product.manage')): ?><a class="btn btn-primary" href="<?= e(url('/products/' . $product['id'] . '/edit')) ?>"><?= icon('edit') ?> Edit</a><?php endif; ?>
  <a class="btn" href="<?= e(url('/labels', ['product' => $product['id']])) ?>" target="_blank" rel="noopener"><?= icon('barcode') ?> Labels</a>
  <?php if (can('product.manage')): ?><form method="post" action="<?= e(url('/products/' . $product['id'] . '/archive')) ?>" data-confirm="<?= $product['status'] === 'archived' ? 'Restore this product for sale?' : 'Archive this product? It will no longer be sellable but stays in history.' ?>">
    <?= csrf_field() ?><?php if ($product['status'] === 'archived'): ?><input type="hidden" name="restore" value="1"><button class="btn">Restore</button><?php else: ?><button class="btn btn-danger"><?= icon('archive') ?> Archive</button><?php endif; ?></form><?php endif; ?>
</div>
<div class="card">
  <div class="card-head"><h3>Variants (size / colour / design)</h3></div>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>SKU</th><th>Barcode</th><th>Size</th><th>Colour</th><th>Design / lot</th><th class="num">MRP</th><th class="num">Price</th><?php if (can('product.view_cost')): ?><th class="num">Avg cost</th><?php endif; ?><th class="num">In stock</th><th class="num">Damaged</th><th></th></tr></thead>
    <tbody><?php foreach ($product['variants'] as $v): $s = $stock[$v['id']] ?? []; ?><tr>
      <td class="mono cell-title"><img class="thumb thumb-sm" src="<?= e(product_image_url($v['image_path'] ?: ($product['image_path'] ?? null))) ?>" alt="" loading="lazy"> <?= e($v['sku']) ?> <?= $v['status'] === 'archived' ? '<span class="badge">archived</span>' : '' ?></td><td class="mono"><?= e($v['barcode']) ?></td>
      <td><?= e($v['size_label']) ?></td><td><?= e($v['color_name']) ?></td><td><?= e(trim(($v['design_no'] ?? '') . ' ' . ($v['batch_no'] ?? ''))) ?></td>
      <td class="num"><?= money($v['mrp_paise']) ?></td><td class="num"><strong><?= money($v['price_paise']) ?></strong></td>
      <?php if (can('product.view_cost')): ?><td class="num"><?= money($s['MAIN']['avg_cost_paise'] ?? $v['cost_paise']) ?></td><?php endif; ?>
      <td class="num"><?= qty($s['MAIN']['qty_milli'] ?? 0, $unit) ?></td><td class="num"><?= qty($s['DAMAGED']['qty_milli'] ?? 0, $unit) ?></td>
      <td class="actions"><a class="btn btn-sm" href="<?= e(url('/stock/movements', ['variant' => $v['id']])) ?>">Movements</a></td></tr><?php endforeach; ?></tbody>
  </table></div>
</div>
<?php if ($product['description']): ?><div class="card card-pad"><?= nl2br(e($product['description'])) ?></div><?php endif; ?>
<div class="card"><div class="card-head"><h3>Price &amp; cost history</h3></div>
  <?php if (!$history): ?><div class="empty small">No changes recorded.</div><?php else: ?>
  <div class="table-wrap"><table class="table table-compact"><thead><tr><th>When</th><th>SKU</th><th>Source</th><th class="num">MRP</th><th class="num">Price</th><?php if (can('product.view_cost')): ?><th class="num">Cost</th><?php endif; ?><th>By</th></tr></thead><tbody>
    <?php foreach ($history as $h): ?><tr><td><?= e(fmt_date($h['changed_at'])) ?></td><td class="mono"><?= e($h['sku']) ?></td><td><?= e($h['source']) ?></td>
      <td class="num"><?= $h['old_mrp_paise'] !== null ? money($h['old_mrp_paise']) . ' → ' : '' ?><?= money($h['new_mrp_paise']) ?></td>
      <td class="num"><?= $h['old_price_paise'] !== null ? money($h['old_price_paise']) . ' → ' : '' ?><?= money($h['new_price_paise']) ?></td>
      <?php if (can('product.view_cost')): ?><td class="num"><?= $h['old_cost_paise'] !== null ? money($h['old_cost_paise']) . ' → ' : '' ?><?= money($h['new_cost_paise']) ?></td><?php endif; ?>
      <td><?= e($h['full_name']) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
</div>
