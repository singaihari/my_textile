<?php $u = App\Core\Auth::user(); ?>
<div class="auth" style="grid-template-columns:1fr">
  <section class="auth-form">
    <form class="auth-card card card-pad" method="post" action="<?= e(url('/unlock')) ?>">
      <?= csrf_field() ?><input type="hidden" name="next" value="<?= e($next ?? '/') ?>">
      <div class="row" style="margin-bottom:12px"><?= icon('lock', 'icon icon-lg') ?><h2 style="margin:0">Screen locked</h2></div>
      <p class="muted">Signed in as <strong><?= e($u['full_name'] ?? '') ?></strong>. Unsaved POS bills on screen are kept.</p>
      <div class="field"><label for="password">Password</label><input class="input" type="password" id="password" name="password" required autofocus></div>
      <button class="btn btn-primary" style="width:100%">Unlock</button>
    </form>
    </section>
</div>
<form method="post" action="<?= e(url('/logout')) ?>" style="position:fixed;bottom:16px;left:0;right:0;text-align:center"><?= csrf_field() ?><button class="btn btn-ghost btn-sm">Switch user (sign out)</button></form>
