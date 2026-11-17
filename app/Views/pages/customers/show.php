<?php use App\Core\Money; ?>
<div class="grid g-main">
<div class="stack">
  <?php if ($history !== null): ?>
  <div class="card"><div class="card-head"><h3>Purchase history</h3></div>
    <?php if (!$history): ?><div class="empty small">No bills yet.</div><?php else: ?><table class="table"><thead><tr><th>Invoice</th><th>Date</th><th class="num">Items</th><th class="num">Total</th><th></th></tr></thead><tbody>
    <?php foreach ($history as $h): ?><tr class="row-link" data-href="<?= e(url('/sales/' . $h['id'])) ?>" tabindex="0"><td class="mono"><?= e($h['invoice_no']) ?></td><td><?= e(fmt_date($h['created_at'])) ?></td><td class="num"><?= (int)$h['items_count'] ?></td><td class="num"><?= money($h['total_paise']) ?></td><td><?= $h['status'] === 'voided' ? '<span class="badge badge-danger">voided</span>' : '' ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?></div>
  <div class="card"><div class="card-head"><h3>Returns</h3></div>
    <?php if (!$returns): ?><div class="empty small">No returns.</div><?php else: ?><table class="table table-compact"><tbody><?php foreach ($returns as $r): ?><tr><td class="mono"><a href="<?= e(url('/returns/' . $r['id'])) ?>"><?= e($r['return_no']) ?></a></td><td><?= e($r['invoice_no']) ?></td><td><?= e($r['reason']) ?></td><td class="num"><?= money($r['total_paise']) ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?></div>
  <?php else: ?><div class="card card-pad muted">You do not have permission to view purchase history.</div><?php endif; ?>
</div>
<div class="stack">
  <?php if (can('customer.manage')): ?>
  <form class="card card-pad" method="post" action="<?= e(url('/customers/save')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= e($c['id']) ?>">
    <h3>Details</h3>
    <div class="field"><label>Name *</label><input class="input" name="name" value="<?= e($c['name']) ?>" required></div>
    <div class="field"><label>Mobile</label><input class="input" name="phone" value="<?= e($c['phone']) ?>"></div>
    <div class="field"><label>Email</label><input class="input" name="email" value="<?= e($c['email']) ?>"></div>
    <div class="field"><label>GSTIN</label><input class="input mono" name="gstin" value="<?= e($c['gstin']) ?>" maxlength="15"></div>
    <div class="field"><label>Address</label><textarea name="address" rows="2"><?= e($c['address']) ?></textarea></div>
    <?php if (can('pos.credit_sale')): ?><div class="field"><label>Credit limit ₹</label><input class="input num" name="credit_limit" value="<?= e(Money::toDecimal((int)$c['credit_limit_paise'])) ?>"></div><?php endif; ?>
    <div class="field"><label>Notes</label><input class="input" name="notes" value="<?= e($c['notes']) ?>"></div>
    <label class="check"><input type="checkbox" name="consent_marketing" value="1" <?= (int)$c['consent_marketing'] ? 'checked' : '' ?>> Agrees to receive offers</label>
    <div class="form-actions"><button class="btn btn-primary">Save</button></div>
  </form><?php else: ?><div class="card card-pad"><h3><?= e($c['name']) ?></h3><p class="mono"><?= e($c['phone']) ?></p></div><?php endif; ?>
  <div class="card card-pad"><h3>Credit balance</h3><div class="stat-value" style="color:<?= $balance > 0 ? 'var(--warning)' : 'inherit' ?>"><?= money($balance) ?></div><p class="hint">Credit (pay-later) bills minus payments received.</p>
    <?php if ($balance > 0 && can('customer.receive_payment')): ?><form method="post" action="<?= e(url('/customers/' . $c['id'] . '/payment')) ?>" data-once><?= csrf_field() ?>
      <div class="grid g2"><div class="field"><label>Amount ₹</label><input class="input num" name="amount" value="<?= e(Money::toDecimal($balance)) ?>" required></div><div class="field"><label>Method</label><select name="method"><option value="cash">Cash</option><option value="upi">UPI</option><option value="card">Card</option></select></div></div>
      <div class="field"><label>Reference</label><input class="input" name="reference"></div><button class="btn btn-primary">Receive payment</button></form><?php endif; ?>
    <?php if ($receipts): ?><ul class="list-plain small" style="margin-top:12px"><?php foreach ($receipts as $r): ?><li><?= e(fmt_date($r['created_at'])) ?> · <?= e($r['method']) ?> <strong style="margin-left:auto"><?= money($r['amount_paise']) ?></strong></li><?php endforeach; ?></ul><?php endif; ?>
  </div>
  <?php if (can('settings.manage') && !$c['anonymized_at']): ?>
  <form class="card card-pad" method="post" action="<?= e(url('/customers/' . $c['id'] . '/anonymize')) ?>"><?= csrf_field() ?>
    <h3>Privacy: delete personal data</h3><p class="small muted">Removes name, phone, email and address on the customer's request. Bills are kept for tax records (B2B bills keep the GSTIN snapshot).</p>
    <div class="field"><label>Type DELETE to confirm</label><input class="input" name="confirm" autocomplete="off"></div><button class="btn btn-danger">Delete personal data</button></form><?php endif; ?>
</div>
</div>
