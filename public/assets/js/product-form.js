/* Product form: variant matrix generator, row add/remove, Ctrl+S save. */
(function () {
  const body = document.getElementById('variantRows');
  const tpl = document.getElementById('variantTpl');
  if (!body || !tpl) return;
  let n = 1000;
  function addRow(vals = {}) {
    const tmp = document.createElement('tbody');
    tmp.innerHTML = tpl.innerHTML.replace(/__i__/g, 'n' + n++).trim();
    const tr = tmp.firstElementChild;
    Object.entries(vals).forEach(([k, v]) => { const el = tr.querySelector(`[name$="[${k}]"]`); if (el) el.value = v; });
    body.appendChild(tr);
    return tr;
  }
  function isBlank(tr) { return [...tr.querySelectorAll('input:not([type=hidden]), select')].every((el) => !el.value || el.value === 'active'); }
  document.getElementById('addRow').addEventListener('click', () => { const tr = addRow(); tr.querySelector('select').focus(); });
  body.addEventListener('click', (e) => { const b = e.target.closest('[data-remove-variant]'); if (b) b.closest('tr').remove(); });
  document.getElementById('genRows').addEventListener('click', () => {
    const sizes = [...document.querySelectorAll('[data-gen-size]:checked')].map((x) => x.value);
    const colors = [...document.querySelectorAll('[data-gen-color]:checked')].map((x) => x.value);
    if (!sizes.length && !colors.length) { window.TPOS.toast('Tick at least one size or colour', 'error'); return; }
    const existing = new Set([...body.querySelectorAll('tr')].map((tr) => (tr.querySelector('[name$="[size_id]"]').value) + '|' + (tr.querySelector('[name$="[color_id]"]').value)));
    [...body.querySelectorAll('tr')].forEach((tr) => { if (isBlank(tr)) tr.remove(); });
    const mrp = document.getElementById('genMrp').value, price = document.getElementById('genPrice').value, costEl = document.getElementById('genCost');
    let added = 0;
    (sizes.length ? sizes : ['']).forEach((s) => (colors.length ? colors : ['']).forEach((c) => {
      if (existing.has(s + '|' + c)) return;
      addRow({ size_id: s, color_id: c, mrp, price, cost: costEl ? costEl.value : '' }); added++;
    }));
    window.TPOS.toast(added + ' rows added', 'success');
  });
  const img = document.getElementById('image'), prev = document.getElementById('imgPreview');
  if (img && prev) img.addEventListener('change', () => { if (img.files[0]) prev.src = URL.createObjectURL(img.files[0]); });
  document.addEventListener('keydown', (e) => { if (e.ctrlKey && (e.key === 's' || e.key === 'S')) { e.preventDefault(); document.getElementById('productForm').requestSubmit(); } });
})();
