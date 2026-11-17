<?php
/** @var array $config */
$k = fn(string $a, int $i = 0) => e($config['shortcuts'][$a][$i] ?? '');
$path = '/pos';
$rail = [
    ['/', 'home', 'Dashboard', 'report.sales'], ['/pos', 'pos', 'New Sale', 'pos.sell'], ['/sales', 'receipt', 'Invoices', 'sale.view'],
    ['/returns', 'return', 'Returns', 'return.create'], ['/products', 'shirt', 'Products', 'product.view'], ['/stock', 'box', 'Inventory', 'stock.view'],
    ['/customers', 'user', 'Customers', 'customer.view'], ['/reports', 'chart', 'Reports', null], ['/settings', 'settings', 'Settings', 'settings.manage'],
];
$u = App\Core\Auth::user();
$initials = mb_strtoupper(implode('', array_map(fn($w) => mb_substr($w, 0, 1), array_slice(preg_split('/\s+/', trim($u['full_name'])), 0, 2))));
?>
<script type="application/json" id="pos-config"><?= json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>
<?php if (setting('demo_mode') === '1'): ?><div class="demo-ribbon">DEMO DATA — training mode</div><?php endif; ?>
<div class="bill">
  <!-- ============ compact navigation rail (same destinations as the main sidebar) ============ -->
  <nav class="rail" aria-label="Main navigation">
    <a class="rail-brand" href="<?= e(url('/')) ?>" title="<?= e(setting('shop_name')) ?>" tabindex="-1"><span class="brand-mark">TX</span></a>
    <?php foreach ($rail as [$href, $ic, $label, $perm]): if ($perm !== null && !can($perm)) continue; ?>
      <a href="<?= e(url($href)) ?>" class="rail-link <?= $href === $path ? 'active' : '' ?>" tabindex="-1" title="<?= e($label) ?>" <?= $href === $path ? 'aria-current="page"' : '' ?>>
        <?= icon($ic) ?><span><?= e($label) ?></span></a>
    <?php endforeach; ?>
    <div class="rail-foot">
      <span class="rail-avatar" title="<?= e($u['full_name'] . ' · ' . $u['role_name']) ?>"><?= e($initials ?: 'U') ?></span>
      <a class="rail-link" href="<?= e(url('/lock')) ?>" tabindex="-1" title="Lock screen"><?= icon('lock') ?><span>Lock</span></a>
    </div>
  </nav>

  <!-- ============ main: header, search, chips, product grid ============ -->
  <main class="bill-main" aria-label="Products">
    <header class="bill-head">
      <div class="bill-title">
        <h1>New Sale</h1>
        <span class="receipt-chip" title="Next invoice number (assigned when the bill is saved)">Receipt <strong id="billHint" class="mono">#…</strong></span>
      </div>
      <div class="bill-status">
        <span class="badge" data-net-badge title="Informational only. Bills always save on this PC."></span>
        <a class="badge" id="syncBadge" href="<?= e(url('/sync')) ?>" tabindex="-1" title="Bills upload only when you click Sync Now">…</a>
        <span class="badge" id="cashBadge">Cash: …</span>
      </div>
      <div class="bill-actions">
        <button class="hbtn" type="button" data-action="returns" title="Returns / exchange (<?= $k('returns') ?> · <?= $k('returns', 1) ?>)"><?= icon('return') ?><span>Returns</span></button>
        <button class="hbtn" type="button" data-action="history" title="Recent bills (Ctrl+H)"><?= icon('clock') ?><span>History</span></button>
        <button class="hbtn hbtn-accent" type="button" data-action="help" title="Keyboard shortcuts (<?= $k('help') ?>)"><?= icon('keyboard') ?><span>Shortcuts</span></button>
      </div>
    </header>

    <div class="search-wrap">
      <?= icon('search', 'icon search-ico') ?>
      <label for="scan" class="sr-only">Search products or scan barcode</label>
      <input id="scan" class="search-input" autocomplete="off" spellcheck="false" autofocus
             placeholder="Search products by name, SKU, barcode, design number…" aria-describedby="scanHelp" aria-controls="grid">
      <span class="search-kbd"><kbd><?= $k('search') ?></kbd></span>
      <?= icon('barcode', 'icon search-bar') ?>
      <span id="scanHelp" class="sr-only">Scan a barcode and press Enter to add. Press Down arrow to move into the product cards.</span>
    </div>

    <div class="chips" id="chips" role="tablist" aria-label="Categories (PgUp / PgDn)">
      <button class="chip active" role="tab" aria-selected="true" data-cat="" tabindex="0">All Products</button>
      <?php foreach ($config['categories'] as $c): ?>
        <button class="chip" role="tab" aria-selected="false" data-cat="<?= e($c['id']) ?>" tabindex="-1"><?= e($c['name']) ?></button>
      <?php endforeach; ?>
      <button class="chip chip-offer" role="tab" aria-selected="false" data-cat="offers" tabindex="-1"><?= icon('tag') ?> Offers</button>
    </div>

    <section class="grid-wrap" id="gridWrap" aria-label="Product cards">
      <div class="grid-meta"><span id="gridCount" aria-live="polite"></span><span class="muted small">↑↓←→ move · Enter add · PgUp/PgDn category</span></div>
      <div class="pgrid" id="grid" role="listbox" aria-label="Products"></div>
      <div class="grid-state" id="gridState"></div>
      <button class="btn more-btn" type="button" id="moreBtn" hidden>Show more products</button>
    </section>
  </main>

  <!-- ============ right: customer + cart + totals + payment (always visible) ============ -->
  <aside class="cartpanel" aria-label="Bill">
    <div class="cp-customer">
      <div class="cp-row"><span class="cp-label">Customer</span><button class="linkbtn" type="button" id="custAddBtn" tabindex="-1"><?= icon('plus') ?> Add New</button></div>
      <button class="cust-card" type="button" data-action="customer" id="custCard">
        <span class="cust-avatar"><?= icon('user') ?></span>
        <span class="grow"><span class="cust-name" id="custName">Walk-in Customer</span><span class="cust-sub" id="custSub">Optional · press <?= $k('customer') ?></span></span>
        <kbd><?= $k('customer') ?></kbd>
      </button>
    </div>

    <div class="cp-items-head"><span class="cp-label">Cart Items <span class="count zero" id="cartCount">0</span></span>
      <button class="linkbtn danger" type="button" id="cancelBill" tabindex="-1"><?= icon('x') ?> Cancel bill</button></div>
    <div class="cp-items" id="cartBody" role="list" aria-label="Cart items"></div>
    <div class="cart-empty" id="cartEmpty">
      <?= icon('pos', 'icon') ?>
      <h3>Cart is empty</h3>
      <p>Scan a barcode or pick a product card.<br><kbd>Enter</kbd> adds · <kbd>+</kbd><kbd>−</kbd> qty · <kbd>Del</kbd> remove</p>
    </div>

    <div class="cp-totals">
      <div class="t-row"><span>Subtotal <span class="muted" id="tItems"></span></span><span id="tGross">₹0.00</span></div>
      <div class="t-row t-disc"><span>Discount <span id="tDiscPct" class="muted"></span></span><span id="tDisc">−₹0.00</span></div>
      <div class="t-row muted"><span id="tTaxLabel">GST</span><span id="tTax">₹0.00</span></div>
      <div class="t-row muted"><span>Taxable value</span><span id="tTaxable">₹0.00</span></div>
      <div class="t-row muted"><span>Round off</span><span id="tRound">₹0.00</span></div>
      <div class="t-grand"><span>Total</span><span id="tTotal">₹0.00</span></div>
      <div class="t-note" id="tNote" aria-live="polite"></div>
    </div>

    <div class="cp-actions">
      <button class="btn-soft btn-wide" type="button" data-action="discount"><?= icon('percent') ?> Apply Discount <kbd><?= $k('discount') ?></kbd></button>
      <div class="pay-grid">
        <button class="paybtn pay-cash" type="button" data-pay="cash"><?= icon('cash') ?> Cash</button>
        <button class="paybtn pay-upi" type="button" data-pay="upi"><?= icon('wallet') ?> UPI</button>
        <button class="paybtn pay-card" type="button" data-pay="card"><?= icon('wallet') ?> Card</button>
        <button class="paybtn pay-split" type="button" data-pay="split"><?= icon('layers') ?> Split</button>
      </div>
      <div class="act-grid">
        <button class="btn-soft" type="button" data-action="hold" title="Hold bill (<?= $k('hold') ?>)"><?= icon('pause') ?> Hold <kbd><?= $k('hold') ?></kbd></button>
        <button class="btn-soft" type="button" data-action="resume" title="Resume held bill (<?= $k('resume') ?>)"><?= icon('play') ?> Resume <span class="count zero" id="heldCount">0</span></button>
        <button class="btn-soft" type="button" id="saveOnlyBtn" title="Save without printing"><?= icon('check') ?> Save only</button>
      </div>
      <button class="btn btn-primary btn-pay" type="button" data-action="payment" id="payBtn" disabled><?= icon('printer', 'icon icon-lg') ?> Save &amp; Print <span class="pay-amt" id="payAmt"></span> <kbd><?= $k('save') ?></kbd></button>
    </div>
  </aside>
