<?php
/** @var array $page */
$q = request()->allQuery();
$link = function (int $p) use ($q) { $q['page'] = $p; return e(url(request()->path, $q)); };
?>
<div class="pager">
  <span>Showing <?= $page['total'] ? (($page['page'] - 1) * $page['per_page'] + 1) : 0 ?>–<?= min($page['total'], $page['page'] * $page['per_page']) ?> of <?= number_format($page['total']) ?></span>
  <span class="row">
    <?php if ($page['page'] > 1): ?><a class="btn btn-sm" href="<?= $link($page['page'] - 1) ?>">← Prev</a><?php endif; ?>
    <span>Page <?= $page['page'] ?> / <?= $page['pages'] ?></span>
    <?php if ($page['page'] < $page['pages']): ?><a class="btn btn-sm" href="<?= $link($page['page'] + 1) ?>">Next →</a><?php endif; ?>
  </span>
</div>
