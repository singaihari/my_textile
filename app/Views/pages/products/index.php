<div class="page-head">
  <form class="filters grow" method="get">
    <div class="searchbar"><?= icon('search') ?><input class="input" name="q" value="<?= e($q) ?>" placeholder="Name, code, SKU, barcode, design no  ( / )" data-page-search></div>
    <select name="category_id" aria-label="Category"><option value="">All categories</option><?php foreach ($categories as $c): ?><option value="<?= e($c['id']) ?>" <?= request()->query('category_id') === $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select>
    <select name="brand_id" aria-label="Brand"><option value="">All brands</option><?php foreach ($brands as $b): ?><option value="<?= e($b['id']) ?>" <?= request()->query('brand_id') === $b['id'] ? 'selected' : '' ?>><?= e($b['name']) ?></option><?php endforeach; ?></select>
    <select name="status" aria-label="Status"><option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active</option><option value="archived" <?= $status === 'archived' ? 'selected' : '' ?>>Archived</option><option value="all" <?= $status === 'all' ? 'selected' : '' ?>>All</option></select>
    <select name="sort" aria-label="Sort"><option value="">Name</option><option value="stock" <?= request()->query('sort') === 'stock' ? 'selected' : '' ?>>Lowest stock</option><option value="new" <?= request()->query('sort') === 'new' ? 'selected' : '' ?>>Newest</option><option value="code" <?= request()->query('sort') === 'code' ? 'selected' : '' ?>>Code</option></select>
    <button class="btn">Filter</button>
  </form>
  <?php if (can('product.manage')): ?><a class="btn btn-primary" href="<?= e(url('/products/new')) ?>"><?= icon('plus') ?> New product</a><?php endif; ?>
  <a class="btn" href="<?= e(url('/export/products')) ?>"><?= icon('download') ?> Export CSV</a>
</div>
<div class="card">
<?php if (!$page['rows']): ?><div class="empty"><?= icon('shirt') ?><h3>No products</h3><p>Add your first product family (e.g. “Cotton formal shirt”) with its sizes and colours, or import a CSV.</p>
  <?php if (can('product.manage')): ?><a class="btn btn-primary" href="<?= e(url('/products/new')) ?>">New product</a> <a class="btn" href="<?= e(url('/import')) ?>">Import CSV</a><?php endif; ?></div>
<?php else: ?>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>Product</th><th>Category / brand</th><th>Unit</th><th class="num">Variants</th><th class="num">Price</th><th class="num">Stock</th><th></th></tr></thead>
    <tbody><?php foreach ($page['rows'] as $p): ?><tr class="row-link" data-href="<?= e(url('/products/' . $p['id'])) ?>" tabindex="0">
      <td><div class="row" style="flex-wrap:nowrap;gap:12px"><img class="thumb" src="<?= e(product_image_url($p['image_path'])) ?>" alt="" loading="lazy">
        <div><div class="cell-title"><?= e($p['name']) ?> <?= $p['is_demo'] ? '<span class="badge badge-demo plain">sample</span>' : '' ?> <?= $p['status'] === 'archived' ? '<span class="badge">archived</span>' : '' ?></div><div class="cell-sub mono"><?= e($p['code']) ?></div></div></div></td>
      <td><?= e($p['category'] ?? '—') ?><div class="cell-sub"><?= e($p['brand'] ?? '') ?></div></td><td><?= e(App\Core\Qty::UNITS[$p['unit']] ?? $p['unit']) ?></td>
      <td class="num"><?= (int)$p['variants'] ?></td>
      <td class="num"><?= money($p['min_price']) ?><?= $p['max_price'] !== $p['min_price'] ? ' – ' . money($p['max_price']) : '' ?></td>
      <td class="num"><?= qty($p['stock'], $p['unit']) ?> <?= (int)$p['low'] ? '<span class="badge badge-warning">' . (int)$p['low'] . ' low</span>' : '' ?></td>
      <td class="actions"><?php if (can('product.manage')): ?><a class="btn btn-sm" href="<?= e(url('/products/' . $p['id'] . '/edit')) ?>">Edit</a><?php endif; ?></td></tr><?php endforeach; ?></tbody>
  </table></div>
  <?= App\Core\View::partial('partials/pager', ['page' => $page]) ?>
<?php endif; ?>
</div>
