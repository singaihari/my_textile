<div class="grid g-main">
<div class="card">
  <div class="card-head"><form class="filters" method="get" style="flex:1"><div class="searchbar"><?= icon('search') ?><input class="input" name="q" value="<?= e($q) ?>" placeholder="Name, mobile or GSTIN  ( / )" data-page-search></div></form></div>
  <?php if (!$page['rows']): ?><div class="empty"><?= icon('user') ?><h3>No customers</h3><p>A phone number is never required for ordinary cash bills.</p></div><?php else: ?>
  <div class="table-wrap"><table class="table"><thead><tr><th>Customer</th><th>Mobile</th><th class="num">Bills</th><th class="num">Spent</th><th>Last visit</th></tr></thead><tbody>
  <?php foreach ($page['rows'] as $c): ?><tr class="row-link" data-href="<?= e(url('/customers/' . $c['id'])) ?>" tabindex="0"><td class="cell-title"><?= e($c['name']) ?><?= $c['gstin'] ? ' <span class="badge plain">B2B</span>' : '' ?></td><td class="mono"><?= e($c['phone']) ?></td>
    <td class="num"><?= (int)$c['bills'] ?></td><td class="num"><?= money($c['spent']) ?></td><td><?= e(fmt_date($c['last_visit'], false)) ?></td></tr><?php endforeach; ?></tbody></table></div>
  <?= App\Core\View::partial('partials/pager', ['page' => $page]) ?><?php endif; ?>
</div>
<?php if (can('customer.manage')): ?>
<form class="card card-pad" method="post" action="<?= e(url('/customers/save')) ?>"><?= csrf_field() ?>
  <h3>New customer</h3>
  <div class="field"><label>Name *</label><input class="input" name="name" value="<?= e(old('name')) ?>" required></div>
  <div class="field"><label>Mobile</label><input class="input" name="phone" value="<?= e(old('phone')) ?>" inputmode="tel"></div>
  <div class="field"><label>Email</label><input class="input" type="email" name="email" value="<?= e(old('email')) ?>"></div>
  <div class="field"><label>GSTIN (business customers)</label><input class="input mono" name="gstin" value="<?= e(old('gstin')) ?>" maxlength="15"></div>
  <div class="field"><label>Address</label><textarea name="address" rows="2"><?= e(old('address')) ?></textarea></div>
  <label class="check"><input type="checkbox" name="consent_marketing" value="1"> Agrees to receive offers (SMS/WhatsApp)</label>
  <div class="form-actions"><button class="btn btn-primary">Save</button></div>
</form><?php endif; ?>
</div>
