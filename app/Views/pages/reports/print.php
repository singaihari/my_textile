<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title><?= e($report['title']) ?></title><link rel="stylesheet" href="<?= asset('css/app.css') ?>">
<style>body{background:#fff}.content{max-width:none}.table th{position:static}</style></head>
<body><div class="content">
  <div class="row-between no-print" style="margin-bottom:12px"><span class="muted">Print preview</span><button class="btn btn-primary" type="button" data-print>Print</button></div>
  <h1><?= e(setting('shop_name')) ?> — <?= e($report['title']) ?></h1>
  <p class="muted"><?= e(fmt_date($report['from'], false)) ?> to <?= e(fmt_date($report['to'], false)) ?> · generated <?= e(fmt_date(now())) ?><?= setting('demo_mode') === '1' ? ' · DEMO DATA' : '' ?></p>
  <?php if (!empty($report['note'])): ?><p class="small"><?= e($report['note']) ?></p><?php endif; ?>
  <?= App\Core\View::partial('pages/reports/_table', ['report' => $report]) ?>
</div><script src="<?= asset('js/receipt.js') ?>"></script></body></html>
