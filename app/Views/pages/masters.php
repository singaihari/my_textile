<?php
use App\Core\Money;
$editId = (string)request()->query('edit', '');
$find = fn(array $rows) => $editId ? (array_values(array_filter($rows, fn($r) => $r['id'] === $editId))[0] ?? null) : null;
$tabs = ['categories' => 'Categories', 'brands' => 'Brands', 'sizes' => 'Sizes', 'colors' => 'Colours', 'tax' => 'Tax rules (GST)', 'expense' => 'Expense categories'];
$act = fn($r) => (int)$r['is_active'] ? '<span class="badge badge-success">Active</span>' : '<span class="badge">Inactive</span>';
$editLink = fn($id) => '<a class="btn btn-sm" href="' . e(url('/masters', ['tab' => $tab, 'edit' => $id])) . '">Edit</a>';
?>
<nav class="tabs"><?php foreach ($tabs as $k => $l): ?><a href="<?= e(url('/masters', ['tab' => $k])) ?>" class="<?= $tab === $k ? 'active' : '' ?>"><?= e($l) ?></a><?php endforeach; ?></nav>

<?php if ($tab === 'tax'): $e = $find($taxRules); ?>
<div class="alert alert-warning"><?= icon('alert') ?><div><strong>Tax rates are your responsibility.</strong> The sample rules are examples, not legal advice. Confirm current GST rates, thresholds and HSN codes with your accountant. A changed rule applies to new bills only; saved bills never change.</div></div>
<div class="grid g-main">
  <div class="card"><table class="table"><thead><tr><th>Code</th><th>Name</th><th class="num">Rate</th><th>Slab</th><th class="num">Products</th><th>Status</th><th></th></tr></thead><tbody>
    <?php foreach ($taxRules as $t): ?><tr><td class="mono cell-title"><?= e($t['code']) ?></td><td><?= e($t['name']) ?><div class="cell-sub"><?= e($t['notes']) ?></div></td><td class="num"><?= bp_pct($t['rate_bp']) ?></td>
      <td><?= $t['threshold_paise'] !== null ? bp_pct($t['above_rate_bp']) . ' when unit value &gt; ' . money($t['threshold_paise']) : '—' ?></td><td class="num"><?= (int)$t['used'] ?></td><td><?= $act($t) ?></td><td class="actions"><?= can('tax.manage') ? $editLink($t['id']) : '' ?></td></tr><?php endforeach; ?></tbody></table></div>
  <?php if (can('tax.manage')): ?>
  <form class="card card-pad" method="post" action="<?= e(url('/masters/tax')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= e($e['id'] ?? '') ?>">
    <h3><?= $e ? 'Edit tax rule' : 'New tax rule' ?></h3>
    <div class="field"><label>Code</label><input class="input mono" name="code" value="<?= e($e['code'] ?? '') ?>" required></div>
    <div class="field"><label>Name</label><input class="input" name="name" value="<?= e($e['name'] ?? '') ?>"></div>
    <div class="field"><label>Base GST rate %</label><input class="input num" name="rate" value="<?= e(isset($e['rate_bp']) ? rtrim(rtrim(sprintf('%.2f', $e['rate_bp'] / 100), '0'), '.') : '') ?>" required></div>
    <div class="grid g2"><div class="field"><label>Threshold per unit ₹ (optional)</label><input class="input num" name="threshold" value="<?= e(isset($e['threshold_paise']) ? Money::toDecimal((int)$e['threshold_paise']) : '') ?>"></div>
      <div class="field"><label>Higher rate % above it</label><input class="input num" name="above_rate" value="<?= e(isset($e['above_rate_bp']) ? rtrim(rtrim(sprintf('%.2f', $e['above_rate_bp'] / 100), '0'), '.') : '') ?>"></div></div>
    <p class="hint">Slab test: per-unit taxable value (after discount) at the base rate is compared with the threshold.</p>
    <div class="field"><label>Notes</label><input class="input" name="notes" value="<?= e($e['notes'] ?? '') ?>"></div>
    <label class="check"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" <?= !$e || (int)$e['is_active'] ? 'checked' : '' ?>> Active</label>
    <div class="form-actions"><button class="btn btn-primary">Save</button><?php if ($e): ?><a class="btn" href="<?= e(url('/masters', ['tab' => 'tax'])) ?>">New instead</a><?php endif; ?></div>
  </form><?php endif; ?>
