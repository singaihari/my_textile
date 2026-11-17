/* Invoice detail: reprint (logged) via hidden iframe. Ctrl+P is captured here so the logged reprint is used. */
(function () {
  const T = window.TPOS;
  const frame = document.getElementById('printFrame');
  function reprint(btn) {
    const id = btn.dataset.reprint, format = btn.dataset.format;
    frame.onload = () => {
      try { frame.contentWindow.focus(); frame.contentWindow.print(); T.api('POST', '/api/sales/' + id + '/printed', { kind: 'reprint', format }).then(() => T.toast('Reprint logged (same bill, no new sale)', 'success')); }
      catch (e) { T.toast('Print failed — try again', 'error'); }
    };
    frame.src = T.url('/sales/' + id + '/receipt?format=' + format + '&kind=reprint');
  }
  document.querySelectorAll('[data-reprint]').forEach((b) => b.addEventListener('click', () => reprint(b)));
  document.addEventListener('keydown', (e) => {
    if (e.ctrlKey && (e.key === 'p' || e.key === 'P')) { const b = document.querySelector('[data-reprint]'); if (b) { e.preventDefault(); reprint(b); } }
  });
})();
