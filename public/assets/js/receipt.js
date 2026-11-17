/* Print helpers for receipt / label / report windows (no inline scripts because of CSP). */
(function () {
  document.querySelectorAll('[data-print]').forEach((b) => b.addEventListener('click', () => window.print()));
  if (/[?&]autoprint=1/.test(location.search)) window.addEventListener('load', () => setTimeout(() => window.print(), 200));
})();
