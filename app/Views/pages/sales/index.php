<div class="page-head">
  <form class="filters grow" method="get">
    <div class="searchbar"><?= icon('search') ?><input class="input" name="q" value="<?= e($q) ?>" placeholder="Invoice no, mobile, name or SKU  ( / )" data-page-search></div>
    <input class="input" type="date" name="date" value="<?= e(request()->query('date', '')) ?>" aria-label="Date">
    <select name="status" aria-label="Status"><option value="">All</option><option value="completed" <?= request()->query('status') === 'completed' ? 'selected' : '' ?>>Completed</option><option value="voided" <?= request()->query('status') === 'voided' ? 'selected' : '' ?>>Voided</option></select>
    <button class="btn">Search</button>
  </form>
</div>
<div class="card">
<?php if (!$page['rows']): ?><div class="empty"><?= icon('receipt') ?><h3>No invoices found</h3><p>Try another search, or create a bill from POS (F2).</p></div>
<?php else: ?>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>Invoice</th><th>Date</th><th>Customer</th><th>Cashier</th><th class="num">Items</th><th class="num">Total</th><th>Status</th><th>Sync</th></tr></thead>
    <tbody><?php foreach ($page['rows'] as $s): ?>
      <tr class="row-link" data-href="<?= e(url('/sales/' . $s['id'])) ?>" tabindex="0">
        <td class="mono cell-title"><?= e($s['invoice_no']) ?></td><td><?= e(fmt_date($s['created_at'])) ?></td>
        <td><?= e($s['customer_name'] ?: 'Walk-in') ?><div class="cell-sub"><?= e($s['customer_phone']) ?></div></td><td><?= e($s['cashier']) ?></td>
        <td class="num"><?= (int)$s['items_count'] ?></td><td class="num"><strong><?= money($s['total_paise']) ?></strong></td>
        <td><?= $s['status'] === 'voided' ? '<span class="badge badge-danger">Voided</span>' : ($s['sale_type'] === 'exchange' ? '<span class="badge badge-primary">Exchange</span>' : '<span class="badge badge-success">Paid</span>') ?>
          <?= $s['returns_count'] ? '<span class="badge badge-warning">Returns ' . (int)$s['returns_count'] . '</span>' : '' ?></td>
        <td><?= $s['sync_status'] === 'acked' ? '<span class="badge badge-success">Synced</span>' : ($s['sync_status'] === 'conflict' ? '<span class="badge badge-danger">Conflict</span>' : ($s['sync_status'] === 'failed' ? '<span class="badge badge-danger">Failed</span>' : '<span class="badge badge-warning">Pending</span>')) ?></td>
      </tr><?php endforeach; ?></tbody>
  </table></div>
  <?= App\Core\View::partial('partials/pager', ['page' => $page]) ?>
<?php endif; ?>
</div>
