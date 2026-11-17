<div class="grid g-main">
<div class="card"><div class="card-head"><h3>Health checks</h3></div><table class="table"><tbody>
<?php foreach ($checks as [$label, $value, $ok]): ?><tr><td><span class="status-dot <?= $ok ? 'ok' : 'warn' ?>"></span> <?= e($label) ?></td><td class="mono small"><?= e($value) ?></td></tr><?php endforeach; ?>
</tbody></table>
<?php foreach ($checks as [$label, $value, $ok]): if ($label === 'Server software' && !$ok): ?><div class="card-body"><div class="alert alert-warning"><?= icon('alert') ?><div>You are using PHP's built-in development server. It is fine for testing; for daily shop use run Apache or Nginx + PHP as described in docs/DEPLOY_WINDOWS.md.</div></div></div><?php endif; endforeach; ?></div>
<div class="stack">
  <div class="card card-pad"><h3>This installation</h3><dl class="kv"><dt>Version</dt><dd><?= e(APP_VERSION) ?></dd><dt>Shop</dt><dd><?= e($store['name']) ?></dd><dt>Counter</dt><dd><?= e($device['code']) ?> — <?= e($device['name']) ?></dd>
    <dt>Store ID</dt><dd class="mono small"><?= e($store['id']) ?></dd><dt>Device ID</dt><dd class="mono small"><?= e($device['id']) ?></dd><dt>Pending sync</dt><dd><?= (int)($sync['pending'] + $sync['failed']) ?></dd>
    <dt>Last backup</dt><dd><?= $lastBackup ? e(fmt_date($lastBackup['mtime'])) : '<span style="color:var(--danger)">never</span>' ?></dd><dt>Time zone</dt><dd><?= e(date_default_timezone_get()) ?> · <?= e(date('d M Y H:i')) ?></dd></dl></div>
  <div class="card card-pad"><h3>Printer</h3><a class="btn" href="<?= e(url('/print-test')) ?>" target="_blank" rel="noopener"><?= icon('printer') ?> Print test page</a></div>
</div>
</div>
