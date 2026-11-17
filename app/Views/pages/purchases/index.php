<div class="page-head"><div class="grow"><p>Supplier invoices (GRN). Stock and weighted-average cost change only when a purchase is <strong>posted</strong>.</p></div>
  <a class="btn btn-primary" href="<?= e(url('/purchases/new')) ?>"><?= icon('plus') ?> New purchase</a>
  <a class="btn" href="<?= e(url('/supplier-returns/new')) ?>"><?= icon('return') ?> Return to supplier</a>
  <a class="btn" href="<?= e(url('/suppliers')) ?>">Suppliers</a></div>
<div class="card">
<?php if (!$page['rows']): ?><div class="empty"><?= icon('truck') ?><h3>No purchases yet</h3><p>Record goods received from a supplier to increase stock.</p></div><?php else: ?>
  <div class="table-wrap"><table class="table"><thead><tr><th>Purchase no</th><th>Supplier</th><th>Supplier invoice</th><th class="num">Lines</th><th class="num">Total</th><th class="num">Balance</th><th>Status</th><th>Date</th></tr></thead><tbody>
  <?php foreach ($page['rows'] as $p): ?><tr class="row-link" data-href="<?= e(url('/purchases/' . $p['id'])) ?>" tabindex="0"><td class="mono cell-title"><?= e($p['purchase_no']) ?></td><td><?= e($p['supplier']) ?></td>
    <td><?= e($p['supplier_invoice_no']) ?> <span class="muted small"><?= e($p['supplier_invoice_date']) ?></span></td><td class="num"><?= (int)$p['lines'] ?></td><td class="num"><?= money($p['total_paise']) ?></td>
    <td class="num"><?= money((int)$p['total_paise'] - (int)$p['amount_paid_paise']) ?></td>
    <td><span class="badge <?= ['draft' => 'badge-warning', 'posted' => 'badge-success', 'cancelled' => ''][$p['status']] ?? '' ?>"><?= e($p['status']) ?></span></td><td><?= e(fmt_date($p['posted_at'] ?: $p['created_at'], false)) ?></td></tr><?php endforeach; ?></tbody></table></div>
  <?= App\Core\View::partial('partials/pager', ['page' => $page]) ?><?php endif; ?>
</div>
<?php if ($returns): ?><div class="card"><div class="card-head"><h3>Recent supplier returns</h3></div><table class="table table-compact"><thead><tr><th>No</th><th>Supplier</th><th>Reason</th><th class="num">Value at cost</th><th>Date</th></tr></thead><tbody>
<?php foreach ($returns as $r): ?><tr><td class="mono"><?= e($r['return_no']) ?></td><td><?= e($r['supplier']) ?></td><td><?= e($r['reason']) ?></td><td class="num"><?= money($r['total_paise']) ?></td><td><?= e(fmt_date($r['created_at'])) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
