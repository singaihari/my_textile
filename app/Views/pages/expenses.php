<div class="grid g-main">
<div class="card">
  <div class="card-head"><form class="filters" method="get" style="flex:1"><input class="input" type="date" name="from" value="<?= e($from) ?>"><input class="input" type="date" name="to" value="<?= e($to) ?>"><button class="btn">Show</button></form><strong>Total <?= money($total) ?></strong></div>
  <?php if (!$page['rows']): ?><div class="empty"><?= icon('wallet') ?><h3>No expenses in this period</h3><p>Rent, salaries, electricity… recorded here are used in the net operating profit report.</p></div><?php else: ?>
  <div class="table-wrap"><table class="table"><thead><tr><th>Date</th><th>Category</th><th>Description</th><th>Paid by</th><th class="num">Amount</th><th>By</th></tr></thead><tbody>
  <?php foreach ($page['rows'] as $x): ?><tr><td><?= e(fmt_date($x['expense_date'], false)) ?></td><td><?= e($x['category']) ?></td><td><?= e($x['description']) ?> <?= $x['attachment_path'] ? '<span class="badge plain">attachment</span>' : '' ?><div class="cell-sub"><?= e($x['reference']) ?></div></td>
    <td><?= e($x['payment_method']) ?></td><td class="num"><?= money($x['amount_paise']) ?></td><td><?= e($x['user']) ?></td></tr><?php endforeach; ?></tbody></table></div>
  <?= App\Core\View::partial('partials/pager', ['page' => $page]) ?><?php endif; ?>
</div>
<form class="card card-pad" method="post" action="<?= e(url('/expenses')) ?>" enctype="multipart/form-data" data-once><?= csrf_field() ?>
  <h3>Record expense</h3>
  <div class="field"><label>Date</label><input class="input" type="date" name="expense_date" value="<?= e(today()) ?>" required></div>
  <div class="field"><label>Category</label><select name="category_id" required><?php foreach ($categories as $c): ?><option value="<?= e($c['id']) ?>"><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
  <div class="field"><label>Description *</label><input class="input" name="description" required></div>
  <div class="grid g2"><div class="field"><label>Amount ₹ *</label><input class="input num" name="amount" required inputmode="decimal"></div>
    <div class="field"><label>Paid by</label><select name="payment_method"><option value="cash">Cash (from drawer)</option><option value="upi">UPI</option><option value="card">Card</option><option value="bank">Bank</option></select></div></div>
  <div class="field"><label>Reference</label><input class="input" name="reference"></div>
  <div class="field"><label>Bill photo / PDF (optional)</label><input class="input" type="file" name="attachment" accept=".jpg,.jpeg,.png,.pdf"></div>
  <div class="form-actions"><button class="btn btn-primary">Save expense</button></div>
</form>
</div>
