<div class="page-head">
  <form class="filters grow" method="get">
    <div class="searchbar"><?= icon('search') ?><input class="input" name="q" value="<?= e($q) ?>" placeholder="Product, SKU or scan barcode  ( / )" data-page-search></div>
    <select name="loc" aria-label="Location"><option value="MAIN">Shop floor (sellable)</option><option value="DAMAGED" <?= $loc === 'DAMAGED' ? 'selected' : '' ?>>Damaged</option></select>
    <select name="filter" aria-label="Filter"><option value="">All</option><option value="instock" <?= $filter === 'instock' ? 'selected' : '' ?>>In stock</option><option value="low" <?= $filter === 'low' ? 'selected' : '' ?>>Low stock</option><option value="zero" <?= $filter === 'zero' ? 'selected' : '' ?>>Out of stock</option></select>
    <button class="btn">Filter</button>
  </form>
  <?php if (can('stock.adjust')): ?><a class="btn" href="<?= e(url('/stock/adjust')) ?>"><?= icon('layers') ?> Adjust stock</a><?php endif; ?>
  <a class="btn" href="<?= e(url('/stock/movements')) ?>">All movements</a>
</div>
<?php if (can('product.view_cost')): ?>
<div class="grid g3" style="margin-bottom:16px">
  <div class="card stat"><div class="stat-label">Stock value at weighted-average cost</div><div class="stat-value"><?= money((int)($totals['cost'] ?? 0)) ?></div></div>
  <div class="card stat"><div class="stat-label">Stock value at selling price</div><div class="stat-value"><?= money((int)($totals['retail'] ?? 0)) ?></div></div>
  <div class="card stat"><div class="stat-label">Location</div><div class="stat-value" style="font-size:1.2rem"><?= $loc === 'DAMAGED' ? 'Damaged (not sellable)' : 'Shop floor' ?></div></div>
</div><?php endif; ?>
<div class="card">
<?php if (!$page['rows']): ?><div class="empty"><?= icon('box') ?><h3>Nothing matches</h3></div><?php else: ?>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>Item</th><th>SKU / barcode</th><th class="num">Price</th><?php if (can('product.view_cost')): ?><th class="num">Avg cost</th><?php endif; ?><th class="num">Reorder at</th><th class="num">In stock</th><th>Last in / out</th><th></th></tr></thead>
    <tbody><?php foreach ($page['rows'] as $r): $low = (int)$r['reorder'] > 0 && (int)$r['qty'] <= (int)$r['reorder']; ?><tr>
      <td><a class="cell-title" href="<?= e(url('/products/' . $r['product_id'])) ?>"><?= e($r['name']) ?></a><div class="cell-sub"><?= e(trim(($r['size'] ?? '') . ' ' . ($r['color'] ?? '') . ' ' . ($r['design_no'] ? 'D#' . $r['design_no'] : ''))) ?></div></td>
      <td class="mono small"><?= e($r['sku']) ?><div class="cell-sub"><?= e($r['barcode']) ?></div></td><td class="num"><?= money($r['price_paise']) ?></td>
      <?php if (can('product.view_cost')): ?><td class="num"><?= money($r['avg_cost']) ?></td><?php endif; ?>
      <td class="num"><?= qty($r['reorder'], $r['unit']) ?></td>
      <td class="num"><strong style="color:<?= (int)$r['qty'] <= 0 ? 'var(--danger)' : ($low ? 'var(--warning)' : 'inherit') ?>"><?= qty($r['qty'], $r['unit']) ?></strong> <span class="muted small"><?= $r['unit'] === 'm' ? 'm' : '' ?></span></td>
      <td class="small muted"><?= e(fmt_date($r['last_in_at'], false)) ?> / <?= e(fmt_date($r['last_out_at'], false)) ?></td>
      <td class="actions"><a class="btn btn-sm" href="<?= e(url('/stock/movements', ['variant' => $r['id']])) ?>">History</a></td></tr><?php endforeach; ?></tbody>
  </table></div><?= App\Core\View::partial('partials/pager', ['page' => $page]) ?>
<?php endif; ?>
</div>
