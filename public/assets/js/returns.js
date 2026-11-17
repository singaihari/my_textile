/* Return / exchange screen. The server computes the authoritative values; this shows a preview. */
(function () {
  const T = window.TPOS;
  const C = JSON.parse(document.getElementById('ret-config').textContent);
  const byId = Object.fromEntries(C.items.map((i) => [i.id, i]));
  const reps = [];
  let repTotal = 0, token = null, busy = false;
  const $ = (id) => document.getElementById(id);
  const clientRef = (crypto.randomUUID ? crypto.randomUUID() : 'r' + Date.now());

  function items() {
    const out = [];
    document.querySelectorAll('[data-ret-qty]').forEach((el) => {
      const v = el.value.trim(); if (!v) return;
      out.push({ sale_item_id: el.dataset.retQty, qty: v, condition: document.querySelector(`[data-ret-cond="${el.dataset.retQty}"]`).value });
    });
    return out;
  }
  function credit() {
    let c = 0, bad = false;
    items().forEach((r) => {
      const i = byId[r.sale_item_id]; const q = T.parseQty(r.qty, i.unit);
      if (isNaN(q) || q <= 0 || q > i.returnable) bad = true; else c += Math.round(i.line_total * q / i.qty);
    });
    return bad ? NaN : c;
  }
  async function refreshReplacement() {
    if (!reps.length) { repTotal = 0; summary(); return; }
    try {
      const r = await T.api('POST', '/api/pos/quote', { lines: reps.map((x) => ({ variant_id: x.id, qty: x.qty })) });
      repTotal = r.quote.total_paise;
    } catch (e) { T.toast(e.message, 'error'); }
    summary();
  }
  function summary() {
    const c = credit();
    const box = $('retSummary').querySelector('div');
    if (isNaN(c)) { box.textContent = 'A quantity is invalid or more than what can still be returned.'; return; }
    const diff = repTotal - c;
    $('extraPay').style.display = diff > 0 ? '' : 'none';
    if (diff > 0 && !$('payAmount').dataset.touched) $('payAmount').value = (diff / 100).toFixed(2);
    box.innerHTML = `Return credit ≈ <strong>${T.money(c)}</strong>` + (reps.length ? ` · Replacement <strong>${T.money(repTotal)}</strong> · ` +
      (diff > 0 ? `Customer pays <strong>${T.money(diff)}</strong>` : diff < 0 ? `Refund to customer <strong>${T.money(-diff)}</strong>` : '<strong>No balance</strong>') : ` · Refund ≈ <strong>${T.money(c)}</strong> (rounded as per settings)`);
  }
  function renderReps() {
    const body = $('repBody');
    body.innerHTML = reps.length ? reps.map((r, i) => `<tr><td><div class="cell-title">${T.esc(r.name)}</div><div class="cell-sub mono">${T.esc(r.sku)} ${T.esc(r.label || '')}</div></td>
      <td style="width:120px"><input class="input input-sm num" data-rq="${i}" value="${T.esc(r.qty)}" aria-label="Quantity"></td><td class="num">${T.esc(r.price)}</td>
      <td class="actions"><button class="btn btn-sm btn-ghost" type="button" data-rr="${i}">Remove</button></td></tr>`).join('') : '<tr data-empty><td class="muted">No replacement — this will be a refund.</td></tr>';
  }
  T.picker($('repPick'), (it) => { reps.push({ id: it.id, name: it.name, sku: it.sku, label: it.label, price: it.price, qty: it.unit === 'm' ? '1' : '1' }); renderReps(); refreshReplacement(); });
  $('repBody').addEventListener('input', (e) => { const i = e.target.dataset.rq; if (i !== undefined) { reps[+i].qty = e.target.value; clearTimeout(window._rq); window._rq = setTimeout(refreshReplacement, 250); } });
  $('repBody').addEventListener('click', (e) => { const i = e.target.dataset.rr; if (i !== undefined) { reps.splice(+i, 1); renderReps(); refreshReplacement(); } });
  document.addEventListener('input', (e) => { if (e.target.matches('[data-ret-qty]')) summary(); if (e.target.id === 'payAmount') e.target.dataset.touched = '1'; });
  document.addEventListener('change', (e) => { if (e.target.matches('[data-ret-cond]')) summary(); });

  let apResolve = null;
  function approve(perm, why) {
    return new Promise((res) => { apResolve = res; $('approveWhy').textContent = why; $('approveForm').dataset.perm = perm; $('apUser').value = ''; $('apPass').value = ''; $('approveError').style.display = 'none'; T.openModal('mApprove'); });
  }
  $('approveForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    try { const r = await T.api('POST', '/api/approvals', { username: $('apUser').value, password: $('apPass').value, permission: $('approveForm').dataset.perm }); const f = apResolve; apResolve = null; T.closeModal('mApprove'); f(r.token); }
    catch (err) { $('approveError').textContent = err.message; $('approveError').style.display = ''; }
  });
  $('mApprove').addEventListener('modal:closed', () => { if (apResolve) { const f = apResolve; apResolve = null; f(null); } });

  $('retForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    if (busy) return;
    if (!items().length) { T.toast('Enter a return quantity for at least one item', 'error'); return; }
    if (!$('reason').value.trim()) { T.toast('Enter a reason', 'error'); $('reason').focus(); return; }
    const diff = repTotal - credit();
    const body = { sale_id: C.sale_id, items: items(), reason: $('reason').value, refund_method: $('refundMethod').value, refund_reference: $('refundRef').value,
      replacements: reps.map((r) => ({ variant_id: r.id, qty: r.qty })), approval_token: token, client_ref: clientRef,
      payments: diff > 0 ? [{ method: $('payMethod').value, amount_paise: T.parseMoney($('payAmount').value), reference: $('payRef').value }] : [] };
    busy = true; $('retSubmit').disabled = true;
    try {
      const r = await T.api('POST', '/api/returns', body);
      T.toast('Saved credit note ' + r.return_no, 'success');
      if (r.exchange) { window.open(r.receipt_url, '_blank'); }
      location = r.url;
    } catch (err) {
      busy = false; $('retSubmit').disabled = false;
      const ap = err.data && err.data.errors && err.data.errors.approval;
      if (ap) { token = await approve(ap, err.message); if (token) $('retForm').requestSubmit(); return; }
      T.toast(err.message, 'error', 7000);
    }
  });
})();