</div>

<!-- Variant quick-pick -->
<div class="modal-backdrop" id="mPick"><form class="modal modal-lg pick" id="pickForm" autocomplete="off">
  <div class="pick-head">
    <img class="pick-img" id="pickImg" alt="">
    <div class="grow"><h2 id="pickName">Product</h2><div class="muted small mono" id="pickCode"></div><div class="pick-price" id="pickPrice"></div></div>
    <button class="btn btn-ghost btn-icon" type="button" data-modal-close aria-label="Close"><?= icon('x') ?></button>
  </div>
  <div class="modal-body">
    <div class="pick-group" id="pickColors" hidden><div class="label">Colour</div><div class="opts" role="radiogroup" aria-label="Colour"></div></div>
    <div class="pick-group" id="pickSizes" hidden><div class="label">Size</div><div class="opts" role="radiogroup" aria-label="Size"></div></div>
    <div class="pick-group" id="pickDesigns" hidden><div class="label">Design / piece</div><div class="opts" role="radiogroup" aria-label="Design"></div></div>
    <div class="pick-foot">
      <div class="pick-sel" id="pickSel"></div>
      <div class="field" style="margin:0"><label for="pickQty" id="pickQtyLabel">Quantity</label><input class="input num" id="pickQty" inputmode="decimal" value="1"></div>
    </div>
    <p class="hint">←→ choose · ↑↓ / Tab switch row · Enter add to bill · Esc close · For fabric, type metres then Enter (↑ to change colour)</p>
  </div>
  <div class="modal-foot"><button class="btn" type="button" data-modal-close>Cancel</button><button class="btn btn-primary" id="pickAdd">Add to bill <kbd>Enter</kbd></button></div>
