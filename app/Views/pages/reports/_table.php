<?php
use App\Core\Money;
use App\Core\Qty;
$fmt = function ($v, string $type, array $row) {
    return match ($type) {
        'money' => e(Money::format((int)$v)),
        'qty' => e(Qty::format((int)$v, ($row['unit'] ?? '') === 'm' ? 'm' : 'pcs')),
        'int' => number_format((int)$v),
        'date' => e(fmt_date($v)),
        default => e($v),
    };
};
?>
<div class="table-wrap"><table class="table">
  <thead><tr><?php foreach ($report['columns'] as $k => [$label, $type]): ?><th class="<?= in_array($type, ['money', 'qty', 'int'], true) ? 'num' : '' ?>"><?= e($label) ?></th><?php endforeach; ?></tr></thead>
  <tbody>
  <?php if (!$report['rows']): ?><tr><td colspan="<?= count($report['columns']) ?>" class="empty">No data for this period.</td></tr><?php endif; ?>
  <?php foreach ($report['rows'] as $row): $strong = $report['key'] === 'profit' && str_starts_with((string)$row['line'], '='); ?>
    <tr style="<?= $strong ? 'font-weight:700;background:var(--surface-2)' : '' ?>"><?php foreach ($report['columns'] as $k => [$label, $type]): ?><td class="<?= in_array($type, ['money', 'qty', 'int'], true) ? 'num' : '' ?>"><?= $fmt($row[$k] ?? '', $type, $row) ?></td><?php endforeach; ?></tr>
  <?php endforeach; ?>
  </tbody>
  <?php if (!empty($report['totals']) && $report['rows']): ?><tfoot><tr><?php $first = true; foreach ($report['columns'] as $k => [$label, $type]): ?><td class="<?= in_array($type, ['money', 'qty', 'int'], true) ? 'num' : '' ?>"><?= $first ? 'Total' : (isset($report['totals'][$k]) ? ($type === 'qty' ? e(Qty::format((int)$report['totals'][$k], 'pcs')) : $fmt($report['totals'][$k], $type, [])) : '') ?></td><?php $first = false; endforeach; ?></tr></tfoot><?php endif; ?>
</table></div>
