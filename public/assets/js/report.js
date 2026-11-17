/* Report chart: drawn from the same rows as the table (embedded JSON). */
(function () {
  const el = document.getElementById('report-chart'); const cv = document.getElementById('reportChart');
  if (!el || !cv || !window.Chart) return;
  const d = JSON.parse(el.textContent);
  const T = window.TPOS;
  const palette = ['#2563EB', '#16A34A', '#D97706', '#7C3AED', '#0891B2', '#DB2777', '#65A30D', '#64748B', '#0EA5E9', '#F43F5E'];
  const isD = d.type === 'doughnut';
  const money = /₹|sales|total|net|value|amount|received|margin/i.test(d.label) || true;
  new Chart(cv, {
    type: d.type,
    data: { labels: d.labels, datasets: [{ label: d.label, data: d.values.map((v) => v / 100), backgroundColor: isD ? palette : '#2563EB', borderRadius: isD ? 0 : 4 }] },
    options: { responsive: true, maintainAspectRatio: false, animation: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? false : { duration: 300 },
      plugins: { legend: { display: isD, position: 'right' }, tooltip: { callbacks: { label: (c) => ' ' + (money ? T.money(Math.round((isD ? c.parsed : c.parsed.y) * 100)) : c.formattedValue) } } },
      scales: isD ? {} : { y: { ticks: { callback: (v) => '₹' + v.toLocaleString('en-IN') } }, x: { grid: { display: false } } } },
  });
})();