</form></div>

<!-- History -->
<div class="modal-backdrop" id="mHistory"><div class="modal modal-lg">
  <div class="modal-head"><?= icon('clock') ?><h2>Recent bills on this counter</h2><button class="btn btn-ghost btn-icon" type="button" data-modal-close aria-label="Close"><?= icon('x') ?></button></div>
  <div class="modal-body"><div id="historyList" tabindex="0" role="listbox" aria-label="Recent bills"></div></div>
  <div class="modal-foot"><span class="muted small">↑↓ choose · Enter open bill<?= $config['can_reprint'] ? ' · R reprint' : '' ?></span><span style="flex:1"></span><a class="btn" href="<?= e(url('/sales')) ?>">All invoices</a></div>
</div></div>

<!-- Line edit -->
<div class="modal-backdrop" id="mLine"><form class="modal modal-sm" id="lineForm" autocomplete="off">
  <div class="modal-head"><h2 id="lineTitle">Edit line</h2><button class="btn btn-ghost btn-icon" type="button" data-modal-close aria-label="Close"><?= icon('x') ?></button></div>
  <div class="modal-body">
    <div class="muted small" id="lineSub"></div>
    <div class="field"><label for="lineQty">Quantity <span id="lineUnit" class="muted"></span></label><input class="input num" id="lineQty" inputmode="decimal" required></div>
    <div class="field" id="linePriceField"><label for="linePrice">Unit price (₹)</label><input class="input num" id="linePrice" inputmode="decimal"><div class="hint" id="linePriceHint"></div></div>
    <div class="grid g2">
      <div class="field"><label for="lineDiscType">Item discount</label><select id="lineDiscType"><option value="">None</option><option value="percent">%</option><option value="amount">₹ amount</option></select></div>
      <div class="field"><label for="lineDiscVal">Value</label><input class="input num" id="lineDiscVal" inputmode="decimal"></div>
    </div>
  </div>
  <div class="modal-foot"><button class="btn btn-danger" type="button" id="lineRemove">Remove <kbd>Del</kbd></button><span style="flex:1"></span><button class="btn" type="button" data-modal-close>Cancel</button><button class="btn btn-primary">Apply <kbd>Enter</kbd></button></div>
