/* Textile POS — shared UI helpers (vanilla JS, no dependencies, no network calls except to this local server). */
(function () {
  'use strict';
  const meta = (n) => (document.querySelector(`meta[name="${n}"]`) || {}).content || '';
  const T = window.TPOS = window.TPOS || {};
  T.base = meta('base-url');
  T.csrf = meta('csrf-token');
  T.url = (p) => T.base + p;

  /** JSON fetch to the LOCAL server with CSRF; rejects with {status, message, data}. */
  T.api = async function (method, path, body) {
    const opts = { method, headers: { 'Accept': 'application/json', 'X-CSRF-Token': T.csrf }, credentials: 'same-origin' };
    if (body !== undefined) { opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body); }
    let res;
    try { res = await fetch(T.url(path), opts); }
    catch (e) { throw { status: 0, message: 'The local POS server is not responding. Is the PHP server running on this PC?' }; }
    let data = {};
    try { data = await res.json(); } catch (e) { data = { error: 'Unexpected response (HTTP ' + res.status + ')' }; }
    if (res.status === 423) { T.onLocked && T.onLocked(); throw { status: 423, message: data.error || 'Screen locked', data }; }
    if (res.status === 401) { window.location = T.url('/login?next=' + encodeURIComponent(location.pathname)); throw { status: 401, message: 'Signed out' }; }
    if (!res.ok || data.ok === false) throw { status: res.status, message: data.error || ('Error ' + res.status), data };
    return data;
  };

  T.esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  /** paise → "₹1,23,456.78" (Indian grouping), integer math only */
  T.money = function (p, sym = true) {
    p = Math.trunc(Number(p) || 0);
    const neg = p < 0; p = Math.abs(p);
    let r = String(Math.floor(p / 100)); const ps = String(p % 100).padStart(2, '0');
    if (r.length > 3) { const last = r.slice(-3); let rest = r.slice(0, -3); rest = rest.replace(/\B(?=(\d{2})+(?!\d))/g, ','); r = rest + ',' + last; }
    return (neg ? '-' : '') + (sym ? '₹' : '') + r + '.' + ps;
  };
  /** "123.45" → 12345 paise, string parsing (no float arithmetic) */
  T.parseMoney = function (s) {
    s = String(s == null ? '' : s).replace(/[₹,\s]/g, '');
    if (s === '') return 0;
    const m = /^(-)?(\d+)(?:\.(\d{0,2}))?$/.exec(s);
    if (!m) return NaN;
    const v = parseInt(m[2], 10) * 100 + parseInt((m[3] || '').padEnd(2, '0') || '0', 10);
    return m[1] ? -v : v;
  };
  T.parseQty = function (s, unit) {
    s = String(s == null ? '' : s).trim();
    const m = /^(\d+)(?:\.(\d{0,3}))?$/.exec(s);
    if (!m) return NaN;
    const v = parseInt(m[1], 10) * 1000 + parseInt((m[2] || '').padEnd(3, '0') || '0', 10);
    if (unit !== 'm' && v % 1000 !== 0) return NaN;
    return v;
  };
  T.qty = function (milli, unit) {
    milli = Number(milli) || 0;
    const neg = milli < 0; milli = Math.abs(milli);
    let s;
    if (unit === 'm') { s = Math.floor(milli / 1000) + '.' + String(milli % 1000).padStart(3, '0'); s = s.replace(/0+$/, '').replace(/\.$/, '.0'); }
    else { s = String(Math.floor(milli / 1000)); if (milli % 1000) s += '.' + String(milli % 1000).padStart(3, '0').replace(/0+$/, ''); }
    return (neg ? '-' : '') + s;
  };

  T.toast = function (msg, type = 'info', ms = 3800) {
    const box = document.getElementById('toasts'); if (!box) { if (type === 'error') alert(msg); return; }
    const el = document.createElement('div'); el.className = 'toast ' + type; el.setAttribute('role', type === 'error' ? 'alert' : 'status');
    el.textContent = msg; box.appendChild(el);
    setTimeout(() => el.remove(), ms);
  };

  // ---------------------------------------------------------------- modals (Esc closes; focus returns to opener)
  T.openModal = function (id, opener) {
    const m = document.getElementById(id); if (!m) return;
    m.classList.add('open'); m._opener = opener || document.activeElement;
    const f = m.querySelector('[autofocus], input:not([type=hidden]):not([disabled]), select, textarea, button.btn-primary, button');
    setTimeout(() => f && f.focus(), 20);
  };
  T.closeModal = function (id) {
    const m = typeof id === 'string' ? document.getElementById(id) : id; if (!m) return;
    m.classList.remove('open');
    if (m._opener && document.body.contains(m._opener)) m._opener.focus();
    m.dispatchEvent(new CustomEvent('modal:closed'));
  };
  T.openModalEl = () => document.querySelector('.modal-backdrop.open');
  document.addEventListener('keydown', (e) => {
    const m = T.openModalEl();
    if (!m) return;
    if (e.key === 'Escape' && !m.hasAttribute('data-no-esc')) { e.preventDefault(); T.closeModal(m); }
    if (e.key === 'Tab') {   // keep Tab inside the dialog (Esc always exits — not a trap)
      const els = [...m.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]):not([type=hidden]), select:not([disabled]), textarea, [tabindex]:not([tabindex="-1"])')].filter((x) => x.offsetParent !== null);
      if (!els.length) return;
      const first = els[0], last = els[els.length - 1];
      if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    }
  });
  document.addEventListener('click', (e) => {
    const o = e.target.closest('[data-modal-open]');
    if (o) {
      e.preventDefault();
      const m = document.getElementById(o.dataset.modalOpen);
      if (m && o.dataset.file) { const f = m.querySelector('[name=file]'); if (f) f.value = o.dataset.file; }
      T.openModal(o.dataset.modalOpen, o);
    }
    const c = e.target.closest('[data-modal-close]'); if (c) { e.preventDefault(); T.closeModal(c.closest('.modal-backdrop')); }
    if (e.target.classList && e.target.classList.contains('modal-backdrop') && !e.target.hasAttribute('data-no-esc')) T.closeModal(e.target);
    const b = e.target.closest('[data-back]'); if (b) { e.preventDefault(); history.back(); }
    if (e.target.closest('[data-print-page]')) { e.preventDefault(); window.print(); }
    const s = e.target.closest('[data-toggle-sidebar]'); if (s) document.getElementById('sidebar').classList.toggle('open');
    const tr = e.target.closest('tr[data-href]'); if (tr && !e.target.closest('a,button,input,select,form')) location = tr.dataset.href;
  });
  document.addEventListener('keydown', (e) => {
    const tr = e.target.closest && e.target.closest('tr[data-href]');
    if (tr && e.key === 'Enter' && e.target === tr) location = tr.dataset.href;
  });

  // confirmations for destructive / high-value form actions
  document.addEventListener('submit', (e) => {
    const f = e.target;
    const msg = f.getAttribute('data-confirm') || (e.submitter && e.submitter.getAttribute('data-confirm'));
    if (msg && !confirm(msg)) { e.preventDefault(); return; }
    if (f.hasAttribute('data-once')) {   // prevent accidental double submit
      if (f._sent) { e.preventDefault(); return; }
      f._sent = true; setTimeout(() => (f._sent = false), 8000);
    }
  });

  // network badge — informational only; NEVER triggers synchronisation
  function netBadge() {
    document.querySelectorAll('[data-net-badge]').forEach((b) => {
      b.className = 'badge ' + (navigator.onLine ? '' : 'badge-warning');
      b.textContent = navigator.onLine ? 'Internet: online' : 'Internet: offline — billing works';
    });
  }
  window.addEventListener('online', netBadge); window.addEventListener('offline', netBadge); netBadge();

  // global keys on management pages: F1 help, "/" focuses page search
  document.addEventListener('keydown', (e) => {
    if (document.body.dataset.pos) return;
    const typing = /^(INPUT|TEXTAREA|SELECT)$/.test((e.target.tagName || ''));
    if (e.key === 'F1') { e.preventDefault(); location = T.url('/help'); }
    if (e.key === 'F2' && document.querySelector('a[href$="/pos"]')) { e.preventDefault(); location = T.url('/pos'); }
    if (e.key === '/' && !typing) { const s = document.querySelector('[data-page-search]'); if (s) { e.preventDefault(); s.focus(); s.select(); } }
  });

  // (automatic inactivity lock removed at the shop's request)

  // ---------------------------------------------------------------- item picker (search variants, arrow keys + Enter)
  T.picker = function (input, onSelect, opts = {}) {
    const wrap = input.closest('.picker') || input.parentElement;
    let box = wrap.querySelector('.picker-results');
    if (!box) { box = document.createElement('div'); box.className = 'picker-results'; box.setAttribute('role', 'listbox'); wrap.appendChild(box); }
    input.setAttribute('role', 'combobox'); input.setAttribute('aria-autocomplete', 'list'); input.setAttribute('aria-expanded', 'false');
    let items = [], idx = -1, timer = null, seq = 0;
    const render = () => {
      box.innerHTML = items.map((it, i) => `<div class="picker-item" role="option" data-i="${i}" aria-selected="${i === idx}">
        <img class="thumb" src="${T.esc(it.image_url || '')}" alt="" loading="lazy">
        <div style="flex:1"><div class="cell-title">${T.esc(it.name)} <span class="muted">${T.esc(it.label || '')}</span></div>
        <div class="cell-sub mono">${T.esc(it.sku)} · ${T.esc(it.barcode)}</div></div>
        <div class="num"><div>${T.esc(it.price)}</div><div class="cell-sub">Stock ${T.esc(it.stock)}</div></div></div>`).join('')
        || '<div class="picker-item muted">No matching items</div>';
      box.classList.add('open'); input.setAttribute('aria-expanded', 'true');
      const sel = box.querySelector('[aria-selected="true"]'); if (sel) sel.scrollIntoView({ block: 'nearest' });
    };
    const close = () => { box.classList.remove('open'); input.setAttribute('aria-expanded', 'false'); idx = -1; };
    const choose = (it) => { close(); onSelect(it); input.value = ''; };
    const search = async (q) => {
      const my = ++seq;
      try {
        const r = await T.api('GET', '/api/pos/search?q=' + encodeURIComponent(q));
        if (my !== seq) return; items = r.items; idx = items.length ? 0 : -1; render();
      } catch (e) { T.toast(e.message, 'error'); }
    };
    input.addEventListener('input', () => { clearTimeout(timer); const q = input.value.trim(); if (q.length < 2) { close(); return; } timer = setTimeout(() => search(q), 160); });
    input.addEventListener('keydown', async (e) => {
      if (e.key === 'ArrowDown' && items.length) { e.preventDefault(); idx = Math.min(items.length - 1, idx + 1); render(); }
      else if (e.key === 'ArrowUp' && items.length) { e.preventDefault(); idx = Math.max(0, idx - 1); render(); }
      else if (e.key === 'Escape' && box.classList.contains('open')) { e.preventDefault(); e.stopPropagation(); close(); }
      else if (e.key === 'Enter') {
        e.preventDefault();
        const q = input.value.trim(); if (!q) return;
        if (box.classList.contains('open') && items[idx]) { choose(items[idx]); return; }
        try {   // scanned barcode → exact lookup
          const r = await T.api('GET', '/api/pos/lookup?code=' + encodeURIComponent(q));
          if (r.items.length === 1) choose(r.items[0]); else { items = r.items; idx = 0; if (!items.length) await search(q); else render(); }
        } catch (err) { T.toast(err.message, 'error'); }
      }
    });
    box.addEventListener('mousedown', (e) => { const el = e.target.closest('[data-i]'); if (el) { e.preventDefault(); choose(items[+el.dataset.i]); } });
    input.addEventListener('blur', () => setTimeout(close, 150));
    return { close };
  };

  // ---------------------------------------------------------------- line-item tables (purchase, adjustment, supplier return)
  document.querySelectorAll('[data-item-table]').forEach((table) => {
    const tpl = table.querySelector('template');
    const body = table.querySelector('tbody');
    const pick = document.querySelector(table.dataset.pickerInput);
    let n = body.querySelectorAll('tr').length + 1000;
    const recalc = () => {
      if (table.dataset.itemTable !== 'purchase') return;
      let sub = 0, tax = 0;
      body.querySelectorAll('tr').forEach((tr) => {
        const q = T.parseQty(tr.querySelector('[data-f=qty]').value, tr.dataset.unit);
        const c = T.parseMoney(tr.querySelector('[data-f=unit_cost]').value);
        const t = T.parseMoney(tr.querySelector('[data-f=tax_pct]').value || '0');   // "5" → 500 basis points (same integer parser)
        const line = isNaN(q) || isNaN(c) ? 0 : Math.round(c * q / 1000);         // integers only; preview — server recomputes
        tr.querySelector('[data-out=line]').textContent = T.money(line);
        sub += line; tax += Math.round(line * (isNaN(t) ? 0 : t) / 10000);
      });
      const fr = T.parseMoney((document.querySelector('[name=freight]') || {}).value || '0') || 0;
      const out = document.querySelector('[data-purchase-totals]');
      if (out) out.innerHTML = `Goods ${T.money(sub)} · Input tax ${T.money(tax)} · Freight ${T.money(fr)} · <strong>Total ${T.money(sub + tax + fr)}</strong> <span class="muted">(preview; server recalculates)</span>`;
    };
    const add = (it) => {
      const existing = body.querySelector(`tr[data-variant="${it.id}"]`);
      if (existing) { const q = existing.querySelector('[data-f=qty]'); q.focus(); q.select(); return; }
      const html = tpl.innerHTML.replace(/__i__/g, String(n++));
      const tmp = document.createElement('tbody'); tmp.innerHTML = html.trim();
      const tr = tmp.firstElementChild; tr.dataset.variant = it.id; tr.dataset.unit = it.unit;
      tr.querySelectorAll('[data-fill]').forEach((el) => {
        const k = el.dataset.fill; const v = k === 'cost' ? (it.cost_paise != null ? (it.cost_paise / 100).toFixed(2) : '') : (it[k] ?? '');
        if ('value' in el && el.tagName !== 'TD' && el.tagName !== 'SPAN' && el.tagName !== 'DIV') el.value = v; else el.textContent = v;
      });
      body.appendChild(tr);
      const empty = table.querySelector('[data-empty]'); if (empty) empty.remove();
      const q = tr.querySelector('[data-f=qty]'); if (q) { q.focus(); q.select(); }
      recalc();
    };
    if (pick) T.picker(pick, add);
    table.addEventListener('click', (e) => { const rm = e.target.closest('[data-remove-row]'); if (rm) { rm.closest('tr').remove(); recalc(); pick && pick.focus(); } });
    table.addEventListener('input', recalc);
    const fr = document.querySelector('[name=freight]'); if (fr) fr.addEventListener('input', recalc);
    table.addEventListener('keydown', (e) => {   // Enter in a quantity/cost field jumps back to the scanner box
      if (e.key === 'Enter' && e.target.matches('input') && pick) { e.preventDefault(); pick.focus(); }
    });
    recalc();
  });
})();
