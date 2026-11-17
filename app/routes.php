<?php
declare(strict_types=1);

/** @var App\Core\Router $router */

use App\Controllers\AdminController as Adm;
use App\Controllers\AuthController as A;
use App\Controllers\CustomerController as Cu;
use App\Controllers\DashboardController as D;
use App\Controllers\FinanceController as F;
use App\Controllers\MasterController as M;
use App\Controllers\PosController as P;
use App\Controllers\ProductController as Pr;
use App\Controllers\PurchaseController as Pu;
use App\Controllers\ReportController as R;
use App\Controllers\ReturnController as Rt;
use App\Controllers\SalesController as S;
use App\Controllers\StockController as St;
use App\Controllers\SyncController as Sy;

$pub = ['auth' => false];

$router->get('/favicon.ico', [A::class, 'favicon'], $pub);
// This app never uses a service worker. If another app once registered one on this address, its update check
// receives a worker that unregisters itself (no data is deleted).
foreach (['/sw.js', '/service-worker.js', '/serviceworker.js', '/serviceWorker.js', '/sw-offline.js'] as $swPath) {
    $router->get($swPath, [A::class, 'killServiceWorker'], $pub);
}

// auth & setup
$router->get('/login', [A::class, 'loginForm'], $pub);
$router->post('/login', [A::class, 'login'], $pub);
$router->post('/logout', [A::class, 'logout'], $pub);
$router->get('/lock', [A::class, 'lockForm'], $pub);
$router->post('/unlock', [A::class, 'unlock'], $pub);
$router->post('/lang', [A::class, 'lang']);
$router->get('/account/password', [A::class, 'passwordForm']);
$router->post('/account/password', [A::class, 'changePassword']);
$router->get('/api/ping', [A::class, 'ping']);
$router->post('/api/approvals', [A::class, 'approve']);

// dashboard
$router->get('/', [D::class, 'index']);
$router->get('/api/dashboard', [D::class, 'data']);

// POS
$router->get('/pos', [P::class, 'index'], ['perm' => 'pos.sell']);
$router->get('/api/pos/lookup', [P::class, 'lookup'], ['perm' => 'pos.sell']);
$router->get('/api/pos/search', [P::class, 'search']);
$router->post('/api/pos/quote', [P::class, 'quote'], ['perm' => 'pos.sell']);
$router->post('/api/pos/checkout', [P::class, 'checkout'], ['perm' => 'pos.sell']);
$router->post('/api/pos/hold', [P::class, 'hold'], ['perm' => 'pos.sell']);
$router->get('/api/pos/held', [P::class, 'held'], ['perm' => 'pos.sell']);
$router->post('/api/pos/held/{id}/resume', [P::class, 'resume'], ['perm' => 'pos.sell']);
$router->post('/api/pos/held/{id}/delete', [P::class, 'deleteHeld'], ['perm' => 'pos.sell']);
$router->get('/api/pos/status', [P::class, 'status']);
$router->get('/api/pos/catalog', [P::class, 'catalog'], ['perm' => 'pos.sell']);
$router->get('/api/pos/product/{id}', [P::class, 'product'], ['perm' => 'pos.sell']);
$router->get('/api/pos/recent', [P::class, 'recent'], ['perm' => 'pos.sell']);
$router->post('/api/cash/open', [P::class, 'openCash'], ['perm' => 'cash.manage']);

// sales / invoices
$router->get('/sales', [S::class, 'index'], ['perm' => 'sale.view']);
$router->get('/sales/{id}', [S::class, 'show'], ['perm' => 'sale.view']);
$router->get('/sales/{id}/receipt', [S::class, 'receipt'], ['perm' => 'sale.view']);
$router->post('/api/sales/{id}/printed', [S::class, 'printed'], ['perm' => 'sale.view']);
$router->post('/sales/{id}/void', [S::class, 'void'], ['perm' => 'sale.view']);
$router->get('/print-test', [S::class, 'printTest']);

// returns & exchanges
$router->get('/returns', [Rt::class, 'index'], ['perm' => 'return.create']);
$router->get('/returns/sale/{id}', [Rt::class, 'form'], ['perm' => 'return.create']);
$router->post('/api/returns', [Rt::class, 'store'], ['perm' => 'return.create']);
$router->get('/returns/{id}', [Rt::class, 'show'], ['perm' => 'return.create']);

// catalog
$router->get('/products', [Pr::class, 'index'], ['perm' => 'product.view']);
$router->get('/products/new', [Pr::class, 'form'], ['perm' => 'product.manage']);
$router->get('/products/{id}/edit', [Pr::class, 'form'], ['perm' => 'product.manage']);
$router->get('/products/{id}', [Pr::class, 'show'], ['perm' => 'product.view']);
$router->post('/products/save', [Pr::class, 'save'], ['perm' => 'product.manage']);
$router->post('/products/{id}/archive', [Pr::class, 'archive'], ['perm' => 'product.manage']);
$router->get('/import', [Pr::class, 'importForm'], ['perm' => 'product.import']);
$router->post('/import/preview', [Pr::class, 'importPreview'], ['perm' => 'product.import']);
$router->post('/import/commit', [Pr::class, 'importCommit'], ['perm' => 'product.import']);
$router->get('/export/products', [Pr::class, 'export'], ['perm' => 'product.view']);
$router->get('/labels', [Pr::class, 'labels'], ['perm' => 'product.view']);
$router->get('/masters', [M::class, 'index'], ['perm' => 'product.manage']);
$router->post('/masters/save', [M::class, 'save'], ['perm' => 'product.manage']);
$router->post('/masters/tax', [M::class, 'saveTax'], ['perm' => 'tax.manage']);

