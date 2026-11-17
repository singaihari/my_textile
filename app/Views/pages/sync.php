<?php App\Core\View::script('js/sync.js'); $pending = $counts['pending'] + $counts['failed']; ?>
<script type="application/json" id="sync-config"><?= json_encode(['pending' => $pending, 'configured' => $endpoint && $tokenMasked], JSON_HEX_TAG) ?></script>
<div class="alert alert-info"><?= icon('info') ?><div><strong>Manual sync only.</strong> Nothing is uploaded or downloaded automatically — not on startup, reconnect, login or a timer. Bills always save on this PC first; click <strong>Sync Now</strong> when you want to send them to the online server. Re-running is always safe.</div></div>
<div class="grid g4">
  <div class="card stat"><div class="stat-label"><span class="status-dot warn"></span> Pending</div><div class="stat-value"><?= (int)$counts['pending'] ?></div><div class="stat-foot">not yet uploaded</div></div>
  <div class="card stat"><div class="stat-label"><span class="status-dot bad"></span> Failed</div><div class="stat-value"><?= (int)$counts['failed'] ?></div><div class="stat-foot">will be retried on next Sync Now</div></div>
  <div class="card stat"><div class="stat-label"><span class="status-dot ok"></span> Synced</div><div class="stat-value"><?= (int)$counts['acked'] ?></div><div class="stat-foot">last success <?= e($lastSuccess ? fmt_date($lastSuccess) : 'never') ?></div></div>
  <div class="card stat"><div class="stat-label"><span class="status-dot bad"></span> Conflicts</div><div class="stat-value"><?= (int)$counts['open_conflicts'] ?></div><div class="stat-foot">need review below</div></div>
</div>
<div class="card card-pad" style="margin-top:16px">
  <div class="row-between">
    <div><h2 style="margin:0">Sync Now</h2><p class="muted" style="margin:0"><?= $endpoint ? 'Server: <span class="mono">' . e($endpoint) . '</span>' : 'Not configured yet — set the server URL and device token below.' ?></p></div>
    <div class="row"><button class="btn" type="button" id="testBtn" <?= $endpoint ? '' : 'disabled' ?>>Test connection (sends no data)</button>
      <button class="btn btn-primary btn-lg" type="button" id="syncBtn" <?= $endpoint ? '' : 'disabled' ?>><?= icon('sync') ?> Sync Now (<?= $pending ?>)</button></div>
  </div>
  <div id="syncResult" style="margin-top:12px" aria-live="polite"></div>
</div>

<?php if ($conflicts): ?>
<div class="card"><div class="card-head"><h3>Open conflicts</h3><span class="muted small">Financial records are never overwritten; review and resolve each item.</span></div>
  <div class="table-wrap"><table class="table"><thead><tr><th>When</th><th>Kind</th><th>Entity</th><th>Local</th><th>Server / detail</th><th></th></tr></thead><tbody>
  <?php foreach ($conflicts as $c): ?><tr><td class="nowrap"><?= e(fmt_date($c['created_at'])) ?></td><td><span class="badge badge-danger"><?= e(str_replace('_', ' ', $c['kind'])) ?></span></td><td class="mono small"><?= e($c['entity_type']) ?> <?= e(substr($c['entity_id'], 0, 8)) ?>…</td>
    <td class="small mono" style="max-width:220px;word-break:break-all"><?= e($c['local_value']) ?></td><td class="small mono" style="max-width:320px;word-break:break-all"><?= e($c['server_value']) ?></td>
    <td class="actions"><?php if (can('sync.manage')): ?><form method="post" action="<?= e(url('/sync/conflicts/' . $c['id'] . '/resolve')) ?>" class="row"><?= csrf_field() ?>
      <input class="input input-sm" name="note" placeholder="note" style="width:110px">
      <?php if ($c['kind'] === 'price_changed'): ?><button class="btn btn-sm" name="action" value="apply_server">Use server price</button><button class="btn btn-sm" name="action" value="keep_local">Keep local (send again)</button><?php endif; ?>
      <button class="btn btn-sm" name="action" value="resolved">Mark resolved</button><button class="btn btn-sm btn-ghost" name="action" value="ignore">Ignore</button></form><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div></div>
<?php endif; ?>

