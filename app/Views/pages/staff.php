<?php $tab = request()->query('tab') === 'roles' ? 'roles' : 'staff'; $e = $edit; $byGroup = []; foreach ($perms as $p) { $byGroup[$p['grp']][] = $p; } ?>
<nav class="tabs"><a href="<?= e(url('/staff')) ?>" class="<?= $tab === 'staff' ? 'active' : '' ?>">Staff</a><a href="<?= e(url('/staff', ['tab' => 'roles'])) ?>" class="<?= $tab === 'roles' ? 'active' : '' ?>">Roles &amp; permissions</a></nav>
<?php if ($tab === 'staff'): ?>
<div class="grid g-main">
  <div class="stack">
  <div class="card"><table class="table"><thead><tr><th>Name</th><th>Username</th><th>Role</th><th>Last login</th><th>Status</th><th></th></tr></thead><tbody>
  <?php foreach ($users as $u): ?><tr><td class="cell-title"><?= e($u['full_name']) ?></td><td class="mono"><?= e($u['username']) ?></td><td><?= e($u['role_name']) ?></td><td><?= e(fmt_date($u['last_login_at'])) ?></td>
    <td><?= (int)$u['is_active'] ? '<span class="badge badge-success">Active</span>' : '<span class="badge">Disabled</span>' ?></td><td class="actions"><a class="btn btn-sm" href="<?= e(url('/staff', ['edit' => $u['id']])) ?>">Edit</a></td></tr><?php endforeach; ?></tbody></table></div>
  <div class="card"><div class="card-head"><h3>Recent sign-in activity</h3><a class="btn btn-sm" href="<?= e(url('/audit', ['action' => 'auth.'])) ?>">Audit log</a></div><table class="table table-compact"><tbody>
    <?php foreach ($activity as $a): ?><tr><td><?= e(fmt_date($a['created_at'])) ?></td><td><?= e($a['action']) ?></td><td><?= e($a['full_name']) ?></td></tr><?php endforeach; ?></tbody></table></div>
  </div>
  <form class="card card-pad" method="post" action="<?= e(url('/staff/save')) ?>" autocomplete="off"><?= csrf_field() ?><input type="hidden" name="id" value="<?= e($e['id'] ?? '') ?>">
    <h3><?= $e ? 'Edit staff' : 'Add staff' ?></h3>
    <div class="field"><label>Full name *</label><input class="input" name="full_name" value="<?= e($e['full_name'] ?? '') ?>" required></div>
    <div class="field"><label>Username *</label><input class="input" name="username" value="<?= e($e['username'] ?? '') ?>" required autocapitalize="none"></div>
    <div class="field"><label>Role *</label><select name="role_id"><?php foreach ($roles as $r): ?><option value="<?= e($r['id']) ?>" <?= ($e['role_id'] ?? '') === $r['id'] || (!$e && $r['code'] === 'cashier') ? 'selected' : '' ?>><?= e($r['name']) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label>Counter</label><select name="counter_device_id"><option value="">Any</option><?php foreach ($devices as $d): ?><option value="<?= e($d['id']) ?>" <?= ($e['counter_device_id'] ?? '') === $d['id'] ? 'selected' : '' ?>><?= e($d['code'] . ' — ' . $d['name']) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label><?= $e ? 'New password (leave blank to keep)' : 'Password *' ?></label><input class="input" type="password" name="password" <?= $e ? '' : 'required' ?> minlength="8" autocomplete="new-password"><div class="hint">8+ characters, letters and numbers. Share it privately.</div></div>
    <label class="check"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" <?= !$e || (int)$e['is_active'] ? 'checked' : '' ?>> Active (untick to disable sign-in)</label>
    <div class="form-actions"><button class="btn btn-primary">Save</button><?php if ($e): ?><a class="btn" href="<?= e(url('/staff')) ?>">Add new instead</a><?php endif; ?></div>
  </form>
</div>
<?php else: ?>
<p class="muted">Permissions are enforced on the server for every page and action. Admin always has everything.</p>
<?php foreach ($roles as $r): if ($r['code'] === 'admin') continue; ?>
<form class="card" method="post" action="<?= e(url('/roles/save')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= e($r['id']) ?>">
  <div class="card-head"><input class="input" name="name" value="<?= e($r['name']) ?>" style="max-width:240px" aria-label="Role name"><label class="row small" style="margin:0">Max discount without approval <input class="input input-sm num" name="max_discount_pct" value="<?= (int)($r['max_discount_bp'] / 100) ?>" style="width:70px"> %</label><span style="flex:1"></span><button class="btn btn-sm btn-primary">Save role</button></div>
  <div class="card-body grid g3"><?php foreach ($byGroup as $g => $list): ?><div><div class="label"><?= e($g) ?></div><?php foreach ($list as $p): ?><label class="check small" title="<?= e($p['code']) ?>"><input type="checkbox" name="perms[]" value="<?= e($p['code']) ?>" <?= isset($rp[$r['id']][$p['code']]) ? 'checked' : '' ?>> <?= e($p['description']) ?></label><?php endforeach; ?></div><?php endforeach; ?></div>
</form>
<?php endforeach; ?>
<form class="card card-pad" method="post" action="<?= e(url('/roles/save')) ?>"><?= csrf_field() ?><h3>New custom role</h3><div class="row"><input class="input" name="name" placeholder="e.g. Senior cashier" style="max-width:280px" required><button class="btn">Create (then tick permissions)</button></div></form>
<?php endif; ?>
