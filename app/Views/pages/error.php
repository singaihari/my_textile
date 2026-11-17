<div class="content" style="max-width:640px;margin:8vh auto">
  <div class="card card-pad">
    <div class="row" style="margin-bottom:8px"><?= icon('alert', 'icon icon-lg') ?><h2 style="margin:0"><?= (int)$status === 403 ? 'Not allowed' : ((int)$status === 404 ? 'Not found' : 'Something went wrong') ?></h2></div>
    <p><?= e($message) ?></p>
    <div class="row"><button class="btn" type="button" data-back>Go back</button><a class="btn btn-primary" href="<?= e(url('/')) ?>">Home</a></div>
  </div>
</div>
