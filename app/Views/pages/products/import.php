<?php use App\Services\CsvImportService; ?>
<div class="grid g-main">
<div class="card">
  <div class="card-head"><h3>1. Upload CSV</h3><a class="btn btn-sm" href="<?= e(url('/export/products')) ?>"><?= icon('download') ?> Export current products (template)</a></div>
  <form class="card-body" method="post" action="<?= e(url('/import/preview')) ?>" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <div class="field"><label for="csv">CSV file (UTF-8, comma separated)</label><input class="input" type="file" id="csv" name="csv" accept=".csv,text/csv" required></div>
    <button class="btn btn-primary">Preview — nothing is saved yet</button>
  </form>
</div>
<div class="card card-pad"><h3>Columns</h3><p class="small mono"><?= e(implode(', ', CsvImportService::COLUMNS)) ?></p>
  <p class="small muted">Required: product_code, product_name, unit (pcs/set/m), mrp, selling_price. One row per variant; rows with the same product_code form one product. Existing SKUs get their prices updated (if you have permission); opening_qty is used only for new SKUs. Categories, brands, sizes and colours are created by name when missing.</p></div>
</div>
<?php if ($preview): ?>
<div class="card">
  <div class="card-head"><h3>2. Preview: <?= (int)$preview['total'] ?> rows, <?= (int)$preview['products'] ?> products</h3>
    <?php if ($preview['errors']): ?><span class="badge badge-danger"><?= (int)$preview['errors'] ?> rows with errors</span><?php else: ?><span class="badge badge-success">No errors</span><?php endif; ?></div>
  <div class="table-wrap" style="max-height:480px"><table class="table table-compact"><thead><tr><th>Line</th><th>Action</th><th>Code</th><th>Name</th><th>Size</th><th>Colour</th><th>SKU</th><th>Barcode</th><th class="num">MRP</th><th class="num">Price</th><th class="num">Opening</th><th>Problems</th></tr></thead><tbody>
    <?php foreach (array_slice($preview['rows'], 0, 1000) as $r): ?><tr style="<?= $r['_errors'] ? 'background:var(--danger-50)' : '' ?>"><td><?= (int)$r['_line'] ?></td><td><?= e($r['_action']) ?></td><td class="mono"><?= e($r['product_code']) ?></td><td><?= e($r['product_name']) ?></td><td><?= e($r['size']) ?></td><td><?= e($r['color']) ?></td><td class="mono"><?= e($r['sku'] ?: 'auto') ?></td><td class="mono"><?= e($r['barcode'] ?: 'auto') ?></td><td class="num"><?= e($r['mrp']) ?></td><td class="num"><?= e($r['selling_price']) ?></td><td class="num"><?= e($r['opening_qty']) ?></td><td style="color:var(--danger)"><?= e(implode('; ', $r['_errors'])) ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
  <div class="card-body">
    <?php if ($preview['errors']): ?><div class="alert alert-error"><?= icon('alert') ?><div>Fix the highlighted rows in your spreadsheet and upload again. Nothing was imported.</div></div>
    <?php else: ?><form method="post" action="<?= e(url('/import/commit')) ?>" data-confirm="Import <?= (int)$preview['total'] ?> rows now?" data-once><?= csrf_field() ?><input type="hidden" name="token" value="<?= e($token) ?>"><button class="btn btn-primary btn-lg">3. Import now</button> <span class="muted small">The whole import is one transaction — it either fully succeeds or nothing changes.</span></form><?php endif; ?>
  </div>
</div>
<?php endif; ?>
