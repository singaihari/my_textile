<?php
$flashes = $_SESSION['_flash'] ?? [];
unset($_SESSION['_flash']);
?><!doctype html>
<html lang="<?= e(App\Core\Lang::locale()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<meta name="base-url" content="<?= e(base_path_url()) ?>">
<title><?= e($title ?? 'Textile POS') ?></title>
<link rel="stylesheet" href="<?= asset('css/app.css') ?>">
</head>
<body>
<?php foreach ($flashes as $f): $t = $f['type'] === 'error' ? 'error' : ($f['type'] === 'warning' ? 'warning' : 'success'); ?>
  <div class="alert alert-<?= $t ?>" role="alert" style="margin:0;border-radius:0"><?= icon('alert') ?><div><?= e($f['message']) ?></div></div>
<?php endforeach; ?>
<?= $content ?>
<script src="<?= asset('js/app.js') ?>"></script>
</body>
</html>
<?php unset($_SESSION['_old'], $_SESSION['_errors']); ?>