</form></div>

<!-- Customer -->
<div class="modal-backdrop" id="mCustomer"><div class="modal">
  <div class="modal-head"><h2>Customer (optional)</h2><button class="btn btn-ghost btn-icon" type="button" data-modal-close aria-label="Close"><?= icon('x') ?></button></div>
  <div class="modal-body">
    <div class="field picker"><label for="custQ">Mobile number or name</label><input class="input" id="custQ" inputmode="tel" autocomplete="off" placeholder="e.g. 98765 43210">
      <div class="picker-results" id="custResults" role="listbox"></div></div>
    <form id="custNew" class="card card-pad" style="display:none" autocomplete="off">
      <h3>New customer</h3>
      <div class="grid g2"><div class="field"><label for="cnName">Name *</label><input class="input" id="cnName" required></div>
      <div class="field"><label for="cnPhone">Mobile</label><input class="input" id="cnPhone" inputmode="tel"></div></div>
      <div class="field"><label for="cnGstin">GSTIN (B2B, optional)</label><input class="input" id="cnGstin" maxlength="15"></div>
      <button class="btn btn-primary">Save &amp; use <kbd>Enter</kbd></button>
    </form>
    <div id="custHistory" class="small"></div>
    <label class="check"><input type="checkbox" id="interstate"> Inter-state sale (charge IGST instead of CGST + SGST)</label>
  </div>
  <div class="modal-foot"><button class="btn" type="button" id="custClear">Walk-in (no customer)</button><button class="btn" type="button" id="custNewBtn">+ New customer</button><button class="btn btn-primary" type="button" data-modal-close>Done</button></div>
</div></div>

<!-- Bill discount -->
<div class="modal-backdrop" id="mDiscount"><form class="modal modal-sm" id="discForm" autocomplete="off">
  <div class="modal-head"><h2>Bill discount</h2><button class="btn btn-ghost btn-icon" type="button" data-modal-close aria-label="Close"><?= icon('x') ?></button></div>
  <div class="modal-body">
    <div class="grid g2">
      <div class="field"><label for="discType">Type</label><select id="discType"><option value="percent">Percent %</option><option value="amount">Amount ₹</option></select></div>
      <div class="field"><label for="discVal">Value</label><input class="input num" id="discVal" inputmode="decimal" placeholder="0"></div>
    </div>
    <p class="hint">Your limit: <strong id="discLimit"></strong> of the bill. Above that a manager must approve. Items marked “no discount” are excluded. The discount is split across lines so GST and profit stay exact.</p>
  </div>
  <div class="modal-foot"><button class="btn" type="button" id="discClear">Remove discount</button><button class="btn btn-primary">Apply <kbd>Enter</kbd></button></div>
</form></div>

