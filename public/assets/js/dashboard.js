/* Dashboard charts — data from /api/dashboard (SQL over saved bills). Chart.js bundled locally. */
(function () {
  const T = window.TPOS;
  if (!window.Chart) return;
  const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
  Chart.defaults.color = '#64748B';
  Chart.defaults.animation = reduce ? false : { duration: 300 };
  const palette = ['#2563EB', '#16A34A', '#D97706', '#7C3AED', '#0891B2', '#DB2777', '#65A30D', '#64748B'];
  const rupees = (p) => p / 100;
  const tick = (v) => '₹' + (v >= 100000 ? (v / 100000).toFixed(1) + 'L' : v >= 1000 ? (v / 1000).toFixed(0) + 'k' : v);
  const tip = { callbacks: { label: (c) => ' ' + T.money(Math.round(c.parsed.y !== undefined && c.chart.config.type !== 'doughnut' ? c.parsed.y * 100 : c.parsed * 100)) } };
  const charts = {};
  const make = (id, cfg) => { const el = document.getElementById(id); if (!el) return; if (charts[id]) charts[id].destroy(); charts[id] = new Chart(el, cfg); };
  const opts = (extra = {}) => Object.assign({ responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false }, tooltip: tip },
    scales: { y: { ticks: { callback: tick }, grid: { color: '#EEF2F7' } }, x: { grid: { display: false } } } }, extra);
  const empty = (id, d) => { if (!d.length) { const el = document.getElementById(id); if (el) el.parentElement.innerHTML = '<div class="empty small">No sales in this period yet</div>'; return true; } return false; };

  async function load(days) {
    let d;
    try { d = await T.api('GET', '/api/dashboard?days=' + days); } catch (e) { T.toast(e.message, 'error'); return; }
    make('cTrend', { type: 'bar', data: { labels: d.series.map((r) => r.d.slice(5)), datasets: [{ data: d.series.map((r) => rupees(r.total)), backgroundColor: '#2563EB', borderRadius: 4 }] }, options: opts() });
    if (!empty('cHour', d.hourly)) make('cHour', { type: 'bar', data: { labels: d.hourly.map((r) => String(r.h).padStart(2, '0') + ':00'), datasets: [{ data: d.hourly.map((r) => rupees(r.total)), backgroundColor: '#0891B2', borderRadius: 4 }] }, options: opts() });
    const dough = { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'right', labels: { boxWidth: 10 } }, tooltip: tip }, cutout: '62%' };
    if (!empty('cCat', d.category)) make('cCat', { type: 'doughnut', data: { labels: d.category.map((r) => r.name), datasets: [{ data: d.category.map((r) => rupees(r.total)), backgroundColor: palette }] }, options: dough });
    if (!empty('cBrand', d.brands)) make('cBrand', { type: 'doughnut', data: { labels: d.brands.map((r) => r.name), datasets: [{ data: d.brands.map((r) => rupees(r.total)), backgroundColor: palette }] }, options: dough });
    if (!empty('cTop', d.top)) make('cTop', { type: 'bar', data: { labels: d.top.map((r) => r.name.length > 22 ? r.name.slice(0, 21) + '…' : r.name), datasets: [{ data: d.top.map((r) => rupees(r.total)), backgroundColor: '#16A34A', borderRadius: 4 }] },
      options: Object.assign(opts(), { indexAxis: 'y', scales: { x: { ticks: { callback: tick } }, y: { grid: { display: false } } },
        plugins: { legend: { display: false }, tooltip: { callbacks: { label: (c) => ' ' + T.money(Math.round(c.parsed.x * 100)) } } } }) });
    if (d.profit && d.profit.length) make('cProfit', { type: 'line', data: { labels: d.profit.map((r) => r.m), datasets: [
      { label: 'Net sales (ex-GST)', data: d.profit.map((r) => rupees(r.net)), borderColor: '#2563EB', backgroundColor: 'rgba(37,99,235,.08)', fill: true, tension: .3 },
      { label: 'Cost of goods', data: d.profit.map((r) => rupees(r.cogs)), borderColor: '#D97706', tension: .3 },
      { label: 'Gross margin', data: d.profit.map((r) => rupees(r.net - r.cogs)), borderColor: '#16A34A', tension: .3 }] },
      options: Object.assign(opts(), { plugins: { legend: { display: true, position: 'bottom' }, tooltip: { callbacks: { label: (c) => ' ' + c.dataset.label + ': ' + T.money(Math.round(c.parsed.y * 100)) } } } }) });
  }
  const sel = document.getElementById('chartDays');
  if (sel) sel.addEventListener('change', () => load(sel.value));
  load(sel ? sel.value : 30);
})();
