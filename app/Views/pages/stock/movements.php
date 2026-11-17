<div class="page-head"><div class="grow"><?php if ($variant): ?><h2 style="margin:0"><?= e($variant['name']) ?> <span class="mono muted"><?= e($variant['sku']) ?></span></h2><?php endif; ?>
  <p>Every stock change is recorded here and can never be edited or deleted.</p></div>
  <form class="filters" method="get"><?php if ($variant): ?><input type="hidden" name="variant" value="<?= e(request()->query('variant')) ?>"><?php endif; ?>
    <select name="type" aria-label="Type"><option value="">All types</option><?php foreach (App\Services\InventoryService::TYPES as $t): ?><option value="<?= e($t) ?>" <?= request()->query('type') === $t ? 'selected' : '' ?>><?= e(str_replace('_', ' ', $t)) ?></option><?php endforeach; ?></select><button class="btn">Filter</button></form></div>
<div class="card">
<?php if (!$page['rows']): ?><div class="empty"><?= icon('activity') ?><h3>No movements</h3></div><?php else: ?>
  <div class="table-wrap"><table class="table table-compact"><thead><tr><th>When</th><th>Item</th><th>Type</th><th>Location</th><th class="num">Qty</th><th class="num">Balance after</th><?php if (can('product.view_cost')): ?><th class="num">Unit cost</th><th class="num">Avg after</th><?php endif; ?><th>Reference</th><th>By</th></tr></thead><tbody>
  <?php foreach ($page['rows'] as $m): ?><tr><td class="nowrap"><?= e(fmt_date($m['created_at'])) ?></td><td><?= e($m['name']) ?><div class="cell-sub mono"><?= e($m['sku']) ?></div></td>
    <td><span class="badge <?= (int)$m['qty_milli'] > 0 ? 'badge-success' : 'badge-warning' ?> plain"><?= e(str_replace('_', ' ', $m['movement_type'])) ?></span></td><td><?= e($m['location']) ?></td>
    <td class="num"><strong><?= (int)$m['qty_milli'] > 0 ? '+' : '' ?><?= qty($m['qty_milli'], $m['unit']) ?></strong></td><td class="num"><?= qty($m['balance_after_milli'], $m['unit']) ?></td>
    <?php if (can('product.view_cost')): ?><td class="num"><?= money($m['unit_cost_paise']) ?></td><td class="num"><?= money($m['avg_cost_after_paise']) ?></td><?php endif; ?>
    <td class="small"><?php if ($m['ref_type'] === 'sale'): ?><a class="mono" href="<?= e(url('/sales/' . $m['ref_id'])) ?>"><?= e($m['reason']) ?></a><?php elseif ($m['ref_type'] === 'return'): ?><a class="mono" href="<?= e(url('/returns/' . $m['ref_id'])) ?>"><?= e($m['reason']) ?></a><?php elseif ($m['ref_type'] === 'purchase'): ?><a class="mono" href="<?= e(url('/purchases/' . $m['ref_id'])) ?>"><?= e($m['reason']) ?></a><?php else: ?><?= e($m['reason']) ?><?php endif; ?></td>
    <td class="small"><?= e($m['user']) ?></td></tr><?php endforeach; ?></tbody></table></div>
  <?= App\Core\View::partial('partials/pager', ['page' => $page]) ?>
<?php endif; ?>
</div>