<div class="grid g-main">
  <div class="card">
    <div class="card-head"><h3>Outbox events</h3>
      <nav class="filters"><?php foreach (['' => 'All', 'pending' => 'Pending', 'failed' => 'Failed', 'conflict' => 'Conflict', 'acked' => 'Synced'] as $k => $l): ?><a class="btn btn-sm <?= (string)$status === $k ? 'btn-primary' : '' ?>" href="<?= e(url('/sync', $k ? ['status' => $k] : [])) ?>"><?= e($l) ?></a><?php endforeach; ?></nav></div>
    <?php if (!$page['rows']): ?><div class="empty small">No events.</div><?php else: ?>
    <div class="table-wrap"><table class="table table-compact"><thead><tr><th>#</th><th>Event</th><th>Status</th><th class="num">Tries</th><th>Created</th><th>Message</th></tr></thead><tbody>
    <?php foreach ($page['rows'] as $ev): ?><tr><td class="mono"><?= (int)$ev['seq'] ?></td><td><?= e($ev['entity_type']) ?>.<?= e($ev['op']) ?><div class="cell-sub mono"><?= e(substr($ev['id'], 0, 13)) ?></div></td>
      <td><?= ['pending' => '<span class="badge badge-warning">Pending</span>', 'failed' => '<span class="badge badge-danger">Failed</span>', 'acked' => '<span class="badge badge-success">Synced</span>', 'conflict' => '<span class="badge badge-danger">Conflict</span>'][$ev['status']] ?? e($ev['status']) ?>
        <?= $ev['server_status'] === 'duplicate' ? '<span class="cell-sub">already on server</span>' : '' ?></td>
      <td class="num"><?= (int)$ev['attempts'] ?></td><td class="nowrap small"><?= e(fmt_date($ev['created_at'])) ?></td><td class="small" style="max-width:280px"><?= e($ev['last_error']) ?></td></tr><?php endforeach; ?></tbody></table></div>
    <?= App\Core\View::partial('partials/pager', ['page' => $page]) ?><?php endif; ?>
  </div>
  <div class="stack">
    <div class="card"><div class="card-head"><h3>Run history</h3></div>
      <?php if (!$runs): ?><div class="empty small">Never synced.</div><?php else: ?><ul class="list-plain" style="padding:0 16px"><?php foreach ($runs as $r): ?>
        <li style="align-items:flex-start"><span class="status-dot <?= $r['status'] === 'success' ? 'ok' : ($r['status'] === 'partial' || $r['status'] === 'running' ? 'warn' : 'bad') ?>" style="margin-top:7px"></span>
          <div><div class="small"><strong><?= e(ucfirst($r['status'])) ?></strong> · <?= e(fmt_date($r['started_at'])) ?> · <?= e($r['full_name']) ?></div><div class="cell-sub"><?= e($r['message']) ?></div></div></li><?php endforeach; ?></ul><?php endif; ?></div>
    <?php if (can('sync.manage')): ?>
    <form class="card card-pad" method="post" action="<?= e(url('/sync/config')) ?>" autocomplete="off"><?= csrf_field() ?>
      <h3>Server settings</h3>
      <div class="field"><label>Server URL</label><input class="input mono" name="endpoint" value="<?= e($endpoint) ?>" placeholder="https://sync.example.com"></div>
      <div class="field"><label>Device token <?= $tokenMasked ? '(saved: <span class="mono">' . e($tokenMasked) . '</span>)' : '' ?></label><input class="input mono" name="token" type="password" placeholder="<?= $tokenMasked ? 'leave blank to keep' : 'tpd_…' ?>"><div class="hint">Created on the server with <span class="mono">server-cli.php register-device</span>. Stored only on this PC.</div></div>
      <div class="field"><label>Batch size</label><input class="input num" name="batch_size" value="<?= e(setting('sync_batch_size')) ?>"></div>
      <dl class="kv small"><dt>Store ID</dt><dd class="mono"><?= e($store['id']) ?></dd><dt>Device ID</dt><dd class="mono"><?= e($device['id']) ?></dd><dt>Device code</dt><dd class="mono"><?= e($device['code']) ?></dd></dl>
      <div class="form-actions"><button class="btn btn-primary">Save settings</button></div>
    </form><?php endif; ?>
  </div>
</div>
<div class="modal-backdrop" id="mSyncConfirm"><div class="modal modal-sm">
  <div class="modal-head"><?= icon('sync') ?><h2>Sync now?</h2></div>
  <div class="modal-body"><p><strong id="syncPendingN"><?= $pending ?></strong> pending events will be uploaded to <span class="mono small"><?= e($endpoint) ?></span>, then server-approved price/tax updates will be downloaded.</p><p class="small muted">Billing can continue during sync. If the connection drops, nothing is lost — just run it again.</p></div>
  <div class="modal-foot"><button class="btn" type="button" data-modal-close>Cancel</button><button class="btn btn-primary" type="button" id="syncGo">Yes, sync now</button></div>
</div></div>
