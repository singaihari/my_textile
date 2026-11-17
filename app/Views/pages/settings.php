<?php
$tab = in_array(request()->query('tab'), ['shop', 'billing', 'printing', 'stock', 'security', 'shortcuts'], true) ? request()->query('tab') : 'shop';
$tabs = ['shop' => 'Shop profile', 'billing' => 'Billing & tax', 'printing' => 'Printing & labels', 'stock' => 'Stock & returns', 'security' => 'Security', 'shortcuts' => 'Shortcuts'];
$f = fn($k, $label, $hint = '', $type = 'text') => '<div class="field"><label for="s_' . $k . '">' . e($label) . '</label><input class="input" type="' . $type . '" id="s_' . $k . '" name="' . $k . '" value="' . e($s[$k]) . '">' . ($hint ? '<div class="hint">' . e($hint) . '</div>' : '') . '</div>';
$b = fn($k, $label) => '<input type="hidden" name="_has_' . $k . '" value="1"><label class="check"><input type="checkbox" name="' . $k . '" value="1" ' . ($s[$k] === '1' ? 'checked' : '') . '> ' . e($label) . '</label>';
$sel = fn($k, $label, array $opts) => '<div class="field"><label for="s_' . $k . '">' . e($label) . '</label><select id="s_' . $k . '" name="' . $k . '">' . implode('', array_map(fn($v, $l) => '<option value="' . e($v) . '" ' . ((string)$s[$k] === (string)$v ? 'selected' : '') . '>' . e($l) . '</option>', array_keys($opts), $opts)) . '</select></div>';
?>
<nav class="tabs"><?php foreach ($tabs as $k => $l): ?><a href="<?= e(url('/settings', ['tab' => $k])) ?>" class="<?= $tab === $k ? 'active' : '' ?>"><?= e($l) ?></a><?php endforeach; ?></nav>
<form class="card" method="post" action="<?= e(url('/settings')) ?>" style="max-width:900px"><?= csrf_field() ?><input type="hidden" name="section" value="<?= e($tab) ?>">
<div class="card-body">
<?php if ($tab === 'shop'): ?>
  <div class="form-grid"><?= $f('shop_name', 'Shop name') ?><?= $f('shop_phone', 'Phone') ?><?= $f('shop_email', 'Email', '', 'email') ?><?= $f('shop_gstin', 'GSTIN', 'Leave empty if not GST-registered') ?>
  <?= $f('shop_state_name', 'State') ?><?= $f('shop_state_code', 'State code', 'e.g. 33 for Tamil Nadu') ?></div>
  <div class="field"><label for="s_addr">Address</label><textarea id="s_addr" name="shop_address" rows="3"><?= e($s['shop_address']) ?></textarea></div>
  <?= $sel('language', 'Default language', ['en' => 'English', 'ta' => 'தமிழ் (Tamil)']) ?>
<?php elseif ($tab === 'billing'): ?>
  <div class="alert alert-warning"><?= icon('alert') ?><div>Tax settings affect every new bill. Confirm with your accountant. Saved bills never change. Tax rates themselves are in <a href="<?= e(url('/masters', ['tab' => 'tax'])) ?>">Masters → Tax rules</a>.</div></div>
  <div class="form-grid"><?= $f('invoice_prefix', 'Invoice prefix', 'Invoice no: PREFIX-COUNTER-FY-00001') ?><?= $f('return_prefix', 'Credit note prefix') ?>
  <?= $sel('fy_start_month', 'Financial year starts', ['4' => 'April (India)', '1' => 'January']) ?><?= $sel('round_off', 'Bill round-off', ['rupee' => 'Nearest rupee', 'none' => 'No rounding']) ?></div>
  <?= $b('prices_include_tax', 'Selling prices include GST (MRP-style pricing)') ?>
  <?= $b('require_customer', 'Require customer mobile on every bill') ?>
  <?= $b('merge_duplicate_scans', 'Scanning the same item again increases quantity') ?>
  <div class="field" style="margin-top:12px"><span class="label">Payment methods</span><input type="hidden" name="_has_payment_methods" value="1">
    <?php foreach (['cash' => 'Cash', 'upi' => 'UPI', 'card' => 'Card', 'credit' => 'Credit (pay later)'] as $m => $l): ?><label class="check"><input type="checkbox" name="payment_methods[]" value="<?= $m ?>" <?= in_array($m, explode(',', $s['payment_methods']), true) ? 'checked' : '' ?> <?= $m === 'cash' ? 'disabled checked' : '' ?>> <?= $l ?></label><?php endforeach; ?></div>
  <?= $f('upi_vpa', 'Shop UPI ID (printed on receipt, optional)', 'Only enter a real, verified UPI ID') ?>