// stock & purchasing
$router->get('/stock', [St::class, 'index'], ['perm' => 'stock.view']);
$router->get('/stock/movements', [St::class, 'movements'], ['perm' => 'stock.view']);
$router->get('/stock/adjust', [St::class, 'adjustForm'], ['perm' => 'stock.adjust']);
$router->post('/stock/adjust', [St::class, 'adjust'], ['perm' => 'stock.adjust']);
$router->get('/purchases', [Pu::class, 'index'], ['perm' => 'purchase.manage']);
$router->get('/purchases/new', [Pu::class, 'form'], ['perm' => 'purchase.manage']);
$router->get('/purchases/{id}', [Pu::class, 'form'], ['perm' => 'purchase.manage']);
$router->post('/purchases/save', [Pu::class, 'save'], ['perm' => 'purchase.manage']);
$router->post('/purchases/{id}/post', [Pu::class, 'post'], ['perm' => 'purchase.manage']);
$router->post('/purchases/{id}/cancel', [Pu::class, 'cancel'], ['perm' => 'purchase.manage']);
$router->get('/supplier-returns/new', [Pu::class, 'supplierReturnForm'], ['perm' => 'purchase.manage']);
$router->post('/supplier-returns', [Pu::class, 'supplierReturn'], ['perm' => 'purchase.manage']);
$router->get('/suppliers', [Pu::class, 'suppliers'], ['perm' => 'supplier.manage']);
$router->post('/suppliers/save', [Pu::class, 'saveSupplier'], ['perm' => 'supplier.manage']);

// customers
$router->get('/customers', [Cu::class, 'index'], ['perm' => 'customer.view']);
$router->get('/customers/{id}', [Cu::class, 'show'], ['perm' => 'customer.view']);
$router->post('/customers/save', [Cu::class, 'save'], ['perm' => 'customer.manage']);
$router->post('/customers/{id}/payment', [Cu::class, 'payment'], ['perm' => 'customer.receive_payment']);
$router->post('/customers/{id}/anonymize', [Cu::class, 'anonymize'], ['perm' => 'settings.manage']);
$router->get('/api/customers/search', [Cu::class, 'search'], ['perm' => 'customer.view']);
$router->post('/api/customers', [Cu::class, 'quickCreate'], ['perm' => 'customer.manage']);

// finance
$router->get('/expenses', [F::class, 'expenses'], ['perm' => 'expense.manage']);
$router->post('/expenses', [F::class, 'saveExpense'], ['perm' => 'expense.manage']);
$router->get('/cash', [F::class, 'cash'], ['perm' => 'cash.manage']);
$router->post('/cash/open', [F::class, 'openCash'], ['perm' => 'cash.manage']);
$router->post('/cash/movement', [F::class, 'cashMovement'], ['perm' => 'cash.manage']);
$router->post('/cash/close', [F::class, 'closeCash'], ['perm' => 'cash.manage']);

// reports
$router->get('/reports', [R::class, 'index']);
$router->get('/reports/{key}', [R::class, 'show']);

// sync (manual only)
$router->get('/sync', [Sy::class, 'index'], ['perm' => 'sync.run']);
$router->post('/api/sync/run', [Sy::class, 'run'], ['perm' => 'sync.run']);
$router->post('/api/sync/test', [Sy::class, 'test'], ['perm' => 'sync.run']);
$router->post('/sync/config', [Sy::class, 'config'], ['perm' => 'sync.manage']);
$router->post('/sync/conflicts/{id}/resolve', [Sy::class, 'resolve'], ['perm' => 'sync.manage']);

// admin
$router->get('/settings', [Adm::class, 'settings'], ['perm' => 'settings.manage']);
$router->post('/settings', [Adm::class, 'saveSettings'], ['perm' => 'settings.manage']);
$router->get('/staff', [Adm::class, 'staff'], ['perm' => 'staff.manage']);
$router->post('/staff/save', [Adm::class, 'saveStaff'], ['perm' => 'staff.manage']);
$router->post('/roles/save', [Adm::class, 'saveRole'], ['perm' => 'staff.manage']);
$router->get('/backup', [Adm::class, 'backup'], ['perm' => 'backup.manage']);
$router->post('/backup/create', [Adm::class, 'createBackup'], ['perm' => 'backup.manage']);
$router->post('/backup/upload', [Adm::class, 'uploadBackup'], ['perm' => 'backup.manage']);
$router->post('/backup/restore', [Adm::class, 'restoreBackup'], ['perm' => 'backup.manage']);
$router->get('/backup/download', [Adm::class, 'downloadBackup'], ['perm' => 'backup.manage']);
$router->get('/audit', [Adm::class, 'audit'], ['perm' => 'audit.view']);
$router->get('/system', [Adm::class, 'system']);
$router->get('/help', [Adm::class, 'help']);
