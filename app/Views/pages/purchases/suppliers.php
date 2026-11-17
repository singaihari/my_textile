<?php use App\Core\Money; $e = $edit; ?>
<div class="grid g-main">
<div class="card">
  <div class="card-head"><form class="filters" method="get" style="flex:1"><div class="searchbar"><?= icon('search') ?><input class="input" name="q" value="<?= e($q) ?>" placeholder="Name, phone or GSTIN  ( / )" data-page-search></div></form></div>
  <?php if (!$page['rows']): ?><div class="empty"><?= icon('users') ?><h3>No suppliers yet</h3></div><?php else: ?>
  <div class="table-wrap"><table class="table"><thead><tr><th>Supplier</th><th>Contact</th><th>GSTIN</th><th class="num">Invoices</th><th class="num">Balance due</th><th>Last purchase</th><th></th></tr></thead><tbody>
  <?php foreach ($page['rows'] as $s): ?><tr><td class="cell-title"><?= e($s['name']) ?> <?= (int)$s['is_active'] ? '' : '<span class="badge">inactive</span>' ?></td><td><?= e($s['contact_person']) ?><div class="cell-sub"><?= e($s['phone']) ?></div></td>
    <td class="mono small"><?= e($s['gstin']) ?></td><td class="num"><?= (int)$s['invoices'] ?></td><td class="num"><?= money($s['balance']) ?></td><td><?= e(fmt_date($s['last_purchase'], false)) ?></td>
    <td class="actions"><a class="btn btn-sm" href="<?= e(url('/suppliers', ['edit' => $s['id']])) ?>">Edit</a></td></tr><?php endforeach; ?></tbody></table></div>
  <?= App\Core\View::partial('partials/pager', ['page' => $page]) ?><?php endif; ?>
</div>
<form class="card card-pad" method="post" action="<?= e(url('/suppliers/save')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= e($e['id'] ?? '') ?>">
  <h3><?= $e ? 'Edit supplier' : 'New supplier' ?></h3>
  <div class="field"><label>Name *</label><input class="input" name="name" value="<?= e($e['name'] ?? '') ?>" required></div>
  <div class="grid g2"><div class="field"><label>Contact person</label><input class="input" name="contact_person" value="<?= e($e['contact_person'] ?? '') ?>"></div><div class="field"><label>Phone</label><input class="input" name="phone" value="<?= e($e['phone'] ?? '') ?>"></div></div>
  <div class="grid g2"><div class="field"><label>GSTIN (optional)</label><input class="input mono" name="gstin" value="<?= e($e['gstin'] ?? '') ?>" maxlength="15"></div><div class="field"><label>Email</label><input class="input" type="email" name="email" value="<?= e($e['email'] ?? '') ?>"></div></div>
  <div class="field"><label>Address</label><textarea name="address" rows="2"><?= e($e['address'] ?? '') ?></textarea></div>
  <div class="field"><label>Opening balance ₹ (we owe)</label><input class="input num" name="opening_balance" value="<?= e(isset($e['opening_balance_paise']) ? Money::toDecimal((int)$e['opening_balance_paise']) : '0') ?>"></div>
  <label class="check"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" <?= !$e || (int)$e['is_active'] ? 'checked' : '' ?>> Active</label>
  <div class="form-actions"><button class="btn btn-primary">Save supplier</button><?php if ($e): ?><a class="btn" href="<?= e(url('/suppliers')) ?>">New instead</a><?php endif; ?></div>
</form>
</div>