<?php elseif ($tab === 'printing'): ?>
  <div class="form-grid"><?= $sel('printer_format', 'Default receipt', ['80mm' => '80 mm thermal', 'a4' => 'A4 / laser']) ?></div>
  <?= $b('auto_print', 'Open print dialog automatically after saving a bill') ?><?= $b('show_tax_breakup', 'Print GST rate-wise breakup on receipts') ?>
  <div class="field"><label>Receipt footer</label><textarea name="receipt_footer" rows="2"><?= e($s['receipt_footer']) ?></textarea></div>
  <div class="field"><label>Return policy (printed)</label><textarea name="return_policy_text" rows="2"><?= e($s['return_policy_text']) ?></textarea></div>
  <h3>Barcode labels</h3><div class="form-grid"><?= $f('label_width_mm', 'Label width (mm)') ?><?= $f('label_height_mm', 'Label height (mm)') ?><?= $f('label_columns', 'Labels per row') ?></div>
  <div class="row"><a class="btn" href="<?= e(url('/print-test', ['format' => '80mm'])) ?>" target="_blank" rel="noopener"><?= icon('printer') ?> Test 80 mm</a><a class="btn" href="<?= e(url('/print-test', ['format' => 'a4'])) ?>" target="_blank" rel="noopener">Test A4</a></div>
  <p class="hint">Browsers always show a print dialog; truly silent printing needs a kiosk-mode browser or a print bridge (see docs/DEPLOY_WINDOWS.md).</p>
<?php elseif ($tab === 'stock'): ?>
  <?= $b('allow_negative_stock', 'Allow selling below zero stock for everyone (not recommended)') ?>
  <p class="hint">When off, only roles with “Sell beyond available stock” can oversell, and each time must be confirmed and is audited.</p>
  <div class="form-grid"><?= $f('return_window_days', 'Return window (days)', '0 = no limit') ?><?= $sel('qty_precision_m', 'Metre quantity decimals', ['0' => '0 (1 m)', '1' => '1 (0.1 m)', '2' => '2 (0.01 m)', '3' => '3 (0.001 m)']) ?><?= $f('backup_keep', 'Backups to keep') ?></div>
<?php elseif ($tab === 'security'): ?>
  <div class="form-grid"><?= $f('session_lock_minutes', 'Lock screen after inactivity (minutes)') ?></div>
  <?= $b('require_cash_session', 'Require an open cash counter before billing') ?>
  <p class="hint">Role permissions and discount limits are under <a href="<?= e(url('/staff')) ?>">Staff &amp; roles</a>.</p>
<?php else: ?>
  <p class="muted">Comma-separated keys per action, e.g. <span class="mono">F6, Alt+6</span>. Supported: F1–F12, Ctrl+/Alt+/Shift+ with a letter or digit. Avoid keys your browser reserves (Ctrl+T, Ctrl+W, Alt+F, Alt+D).</p>
  <table class="table table-compact"><tbody><?php foreach ($shortcuts as $a => $keys): ?><tr><td style="width:160px"><strong><?= e(ucfirst($a)) ?></strong></td><td><input class="input mono" name="shortcut[<?= e($a) ?>]" value="<?= e(implode(', ', $keys)) ?>"></td></tr><?php endforeach; ?></tbody></table>
<?php endif; ?>
</div>
<div class="modal-foot"><button class="btn btn-primary">Save settings</button></div>
</form>
