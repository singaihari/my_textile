<?php $labels = ['help' => 'Help overlay', 'new' => 'New bill', 'search' => 'Product search', 'customer' => 'Customer', 'discount' => 'Bill discount', 'payment' => 'Payment', 'hold' => 'Hold bill', 'resume' => 'Resume held bill', 'returns' => 'Return / exchange', 'save' => 'Confirm, save & print']; ?>
<div class="grid g2">
<div class="card"><div class="card-head"><h3>POS keyboard shortcuts</h3><?php if (can('settings.manage')): ?><a class="btn btn-sm" href="<?= e(url('/settings', ['tab' => 'shortcuts'])) ?>">Change keys</a><?php endif; ?></div>
  <table class="table"><tbody>
  <?php foreach ($shortcuts as $a => $keys): ?><tr><td><?= e($labels[$a] ?? $a) ?></td><td><?= implode(' or ', array_map(fn($k) => '<kbd>' . e($k) . '</kbd>', $keys)) ?></td></tr><?php endforeach; ?>
  <tr><td>Move in results / cart</td><td><kbd>↑</kbd> <kbd>↓</kbd></td></tr><tr><td>Add item / edit line / confirm</td><td><kbd>Enter</kbd></td></tr>
  <tr><td>Change quantity of selected line</td><td><kbd>+</kbd> <kbd>−</kbd></td></tr><tr><td>Remove selected line (undo Ctrl+Z)</td><td><kbd>Del</kbd></td></tr>
  <tr><td>Payment method inside payment</td><td><kbd>Alt+1</kbd>…<kbd>Alt+4</kbd>, split <kbd>Alt+S</kbd>, exact <kbd>=</kbd></td></tr>
  <tr><td>After saving: retry print / A4 / next bill</td><td><kbd>R</kbd> <kbd>A</kbd> <kbd>Enter</kbd></td></tr>
  <tr><td>Close dialog (never clears the bill)</td><td><kbd>Esc</kbd></td></tr>
  <tr><td>Reprint on invoice page</td><td><kbd>Ctrl+P</kbd></td></tr><tr><td>Search box on list pages</td><td><kbd>/</kbd></td></tr>
  </tbody></table></div>
<div class="card card-pad"><h3>Fastest checkout</h3>
  <ol><li>Scan item → scan item (same item twice = qty 2)</li><li>For fabric: scan, type metres (e.g. <span class="mono">2.75</span>), <kbd>Enter</kbd></li><li><kbd>F6</kbd> payment — type cash received, or <kbd>Alt+2</kbd> for UPI</li><li><kbd>F10</kbd> (or <kbd>Enter</kbd>) to save &amp; print</li><li><kbd>Enter</kbd> for the next bill — focus is back on the scanner</li></ol>
  <h3>Barcode scanner tips</h3><ul class="small"><li>USB scanners act like a keyboard. Set the scanner to send <strong>Enter</strong> after each code (usually the factory default).</li><li>If a scan goes nowhere, click the scan box or press <kbd>F3</kbd>; any typing on the POS screen is redirected to the scan box.</li><li>Unknown barcode: you hear a low beep and see a message; search by name instead, or add the barcode to the product.</li></ul>
  <h3>Offline &amp; sync</h3><p class="small">Bills save on this PC even with no internet. They upload only when an authorised person clicks <a href="<?= e(url('/sync')) ?>">Sync Now</a>.</p>
  <p class="small muted">Browsers may reserve some keys (F6 = address bar in some browsers, F10 = menu, F11 = full screen). If a key does not respond, use the Alt alternative.</p></div>
</div>
