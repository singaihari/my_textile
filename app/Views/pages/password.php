<form class="card" style="max-width:480px" method="post" action="<?= e(url('/account/password')) ?>">
  <?= csrf_field() ?>
  <div class="card-body">
    <div class="field"><label for="current">Current password</label><input class="input" type="password" id="current" name="current" required autofocus></div>
    <div class="field"><label for="password">New password</label><input class="input" type="password" id="password" name="password" required minlength="8"><div class="hint">8+ characters with letters and numbers</div></div>
    <div class="field"><label for="password_confirm">Confirm new password</label><input class="input" type="password" id="password_confirm" name="password_confirm" required></div>
  </div>
  <div class="modal-foot"><button class="btn btn-primary">Change password</button></div>
</form>
