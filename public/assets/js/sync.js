/* Sync Center: the ONLY place that triggers upload/download, and only after a click + confirmation. */
(function () {
  const T = window.TPOS;
  const out = document.getElementById('syncResult');
  const btn = document.getElementById('syncBtn');
  const show = (cls, html) => { out.innerHTML = `<div class="alert alert-${cls}">${html}</div>`; };
  if (btn) btn.addEventListener('click', () => T.openModal('mSyncConfirm', btn));
  const go = document.getElementById('syncGo');
  if (go) go.addEventListener('click', async () => {
    T.closeModal('mSyncConfirm');
    btn.disabled = true; go.disabled = true;
    show('info', 'Syncing… please keep this page open. Billing on the POS can continue.');
    try {
      const r = (await T.api('POST', '/api/sync/run', { confirm: true })).result;
      const cls = r.status === 'success' ? 'success' : (r.status === 'partial' ? 'warning' : 'error');
      show(cls, `<div><strong>${T.esc(r.status.toUpperCase())}</strong> — ${T.esc(r.message)}</div>`);
      setTimeout(() => location.reload(), 2500);
    } catch (e) { show('error', T.esc(e.message) + ' — no data was lost; try again later.'); btn.disabled = false; }
    go.disabled = false;
  });
  const test = document.getElementById('testBtn');
  if (test) test.addEventListener('click', async () => {
    test.disabled = true; show('info', 'Checking connection…');
    try { const r = await T.api('POST', '/api/sync/test', {}); show('success', T.esc(r.message)); }
    catch (e) { show('error', T.esc(e.message)); }
    test.disabled = false;
  });
})();
