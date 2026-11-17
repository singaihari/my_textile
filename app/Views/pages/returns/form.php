<?php use App\Core\Qty; $s = $elig['sale']; App\Core\View::script('js/returns.js'); ?>
<script type="application/json" id="ret-config"><?= json_encode($config + ['items' => array_map(fn($i) => ['id' => $i['id'], 'unit' => $i['unit'], 'returnable' => (int)$i['returnable_milli'],
    'line_total' => (int)$i['line_total_paise'], 'qty' => (int)$i['qty_milli']], $elig['items'])], JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<div class="page-head"><div class="grow"><h2 class="mono" style="margin:0"><?= e($s['invoice_no']) ?></h2>
  <p><?= e(fmt_date($s['created_at'])) ?> · <?= e($s['customer_name'] ?: 'Walk-in') ?> · total <?= money($s['total_paise']) ?></p></div>
  <a class="btn" href="<?= e(url('/sales/' . $s['id'])) ?>">View bill</a></div>
<?php if (!$elig['within_window']): ?><div class="alert alert-warning"><?= icon('alert') ?><div>This bill is older than the <?= (int)$elig['window_days'] ?>-day return window. A manager must approve.</div></div><?php endif; ?>
<?php if ($elig['prior_returns']): ?><div class="alert alert-info"><?= icon('info') ?><div>Earlier returns on this bill: <?php foreach ($elig['prior_returns'] as $p): ?><a class="mono" href="<?= e(url('/returns/' . $p['id'])) ?>"><?= e($p['return_no']) ?></a> (<?= money($p['total_paise']) ?>) <?php endforeach; ?></div></div><?php endif; ?>
<form id="retForm" autocomplete="off">
<div class="card">
  <div class="card-head"><h3>1. Items being returned</h3><span class="muted small">Enter quantity; choose condition. Damaged items go to the DAMAGED stock location.</span></div>
  <div class="table-wrap"><table class="table"><thead><tr><th>Item</th><th class="num">Sold</th><th class="num">Already returned</th><th class="num">Line total</th><th style="width:130px">Return qty</th><th style="width:150px">Condition</th></tr></thead><tbody>
    <?php foreach ($elig['items'] as $i): $left = (int)$i['returnable_milli']; ?><tr>
      <td><div class="cell-title"><?= e($i['product_name']) ?></div><div class="cell-sub mono"><?= e($i['sku']) ?> <?= e($i['variant_label']) ?></div></td>
      <td class="num"><?= e(Qty::format((int)$i['qty_milli'], $i['unit'], true)) ?></td><td class="num"><?= e(Qty::format((int)$i['returned_qty_milli'], $i['unit'])) ?></td>
      <td class="num"><?= money($i['line_total_paise']) ?></td>
      <td><input class="input input-sm num" data-ret-qty="<?= e($i['id']) ?>" placeholder="0" <?= $left ? '' : 'disabled' ?> aria-label="Return quantity for <?= e($i['product_name']) ?>"><div class="cell-sub">max <?= e(Qty::format($left, $i['unit'])) ?></div></td>
      <td><select class="input-sm" data-ret-cond="<?= e($i['id']) ?>" <?= $left ? '' : 'disabled' ?>><option value="sellable">Sellable — restock</option><option value="damaged">Damaged</option></select></td>
    </tr><?php endforeach; ?></tbody></table></div>
</div>
<div class="card">
  <div class="card-head"><h3>2. Replacement items (exchange — optional)</h3></div>
  <div class="card-body">
    <div class="picker searchbar" style="max-width:560px"><?= icon('barcode') ?><input class="input" id="repPick" placeholder="Scan or search the replacement size / colour"></div>
    <table class="table table-compact" style="margin-top:8px"><tbody id="repBody"><tr data-empty><td class="muted">No replacement — this will be a refund.</td></tr></tbody></table>
  </div>
</div>
<div class="card card-pad">
  <h3>3. Settle</h3>
  <div class="grid g3">
    <div class="field"><label for="reason">Reason *</label><input class="input" id="reason" required placeholder="e.g. Size too small"></div>
    <div class="field"><label for="refundMethod">Refund method (if money goes back)</label><select id="refundMethod"><option value="cash">Cash</option><option value="upi">UPI</option><option value="card">Card reversal</option><?php if ($s['customer_id']): ?><option value="credit">Store credit / reduce dues</option><?php endif; ?></select></div>
    <div class="field"><label for="refundRef">Refund reference</label><input class="input" id="refundRef" maxlength="40"></div>
  </div>
  <div class="grid g3" id="extraPay" style="display:none">
    <div class="field"><label for="payMethod">Customer pays balance by</label><select id="payMethod"><?php foreach ($config['methods'] as $m): if ($m === 'credit' && !$s['customer_id']) continue; ?><option value="<?= e($m) ?>"><?= e(ucfirst($m)) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label for="payAmount">Amount received (₹)</label><input class="input num" id="payAmount" inputmode="decimal"></div>
    <div class="field"><label for="payRef">Reference</label><input class="input" id="payRef" maxlength="40"></div>
  </div>
  <div class="alert alert-info" id="retSummary"><?= icon('info') ?><div>Enter quantities to see the credit value.</div></div>
  <div class="form-actions"><button class="btn btn-primary btn-lg" id="retSubmit">Confirm return / exchange</button><a class="btn" href="<?= e(url('/returns')) ?>">Cancel</a><span class="muted small">Values are worked out from the original bill (including its discounts and GST).</span></div>
</div>
</form>
<div class="modal-backdrop" id="mApprove"><form class="modal modal-sm" id="approveForm" autocomplete="off">
  <div class="modal-head"><?= icon('shield') ?><h2>Manager approval</h2></div>
  <div class="modal-body"><p id="approveWhy" class="small"></p>
    <div class="field"><label for="apUser">Manager username</label><input class="input" id="apUser" required></div>
    <div class="field"><label for="apPass">Password</label><input class="input" type="password" id="apPass" required></div>
    <div class="alert alert-error" id="approveError" style="display:none"></div></div>
  <div class="modal-foot"><button class="btn" type="button" data-modal-close>Cancel</button><button class="btn btn-primary">Approve</button></div>
</form></div>