</div>
<?php else:
  $map = ['categories' => [$categories, 'category'], 'brands' => [$brands, 'brand'], 'sizes' => [$sizes, 'size'], 'colors' => [$colors, 'color'], 'expense' => [$expenseCats, 'expense']];
  [$rows, $type] = $map[$tab];
  $e = $find($rows);
?>
<div class="grid g-main">
  <div class="card"><div class="table-wrap"><table class="table"><thead><tr><th>Name</th><?php if ($type === 'category'): ?><th>Parent</th><?php elseif ($type === 'size'): ?><th>System</th><th class="num">Order</th><?php elseif ($type === 'color'): ?><th>Swatch</th><?php endif; ?><?php if ($type !== 'expense'): ?><th class="num">Used by</th><?php endif; ?><th>Status</th><th></th></tr></thead><tbody>
    <?php if (!$rows): ?><tr><td colspan="6" class="empty">Nothing yet — add one on the right.</td></tr><?php endif; ?>
    <?php foreach ($rows as $r): ?><tr><td class="cell-title"><?= e($r['name'] ?? $r['label']) ?></td>
      <?php if ($type === 'category'): ?><td><?= e($r['parent'] ?? '—') ?></td><?php elseif ($type === 'size'): ?><td><?= e($r['size_system']) ?></td><td class="num"><?= (int)$r['sort_order'] ?></td><?php elseif ($type === 'color'): ?><td><span class="swatch" style="background:<?= e($r['hex'] ?: '#fff') ?>"></span> <span class="mono small"><?= e($r['hex']) ?></span></td><?php endif; ?>
      <?php if ($type !== 'expense'): ?><td class="num"><?= (int)$r['used'] ?></td><?php endif; ?><td><?= $act($r) ?></td><td class="actions"><?= $editLink($r['id']) ?></td></tr><?php endforeach; ?></tbody></table></div></div>
  <form class="card card-pad" method="post" action="<?= e(url('/masters/save')) ?>"><?= csrf_field() ?>
    <input type="hidden" name="type" value="<?= e($type) ?>"><input type="hidden" name="id" value="<?= e($e['id'] ?? '') ?>">
    <h3><?= $e ? 'Edit' : 'Add' ?> <?= e($type === 'expense' ? 'expense category' : $type) ?></h3>
    <div class="field"><label><?= $type === 'size' ? 'Size label' : 'Name' ?></label><input class="input" name="name" value="<?= e($e['name'] ?? $e['label'] ?? '') ?>" required autofocus></div>
    <?php if ($type === 'category'): ?><div class="field"><label>Parent category (for sub-categories)</label><select name="parent_id"><option value="">— top level —</option><?php foreach ($parents as $p): if (($e['id'] ?? '') === $p['id']) continue; ?><option value="<?= e($p['id']) ?>" <?= ($e['parent_id'] ?? '') === $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
    <?php if ($type === 'size'): ?><div class="grid g2"><div class="field"><label>Size system</label><select name="size_system"><?php foreach (['apparel' => 'Apparel S–XXL', 'waist' => 'Waist (numeric)', 'age' => 'Kids age', 'free' => 'Free size', 'other' => 'Other'] as $k => $l): ?><option value="<?= $k ?>" <?= ($e['size_system'] ?? '') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
      <div class="field"><label>Sort order</label><input class="input num" name="sort_order" value="<?= e($e['sort_order'] ?? '0') ?>"></div></div><?php endif; ?>
    <?php if ($type === 'color'): ?><div class="field"><label>Swatch colour</label><input class="input" type="color" name="hex" value="<?= e($e['hex'] ?? '#2563EB') ?>" style="height:40px;padding:2px"></div><?php endif; ?>
    <label class="check"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" <?= !$e || (int)$e['is_active'] ? 'checked' : '' ?>> Active</label>
    <p class="hint">Records in use are never deleted — deactivate them to hide from new products.</p>
    <div class="form-actions"><button class="btn btn-primary">Save</button><?php if ($e): ?><a class="btn" href="<?= e(url('/masters', ['tab' => $tab])) ?>">Add new instead</a><?php endif; ?></div>
  </form>
</div>
<?php endif; ?>
