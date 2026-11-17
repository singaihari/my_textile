<!doctype html>
<html lang="<?= e(App\Core\Lang::locale()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<meta name="base-url" content="<?= e(base_path_url()) ?>">
<title>POS · <?= e(setting('shop_name')) ?></title>
<link rel="stylesheet" href="<?= asset('css/app.css') ?>">
<link rel="stylesheet" href="<?= asset('css/pos.css') ?>">
</head>
<body data-pos="1">
<?= $content ?>
<div class="toasts" id="toasts" aria-live="polite"></div>
<script src="<?= asset('js/app.js') ?>"></script>
<script src="<?= asset('js/pos.js') ?>"></script>
</body>
</html>
