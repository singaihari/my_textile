<?php
use App\Core\Auth;
use App\Core\Database;
use App\Core\Lang;

$user = Auth::user();
$path = request()->path;
$pending = 0;
try {
    $pending = (int)Database::instance()->value("SELECT COUNT(*) FROM sync_outbox WHERE status IN ('pending','failed')");
} catch (Throwable) {
}
$isActive = fn(string $p) => $p === '/' ? $path === '/' : ($path === $p || str_starts_with($path, $p . '/'));
$nav = [
    'nav.overview' => [
        ['/', 'home', 'nav.dashboard', 'report.sales'],
        ['/pos', 'pos', 'nav.pos', 'pos.sell'],
        ['/sales', 'receipt', 'nav.invoices', 'sale.view'],
        ['/returns', 'return', 'nav.returns', 'return.create'],
    ],
    'nav.catalog' => [
        ['/products', 'shirt', 'nav.products', 'product.view'],
        ['/masters', 'tag', 'nav.masters', 'product.manage'],
        ['/import', 'upload', 'nav.import', 'product.import'],
    ],
    'nav.inventory' => [
        ['/stock', 'box', 'nav.stock', 'stock.view'],
        ['/stock/adjust', 'layers', 'nav.adjust', 'stock.adjust'],
        ['/purchases', 'truck', 'nav.purchases', 'purchase.manage'],
        ['/suppliers', 'users', 'nav.suppliers', 'supplier.manage'],
    ],
    'nav.people' => [
        ['/customers', 'user', 'nav.customers', 'customer.view'],
        ['/cash', 'cash', 'nav.cash', 'cash.manage'],
        ['/expenses', 'wallet', 'nav.expenses', 'expense.manage'],
        ['/reports', 'chart', 'nav.reports', null],
    ],
    'nav.admin' => [
        ['/sync', 'sync', 'nav.sync', 'sync.run'],
        ['/settings', 'settings', 'nav.settings', 'settings.manage'],
        ['/staff', 'shield', 'nav.staff', 'staff.manage'],
        ['/backup', 'database', 'nav.backup', 'backup.manage'],
        ['/audit', 'activity', 'nav.audit', 'audit.view'],
        ['/system', 'info', 'nav.system', null],
        ['/help', 'keyboard', 'nav.help', null],
    ],
];
$canReports = can('report.sales') || can('report.stock') || can('report.profit');
$flashes = $_SESSION['_flash'] ?? [];
unset($_SESSION['_flash']);
?><!doctype html>
<html lang="<?= e(Lang::locale()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<meta name="base-url" content="<?= e(base_path_url()) ?>">
<meta name="lock-minutes" content="<?= (int)setting('session_lock_minutes', 15) ?>">
<title><?= e($title ?? 'Textile POS') ?> · <?= e(setting('shop_name')) ?></title>
<link rel="stylesheet" href="<?= asset('css/app.css') ?>">
</head>
<body>
<?php if (setting('demo_mode') === '1'): ?><div class="demo-ribbon">DEMO DATA — sample products and transactions for training only. Not real business figures.</div><?php endif; ?>
<a href="#main" class="sr-only">Skip to content</a>
<div class="shell">
  <aside class="sidebar" id="sidebar" aria-label="Main navigation">
    <div class="brand">
      <div class="brand-mark" aria-hidden="true">TX</div>
      <div><div class="brand-name"><?= e(setting('shop_name')) ?></div><div class="brand-sub">Textile POS · <?= e(App\Core\Context::device()['code'] ?? '') ?></div></div>
    </div>
    <nav class="nav">
      <?php foreach ($nav as $group => $items):
          $visible = array_filter($items, fn($i) => $i[3] === null ? ($i[0] !== '/reports' || $canReports) : can($i[3]));
          if (!$visible) continue; ?>
        <div class="nav-group"><?= e(__($group)) ?></div>
        <?php foreach ($visible as [$href, $ic, $label]): ?>
          <a href="<?= e(url($href)) ?>" class="<?= $isActive($href) ? 'active' : '' ?>" <?= $isActive($href) ? 'aria-current="page"' : '' ?>>
            <?= icon($ic) ?><span><?= e(__($label)) ?></span>
            <?php if ($href === '/sync'): ?><span class="count <?= $pending ? '' : 'zero' ?>" title="Pending sync events"><?= $pending ?></span><?php endif; ?>
          </a>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </nav>
    <div class="sidebar-foot">v<?= e(APP_VERSION) ?> · Offline-first · <a href="<?= e(url('/help')) ?>"><kbd>F1</kbd> help</a></div>
  </aside>
  <div class="main">
    <header class="topbar">
      <button class="btn btn-ghost btn-icon menu-toggle" type="button" data-toggle-sidebar aria-label="Open menu"><?= icon('menu') ?></button>
      <h1><?= e($title ?? '') ?></h1>
      <div class="spacer"></div>
      <span class="badge" data-net-badge title="Informational only. Nothing is uploaded automatically."><?= e(__('net.online')) ?></span>
      <a class="badge <?= $pending ? 'badge-warning' : 'badge-success' ?>" href="<?= e(url('/sync')) ?>" title="Data is uploaded only when you click Sync Now">
        <?= $pending ? e(__('sync.pending', ['n' => $pending])) : e(__('sync.none')) ?></a>
      <form method="post" action="<?= e(url('/lang')) ?>" class="no-print"><?= csrf_field() ?>
        <button class="btn btn-sm btn-ghost" name="lang" value="<?= Lang::locale() === 'ta' ? 'en' : 'ta' ?>" title="Switch language"><?= Lang::locale() === 'ta' ? 'English' : 'தமிழ்' ?></button>
      </form>
      <?php if (can('pos.sell') && $path !== '/pos'): ?><a class="btn btn-primary btn-sm" href="<?= e(url('/pos')) ?>"><?= icon('pos') ?> <?= e(__('nav.pos')) ?></a><?php endif; ?>
      <details class="usermenu">
        <summary class="btn btn-sm btn-ghost"><?= icon('user') ?> <?= e($user['full_name'] ?? '') ?> <span class="muted small">(<?= e($user['role_name'] ?? '') ?>)</span></summary>
        <div class="usermenu-pop card">
          <a href="<?= e(url('/account/password')) ?>">Change password</a>
          <a href="<?= e(url('/lock')) ?>" data-lock-now>Lock screen</a>
          <form method="post" action="<?= e(url('/logout')) ?>"><?= csrf_field() ?><button class="linklike"><?= e(__('common.logout')) ?></button></form>
        </div>
      </details>
    </header>
    <main class="content" id="main" tabindex="-1">
      <?php foreach ($flashes as $f): $t = $f['type'] === 'error' ? 'error' : ($f['type'] === 'warning' ? 'warning' : 'success'); ?>
        <div class="alert alert-<?= $t ?>" role="<?= $t === 'error' ? 'alert' : 'status' ?>"><?= icon($t === 'success' ? 'check' : 'alert') ?><div><?= e($f['message']) ?></div></div>
      <?php endforeach; ?>
      <?= $content ?>
    </main>
  </div>
</div>
<div class="toasts" id="toasts" aria-live="polite"></div>
<script src="<?= asset('js/app.js') ?>"></script>
<?php foreach (App\Core\View::$scripts as $s): ?><script src="<?= asset($s) ?>"></script><?php endforeach; ?>
</body>
</html>
<?php unset($_SESSION['_old'], $_SESSION['_errors']); ?>
