<div class="grid g-main">
<div class="card">
  <div class="card-head"><h3>Backups</h3><form method="post" action="<?= e(url('/backup/create')) ?>" data-once><?= csrf_field() ?><button class="btn btn-primary"><?= icon('database') ?> Create backup now</button></form></div>
  <?php if (!$backups): ?><div class="empty"><?= icon('database') ?><h3>No backups yet</h3><p>Create one now, and copy backups to a USB drive or cloud folder regularly.</p></div><?php else: ?>
  <div class="table-wrap"><table class="table"><thead><tr><th>File</th><th>Created</th><th class="num">Size</th><th class="num">Bills</th><th>Last bill</th><th>Check</th><th></th></tr></thead><tbody>
  <?php foreach ($backups as $b): $m = $b['meta']; ?><tr><td class="mono small"><?= e($b['file']) ?></td><td><?= e(fmt_date($b['mtime'])) ?></td><td class="num"><?= number_format($b['size'] / 1048576, 2) ?> MB</td>
    <td class="num"><?= isset($m['counts']['sales']) ? (int)$m['counts']['sales'] : '?' ?></td><td class="small"><?= e(fmt_date($m['last_sale_at'] ?? null)) ?></td><td><?= !empty($m['sha256']) ? '<span class="badge badge-success">verified</span>' : '<span class="badge">unknown</span>' ?></td>
    <td class="actions"><a class="btn btn-sm" href="<?= e(url('/backup/download', ['f' => $b['file']])) ?>"><?= icon('download') ?></a>
      <button class="btn btn-sm btn-danger" type="button" data-modal-open="mRestore" data-file="<?= e($b['file']) ?>">Restore…</button></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
</div>
<div class="stack">
  <div class="card card-pad"><h3>Live database</h3><p class="small mono" style="word-break:break-all"><?= e($dbPath) ?></p><p class="small"><?= number_format($dbSize / 1048576, 2) ?> MB · WAL mode · stored outside the public web folder.</p>
    <p class="hint">Backups use SQLite's online snapshot (VACUUM INTO) and are integrity-checked. Scheduled backups: see docs/DEPLOY_WINDOWS.md (Task Scheduler + scripts/backup.php).</p></div>
  <form class="card card-pad" method="post" action="<?= e(url('/backup/upload')) ?>" enctype="multipart/form-data"><?= csrf_field() ?><h3>Upload a backup file</h3>
    <div class="field"><input class="input" type="file" name="backup" accept=".sqlite" required></div><button class="btn"><?= icon('upload') ?> Upload &amp; validate</button><p class="hint">Uploading does not restore. It is checked first.</p></form>
</div>
</div>
<div class="modal-backdrop" id="mRestore"><form class="modal modal-sm" method="post" action="<?= e(url('/backup/restore')) ?>" autocomplete="off"><?= csrf_field() ?>
  <div class="modal-head"><?= icon('alert') ?><h2>Restore backup</h2></div>
  <div class="modal-body"><p class="small">This replaces <strong>all current data</strong> with the backup. A safety backup of the current database is taken first. Bills made after the backup was taken will not be in the restored data (they stay in the safety backup). Everyone will be signed out.</p>
    <div class="field"><label>Backup file</label><input class="input mono" name="file" id="restoreFile" readonly></div>
    <div class="field"><label>Type RESTORE to confirm</label><input class="input" name="confirm" required></div>
    <div class="field"><label>Your password</label><input class="input" type="password" name="password" required></div></div>
  <div class="modal-foot"><button class="btn" type="button" data-modal-close>Cancel</button><button class="btn btn-danger">Restore</button></div>
</form></div>
