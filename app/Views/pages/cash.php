<?php use App\Services\ReportService; $sumRow = fn($label, $v, $sign = '') => '<tr><td>' . e($label) . '</td><td class="num">' . $sign . money($v) . '</td></tr>'; ?>
<?php if ($lastSummary): $s = $lastSummary['s']; ?>
<div class="card card-pad" style="margin-bottom:16px"><h3>Day-end summary — closed <?= e(fmt_date($s['closed_at'])) ?></h3>
  <div class="grid g4"><div><div class="stat-label">Expected cash</div><div class="stat-value"><?= money($s['expected_cash_paise']) ?></div></div><div><div class="stat-label">Counted</div><div class="stat-value"><?= money($s['counted_cash_paise']) ?></div></div>
    <div><div class="stat-label">Difference</div><div class="stat-value" style="color:<?= (int)$s['difference_paise'] === 0 ? 'var(--success)' : 'var(--danger)' ?>"><?= money($s['difference_paise']) ?></div></div><div><div class="stat-label">Bills</div><div class="stat-value"><?= (int)$lastSummary['bills'] ?></div></div></div>
  <table class="table table-compact" style="margin-top:12px"><tbody><?php foreach ($lastSummary['byMethod'] as $m): ?><tr><td><?= e(ReportService::methodLabel($m['method'])) ?></td><td class="num"><?= money($m['amount']) ?></td></tr><?php endforeach; ?></tbody></table>
  <button class="btn no-print" type="button" data-print-page>Print summary</button>
</div><?php endif; ?>
<?php if (!$current): ?>
<form class="card card-pad" style="max-width:480px" method="post" action="<?= e(url('/cash/open')) ?>"><?= csrf_field() ?>
  <h3>Open the cash counter</h3><p class="muted">Count the notes and coins in the drawer.</p>
  <div class="field"><label>Opening cash ₹</label><input class="input num" name="opening" value="0" required autofocus></div>
  <div class="field"><label>Note</label><input class="input" name="notes"></div><button class="btn btn-primary">Open counter</button></form>
<?php else: $sm = $summary; ?>
<div class="grid g-main">
  <div class="card"><div class="card-head"><h3>Open since <?= e(fmt_date($current['opened_at'])) ?> · by <?= e($current['opened_by_name']) ?></h3></div>
    <table class="table"><tbody>
      <?= $sumRow('Opening cash', $sm['s']['opening_float_paise']) ?><?= $sumRow('Cash received on bills (after change)', $sm['cashSales'], '+') ?><?= $sumRow('Cash received for credit dues', $sm['receipts'], '+') ?>
      <?= $sumRow('Pay-ins', $sm['payIn'], '+') ?><?= $sumRow('Cash refunds', $sm['cashRefunds'], '−') ?><?= $sumRow('Pay-outs', $sm['payOut'], '−') ?><?= $sumRow('Cash expenses', $sm['expenses'], '−') ?>
    </tbody><tfoot><tr><td>Expected in drawer</td><td class="num"><?= money($sm['expected']) ?></td></tr></tfoot></table>
    <div class="card-body"><h3>By payment method (<?= (int)$sm['bills'] ?> bills)</h3><ul class="list-plain"><?php foreach ($sm['byMethod'] as $m): ?><li><span style="flex:1"><?= e(ReportService::methodLabel($m['method'])) ?></span><strong><?= money($m['amount']) ?></strong></li><?php endforeach; ?></ul></div>
    <?php if ($movements): ?><div class="card-body"><h3>Cash in / out</h3><table class="table table-compact"><tbody><?php foreach ($movements as $m): ?><tr><td><?= e(fmt_date($m['created_at'])) ?></td><td><?= e($m['type'] === 'pay_in' ? 'In' : 'Out') ?></td><td><?= e($m['reason']) ?></td><td class="num"><?= money($m['amount_paise']) ?></td><td><?= e($m['full_name']) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
  </div>
  <div class="stack">
    <form class="card card-pad" method="post" action="<?= e(url('/cash/movement')) ?>" data-once><?= csrf_field() ?><h3>Cash in / out</h3>
      <div class="grid g2"><div class="field"><label>Type</label><select name="type"><option value="pay_out">Pay-out (take cash out)</option><option value="pay_in">Pay-in (add cash)</option></select></div><div class="field"><label>Amount ₹</label><input class="input num" name="amount" required></div></div>
      <div class="field"><label>Reason *</label><input class="input" name="reason" required placeholder="e.g. Tea, change from bank"></div><button class="btn">Record</button></form>
    <form class="card card-pad" method="post" action="<?= e(url('/cash/close')) ?>" data-confirm="Close the counter for the day?"><?= csrf_field() ?><h3>Close counter</h3>
      <div class="field"><label>Counted cash in drawer ₹ *</label><input class="input num" name="counted" required></div>
      <div class="field"><label>Note</label><input class="input" name="notes"></div><button class="btn btn-primary">Close &amp; show day-end summary</button></form>
  </div>
</div>
<?php endif; ?>
<div class="card"><div class="card-head"><h3>Previous sessions</h3></div><table class="table table-compact"><thead><tr><th>Opened</th><th>Closed</th><th class="num">Opening</th><th class="num">Expected</th><th class="num">Counted</th><th class="num">Difference</th></tr></thead><tbody>
<?php foreach ($history as $h): ?><tr><td><?= e(fmt_date($h['opened_at'])) ?> · <?= e($h['opened_name']) ?></td><td><?= $h['closed_at'] ? e(fmt_date($h['closed_at'])) . ' · ' . e($h['closed_name']) : '<span class="badge badge-success">open</span>' ?></td>
  <td class="num"><?= money($h['opening_float_paise']) ?></td><td class="num"><?= $h['expected_cash_paise'] !== null ? money($h['expected_cash_paise']) : '' ?></td><td class="num"><?= $h['counted_cash_paise'] !== null ? money($h['counted_cash_paise']) : '' ?></td>
  <td class="num" style="color:<?= (int)$h['difference_paise'] ? 'var(--danger)' : 'inherit' ?>"><?= $h['difference_paise'] !== null ? money($h['difference_paise']) : '' ?></td></tr><?php endforeach; ?></tbody></table></div>