<!-- Payment -->
<div class="modal-backdrop" id="mPay"><form class="modal modal-lg" id="payForm" autocomplete="off">
  <div class="modal-head"><h2>Payment</h2><span class="pay-total" id="payTotal">₹0.00</span><button class="btn btn-ghost btn-icon" type="button" data-modal-close aria-label="Close"><?= icon('x') ?></button></div>
  <div class="modal-body">
    <div class="pay-methods" role="radiogroup" aria-label="Payment method" id="payMethods"></div>
    <div id="payRows"></div>
    <div class="row" style="margin-top:8px"><button class="btn btn-sm" type="button" id="paySplit">+ Split payment <kbd>Alt+S</kbd></button><button class="btn btn-sm" type="button" id="payExact">Exact amount <kbd>=</kbd></button></div>
    <div class="pay-summary">
      <div><span class="muted">Bill total</span><strong id="paySumTotal"></strong></div>
      <div><span class="muted">Received</span><strong id="paySumRecv"></strong></div>
      <div class="pay-change"><span id="payChangeLabel">Change to return</span><strong id="payChange">₹0.00</strong></div>
    </div>
    <div class="alert alert-error" id="payError" style="display:none"></div>
    <p class="hint">Never type card numbers — only the approval / UPI reference. Use <kbd>Alt+1</kbd>…<kbd>Alt+4</kbd> to switch method.</p>
  </div>
  <div class="modal-foot">
    <button class="btn" type="button" id="saveNoPrint">Save without print</button>
    <span style="flex:1"></span>
    <button class="btn" type="button" data-modal-close>Back <kbd>Esc</kbd></button>
    <button class="btn btn-success btn-lg" id="paySave"><?= icon('printer') ?> <span id="paySaveLabel">Save &amp; Print</span> <kbd><?= $k('save') ?></kbd></button>
  </div>
</form></div>

<!-- Saved -->
<div class="modal-backdrop" id="mSaved" data-no-esc><div class="modal">
  <div class="modal-body saved-body">
    <div class="saved-icon"><?= icon('check', 'icon') ?></div>
    <h2>Bill saved <span class="mono" id="savedNo"></span></h2>
    <div class="saved-change" id="savedChange"></div>
    <p class="muted" id="savedPrint">Sending to printer…</p>
    <p class="small muted">Saved on this PC. It will upload when someone clicks <strong>Sync Now</strong>. Reprinting never creates a new bill.</p>
  </div>
  <div class="modal-foot">
    <button class="btn" type="button" id="savedReprint"><?= icon('printer') ?> Retry print <kbd>R</kbd></button>
    <button class="btn" type="button" id="savedA4">A4 copy <kbd>A</kbd></button>
    <a class="btn" id="savedView" href="#">View bill</a>
    <button class="btn btn-primary btn-lg" type="button" id="savedNew">Next bill <kbd>Enter</kbd></button>
  </div>
</div></div>

<!-- Held bills -->
<div class="modal-backdrop" id="mHeld"><div class="modal">
  <div class="modal-head"><h2>Held bills</h2><button class="btn btn-ghost btn-icon" type="button" data-modal-close aria-label="Close"><?= icon('x') ?></button></div>
  <div class="modal-body"><div id="heldList" role="listbox" aria-label="Held bills"></div></div>
  <div class="modal-foot"><span class="muted small">↑↓ choose · Enter resume · Del discard</span></div>
</div></div>

<!-- Hold label -->
<div class="modal-backdrop" id="mHold"><form class="modal modal-sm" id="holdForm" autocomplete="off">
  <div class="modal-head"><h2>Hold this bill</h2></div>
  <div class="modal-body"><div class="field"><label for="holdLabel">Label (optional)</label><input class="input" id="holdLabel" placeholder="e.g. Lady in green saree, trial room"></div></div>
  <div class="modal-foot"><button class="btn" type="button" data-modal-close>Cancel</button><button class="btn btn-primary">Hold <kbd>Enter</kbd></button></div>
</form></div>

<!-- Confirm -->
<div class="modal-backdrop" id="mConfirm"><div class="modal modal-sm">
  <div class="modal-head"><h2 id="confirmTitle">Confirm</h2></div>
  <div class="modal-body"><p id="confirmText"></p></div>
  <div class="modal-foot" id="confirmButtons"></div>
</div></div>

<!-- Manager approval -->
<div class="modal-backdrop" id="mApprove"><form class="modal modal-sm" id="approveForm" autocomplete="off">
  <div class="modal-head"><?= icon('shield') ?><h2>Manager approval</h2></div>
  <div class="modal-body">
    <p id="approveWhy" class="small"></p>
    <div class="field"><label for="apUser">Manager username</label><input class="input" id="apUser" required autocapitalize="none"></div>
    <div class="field"><label for="apPass">Password</label><input class="input" type="password" id="apPass" required></div>
    <div class="alert alert-error" id="approveError" style="display:none"></div>
  </div>
  <div class="modal-foot"><button class="btn" type="button" data-modal-close>Cancel</button><button class="btn btn-primary">Approve</button></div>
