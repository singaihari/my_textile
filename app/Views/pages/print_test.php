<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>Printer test</title><link rel="stylesheet" href="<?= asset('css/receipt.css') ?>"></head>
<body class="<?= $format === 'a4' ? 'a4' : 'thermal' ?>">
<div class="toolbar no-print"><a href="?format=80mm">80 mm</a> · <a href="?format=a4">A4</a> <button type="button" data-print>Print test page</button> <span>No bill is created.</span></div>
<main class="receipt">
  <header class="r-head"><div class="r-shop"><?= e($shop['shop_name']) ?></div><div class="r-title">PRINTER TEST</div></header>
  <p><?= e(date('d-m-Y h:i:s A')) ?></p>
  <p>ABCDEFGHIJKLMNOPQRSTUVWXYZ<br>0123456789 ₹ % / - .<br>தமிழ் எழுத்து சோதனை</p>
  <table class="r-items"><tr><td class="l">Left</td><td>Right ₹1,23,456.78</td></tr></table>
  <div class="r-barcode"><?= App\Services\Barcode::svg('TEST-12345', 38, 1.1) ?></div>
  <p class="r-note">Check: text is sharp, the right edge is not cut, the barcode scans as TEST-12345.</p>
  <footer class="r-foot">--- end ---</footer>
</main>
<script src="<?= asset('js/receipt.js') ?>"></script>
</body></html>
