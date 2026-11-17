<div class="auth">
  <section class="auth-art" aria-hidden="true">
    <div class="brand" style="padding:0"><div class="brand-mark" style="background:#fff;color:#1E3A8A">TX</div><div><div class="brand-name" style="color:#fff"><?= e(setting('shop_name')) ?></div><div class="brand-sub" style="color:#C7D2FE">Textile POS &amp; Inventory</div></div></div>
    <div>
      <h2>Fast, reliable billing — even without internet.</h2>
      <ul>
        <li><?= icon('check') ?> Every bill is saved on this PC first</li>
        <li><?= icon('check') ?> Scan, pay and print using only the keyboard</li>
        <li><?= icon('check') ?> Upload to the cloud only when you click <strong>Sync Now</strong></li>
      </ul>
    </div>
    <div class="small" style="color:#A5B4FC">v<?= e(APP_VERSION) ?></div>
  </section>
  <section class="auth-form">
    <form class="auth-card" method="post" action="<?= e(url('/login')) ?>" autocomplete="off">
      <div class="brand"><div class="brand-mark">TX</div><div><div class="brand-name">Sign in</div><div class="brand-sub"><?= e(setting('shop_name')) ?></div></div></div>
      <?php if (!empty($noAccounts)): ?>
        <div class="alert alert-warning"><?= icon('info') ?><div><strong>No admin account yet.</strong> On this PC, open PowerShell in the app folder and run:<br>
          <span class="mono">php scripts\create-admin.php</span><br>Then sign in here with the username and password you chose.</div></div>
      <?php endif; ?>
      <?= csrf_field() ?>
      <input type="hidden" name="next" value="<?= e($next ?? '/') ?>">
      <div class="field"><label for="username">Username</label><input class="input" id="username" name="username" value="<?= e(old('username')) ?>" required autofocus autocapitalize="none"></div>
      <div class="field"><label for="password">Password</label><input class="input" id="password" type="password" name="password" required></div>
      <button class="btn btn-primary btn-lg" style="width:100%">Sign in</button>
      <p class="hint" style="margin-top:16px">Forgot your password? Ask the shop admin to reset it from Staff &amp; roles. After <?= App\Core\Auth::MAX_FAILED ?> wrong attempts the account is paused for <?= App\Core\Auth::LOCKOUT_MINUTES ?> minutes.</p>
    </form>
  </section>
</div>