</form></div>

<!-- Cash open -->
<div class="modal-backdrop" id="mCash" data-no-esc><form class="modal modal-sm" id="cashForm" autocomplete="off">
  <div class="modal-head"><?= icon('cash') ?><h2>Open cash counter</h2></div>
  <div class="modal-body"><p class="small muted">Count the cash in the drawer before the first bill.</p>
    <div class="field"><label for="cashOpening">Opening cash (₹)</label><input class="input num" id="cashOpening" inputmode="decimal" value="0" required></div></div>
  <div class="modal-foot"><a class="btn" href="<?= e(url('/')) ?>">Not now</a><button class="btn btn-primary">Open counter</button></div>
</form></div>

<!-- Unlock (inactivity) -->
<div class="modal-backdrop" id="mLock" data-no-esc><form class="modal modal-sm" id="lockForm" autocomplete="off">
  <div class="modal-head"><?= icon('lock') ?><h2>Screen locked</h2></div>
  <div class="modal-body"><p class="small muted">The bill on screen is kept. Enter your password to continue.</p>
    <div class="field"><label for="lockPass">Password</label><input class="input" type="password" id="lockPass" required></div>
    <div class="alert alert-error" id="lockError" style="display:none"></div></div>
  <div class="modal-foot"><a class="btn" href="<?= e(url('/lock')) ?>">Switch user</a><button class="btn btn-primary">Unlock</button></div>
</form></div>

<!-- Shortcuts -->
<div class="modal-backdrop" id="mHelp"><div class="modal modal-lg">
  <div class="modal-head"><?= icon('keyboard') ?><h2>Keyboard shortcuts</h2><button class="btn btn-ghost btn-icon" type="button" data-modal-close aria-label="Close"><?= icon('x') ?></button></div>
  <div class="modal-body">
    <div class="grid g2">
    <table class="table table-compact"><tbody>
      <?php foreach (['new' => 'New bill', 'search' => 'Search / scan box', 'customer' => 'Customer', 'discount' => 'Discount', 'payment' => 'Payment',
          'hold' => 'Hold bill', 'resume' => 'Resume held bill', 'returns' => 'Return / exchange', 'save' => 'Save & print', 'history' => 'Recent bills', 'help' => 'This help'] as $a => $d): ?>
        <tr><td><?= e($d) ?></td><td><?= implode(' ', array_map(fn($x) => '<kbd>' . e($x) . '</kbd>', array_slice($config['shortcuts'][$a] ?? [], 0, 2))) ?></td></tr>
      <?php endforeach; ?>
    </tbody></table>
    <table class="table table-compact"><tbody>
      <tr><td>Move between product cards</td><td><kbd>←</kbd><kbd>↑</kbd><kbd>↓</kbd><kbd>→</kbd></td></tr>
      <tr><td>Add focused product (opens size/colour picker if needed)</td><td><kbd>Enter</kbd></td></tr>
      <tr><td>From search: first card / cart lines</td><td><kbd>↓</kbd> / <kbd>↑</kbd></td></tr>
      <tr><td>Previous / next category</td><td><kbd>PgUp</kbd> <kbd>PgDn</kbd></td></tr>
      <tr><td>Cart line: qty / edit / remove</td><td><kbd>+</kbd><kbd>−</kbd> <kbd>Enter</kbd> <kbd>Del</kbd></td></tr>
      <tr><td>Undo removed line</td><td><kbd>Ctrl+Z</kbd></td></tr>
      <tr><td>Payment: Cash / UPI / Card / Credit</td><td><kbd>Alt+1</kbd>…<kbd>Alt+4</kbd></td></tr>
      <tr><td>Close dialog (never clears the bill)</td><td><kbd>Esc</kbd></td></tr>
    </tbody></table>
    </div>
    <p class="hint">Some browsers reserve keys (F6, F10, Ctrl+H…). If one does not respond, use the alternative shown, or change keys in Settings → Shortcuts.</p>
  </div>
</div></div>

<iframe id="printFrame" title="Receipt print frame" style="position:absolute;width:0;height:0;border:0;left:-9999px"></iframe>
