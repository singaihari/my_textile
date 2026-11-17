/* Textile POS — New Sale (image-card billing). Keyboard-first. Talks ONLY to the local PHP server; never syncs. */
(function () {
  'use strict';
  const T = window.TPOS;
  const C = JSON.parse(document.getElementById('pos-config').textContent);
  const $ = (id) => document.getElementById(id);
  const can = (p) => C.perms.includes(p);
  const DRAFT_KEY = 'tpos.draft.' + C.device_code;
  const svg = (id) => `<svg class="icon" aria-hidden="true"><use href="${T.url('/assets/icons/sprite.svg')}#${id}"></use></svg>`;

  const newRef = () => (crypto.randomUUID ? crypto.randomUUID() : 'r' + Date.now() + Math.random().toString(16).slice(2));
  let state = blank();
  let quote = null;
  let selected = -1;
  let lastSale = null;
  let saving = false;
  let undo = null;

  function blank() {
    return { lines: [], bill_discount: null, customer: null, interstate: false, client_ref: newRef(), approval_token: null, allow_oversell: false };
  }
  function saveDraft() { try { localStorage.setItem(DRAFT_KEY, JSON.stringify(state)); } catch (e) { /* storage optional */ } }
  function loadDraft() {
    try { const d = JSON.parse(localStorage.getItem(DRAFT_KEY) || 'null'); if (d && Array.isArray(d.lines) && d.lines.length) { state = Object.assign(blank(), d); T.toast('Restored the unsaved bill from before the page reload', 'warning'); } } catch (e) { /* ignore */ }
  }

  // ---------------------------------------------------------------- feedback
  let audio;
  function beep(ok) {
    try {
      audio = audio || new (window.AudioContext || window.webkitAudioContext)();
      const o = audio.createOscillator(), g = audio.createGain();
      o.frequency.value = ok ? 1200 : 220; g.gain.value = 0.06; o.connect(g); g.connect(audio.destination);
      o.start(); o.stop(audio.currentTime + (ok ? 0.06 : 0.25));
    } catch (e) { /* no audio */ }
  }
  function flash(ok) { const s = $('scan'); s.classList.remove('flash-ok', 'flash-bad'); void s.offsetWidth; s.classList.add(ok ? 'flash-ok' : 'flash-bad'); setTimeout(() => s.classList.remove('flash-ok', 'flash-bad'), 500); }
  const priceText = (min, max) => (min === max ? T.money(min) : T.money(min) + ' – ' + T.money(max)).replace(/\.00/g, '');

  // ================================================================= PRODUCT GRID
  const grid = $('grid');
  const gridState = $('gridState');
  const moreBtn = $('moreBtn');
  let gItems = [], gQ = '', gCat = '', gOffset = 0, gMore = false, gSeq = 0, gFocus = 0;

  function skeleton() {
    grid.innerHTML = Array.from({ length: 8 }, () => '<div class="sk-card"><div class="skeleton" style="aspect-ratio:4/3"></div><div style="padding:12px"><div class="skeleton" style="width:80%;margin-bottom:8px"></div><div class="skeleton" style="width:45%"></div></div></div>').join('');
  }
  async function loadGrid(reset = true) {
    const my = ++gSeq;
    if (reset) { gOffset = 0; gItems = []; skeleton(); gridState.innerHTML = ''; }
    moreBtn.hidden = true;
    try {
      const r = await T.api('GET', '/api/pos/catalog?' + new URLSearchParams({ q: gQ, cat: gCat, offset: String(gOffset), limit: '48' }));
      if (my !== gSeq) return;
      gItems = reset ? r.items : gItems.concat(r.items);
      gMore = r.more; gOffset = gItems.length;
      renderGrid();
    } catch (e) {
      if (my !== gSeq) return;
      grid.innerHTML = '';
      gridState.innerHTML = `${svg('alert')}<h3>Could not load products</h3><p>${T.esc(e.message)}</p>`;
    }
  }
  function stockBadge(p) {
    if (p.stock_milli <= 0) return '<span class="stock stock-out">Out of stock</span>';
    const unit = p.unit === 'm' ? ' m' : '';
    if (p.low_variants > 0 || p.out_variants > 0 && p.out_variants >= p.variants / 2) return `<span class="stock stock-low">Low stock: ${T.esc(T.qty(p.stock_milli, p.unit))}${unit}</span>`;
    return `<span class="stock stock-in">In stock: ${T.esc(T.qty(p.stock_milli, p.unit))}${unit}</span>`;
  }
  function variantText(p) {
    const bits = [];
    if (p.colors > 1) bits.push(p.colors + ' colours');
    if (p.sizes > 1) bits.push(p.sizes + ' sizes');
    if (!bits.length && p.variants > 1) bits.push(p.variants + ' pieces');
    if (p.is_unique_piece && p.design_no) bits.push('Design ' + p.design_no);
    if (!bits.length) bits.push(p.unit === 'm' ? 'Sold per metre' : 'Single item');
    return bits.join(' · ');
  }
  function renderGrid() {
    $('gridCount').textContent = gItems.length ? `${gItems.length}${gMore ? '+' : ''} product${gItems.length === 1 ? '' : 's'}${gQ ? ` for “${gQ}”` : ''}` : '';
    if (!gItems.length) {
      grid.innerHTML = '';
      gridState.innerHTML = `${svg('search')}<h3>No products found</h3><p>${gQ ? 'Try a different name, SKU, barcode or design number.' : 'No products in this category yet.'}</p>`;
      return;
    }
    gridState.innerHTML = '';
    gFocus = Math.min(gFocus, gItems.length - 1);
    grid.innerHTML = gItems.map((p, i) => {
      const off = p.max_off_bp >= 100 ? `<span class="pcard-tag">${Math.floor(p.max_off_bp / 100)}% off</span>` : '';
      const mrp = p.max_mrp > p.max_price ? `<s>${T.money(p.max_mrp).replace('.00', '')}</s>` : '';
      return `<div class="pcard ${p.stock_milli <= 0 ? 'is-out' : ''}" role="option" tabindex="${i === gFocus ? 0 : -1}" data-i="${i}" aria-label="${T.esc(p.name)}, ${T.esc(priceText(p.min_price, p.max_price))}">
        <div class="pcard-img"><img src="${T.esc(p.image_url)}" alt="" loading="lazy">${off}${p.unit === 'm' ? '<span class="pcard-unit">per metre</span>' : p.unit === 'set' ? '<span class="pcard-unit">set</span>' : ''}
          <span class="pcard-hint">${p.variants > 1 || p.unit === 'm' ? 'Enter: choose ' + (p.unit === 'm' ? 'metres' : 'size / colour') : 'Enter: add to bill'}</span></div>
        <div class="pcard-body">
          <div class="pcard-top"><div class="pcard-name">${T.esc(p.name)}</div><div class="pcard-price">${T.esc(priceText(p.min_price, p.max_price))}${mrp}</div></div>
          <div class="pcard-code">${T.esc(p.code)}${p.brand ? ' · ' + T.esc(p.brand) : ''}</div>
          <div class="pcard-variants">${p.hexes.length > 1 ? `<span class="swatches">${p.hexes.map((h) => `<i style="background:${T.esc(h)}"></i>`).join('')}</span>` : ''}${T.esc(variantText(p))}</div>
          <div class="pcard-foot">${stockBadge(p)}<button class="addbtn" type="button" tabindex="-1" data-add aria-label="Add ${T.esc(p.name)}">${svg('plus')}</button></div>
        </div></div>`;
    }).join('');
    moreBtn.hidden = !gMore;
  }
  const cards = () => [...grid.querySelectorAll('.pcard')];
  function focusCard(i) {
    const cs = cards(); if (!cs.length) return;
    gFocus = Math.max(0, Math.min(cs.length - 1, i));
    cs.forEach((c, k) => (c.tabIndex = k === gFocus ? 0 : -1));
    cs[gFocus].focus();
    cs[gFocus].scrollIntoView({ block: 'nearest' });
    if (gMore && gFocus >= cs.length - 4) loadGrid(false);
  }
  function columns() {
    const cs = cards(); if (cs.length < 2) return 1;
    const top = cs[0].offsetTop; let n = 0;
    for (const c of cs) { if (c.offsetTop !== top) break; n++; }
    return Math.max(1, n);
  }
  grid.addEventListener('keydown', (e) => {
    const card = e.target.closest('.pcard'); if (!card) return;
    const i = +card.dataset.i, cols = columns();
    if (e.key === 'ArrowRight') { e.preventDefault(); focusCard(i + 1); }
    else if (e.key === 'ArrowLeft') { e.preventDefault(); focusCard(i - 1); }
    else if (e.key === 'ArrowDown') { e.preventDefault(); focusCard(i + cols); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); if (i - cols < 0) $('scan').focus(); else focusCard(i - cols); }
    else if (e.key === 'Home') { e.preventDefault(); focusCard(0); }
    else if (e.key === 'End') { e.preventDefault(); focusCard(gItems.length - 1); }
    else if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); chooseProduct(i, card); }
    else if (e.key === 'Escape') { e.preventDefault(); $('scan').focus(); }
    else if (e.key.length === 1 && !e.ctrlKey && !e.altKey && /[\w\-\/]/.test(e.key)) { $('scan').focus(); }   // typing → search box
  });
  grid.addEventListener('click', (e) => {
    const card = e.target.closest('.pcard'); if (!card) return;
    gFocus = +card.dataset.i;
    chooseProduct(+card.dataset.i, card);
  });
  moreBtn.addEventListener('click', () => loadGrid(false));
  if ('IntersectionObserver' in window) {
    new IntersectionObserver((en) => { if (en[0].isIntersecting && gMore && !moreBtn.hidden) loadGrid(false); }, { root: $('gridWrap') }).observe(moreBtn);
  }

  /** Card chosen: single piece → add; metre or multi-variant → quick-pick dialog. */
  async function chooseProduct(i, card) {
    const p = gItems[i]; if (!p) return;
    let data;
    try { data = await T.api('GET', '/api/pos/product/' + encodeURIComponent(p.id)); } catch (e) { T.toast(e.message, 'error'); return; }
    if (data.variants.length === 1 && data.product.unit !== 'm') {
      await addVariant(data.variants[0], null, { card });
      return;
    }
    openPick(data, card);
  }

  // ---------------------------------------------------------------- chips
  const chipsEl = $('chips');
  const chipList = () => [...chipsEl.querySelectorAll('.chip')];
  function selectChip(chip, focus) {
    chipList().forEach((c) => { const on = c === chip; c.classList.toggle('active', on); c.setAttribute('aria-selected', String(on)); c.tabIndex = on ? 0 : -1; });
    if (focus) chip.focus();
    chip.scrollIntoView({ block: 'nearest', inline: 'nearest' });
    gCat = chip.dataset.cat; gFocus = 0;
    if (gQ) { gQ = ''; $('scan').value = ''; }   // picking a category clears the text search
    loadGrid(true);
  }
  function stepChip(d, focus = false) {
    const cs = chipList(); const cur = cs.findIndex((c) => c.classList.contains('active'));
    selectChip(cs[(cur + d + cs.length) % cs.length], focus);
  }
  chipsEl.addEventListener('click', (e) => { const c = e.target.closest('.chip'); if (c) selectChip(c, false); });
  chipsEl.addEventListener('keydown', (e) => {
    if (e.key === 'ArrowRight') { e.preventDefault(); stepChip(1, true); }
    else if (e.key === 'ArrowLeft') { e.preventDefault(); stepChip(-1, true); }
    else if (e.key === 'ArrowDown') { e.preventDefault(); focusCard(gFocus); }
  });

  // ================================================================= VARIANT QUICK-PICK
  let pick = null;   // {product, variants, color, size, design, card}
  const keyOf = (v, k) => (k === 'color' ? (v.color_id || '') : k === 'size' ? (v.size_id || '') : v.id);
  function openPick(data, card) {
    const vs = data.variants;
    const first = vs.find((v) => +v.stock_milli > 0) || vs[0];
    pick = { product: data.product, variants: vs, color: keyOf(first, 'color'), size: keyOf(first, 'size'), design: first.id, card };
    $('pickImg').src = first.image_url || data.product.image_url;
    $('pickName').textContent = data.product.name;
    $('pickCode').textContent = data.product.code;
    const prices = vs.map((v) => +v.price_paise);
    $('pickPrice').textContent = priceText(Math.min(...prices), Math.max(...prices));
    const isM = data.product.unit === 'm';
    $('pickQtyLabel').textContent = isM ? 'Metres (e.g. 2.75)' : 'Quantity';
    $('pickQty').value = isM ? '' : '1';
    $('pickQty').placeholder = isM ? '0.00' : '1';
    renderPick();
    T.openModal('mPick', card || document.activeElement);
    setTimeout(() => {   // fabric: type metres straight away (↑ goes to colour); garments: start on colour/size
      const g = [...document.querySelectorAll('#mPick .pick-group:not([hidden]) .opt[aria-checked="true"]')][0];
      if (isM || !g) { $('pickQty').focus(); $('pickQty').select(); } else g.focus();
    }, 40);
  }
  function uniq(list, k) {
    const seen = new Map();
    list.forEach((v) => { const key = keyOf(v, k); if (!seen.has(key)) seen.set(key, v); });
    return [...seen.entries()];
  }
  function currentVariant() {
    if (!pick) return null;
    const m = pick.variants.filter((v) => keyOf(v, 'color') === pick.color && keyOf(v, 'size') === pick.size);
    return m.find((v) => v.id === pick.design) || m[0] || null;
  }
  function renderPick() {
    const vs = pick.variants;
    const colors = uniq(vs, 'color'), sizes = uniq(vs, 'size');
    const optBtn = (group, key, label, extra, checked, na, out) =>
      `<button type="button" class="opt ${na ? 'na' : ''} ${out ? 'out' : ''}" role="radio" aria-checked="${checked}" tabindex="${checked ? 0 : -1}" data-group="${group}" data-key="${T.esc(key)}">${extra}${T.esc(label)}</button>`;
    const cg = $('pickColors');
    cg.hidden = colors.length < 2 && !colors[0][1].color_name;
    cg.querySelector('.opts').innerHTML = colors.map(([key, v]) => {
      const any = vs.filter((x) => keyOf(x, 'color') === key);
      return optBtn('color', key, v.color_name || 'Default', v.color_hex ? `<span class="sw" style="background:${T.esc(v.color_hex)}"></span>` : '', key === pick.color, false, any.every((x) => +x.stock_milli <= 0));
    }).join('');
    const sg = $('pickSizes');
    sg.hidden = sizes.length < 2 && !sizes[0][1].size_label;
    sg.querySelector('.opts').innerHTML = sizes.map(([key, v]) => {
      const m = vs.filter((x) => keyOf(x, 'size') === key && keyOf(x, 'color') === pick.color);
      return optBtn('size', key, v.size_label || 'Standard', '', key === pick.size, !m.length, m.length && m.every((x) => +x.stock_milli <= 0));
    }).join('');
    const combo = vs.filter((v) => keyOf(v, 'color') === pick.color && keyOf(v, 'size') === pick.size);
    const dg = $('pickDesigns');
    dg.hidden = combo.length < 2;
    if (!combo.find((v) => v.id === pick.design) && combo[0]) pick.design = combo[0].id;
    dg.querySelector('.opts').innerHTML = combo.map((v) => optBtn('design', v.id, [v.design_no && 'D#' + v.design_no, v.pattern, v.batch_no && 'Lot ' + v.batch_no].filter(Boolean).join(' ') || v.sku,
      '', v.id === pick.design, false, +v.stock_milli <= 0)).join('');
    const v = currentVariant();
    if (v) {
      $('pickImg').src = v.image_url || pick.product.image_url;
      $('pickSel').innerHTML = `<strong>${T.esc(v.sku)}</strong> · ${T.esc(v.label || 'Standard')}<br><span class="pick-price">${T.esc(v.price)}</span>${+v.mrp_paise > +v.price_paise ? ` <s class="muted">${T.esc(v.mrp)}</s>` : ''} · `
        + (+v.stock_milli > 0 ? `<span style="color:var(--success)">In stock ${T.esc(v.stock)}${v.unit === 'm' ? ' m' : ''}</span>` : '<span style="color:var(--danger)">Out of stock</span>');
    } else {
      $('pickSel').innerHTML = '<span style="color:var(--danger)">This size is not available in the selected colour</span>';
    }
    $('pickAdd').disabled = !v;
  }
  $('mPick').addEventListener('click', (e) => {
    const b = e.target.closest('.opt'); if (!b) return;
    setPickOpt(b.dataset.group, b.dataset.key, true);
  });
  function setPickOpt(group, key, focus) {
    if (group === 'color') pick.color = key; else if (group === 'size') pick.size = key; else pick.design = key;
    if (group === 'color') {   // keep a valid size for the new colour if possible
      const ok = pick.variants.some((v) => keyOf(v, 'color') === pick.color && keyOf(v, 'size') === pick.size);
      if (!ok) { const alt = pick.variants.find((v) => keyOf(v, 'color') === pick.color && +v.stock_milli > 0) || pick.variants.find((v) => keyOf(v, 'color') === pick.color); if (alt) pick.size = keyOf(alt, 'size'); }
    }
    renderPick();
    if (focus) { const el = document.querySelector(`#mPick .opt[data-group="${group}"][aria-checked="true"]`); if (el) el.focus(); }
  }
  $('mPick').addEventListener('keydown', (e) => {
    const b = e.target.closest('.opt');
    if (b && (e.key === 'ArrowRight' || e.key === 'ArrowLeft')) {
      e.preventDefault();
      const opts = [...b.parentElement.querySelectorAll('.opt')];
      const i = opts.indexOf(b) + (e.key === 'ArrowRight' ? 1 : -1);
      const t = opts[(i + opts.length) % opts.length];
      setPickOpt(t.dataset.group, t.dataset.key, true);
    } else if (b && (e.key === 'ArrowDown' || e.key === 'ArrowUp')) {
      e.preventDefault();
      const stops = [...document.querySelectorAll('#mPick .pick-group:not([hidden]) .opt[aria-checked="true"]'), $('pickQty')];
      const i = stops.indexOf(b) + (e.key === 'ArrowDown' ? 1 : -1);
      if (stops[i]) stops[i].focus();
    } else if (e.target === $('pickQty') && e.key === 'ArrowUp') {
      const stops = [...document.querySelectorAll('#mPick .pick-group:not([hidden]) .opt[aria-checked="true"]')];
      if (stops.length) { e.preventDefault(); stops[stops.length - 1].focus(); }
    } else if (b && e.key === 'Enter') { e.preventDefault(); $('pickForm').requestSubmit(); }
  });
  $('pickForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const v = currentVariant(); if (!v) return;
    const q = T.parseQty($('pickQty').value, v.unit);
    if (isNaN(q) || q <= 0) { T.toast(v.unit === 'm' ? 'Enter the length in metres, e.g. 2.75' : 'Enter a whole number of pieces', 'error'); $('pickQty').focus(); $('pickQty').select(); return; }
    const card = pick.card;
    pick = null;
    T.closeModal('mPick');
    await addVariant(v, q, { card });
  });

  // ================================================================= CART
  function lineFromVariant(v) {
    return { variant_id: v.id, sku: v.sku, name: v.name, label: v.label, unit: v.unit, qty_milli: v.unit === 'm' ? 0 : 1000,
      unit_price_paise: null, base_price_paise: +v.price_paise, mrp_paise: +v.mrp_paise, disc_type: null, disc_value: 0,
      stock_milli: +v.stock_milli, discount_allowed: +v.discount_allowed, image_url: v.image_url || '' };
  }

  /** Add a variant. qtyMilli = null → +1 piece (or ask metres for fabric). opts.card → keep focus in the grid. */
  async function addVariant(v, qtyMilli = null, opts = {}) {
    if (!v.sellable) { T.toast(v.name + ' is archived and cannot be sold', 'error'); beep(false); flash(false); return; }
    const add = qtyMilli ?? (v.unit === 'm' ? null : 1000);
    const idx = state.lines.findIndex((l) => l.variant_id === v.id && !l.disc_type && l.unit_price_paise === null && l.qty_milli > 0);
    let target;
    if (add !== null && C.merge_scans && idx >= 0) {
      target = idx;
      if (!(await stockOk(state.lines[idx], state.lines[idx].qty_milli + add))) return;
      state.lines[idx].qty_milli += add;
    } else {
      const l = lineFromVariant(v);
      if (add !== null) { l.qty_milli = add; if (!(await stockOk(l, add))) return; }
      state.lines.push(l); target = state.lines.length - 1;
    }
    beep(true); if (!opts.card) flash(true);
    selected = target;
    changed(true);
    if (add === null) { openLine(target, true); return; }   // fabric scanned: ask metres
    T.toast(`Added: ${v.name}${v.label ? ' (' + v.label + ')' : ''}`, 'success', 1400);
    if (opts.card && document.body.contains(opts.card)) {
      opts.card.classList.remove('added'); void opts.card.offsetWidth; opts.card.classList.add('added');
      opts.card.focus();
    } else { $('scan').focus(); }
  }

  async function stockOk(line, newQty) {
    const reserved = state.lines.filter((l) => l !== line && l.variant_id === line.variant_id).reduce((a, l) => a + l.qty_milli, 0);
    if (newQty + reserved <= line.stock_milli || state.allow_oversell) return true;
    const avail = T.qty(Math.max(0, line.stock_milli - reserved), line.unit);
    beep(false);
    if (!can('pos.oversell')) { T.toast(`Not enough stock for ${line.name}: only ${avail} available`, 'error'); flash(false); return false; }
    const ok = await confirmBox(line.stock_milli - reserved <= 0 ? 'Out of stock' : 'Low stock', `${line.name} (${line.sku}): system shows only ${avail} available. Sell anyway? This is recorded in the audit log.`,
      [{ label: 'Cancel', value: false }, { label: 'Sell anyway', value: true, primary: true }]);
    if (ok) state.allow_oversell = true;
    return ok;
  }

  function changed(added) { saveDraft(); render(added); requestQuote(); }

  function render(added) {
    const body = $('cartBody');
    const hadFocus = body.contains(document.activeElement);
    $('cartEmpty').style.display = state.lines.length ? 'none' : '';
    const cc = $('cartCount'); cc.textContent = state.lines.length; cc.className = 'count' + (state.lines.length ? '' : ' zero');
    const ql = quote && quote.lines && quote.lines.length === state.lines.length ? quote.lines : null;
    body.innerHTML = state.lines.map((l, i) => {
      const q = ql ? ql[i] : null;
      const price = l.unit_price_paise ?? l.base_price_paise;
      const disc = q ? q.line_discount_paise + q.bill_discount_alloc_paise : 0;
      const over = l.qty_milli > l.stock_milli;
      return `<div class="cline ${i === selected ? 'selected' : ''} ${added && i === selected ? 'just-added' : ''}" role="listitem" tabindex="-1" data-i="${i}" aria-label="${T.esc(l.name)}">
        <img class="thumb" src="${T.esc(l.image_url || T.url('/assets/img/no-image.svg'))}" alt="">
        <div class="cl-mid"><div class="cl-name" title="${T.esc(l.name)}">${T.esc(l.name)}</div>
          <div class="cl-sub">${l.label ? T.esc(l.label) + ' · ' : ''}<span class="mono">${T.esc(l.sku)}</span>${l.unit_price_paise !== null ? ' · <span class="warn">price changed</span>' : ''}${over ? ' · <span class="warn">over stock</span>' : ''}</div>
          <div class="cl-ctl"><span class="qty-ctl"><button type="button" tabindex="-1" data-q="-" aria-label="Decrease">${svg('minus')}</button>
            <span class="qty-val">${l.qty_milli ? T.qty(l.qty_milli, l.unit) : '—'}</span><button type="button" tabindex="-1" data-q="+" aria-label="Increase">${svg('plus')}</button></span>
            <span class="cl-unit">× ${T.money(price)}${l.unit === 'm' ? '/m' : ''}${q ? ' · GST ' + (q.tax_rate_bp / 100) + '%' : ''}</span></div></div>
        <div class="cl-right"><div><div class="cl-total">${q ? T.money(q.line_total_paise) : '…'}</div>${disc ? `<div class="cl-disc">−${T.money(disc)}</div>` : ''}</div>
          <button class="rmbtn" type="button" tabindex="-1" data-rm aria-label="Remove ${T.esc(l.name)}">${svg('x')}</button></div></div>`;
    }).join('');
    const sel = body.querySelector('.cline.selected');
    if (sel) { sel.scrollIntoView({ block: 'nearest' }); if (hadFocus) sel.focus(); }
    else if (hadFocus) $('scan').focus();
    renderTotals();
  }

  function renderTotals() {
    const q = quote;
    const set = (id, v) => ($(id).textContent = v);
    set('tItems', state.lines.length ? `(${state.lines.length} item${state.lines.length === 1 ? '' : 's'} · qty ${q ? T.qty(q.qty_total_milli, 'pcs') : '…'})` : '');
    set('tGross', T.money(q ? q.gross_paise : 0));
    set('tDisc', '−' + T.money(q ? q.discount_paise : 0));
    $('tDiscPct').textContent = q && q.discount_paise ? '(' + (q.discount_bp / 100).toFixed(2) + '%)' : '';
    set('tTaxable', T.money(q ? q.taxable_paise : 0));
    $('tTaxLabel').textContent = q && q.interstate ? 'IGST' : 'GST (CGST+SGST)' + (q && q.inclusive ? ' incl.' : '');
    set('tTax', T.money(q ? q.tax_paise : 0));
    set('tRound', T.money(q ? q.round_off_paise : 0));
    set('tTotal', T.money(q ? q.total_paise : 0));
    $('payAmt').textContent = q && state.lines.length ? T.money(q.total_paise) : '';
    const notes = [];
    if (q && q.needs_approval && !state.approval_token) notes.push('Discount above your limit — manager approval will be asked.');
    if (state.bill_discount) notes.push('Bill discount: ' + (state.bill_discount.type === 'percent' ? (state.bill_discount.value / 100) + '%' : T.money(state.bill_discount.value)));
    $('tNote').textContent = notes.join(' ');
    const empty = !state.lines.length;
    $('payBtn').disabled = empty;
    document.querySelectorAll('[data-pay], #saveOnlyBtn').forEach((b) => (b.disabled = empty));
  }

  // ---------------------------------------------------------------- server quote (authoritative maths)
  let qTimer = null, qSeq = 0;
  function payloadLines() {
    return state.lines.filter((l) => l.qty_milli > 0).map((l) => ({ variant_id: l.variant_id, qty_milli: l.qty_milli,
      unit_price_paise: l.unit_price_paise, disc_type: l.disc_type, disc_value: l.disc_value }));
  }
  function requestQuote() {
    clearTimeout(qTimer);
    if (!state.lines.length) { quote = null; renderTotals(); return Promise.resolve(); }
    return new Promise((resolve) => { qTimer = setTimeout(async () => { await fetchQuote(); resolve(); }, 60); });
  }
  async function fetchQuote() {
    const my = ++qSeq;
    if (state.lines.some((l) => !l.qty_milli)) { return; }
    try {
      const r = await T.api('POST', '/api/pos/quote', { lines: payloadLines(), bill_discount: state.bill_discount, interstate: state.interstate });
      if (my !== qSeq) return;
      quote = r.quote; render(false);
    } catch (e) { if (e.status !== 423) T.toast(e.message, 'error'); }
  }

  // ================================================================= SEARCH / SCAN
  const scan = $('scan');
  let sTimer = null, chain = Promise.resolve();
  /** Searching always covers ALL products (the category chip returns to "All Products"). */
  function resetChipToAll() {
    if (!gCat) return;
    gCat = '';
    chipList().forEach((c, i) => { const on = i === 0; c.classList.toggle('active', on); c.setAttribute('aria-selected', String(on)); c.tabIndex = on ? 0 : -1; });
  }
  function setSearch(q) {
    if (q === gQ) return;
    if (q) resetChipToAll();
    gQ = q; gFocus = 0; loadGrid(true);
  }
  scan.addEventListener('input', () => {
    clearTimeout(sTimer);
    const q = scan.value.trim();
    if (/^\d{8,}$/.test(q)) return;            // looks like a barcode being scanned: wait for Enter
    sTimer = setTimeout(() => setSearch(q.length >= 2 ? q : ''), 220);
  });
  scan.addEventListener('keydown', (e) => {
    if (e.key === 'ArrowDown') { e.preventDefault(); clearTimeout(sTimer); if (scan.value.trim().length >= 2) setSearch(scan.value.trim()); focusCard(gFocus); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); if (state.lines.length) selectLine(state.lines.length - 1); }
    else if (e.key === 'Enter' && !e.ctrlKey) {
      e.preventDefault();
      const q = scan.value.trim();
      clearTimeout(sTimer);
      if (!q) { if (gItems.length) focusCard(gFocus); return; }
      chain = chain.then(() => lookup(q));       // queued so fast consecutive scans keep their order
    } else if (e.key === 'Escape') {
      if (scan.value) { e.preventDefault(); scan.value = ''; setSearch(''); }
    }
  });

  async function lookup(code) {
    try {
      const r = await T.api('GET', '/api/pos/lookup?code=' + encodeURIComponent(code));
      if (r.items.length === 1) {
        if (scan.value.trim() === code) scan.value = '';
        if (gQ) setSearch('');
        return addVariant(r.items[0]);
      }
      // not an exact barcode/SKU: show matching product cards (all categories)
      resetChipToAll();
      gQ = code; gFocus = 0;
      await loadGrid(true);
      if (gItems.length === 1) { chooseProduct(0, cards()[0]); return; }
      if (gItems.length > 1) { focusCard(0); T.toast('Choose the product, then size / colour', 'info', 1800); return; }
      beep(false); flash(false);
      T.toast(`Unknown barcode “${code}”. Type the product name, or add it under Products.`, 'error', 5000);
      scan.value = code; scan.select();
    } catch (e) { T.toast(e.message, 'error'); }
  }

  // ---------------------------------------------------------------- cart line keyboard / mouse
  function selectLine(i) {
    selected = Math.max(0, Math.min(state.lines.length - 1, i));
    render(false);
    const el = $('cartBody').querySelector(`[data-i="${selected}"]`); if (el) el.focus();
  }
  async function bump(i, dir) {
    const l = state.lines[i]; if (!l) return;
    const step = l.unit === 'm' ? 250 : 1000;
    const nq = l.qty_milli + dir * step;
    if (nq <= 0) { removeLine(i); return; }
    if (dir > 0 && !(await stockOk(l, nq))) return;
    l.qty_milli = nq; selected = i; changed(false);
    const el = $('cartBody').querySelector(`[data-i="${i}"]`); if (el) el.focus();
  }
  function removeLine(i) {
    const l = state.lines[i]; if (!l) return;
    undo = { line: l, index: i, at: Date.now() };
    state.lines.splice(i, 1);
    selected = Math.min(i, state.lines.length - 1);
    changed(false);
    T.toast(`Removed ${l.name}. Press Ctrl+Z to undo.`, 'warning', 5000);
    if (state.lines.length) selectLine(selected); else scan.focus();
  }
  $('cartBody').addEventListener('keydown', (e) => {
    const row = e.target.closest('[data-i]'); if (!row) return;
    const i = +row.dataset.i;
    if (e.key === 'ArrowDown') { e.preventDefault(); if (i >= state.lines.length - 1) { scan.focus(); selected = -1; render(false); } else selectLine(i + 1); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); if (i === 0) scan.focus(); else selectLine(i - 1); }
    else if (e.key === '+' || e.key === 'Add' || (e.key === '=' && e.shiftKey)) { e.preventDefault(); bump(i, 1); }
    else if (e.key === '-' || e.key === 'Subtract') { e.preventDefault(); bump(i, -1); }
    else if (e.key === 'Delete' || e.key === 'Backspace') { e.preventDefault(); confirmRemove(i); }
    else if (e.key === 'Enter') { e.preventDefault(); openLine(i); }
    else if (e.key === 'Escape') { e.preventDefault(); scan.focus(); }
    else if (e.key.length === 1 && !e.ctrlKey && !e.altKey && /[A-Za-z0-9]/.test(e.key)) { scan.focus(); }
  });
  $('cartBody').addEventListener('click', (e) => {
    const row = e.target.closest('[data-i]'); if (!row) return;
    const i = +row.dataset.i;
    const qb = e.target.closest('[data-q]');
    if (qb) { bump(i, qb.dataset.q === '+' ? 1 : -1); return; }
    if (e.target.closest('[data-rm]')) { confirmRemove(i); return; }
    selectLine(i);
  });
  $('cartBody').addEventListener('dblclick', (e) => { const row = e.target.closest('[data-i]'); if (row) openLine(+row.dataset.i); });
  async function confirmRemove(i) {
    const l = state.lines[i]; if (!l) return;
    const ok = await confirmBox('Remove item?', `${l.name} ${l.label ? '(' + l.label + ')' : ''} — ${T.qty(l.qty_milli, l.unit)}`,
      [{ label: 'Keep', value: false }, { label: 'Remove', value: true, primary: true, danger: true }]);
    if (ok) removeLine(i); else selectLine(i);
  }

  // ---------------------------------------------------------------- line edit
  let lineIdx = -1;
  function openLine(i) {
    const l = state.lines[i]; if (!l) return;
    lineIdx = i;
    $('lineTitle').textContent = l.name;
    $('lineSub').textContent = l.sku + (l.label ? ' · ' + l.label : '') + ' · stock ' + T.qty(l.stock_milli, l.unit);
    $('lineUnit').textContent = l.unit === 'm' ? '(metres, up to 3 decimals e.g. 2.75)' : '(pieces)';
    $('lineQty').value = l.qty_milli ? T.qty(l.qty_milli, l.unit) : '';
    $('linePrice').value = ((l.unit_price_paise ?? l.base_price_paise) / 100).toFixed(2);
    $('linePriceHint').textContent = can('pos.price_override') ? 'MRP ' + T.money(l.mrp_paise) + '. Changing price is logged.' : 'Price change needs manager approval when saving.';
    $('lineDiscType').value = l.disc_type || '';
    $('lineDiscVal').value = l.disc_type === 'percent' ? (l.disc_value / 100) : l.disc_type === 'amount' ? (l.disc_value / 100).toFixed(2) : '';
    $('lineDiscType').disabled = $('lineDiscVal').disabled = !l.discount_allowed || !can('pos.discount');
    T.openModal('mLine');
    setTimeout(() => { $('lineQty').focus(); $('lineQty').select(); }, 30);
  }
  $('lineForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const l = state.lines[lineIdx]; if (!l) return;
    const q = T.parseQty($('lineQty').value, l.unit);
    if (isNaN(q) || q <= 0) { T.toast(l.unit === 'm' ? 'Enter metres like 2.75' : 'Enter a whole number of pieces', 'error'); $('lineQty').focus(); return; }
    const price = T.parseMoney($('linePrice').value);
    if (isNaN(price) || price <= 0 || price > l.mrp_paise) { T.toast('Price must be between ₹0.01 and MRP ' + T.money(l.mrp_paise), 'error'); return; }
    const dt = $('lineDiscType').value || null;
    let dv = 0;
    if (dt) { dv = T.parseMoney($('lineDiscVal').value); if (isNaN(dv) || dv < 0) { T.toast('Invalid discount', 'error'); return; } }
    if (q > l.qty_milli && !(await stockOk(l, q))) return;
    l.qty_milli = q;
    l.unit_price_paise = price === l.base_price_paise ? null : price;
    l.disc_type = dv ? dt : null; l.disc_value = dv ? dv : 0;
    T.closeModal('mLine'); selected = lineIdx; changed(false);
    scan.focus();
  });
  $('lineRemove').addEventListener('click', () => { T.closeModal('mLine'); removeLine(lineIdx); });
  $('mLine').addEventListener('modal:closed', () => {
    const l = state.lines[lineIdx];
    if (l && !l.qty_milli) { state.lines.splice(lineIdx, 1); changed(false); }   // metre item added but no length entered
    if (!T.openModalEl()) scan.focus();
  });
  $('lineQty').addEventListener('keydown', (e) => { if (e.key === 'Delete' && !$('lineQty').value) { e.preventDefault(); $('lineRemove').click(); } });

  // ================================================================= CUSTOMER
  const custQ = $('custQ'); const custRes = $('custResults');
  let custFound = [], cIdx = -1, cTimer;
  function openCustomer(newMode) {
    T.openModal('mCustomer');
    custQ.value = state.customer ? (state.customer.phone || state.customer.name) : '';
    $('interstate').checked = !!state.interstate; $('custNew').style.display = 'none'; renderCustHistory();
    setTimeout(() => { if (newMode === true) showNewCust(); else { custQ.focus(); custQ.select(); } }, 30);
  }
  function setCustomer(c) {
    state.customer = c; saveDraft();
    $('custName').textContent = c ? c.name : 'Walk-in Customer';
    $('custSub').textContent = c ? [c.phone, c.gstin, c.balance_paise > 0 ? 'Due ' + T.money(c.balance_paise) : ''].filter(Boolean).join(' · ') : 'Optional · press ' + C.shortcuts.customer[0];
    renderCustHistory();
  }
  function renderCustHistory() {
    const c = state.customer;
    $('custHistory').innerHTML = c && c.recent && c.recent.length ? '<div class="muted">Recent bills:</div>' + c.recent.map((s) => `<div>${T.esc(s.invoice_no)} · ${T.esc(s.created_at.slice(0, 10))} · ${T.money(s.total_paise)}</div>`).join('') : '';
  }
  function showCust() {
    const digits = custQ.value.replace(/\D/g, '');
    custRes.innerHTML = custFound.map((c, i) => `<div class="picker-item" data-i="${i}" aria-selected="${i === cIdx}"><div style="flex:1"><div class="cell-title">${T.esc(c.name)}</div><div class="cell-sub">${T.esc(c.phone || '')} ${c.gstin ? '· ' + T.esc(c.gstin) : ''}</div></div>${c.balance_paise > 0 ? '<span class="badge badge-warning">Due ' + T.money(c.balance_paise) + '</span>' : ''}</div>`).join('')
      + (can('customer.manage') ? `<div class="picker-item" data-new="1" aria-selected="${cIdx === custFound.length}">${svg('plus')} New customer${digits.length === 10 ? ' with ' + digits : ''}</div>` : '');
    custRes.classList.add('open');
  }
  custQ.addEventListener('input', () => {
    clearTimeout(cTimer);
    const q = custQ.value.trim(); if (q.length < 3) { custRes.classList.remove('open'); return; }
    cTimer = setTimeout(async () => { try { const r = await T.api('GET', '/api/customers/search?q=' + encodeURIComponent(q)); custFound = r.items; cIdx = 0; showCust(); } catch (e) { T.toast(e.message, 'error'); } }, 200);
  });
  custQ.addEventListener('keydown', (e) => {
    const max = custFound.length + (can('customer.manage') ? 0 : -1);
    if (e.key === 'ArrowDown') { e.preventDefault(); cIdx = Math.min(max, cIdx + 1); showCust(); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); cIdx = Math.max(0, cIdx - 1); showCust(); }
    else if (e.key === 'Enter') { e.preventDefault(); if (custRes.classList.contains('open')) pickCust(cIdx); }
  });
  custRes.addEventListener('mousedown', (e) => { const el = e.target.closest('.picker-item'); if (!el) return; e.preventDefault(); pickCust(el.dataset.new ? custFound.length : +el.dataset.i); });
  function pickCust(i) {
    custRes.classList.remove('open');
    if (i >= custFound.length) { showNewCust(); return; }
    setCustomer(custFound[i]); T.closeModal('mCustomer'); scan.focus();
  }
  function showNewCust() {
    if (!can('customer.manage')) { T.toast('You cannot create customers', 'error'); return; }
    $('custNew').style.display = '';
    const d = custQ.value.replace(/\D/g, ''); $('cnPhone').value = d.length >= 10 ? d.slice(-10) : '';
    $('cnName').value = /\d/.test(custQ.value) ? '' : custQ.value; $('cnName').focus();
  }
  $('custNewBtn').addEventListener('click', showNewCust);
  $('custAddBtn').addEventListener('click', () => openCustomer(true));
  $('custNew').addEventListener('submit', async (e) => {
    e.preventDefault();
    try {
      const r = await T.api('POST', '/api/customers', { name: $('cnName').value, phone: $('cnPhone').value, gstin: $('cnGstin').value });
      setCustomer(r.customer); T.toast('Customer saved', 'success'); T.closeModal('mCustomer'); scan.focus();
    } catch (err) { T.toast(err.message, 'error'); }
  });
  $('custClear').addEventListener('click', () => { setCustomer(null); T.closeModal('mCustomer'); scan.focus(); });
  $('interstate').addEventListener('change', () => { state.interstate = $('interstate').checked; changed(false); });
  $('mCustomer').addEventListener('modal:closed', () => { if (!T.openModalEl()) scan.focus(); });

  // ================================================================= DISCOUNT
  function openDiscount() {
    if (!state.lines.length) { T.toast('Add items before giving a discount', 'info'); return; }
    if (!can('pos.discount')) { T.toast('You are not allowed to give discounts', 'error'); return; }
    $('discLimit').textContent = (C.user.max_discount_bp / 100) + '%';
    $('discType').value = state.bill_discount ? state.bill_discount.type : 'percent';
    $('discVal').value = state.bill_discount ? (state.bill_discount.type === 'percent' ? state.bill_discount.value / 100 : (state.bill_discount.value / 100).toFixed(2)) : '';
    T.openModal('mDiscount'); setTimeout(() => { $('discVal').focus(); $('discVal').select(); }, 30);
  }
  $('discForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const v = T.parseMoney($('discVal').value);   // percent "10" → 1000 bp; amount "50" → 5000 paise
    if (isNaN(v) || v < 0) { T.toast('Enter a valid discount', 'error'); return; }
    if ($('discType').value === 'percent' && v > 10000) { T.toast('Cannot exceed 100%', 'error'); return; }
    state.bill_discount = v ? { type: $('discType').value, value: v } : null;
    state.approval_token = null;
    T.closeModal('mDiscount'); changed(false);
    await requestQuote();
    if (quote && quote.needs_approval) {
      const tok = await approve('pos.discount_override', `Discount of ${(quote.discount_bp / 100).toFixed(2)}% is above your ${(C.user.max_discount_bp / 100)}% limit.`);
      if (tok) state.approval_token = tok; else { state.bill_discount = null; changed(false); }
      renderTotals();
    }
    scan.focus();
  });
  $('discClear').addEventListener('click', () => { state.bill_discount = null; T.closeModal('mDiscount'); changed(false); scan.focus(); });

  // ================================================================= APPROVALS
  let apResolve = null;
  function approve(perm, why) {
    return new Promise((resolve) => {
      apResolve = resolve;
      $('approveWhy').textContent = why; $('approveForm').dataset.perm = perm;
      $('apUser').value = ''; $('apPass').value = ''; $('approveError').style.display = 'none';
      T.openModal('mApprove'); setTimeout(() => $('apUser').focus(), 30);
    });
  }
  $('approveForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    try {
      const r = await T.api('POST', '/api/approvals', { username: $('apUser').value, password: $('apPass').value, permission: $('approveForm').dataset.perm });
      const res = apResolve; apResolve = null; T.closeModal('mApprove'); T.toast('Approved by ' + r.approver, 'success'); res && res(r.token);
    } catch (err) { $('approveError').textContent = err.message; $('approveError').style.display = ''; $('apPass').select(); }
  });
  $('mApprove').addEventListener('modal:closed', () => { if (apResolve) { const r = apResolve; apResolve = null; r(null); } });

  // ================================================================= PAYMENT
  const METHODS = { cash: 'Cash', upi: 'UPI', card: 'Card', credit: 'Credit' };
  let pays = [];
  let payPrint = true;
  function openPayment(method = 'cash', opts = {}) {
    if (!state.lines.length) { scan.focus(); return; }
    if (state.lines.some((l) => !l.qty_milli)) { T.toast('Enter the quantity for every item', 'error'); return; }
    requestQuote().then(() => {
      if (!quote) return;
      if (C.require_customer && !state.customer) { T.toast('Customer is required for every bill (shop setting)', 'error'); openCustomer(); return; }
      if (method === 'credit' && !state.customer) { T.toast('Credit needs a customer (F4)', 'error'); method = 'cash'; }
      if (method !== 'split' && !C.payment_methods.includes(method)) method = 'cash';
      payPrint = opts.saveOnly ? false : true;
      $('paySaveLabel').textContent = payPrint ? 'Save & Print' : 'Save (no print)';
      pays = [{ method: method === 'split' ? 'cash' : method, amount: quote.total_paise, reference: '' }];
      renderPay(); T.openModal('mPay');
      if (method === 'split') addSplit(); else setTimeout(() => focusAmount(0), 40);
    });
  }
  function focusAmount(i) { const el = document.querySelector(`#payRows [data-amt="${i}"]`); if (el) { el.focus(); el.select(); } }
  function renderPay() {
    const methods = C.payment_methods.filter((m) => METHODS[m] && (m !== 'credit' || state.customer));
    $('payMethods').innerHTML = methods.map((m, i) => `<button type="button" class="pay-method" role="radio" aria-checked="${pays.length === 1 && pays[0].method === m}" data-m="${m}">${METHODS[m]} <kbd>Alt+${i + 1}</kbd></button>`).join('');
    $('payRows').innerHTML = pays.map((p, i) => `<div class="pay-row">
      <div><label>Method</label><select data-meth="${i}">${methods.map((m) => `<option value="${m}" ${m === p.method ? 'selected' : ''}>${METHODS[m]}</option>`).join('')}</select></div>
      <div><label>${p.method === 'cash' ? 'Cash received (₹)' : 'Amount (₹)'}</label><input class="input num" inputmode="decimal" data-amt="${i}" value="${(p.amount / 100).toFixed(2)}"></div>
      <div><label>${p.method === 'cash' ? '&nbsp;' : 'Reference (UPI ref / approval code)'}</label>${p.method === 'cash' ? '<div class="hint">Change is calculated automatically</div>' : `<input class="input" data-ref="${i}" value="${T.esc(p.reference)}" maxlength="40" placeholder="optional">`}</div>
      <div>${i > 0 ? `<button class="btn btn-ghost btn-icon" type="button" data-rmpay="${i}" aria-label="Remove">${svg('x')}</button>` : ''}</div></div>`).join('');
    $('payTotal').textContent = T.money(quote.total_paise);
    $('saveNoPrint').style.display = can('pos.save_no_print') && payPrint ? '' : 'none';
    updatePaySummary();
  }
  function updatePaySummary() {
    const total = quote ? quote.total_paise : 0;
    let nonCash = 0, cash = 0, bad = false;
    pays.forEach((p) => { if (isNaN(p.amount)) bad = true; else if (p.method === 'cash') cash += p.amount; else nonCash += p.amount; });
    const due = total - nonCash;
    const change = cash - Math.max(0, due);
    $('paySumTotal').textContent = T.money(total);
    $('paySumRecv').textContent = bad ? '—' : T.money(cash + nonCash);
    const box = $('payChange').parentElement;
    let err = '';
    if (bad) err = 'Enter a valid amount';
    else if (nonCash > total) err = 'Card/UPI/credit amount is more than the bill';
    if (!bad && cash + nonCash < total) { box.classList.add('short'); $('payChangeLabel').textContent = 'Still to pay'; $('payChange').textContent = T.money(total - cash - nonCash); }
    else { box.classList.remove('short'); $('payChangeLabel').textContent = 'Change to return'; $('payChange').textContent = T.money(Math.max(0, change)); }
    $('payError').style.display = err ? '' : 'none'; $('payError').textContent = err;
    return !bad && !err && cash + nonCash >= total;
  }
  $('payRows').addEventListener('input', (e) => {
    const a = e.target.dataset.amt, r = e.target.dataset.ref;
    if (a !== undefined) pays[+a].amount = T.parseMoney(e.target.value);
    if (r !== undefined) pays[+r].reference = e.target.value;
    updatePaySummary();
  });
  $('payRows').addEventListener('change', (e) => { const m = e.target.dataset.meth; if (m !== undefined) { pays[+m].method = e.target.value; renderPay(); focusAmount(+m); } });
  $('payRows').addEventListener('click', (e) => { const b = e.target.closest('[data-rmpay]'); if (b) { pays.splice(+b.dataset.rmpay, 1); renderPay(); focusAmount(0); } });
  $('payMethods').addEventListener('click', (e) => { const b = e.target.closest('[data-m]'); if (b) setMethod(b.dataset.m); });
  function setMethod(m) {
    if (m === 'credit' && !state.customer) { T.toast('Credit needs a customer (F4)', 'error'); return; }
    pays = [{ method: m, amount: quote.total_paise, reference: '' }];
    renderPay(); focusAmount(0);
  }
  function remaining() { const paid = pays.reduce((a, p) => a + (isNaN(p.amount) ? 0 : p.amount), 0); return Math.max(0, quote.total_paise - paid); }
  $('paySplit').addEventListener('click', addSplit);
  function addSplit() {
    const used = pays.map((p) => p.method);
    const next = C.payment_methods.find((m) => METHODS[m] && !used.includes(m) && (m !== 'credit' || state.customer)) || 'upi';
    if (pays.length === 1 && pays[0].method === 'cash' && remaining() === 0) pays[0].amount = 0;
    pays.push({ method: next, amount: remaining(), reference: '' });
    renderPay(); focusAmount(0);
  }
  $('payExact').addEventListener('click', exact);
  function exact() {
    const i = pays.length - 1;
    const others = pays.reduce((a, p, j) => a + (j === i || isNaN(p.amount) ? 0 : p.amount), 0);
    pays[i].amount = Math.max(0, quote.total_paise - others); renderPay(); focusAmount(i);
  }
  $('mPay').addEventListener('keydown', (e) => {
    if (e.altKey && /^[1-4]$/.test(e.key)) { const m = [...$('payMethods').querySelectorAll('[data-m]')][+e.key - 1]; if (m) { e.preventDefault(); setMethod(m.dataset.m); } }
    else if (e.altKey && (e.key === 's' || e.key === 'S')) { e.preventDefault(); addSplit(); }
    else if (e.key === '=' && e.target.dataset.amt !== undefined) { e.preventDefault(); exact(); }
  });
  $('payForm').addEventListener('submit', (e) => { e.preventDefault(); save(payPrint); });
  $('saveNoPrint').addEventListener('click', () => save(false));
  $('mPay').addEventListener('modal:closed', () => { if (!T.openModalEl()) scan.focus(); });
  document.querySelectorAll('[data-pay]').forEach((b) => b.addEventListener('click', () => openPayment(b.dataset.pay)));
  $('saveOnlyBtn').addEventListener('click', () => openPayment('cash', { saveOnly: true }));

  // ---------------------------------------------------------------- save (one local transaction on the server)
  async function save(print, extraToken) {
    if (saving) return;
    if (!$('mPay').classList.contains('open')) { openPayment(); return; }
    if (!updatePaySummary()) { T.toast($('payError').textContent || 'Payment is short', 'error'); return; }
    saving = true; $('paySave').disabled = true;
    const body = {
      client_ref: state.client_ref, lines: payloadLines(), bill_discount: state.bill_discount, customer_id: state.customer ? state.customer.id : null,
      interstate: state.interstate, approval_token: extraToken || state.approval_token, expected_total_paise: quote.total_paise,
      payments: pays.map((p) => ({ method: p.method, amount_paise: p.amount, reference: p.reference || null })), print, allow_oversell: state.allow_oversell,
    };
    try {
      const r = await T.api('POST', '/api/pos/checkout', body);
      saving = false; $('paySave').disabled = false;
      onSaved(r, print);
    } catch (err) {
      saving = false; $('paySave').disabled = false;
      const ap = err.data && err.data.errors && err.data.errors.approval;
      if (ap) {
        const tok = await approve(ap, err.message);
        if (tok) { state.approval_token = tok; return save(print, tok); }
        return;
      }
      if (err.data && err.data.errors && err.data.errors.cash_session) { T.closeModal('mPay'); openCash(); return; }
      if (err.status === 0) T.toast('NOT saved: ' + err.message + ' Your bill is still on screen — try again.', 'error', 8000);
      else T.toast('Not saved: ' + err.message, 'error', 6000);
      if (/total changed/i.test(err.message)) { await fetchQuote(); renderPay(); }
    }
  }

  function onSaved(r, print) {
    lastSale = { id: r.sale_id, invoice_no: r.invoice_no, receipt_url: r.receipt_url };
    T.closeModal('mPay');
    state = blank(); quote = null; selected = -1; saveDraft(); render(false); setCustomer(null);
    try { localStorage.removeItem(DRAFT_KEY); } catch (e) { /* */ }
    $('savedNo').textContent = r.invoice_no;
    $('savedChange').textContent = r.change_paise > 0 ? 'Return change: ' + T.money(r.change_paise) : (r.duplicate ? 'Already saved earlier' : 'No change due');
    $('savedView').href = T.url('/sales/' + r.sale_id);
    $('savedPrint').textContent = print ? 'Opening print dialog…' : 'Saved without printing.';
    T.openModal('mSaved');
    setTimeout(() => $('savedNew').focus(), 30);
    updateStatus(r.pending_sync);
    loadGrid(true);   // refresh stock badges
    if (print && C.auto_print) printReceipt('initial', C.printer_format);
  }

  function printReceipt(kind, format, sale = lastSale) {
    if (!sale) return;
    const f = $('printFrame');
    const url = T.url('/sales/' + sale.id + '/receipt?format=' + format + '&kind=' + kind);
    let done = false;
    const fail = () => { if (done) return; done = true; $('savedPrint').innerHTML = '<span style="color:var(--danger)">Print failed or blocked.</span> Bill is saved. Press <kbd>R</kbd> to retry — no new bill is created.'; };
    f.onload = () => {
      if (done) return; done = true;
      try {
        f.contentWindow.focus(); f.contentWindow.print();
        $('savedPrint').textContent = 'Sent to the print dialog. If nothing printed, check the printer and press R to retry.';
        T.api('POST', '/api/sales/' + sale.id + '/printed', { kind, format }).catch(() => {});
      } catch (e) { done = false; fail(); }
      setTimeout(() => { if ($('mSaved').classList.contains('open')) $('savedNew').focus(); }, 300);
    };
    f.onerror = fail;
    setTimeout(() => { if (!done) fail(); }, 8000);
    f.src = url;
  }
  $('savedReprint').addEventListener('click', () => printReceipt('reprint', C.printer_format));
  $('savedA4').addEventListener('click', () => printReceipt('reprint', 'a4'));
  $('savedNew').addEventListener('click', nextBill);
  $('mSaved').addEventListener('keydown', (e) => {
    if (e.key === 'r' || e.key === 'R') { e.preventDefault(); $('savedReprint').click(); }
    else if (e.key === 'a' || e.key === 'A') { e.preventDefault(); $('savedA4').click(); }
    else if (e.key === 'Enter' || e.key === 'Escape' || e.key === 'F2') { e.preventDefault(); nextBill(); }
  });
  function nextBill() { T.closeModal('mSaved'); scan.focus(); }

  // ================================================================= HOLD / RESUME / NEW / CANCEL
  function openHold() { if (!state.lines.length) { T.toast('Nothing to hold', 'info'); return; } $('holdLabel').value = state.customer ? state.customer.name : ''; T.openModal('mHold'); setTimeout(() => $('holdLabel').focus(), 30); }
  const holdPayload = (label) => ({ lines: state.lines, bill_discount: state.bill_discount, customer: state.customer, interstate: state.interstate, total_paise: quote ? quote.total_paise : 0, label });
  $('holdForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    try {
      await T.api('POST', '/api/pos/hold', holdPayload($('holdLabel').value));
      T.closeModal('mHold'); clearCart(); T.toast('Bill held. Press ' + C.shortcuts.resume[0] + ' to resume.', 'success'); updateStatus();
    } catch (err) { T.toast(err.message, 'error'); }
  });
  let held = [], hIdx = 0;
  async function openResume() {
    try { const r = await T.api('GET', '/api/pos/held'); held = r.items; } catch (e) { T.toast(e.message, 'error'); return; }
    if (!held.length) { T.toast('No held bills', 'info'); return; }
    hIdx = 0; renderHeld(); T.openModal('mHeld');
    setTimeout(() => $('heldList').focus(), 30);
  }
  function renderHeld() {
    $('heldList').tabIndex = 0;
    $('heldList').innerHTML = held.map((h, i) => `<div class="held-item" role="option" data-i="${i}" aria-selected="${i === hIdx}">${svg('pause')}<div style="flex:1"><div class="cell-title">${T.esc(h.label || 'Held bill')}</div>
      <div class="cell-sub">${h.items_count} lines · by ${T.esc(h.held_by)} · ${T.esc(h.created_at.slice(11, 16))}</div></div><strong>${T.money(h.total_estimate_paise)}</strong></div>`).join('');
  }
  $('heldList').addEventListener('keydown', (e) => {
    if (e.key === 'ArrowDown') { e.preventDefault(); hIdx = Math.min(held.length - 1, hIdx + 1); renderHeld(); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); hIdx = Math.max(0, hIdx - 1); renderHeld(); }
    else if (e.key === 'Enter') { e.preventDefault(); resumeHeld(hIdx); }
    else if (e.key === 'Delete') { e.preventDefault(); discardHeld(hIdx); }
  });
  $('heldList').addEventListener('click', (e) => { const el = e.target.closest('[data-i]'); if (el) resumeHeld(+el.dataset.i); });
  async function resumeHeld(i) {
    const h = held[i]; if (!h) return;
    if (state.lines.length) {
      const c = await confirmBox('Current bill', 'There is a bill on screen. Hold it first?', [{ label: 'Cancel', value: 'cancel' }, { label: 'Discard it', value: 'discard', danger: true }, { label: 'Hold it', value: 'hold', primary: true }]);
      if (c === 'cancel' || c === null) return;
      if (c === 'hold') { await T.api('POST', '/api/pos/hold', holdPayload('Auto-held')); }
    }
    try {
      const r = await T.api('POST', '/api/pos/held/' + h.id + '/resume', {});
      state = Object.assign(blank(), { lines: r.cart.lines || [], bill_discount: r.cart.bill_discount || null, interstate: !!r.cart.interstate });
      setCustomer(r.cart.customer || null);
      T.closeModal('mHeld'); changed(false); scan.focus(); updateStatus();
      T.toast('Bill resumed — prices and stock are re-checked by the server', 'info');
    } catch (err) { T.toast(err.message, 'error'); }
  }
  async function discardHeld(i) {
    const h = held[i]; if (!h) return;
    if (!(await confirmBox('Discard held bill?', (h.label || 'Held bill') + ' — ' + T.money(h.total_estimate_paise), [{ label: 'Keep', value: false }, { label: 'Discard', value: true, danger: true, primary: true }]))) { T.openModal('mHeld'); return; }
    await T.api('POST', '/api/pos/held/' + h.id + '/delete', {});
    held.splice(i, 1); updateStatus();
    if (held.length) { hIdx = 0; renderHeld(); T.openModal('mHeld'); } else scan.focus();
  }
  function clearCart() { state = blank(); quote = null; selected = -1; setCustomer(null); saveDraft(); render(false); scan.focus(); }
  async function newBill() {
    if (!state.lines.length) { clearCart(); return; }
    const c = await confirmBox('Start a new bill?', 'The current bill is not saved.', [{ label: 'Cancel', value: 'cancel' }, { label: 'Discard', value: 'discard', danger: true }, { label: 'Hold it', value: 'hold', primary: true }]);
    if (c === 'hold') openHold(); else if (c === 'discard') clearCart(); else scan.focus();
  }
  $('cancelBill').addEventListener('click', async () => {
    if (!state.lines.length) { T.toast('The bill is already empty', 'info'); return; }
    const ok = await confirmBox('Cancel this bill?', 'All items will be removed from the screen. Nothing has been saved yet, so stock is not affected.',
      [{ label: 'Keep bill', value: false }, { label: 'Cancel bill', value: true, primary: true, danger: true }]);
    if (ok) { clearCart(); T.toast('Bill cancelled', 'warning'); } else scan.focus();
  });
  async function goReturns() {
    if (state.lines.length) {
      const c = await confirmBox('Go to returns', 'Hold the current bill first?', [{ label: 'Cancel', value: 'cancel' }, { label: 'Hold & go', value: 'hold', primary: true }]);
      if (c !== 'hold') { scan.focus(); return; }
      await T.api('POST', '/api/pos/hold', holdPayload('Held for return'));
      state = blank(); try { localStorage.removeItem(DRAFT_KEY); } catch (e) { /* */ }
    }
    location = T.url('/returns');
  }

  // ---------------------------------------------------------------- history
  let hist = [], histIdx = 0;
  async function openHistory() {
    try { hist = (await T.api('GET', '/api/pos/recent')).items; } catch (e) { T.toast(e.message, 'error'); return; }
    histIdx = 0; renderHistory(); T.openModal('mHistory');
    setTimeout(() => $('historyList').focus(), 30);
  }
  function renderHistory() {
    $('historyList').innerHTML = hist.length ? hist.map((s, i) => `<div class="hist-item" role="option" data-i="${i}" aria-selected="${i === histIdx}">
      <div><div class="cell-title mono">${T.esc(s.invoice_no)} ${s.status === 'voided' ? '<span class="badge badge-danger">Voided</span>' : ''}</div><div class="cell-sub">${T.esc(s.created_at.slice(0, 16))} · ${T.esc(s.customer_name || 'Walk-in')} · ${s.items_count} items</div></div>
      <span class="badge ${s.sync_status === 'acked' ? 'badge-success' : 'badge-warning'}">${s.sync_status === 'acked' ? 'Synced' : 'Pending'}</span><strong>${T.money(s.total_paise)}</strong></div>`).join('')
      : '<div class="empty small">No bills yet on this counter.</div>';
    const el = $('historyList').querySelector('[aria-selected="true"]'); if (el) el.scrollIntoView({ block: 'nearest' });
  }
  const openBill = (i) => { if (hist[i]) window.open(T.url('/sales/' + hist[i].id), '_blank', 'noopener'); };
  $('historyList').addEventListener('keydown', (e) => {
    if (e.key === 'ArrowDown') { e.preventDefault(); histIdx = Math.min(hist.length - 1, histIdx + 1); renderHistory(); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); histIdx = Math.max(0, histIdx - 1); renderHistory(); }
    else if (e.key === 'Enter') { e.preventDefault(); openBill(histIdx); }
    else if ((e.key === 'r' || e.key === 'R') && C.can_reprint && hist[histIdx]) { e.preventDefault(); printReceipt('reprint', C.printer_format, { id: hist[histIdx].id }); T.toast('Reprint sent (logged, no new bill)', 'success'); }
  });
  $('historyList').addEventListener('click', (e) => { const el = e.target.closest('[data-i]'); if (el) { histIdx = +el.dataset.i; renderHistory(); openBill(histIdx); } });

  // ---------------------------------------------------------------- confirm box (Enter = primary, Esc = first)
  function confirmBox(title, text, buttons) {
    return new Promise((resolve) => {
      $('confirmTitle').textContent = title; $('confirmText').textContent = text;
      const foot = $('confirmButtons'); foot.innerHTML = '';
      let settled = false;
      const finish = (v) => { if (settled) return; settled = true; T.closeModal('mConfirm'); resolve(v); };
      buttons.forEach((b) => {
        const el = document.createElement('button'); el.type = 'button';
        el.className = 'btn' + (b.primary ? (b.danger ? ' btn-danger' : ' btn-primary') : (b.danger ? ' btn-danger' : ''));
        el.textContent = b.label; el.addEventListener('click', () => finish(b.value)); foot.appendChild(el);
        if (b.primary) el.dataset.primary = '1';
      });
      $('mConfirm').addEventListener('modal:closed', () => finish(buttons[0].value), { once: true });
      T.openModal('mConfirm');
      setTimeout(() => (foot.querySelector('[data-primary]') || foot.lastChild).focus(), 30);
    });
  }

  // ================================================================= CASH SESSION, STATUS, LOCK
  function openCash() {
    if (!can('cash.manage')) { T.toast('Ask a manager to open the cash counter', 'error'); return; }
    T.openModal('mCash'); setTimeout(() => { $('cashOpening').focus(); $('cashOpening').select(); }, 30);
  }
  $('cashForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    try { await T.api('POST', '/api/cash/open', { opening: $('cashOpening').value }); T.closeModal('mCash'); T.toast('Cash counter opened', 'success'); updateStatus(); scan.focus(); }
    catch (err) { T.toast(err.message, 'error'); }
  });
  async function updateStatus(pending) {
    try {
      const s = await T.api('GET', '/api/pos/status');
      const p = pending ?? s.pending_sync;
      const b = $('syncBadge'); b.className = 'badge ' + (p ? 'badge-warning' : 'badge-success'); b.textContent = p ? p + ' pending sync' : 'All synced';
      const c = $('cashBadge'); c.className = 'badge ' + (s.cash_session ? 'badge-success' : 'badge-warning'); c.textContent = s.cash_session ? 'Cash open' : 'Cash closed';
      const h = $('heldCount'); h.textContent = s.held; h.className = 'count' + (s.held ? '' : ' zero');
      $('billHint').textContent = '#' + (s.next_invoice || s.next_invoice_hint);
      if (!s.cash_session && C.require_cash_session && !T.openModalEl()) openCash();
    } catch (e) { /* local server unreachable: shown on next action */ }
  }
  T.onLocked = () => { if (!$('mLock').classList.contains('open')) { T.openModal('mLock'); setTimeout(() => $('lockPass').focus(), 30); } };
  $('lockForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    try { await T.api('POST', '/unlock', { password: $('lockPass').value }); $('lockPass').value = ''; T.closeModal('mLock'); scan.focus(); idleAt = Date.now(); }
    catch (err) { if (err.status !== 423) { $('lockError').textContent = err.message; $('lockError').style.display = ''; } }
  });
  let idleAt = Date.now();
  ['keydown', 'mousedown'].forEach((ev) => document.addEventListener(ev, () => (idleAt = Date.now()), true));
  setInterval(() => { if (!document.hidden) updateStatus(); }, 60000);   // LOCAL status only

  // ================================================================= GLOBAL SHORTCUTS
  const actions = {
    help: () => T.openModal('mHelp'), new: newBill, search: () => { scan.focus(); scan.select(); }, customer: () => openCustomer(),
    discount: openDiscount, payment: () => openPayment(), hold: openHold, resume: openResume, returns: goReturns, history: openHistory,
    save: () => ($('mPay').classList.contains('open') ? save(true) : openPayment()),
  };
  function keyName(e) {
    let k = e.key;
    if (/^Digit\d$/.test(e.code) && e.altKey) k = e.code.slice(5);
    if (k.length === 1) k = k.toUpperCase();
    return (e.ctrlKey ? 'Ctrl+' : '') + (e.altKey ? 'Alt+' : '') + (e.shiftKey && k.length > 1 && !/^F\d+$/.test(k) ? 'Shift+' : '') + k;
  }
  const keymap = {};
  Object.entries(C.shortcuts).forEach(([a, keys]) => keys.forEach((k) => (keymap[k] = a)));
  document.addEventListener('keydown', (e) => {
    const name = keyName(e);
    const action = keymap[name];
    const modal = T.openModalEl();
    if (name === 'Ctrl+S') {   // Ctrl+S = the existing "Save only" button (never prints); browser "Save page" is blocked
      e.preventDefault();
      if (!modal) $('saveOnlyBtn').click();                                                    // opens payment in save-only mode
      else if (modal.id === 'mPay' && !payPrint && !saving) $('payForm').requestSubmit();     // confirm that save-only payment
      return;                                                                                  // other dialogs (lock, confirm, picker…) block it
    }
    if (e.ctrlKey && (e.key === 'z' || e.key === 'Z') && !modal && undo && Date.now() - undo.at < 15000 && !/^(INPUT|TEXTAREA)$/.test(e.target.tagName)) {
      e.preventDefault(); state.lines.splice(undo.index, 0, undo.line); undo = null; changed(false); T.toast('Restored', 'success'); return;
    }
    if (!modal && (e.key === 'PageDown' || e.key === 'PageUp') && !e.ctrlKey) {   // category chips
      e.preventDefault(); stepChip(e.key === 'PageDown' ? 1 : -1); return;
    }
    if (e.key === 'F5' || (e.ctrlKey && (e.key === 'r' || e.key === 'R'))) { if (!action) e.preventDefault(); }   // never lose the bill to an accidental refresh
    if (!action) {
      // scanner resilience: printable keys typed while focus is "nowhere" go into the search box
      // (handled keys call preventDefault; a cart row may already be re-rendered/detached by the time we get here)
      if (!modal && !e.defaultPrevented && e.key.length === 1 && !e.ctrlKey && !e.altKey && !/^(INPUT|TEXTAREA|SELECT)$/.test(e.target.tagName)
        && !e.target.closest('#cartBody') && !e.target.closest('#grid')) scan.focus();
      return;
    }
    if (modal) {
      if (modal.id === 'mPay' && action === 'save') { e.preventDefault(); save(true); }
      else if (modal.id === 'mSaved' && action === 'new') { e.preventDefault(); nextBill(); }
      else if (/^F\d+$/.test(name) || e.ctrlKey) e.preventDefault();   // swallow F-keys / Ctrl shortcuts under dialogs
      return;
    }
    e.preventDefault();
    actions[action]();
  });
  document.querySelectorAll('[data-action]').forEach((b) => b.addEventListener('click', () => actions[b.dataset.action]()));
  window.addEventListener('beforeunload', (e) => { if (state.lines.length && !saving) { saveDraft(); e.preventDefault(); e.returnValue = ''; } });

  // ================================================================= BOOT
  loadDraft();
  setCustomer(state.customer);
  render(false); requestQuote(); updateStatus();
  loadGrid(true);
  scan.focus();
  window.addEventListener('focus', () => { if (!T.openModalEl() && document.activeElement === document.body) scan.focus(); });
  window.__pos = { state: () => state, quote: () => quote, grid: () => gItems };   // test hook
})();
