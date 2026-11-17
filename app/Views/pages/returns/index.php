<div class="card card-pad">
  <form class="filters" method="get">
    <div class="searchbar" style="max-width:520px"><?= icon('search') ?><input class="input" name="q" value="<?= e($q) ?>" placeholder="Invoice number, customer mobile, or scan item barcode/SKU" autofocus data-page-search></div>
    <input class="input" type="date" name="date" value="<?= e($date) ?>" aria-label="Bill date">
    <button class="btn btn-primary">Find bill</button>
  </form>
  <p class="hint">Scan the barcode on the printed receipt to jump straight to the bill.</p>
</div>
<?php if ($q !== '' || $date !== ''): ?>
<div class="card">
  <div class="card-head"><h3>Matching bills</h3></div>
  <?php if (!$results): ?><div class="empty"><?= icon('search') ?><h3>No completed bill found</h3><p>Voided bills cannot be returned.</p></div>
  <?php else: ?><div class="table-wrap"><table class="table"><thead><tr><th>Invoice</th><th>Date</th><th>Customer</th><th class="num">Total</th><th class="num">Still returnable</th><th></th></tr></thead><tbody>
    <?php foreach ($results as $s): ?><tr class="row-link" data-href="<?= e(url('/returns/sale/' . $s['id'])) ?>" tabindex="0">
      <td class="mono cell-title"><?= e($s['invoice_no']) ?></td><td><?= e(fmt_date($s['created_at'])) ?></td><td><?= e($s['customer_name'] ?: 'Walk-in') ?> <span class="muted"><?= e($s['customer_phone']) ?></span></td>
      <td class="num"><?= money($s['total_paise']) ?></td><td class="num"><?= qty($s['returnable'], 'm') ?></td><td class="actions"><a class="btn btn-sm btn-primary" href="<?= e(url('/returns/sale/' . $s['id'])) ?>">Select</a></td></tr>
    <?php endforeach; ?></tbody></table></div><?php endif; ?>
</div>
<?php endif; ?>
<div class="card">
  <div class="card-head"><h3>Recent credit notes</h3></div>
  <?php if (!$recent): ?><div class="empty small">No returns yet.</div><?php else: ?>
  <div class="table-wrap"><table class="table"><thead><tr><th>Credit note</th><th>Date</th><th>Original bill</th><th>Type</th><th>Reason</th><th class="num">Value</th><th class="num">Refunded</th></tr></thead><tbody>
    <?php foreach ($recent as $r): ?><tr class="row-link" data-href="<?= e(url('/returns/' . $r['id'])) ?>" tabindex="0"><td class="mono"><?= e($r['return_no']) ?></td><td><?= e(fmt_date($r['created_at'])) ?></td><td class="mono"><?= e($r['invoice_no']) ?></td>
      <td><span class="badge <?= $r['return_type'] === 'void' ? 'badge-danger' : ($r['return_type'] === 'exchange' ? 'badge-primary' : '') ?>"><?= e($r['return_type']) ?></span></td><td><?= e($r['reason']) ?></td><td class="num"><?= money($r['total_paise']) ?></td><td class="num"><?= money($r['refund_paise']) ?></td></tr>
    <?php endforeach; ?></tbody></table></div><?php endif; ?>
</div>
