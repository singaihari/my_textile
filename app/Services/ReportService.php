<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Context;
use App\Core\Database;
use App\Core\ValidationException;

/**
 * All reports are SQL over saved transactions — never hardcoded figures.
 *
 * Definitions used consistently across reports and the dashboard:
 *   Sales          = bills with status 'completed' (voided bills are excluded entirely, as are their 'void' credit notes).
 *   Net sales      = Σ sale_items.taxable (excl. GST) − Σ return_items.taxable of non-void returns, by document date.
 *   COGS           = Σ sale_items.cogs (cost_at_sale × qty) − Σ return_items.cogs (reversal).
 *   Gross profit   = Net sales − COGS.
 *   Net operating profit = Gross profit + round-off − net stock adjustment loss − approved operating expenses.
 */
final class ReportService
{
    public const REPORTS = [
        'sales_summary' => ['Sales summary', 'report.sales'],
        'hourly' => ['Peak hours', 'report.sales'],
        'weekday' => ['Day of week', 'report.sales'],
        'by_product' => ['Sales by product', 'report.sales'],
        'by_variant' => ['Sales by SKU (size/colour)', 'report.sales'],
        'by_size' => ['Sales by size', 'report.sales'],
        'by_color' => ['Sales by colour', 'report.sales'],
        'by_category' => ['Sales by category', 'report.sales'],
        'by_brand' => ['Sales by brand', 'report.sales'],
        'by_cashier' => ['Sales by cashier / counter', 'report.sales'],
        'payments' => ['Payments & refunds by method', 'report.sales'],
        'discounts' => ['Discounts given', 'report.sales'],
        'returns' => ['Returns & exchanges', 'report.sales'],
        'slow_movers' => ['Slow / dead stock & ageing', 'report.stock'],
        'purchases' => ['Purchases by supplier', 'report.stock'],
        'stock_movements' => ['Stock in / out', 'report.stock'],
        'low_stock' => ['Low stock', 'report.stock'],
        'valuation' => ['Stock valuation (weighted average cost)', 'report.stock'],
        'expenses' => ['Expenses by category', 'report.profit'],
        'profit' => ['Profit & loss (gross margin and net operating profit)', 'report.profit'],
    ];

    public function __construct(private Database $db)
    {
    }

    public static function range(?string $from, ?string $to): array
    {
        $re = '/^\d{4}-\d{2}-\d{2}$/';
        $from = $from && preg_match($re, $from) ? $from : date('Y-m-01');
        $to = $to && preg_match($re, $to) ? $to : today();
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        return [$from, $to, $from . ' 00:00:00', $to . ' 23:59:59'];
    }

    public function run(string $key, string $from, string $to, array $opt = []): array
    {
        if (!isset(self::REPORTS[$key])) {
            throw new ValidationException('Unknown report');
        }
        [$f, $t, $fs, $ts] = self::range($from, $to);
        $method = 'r_' . $key;
        $r = $this->$method($fs, $ts, $opt);
        $r['key'] = $key;
        $r['title'] = self::REPORTS[$key][0];
        $r['from'] = $f;
        $r['to'] = $t;
        $r['totals'] ??= $this->totals($r['columns'], $r['rows']);
        return $r;
    }

    private function totals(array $cols, array $rows): array
    {
        $t = [];
        foreach ($cols as $k => [$label, $type]) {
            if (in_array($type, ['money', 'qty', 'int'], true) && !str_ends_with($k, '_avg') && !in_array($k, ['stock', 'reorder', 'unit_cost', 'avg_cost', 'days'], true)) {
                $t[$k] = array_sum(array_map(fn($r) => (int)($r[$k] ?? 0), $rows));
            }
        }
        return $t;
    }

    // ------------------------------------------------------------------ sales
    private function r_sales_summary(string $fs, string $ts, array $o): array
    {
        $g = $o['group'] ?? 'day';
        $expr = match ($g) {
            'week' => "strftime('%Y-W%W', s.created_at)",
            'month' => "strftime('%Y-%m', s.created_at)",
            'year' => "strftime('%Y', s.created_at)",
            default => 'date(s.created_at)',
        };
        $rows = $this->db->all("SELECT $expr AS period, COUNT(*) bills, SUM(s.qty_total_milli) qty, SUM(s.gross_paise) gross,
                SUM(s.line_discount_paise + s.bill_discount_paise) discount, SUM(s.taxable_paise) taxable, SUM(s.tax_paise) tax,
                SUM(s.total_paise) total, SUM(s.cogs_paise) cogs, SUM(s.taxable_paise) - SUM(s.cogs_paise) margin
            FROM sales s WHERE s.status = 'completed' AND s.created_at BETWEEN ? AND ? GROUP BY period ORDER BY period", [$fs, $ts]);
        $ret = $this->db->all("SELECT " . str_replace('s.', 'r.', $expr) . " AS period, SUM(r.taxable_paise) rt, SUM(r.total_paise) rtot
            FROM returns r WHERE r.return_type <> 'void' AND r.created_at BETWEEN ? AND ? GROUP BY period", [$fs, $ts]);
        $rmap = array_column($ret, null, 'period');
        foreach ($rows as &$r) {
            $r['returns'] = (int)($rmap[$r['period']]['rtot'] ?? 0);
            $r['net_sales'] = (int)$r['taxable'] - (int)($rmap[$r['period']]['rt'] ?? 0);
            $r['avg_bill'] = $r['bills'] ? intdiv((int)$r['total'], (int)$r['bills']) : 0;
        }
        unset($r);
        $cols = ['period' => ['Period', 'text'], 'bills' => ['Bills', 'int'], 'qty' => ['Qty', 'qty'], 'gross' => ['Gross', 'money'],
            'discount' => ['Discount', 'money'], 'taxable' => ['Taxable', 'money'], 'tax' => ['GST', 'money'], 'total' => ['Bill total', 'money'],
            'returns' => ['Returns', 'money'], 'net_sales' => ['Net sales (ex-GST)', 'money'], 'avg_bill' => ['Avg bill', 'money']];
        if (\App\Core\Auth::can('report.profit')) {
            $cols['margin'] = ['Gross margin*', 'money'];
        }
        return ['columns' => $cols, 'rows' => $rows, 'chart' => ['type' => 'bar', 'label' => 'period', 'value' => 'total'],
            'note' => 'Net sales = taxable value of completed bills minus taxable value of returns, by document date. *Margin before returns.'];
    }

    private function r_hourly(string $fs, string $ts): array
    {
        $rows = $this->db->all("SELECT CAST(strftime('%H', created_at) AS INTEGER) AS hour, COUNT(*) bills, SUM(total_paise) total
            FROM sales WHERE status = 'completed' AND created_at BETWEEN ? AND ? GROUP BY hour ORDER BY hour", [$fs, $ts]);
        foreach ($rows as &$r) {
            $h = (int)$r['hour'];
            $r['slot'] = sprintf('%02d:00–%02d:59', $h, $h);
        }
        unset($r);
        return ['columns' => ['slot' => ['Hour', 'text'], 'bills' => ['Bills', 'int'], 'total' => ['Sales', 'money']], 'rows' => $rows,
            'chart' => ['type' => 'bar', 'label' => 'slot', 'value' => 'total']];
    }

    private function r_weekday(string $fs, string $ts): array
    {
        $names = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        $rows = $this->db->all("SELECT CAST(strftime('%w', created_at) AS INTEGER) AS dow, COUNT(*) bills, SUM(total_paise) total,
            COUNT(DISTINCT date(created_at)) days FROM sales WHERE status = 'completed' AND created_at BETWEEN ? AND ? GROUP BY dow ORDER BY dow", [$fs, $ts]);
        foreach ($rows as &$r) {
            $r['day'] = $names[(int)$r['dow']];
            $r['per_day_avg'] = $r['days'] ? intdiv((int)$r['total'], (int)$r['days']) : 0;
        }
        unset($r);
        return ['columns' => ['day' => ['Day', 'text'], 'bills' => ['Bills', 'int'], 'total' => ['Sales', 'money'], 'per_day_avg' => ['Avg per day', 'money']],
            'rows' => $rows, 'chart' => ['type' => 'bar', 'label' => 'day', 'value' => 'total']];
    }

    private function dimension(string $fs, string $ts, string $select, string $join, string $group, string $label, array $o): array
    {
        $order = ($o['sort'] ?? '') === 'qty' ? 'qty DESC' : 'net DESC';
        $rows = $this->db->all("SELECT $select, SUM(si.qty_milli) qty, SUM(si.returned_qty_milli) returned_qty, SUM(si.gross_paise) gross,
                SUM(si.line_discount_paise + si.bill_discount_alloc_paise) discount, SUM(si.taxable_paise) net, SUM(si.tax_paise) tax,
                SUM(si.line_total_paise) total, SUM(si.cogs_paise) cogs, SUM(si.taxable_paise - si.cogs_paise) margin, COUNT(DISTINCT s.id) bills
            FROM sale_items si JOIN sales s ON s.id = si.sale_id $join
            WHERE s.status = 'completed' AND s.created_at BETWEEN ? AND ? GROUP BY $group ORDER BY $order LIMIT 500", [$fs, $ts]);
        $cols = [$label => [ucfirst(str_replace('_', ' ', $label)), 'text'], 'bills' => ['Bills', 'int'], 'qty' => ['Qty sold', 'qty'],
            'returned_qty' => ['Returned', 'qty'], 'discount' => ['Discount', 'money'], 'net' => ['Net (ex-GST)', 'money'],
            'tax' => ['GST', 'money'], 'total' => ['Total', 'money']];
        if (\App\Core\Auth::can('report.profit')) {
            $cols['cogs'] = ['COGS', 'money'];
            $cols['margin'] = ['Gross margin', 'money'];
        }
        return ['columns' => $cols, 'rows' => $rows, 'chart' => ['type' => 'bar', 'label' => $label, 'value' => 'net', 'limit' => 15],
            'note' => 'Quantities: 1 = one piece or one metre. "Returned" counts returns made so far against these bills.'];
    }

    private function r_by_product(string $fs, string $ts, array $o): array
    {
        return $this->dimension($fs, $ts, 'si.product_name AS product', '', 'si.product_id', 'product', $o);
    }

    private function r_by_variant(string $fs, string $ts, array $o): array
    {
        return $this->dimension($fs, $ts, "si.sku || '  ' || si.product_name || IFNULL(' (' || si.variant_label || ')', '') AS sku", '', 'si.variant_id', 'sku', $o);
    }

    private function r_by_size(string $fs, string $ts, array $o): array
    {
        return $this->dimension($fs, $ts, "IFNULL(sz.label, '(none)') AS size", 'JOIN product_variants v ON v.id = si.variant_id LEFT JOIN sizes sz ON sz.id = v.size_id', 'sz.label', 'size', $o);
    }

    private function r_by_color(string $fs, string $ts, array $o): array
    {
        return $this->dimension($fs, $ts, "IFNULL(c.name, '(none)') AS colour", 'JOIN product_variants v ON v.id = si.variant_id LEFT JOIN colors c ON c.id = v.color_id', 'c.name', 'colour', $o);
    }

    private function r_by_category(string $fs, string $ts, array $o): array
    {
        return $this->dimension($fs, $ts, "IFNULL(c.name, '(uncategorised)') AS category", 'JOIN products p ON p.id = si.product_id LEFT JOIN categories c ON c.id = p.category_id', 'c.name', 'category', $o);
    }

    private function r_by_brand(string $fs, string $ts, array $o): array
    {
        return $this->dimension($fs, $ts, "IFNULL(b.name, '(no brand)') AS brand", 'JOIN products p ON p.id = si.product_id LEFT JOIN brands b ON b.id = p.brand_id', 'b.name', 'brand', $o);
    }

    private function r_by_cashier(string $fs, string $ts, array $o): array
    {
        return $this->dimension($fs, $ts, "u.full_name || ' @ ' || d.code AS cashier", 'JOIN users u ON u.id = s.cashier_id JOIN devices d ON d.id = s.device_id', 's.cashier_id, s.device_id', 'cashier', $o);
    }

    private function r_payments(string $fs, string $ts): array
    {
        $in = $this->db->all("SELECT p.method, COUNT(DISTINCT p.sale_id) bills, SUM(p.amount_paise) received FROM payments p JOIN sales s ON s.id = p.sale_id
            WHERE s.status = 'completed' AND p.created_at BETWEEN ? AND ? GROUP BY p.method", [$fs, $ts]);
        $out = $this->db->all("SELECT f.method, SUM(f.amount_paise) refunded FROM refunds f JOIN returns r ON r.id = f.return_id
            WHERE r.return_type <> 'void' AND f.created_at BETWEEN ? AND ? GROUP BY f.method", [$fs, $ts]);
        $rec = $this->db->all('SELECT method, SUM(amount_paise) receipts FROM customer_receipts WHERE created_at BETWEEN ? AND ? GROUP BY method', [$fs, $ts]);
        $m = [];
        foreach ($in as $r) {
            $m[$r['method']] = ['method' => $r['method'], 'bills' => (int)$r['bills'], 'received' => (int)$r['received'], 'refunded' => 0, 'receipts' => 0];
        }
        foreach ($out as $r) {
            $m[$r['method']] ??= ['method' => $r['method'], 'bills' => 0, 'received' => 0, 'refunded' => 0, 'receipts' => 0];
            $m[$r['method']]['refunded'] = (int)$r['refunded'];
        }
        foreach ($rec as $r) {
            $m[$r['method']] ??= ['method' => $r['method'], 'bills' => 0, 'received' => 0, 'refunded' => 0, 'receipts' => 0];
            $m[$r['method']]['receipts'] = (int)$r['receipts'];
        }
        foreach ($m as &$r) {
            $r['net'] = $r['received'] + $r['receipts'] - $r['refunded'];
            $r['method'] = self::methodLabel($r['method']);
        }
        unset($r);
        return ['columns' => ['method' => ['Method', 'text'], 'bills' => ['Bills', 'int'], 'received' => ['Received on bills', 'money'],
            'receipts' => ['Credit receipts', 'money'], 'refunded' => ['Refunded', 'money'], 'net' => ['Net', 'money']],
            'rows' => array_values($m), 'chart' => ['type' => 'doughnut', 'label' => 'method', 'value' => 'received'],
            'note' => '"Exchange credit" is value carried over from returned goods, not new money. "Credit" is pay-later (outstanding until received).'];
    }

    public static function methodLabel(string $m): string
    {
        return ['cash' => 'Cash', 'upi' => 'UPI', 'card' => 'Card', 'credit' => 'Credit (pay later)', 'exchange_credit' => 'Exchange credit', 'other' => 'Other', 'bank' => 'Bank'][$m] ?? $m;
    }

    private function r_discounts(string $fs, string $ts): array
    {
        $rows = $this->db->all("SELECT s.invoice_no, s.created_at AS date, u.full_name AS cashier, IFNULL(a.full_name, '') AS approved_by,
                s.gross_paise AS gross, s.line_discount_paise AS line_disc, s.bill_discount_paise AS bill_disc,
                (s.line_discount_paise + s.bill_discount_paise) AS discount, s.total_paise AS total
            FROM sales s JOIN users u ON u.id = s.cashier_id LEFT JOIN users a ON a.id = s.approved_by
            WHERE s.status = 'completed' AND (s.line_discount_paise + s.bill_discount_paise) > 0 AND s.created_at BETWEEN ? AND ?
            ORDER BY discount DESC LIMIT 1000", [$fs, $ts]);
        return ['columns' => ['invoice_no' => ['Invoice', 'text'], 'date' => ['Date', 'date'], 'cashier' => ['Cashier', 'text'],
            'approved_by' => ['Approved by', 'text'], 'gross' => ['Gross', 'money'], 'line_disc' => ['Item disc', 'money'],
            'bill_disc' => ['Bill disc', 'money'], 'discount' => ['Total disc', 'money'], 'total' => ['Bill total', 'money']], 'rows' => $rows];
    }

    private function r_returns(string $fs, string $ts): array
    {
        $rows = $this->db->all("SELECT r.return_no, r.created_at AS date, s.invoice_no, r.return_type AS type, r.reason, u.full_name AS staff,
                r.total_paise AS credit, r.refund_paise AS refunded, r.exchange_credit_paise AS exchanged, IFNULL(e.difference_paise, 0) AS exch_diff,
                (SELECT SUM(qty_milli) FROM return_items WHERE return_id = r.id) AS qty,
                (SELECT COUNT(*) FROM return_items WHERE return_id = r.id AND item_condition = 'damaged') AS damaged_lines
            FROM returns r JOIN sales s ON s.id = r.original_sale_id JOIN users u ON u.id = r.created_by LEFT JOIN exchanges e ON e.return_id = r.id
            WHERE r.created_at BETWEEN ? AND ? ORDER BY r.created_at DESC LIMIT 1000", [$fs, $ts]);
        return ['columns' => ['return_no' => ['Credit note', 'text'], 'date' => ['Date', 'date'], 'invoice_no' => ['Original bill', 'text'],
            'type' => ['Type', 'text'], 'reason' => ['Reason', 'text'], 'staff' => ['Staff', 'text'], 'qty' => ['Qty', 'qty'],
            'damaged_lines' => ['Damaged lines', 'int'], 'credit' => ['Credit value', 'money'], 'refunded' => ['Refunded', 'money'],
            'exchanged' => ['Used in exchange', 'money'], 'exch_diff' => ['Exchange difference', 'money']], 'rows' => $rows,
            'note' => 'Type "void" = full cancellation of a bill (excluded from sales and net-sales figures).'];
    }

    // ------------------------------------------------------------------ stock
    private function r_slow_movers(string $fs, string $ts, array $o): array
    {
        $loc = Context::locationId('MAIN');
        $rows = $this->db->all("SELECT v.sku, p.name AS product, p.unit, sb.qty_milli AS stock, sb.avg_cost_paise AS avg_cost,
                (sb.qty_milli * sb.avg_cost_paise / 1000) AS value, sb.last_in_at, sb.last_out_at,
                IFNULL((SELECT SUM(si.qty_milli) FROM sale_items si JOIN sales s ON s.id = si.sale_id WHERE si.variant_id = v.id AND s.status = 'completed' AND s.created_at BETWEEN ? AND ?), 0) AS sold,
                CAST(julianday('now','localtime') - julianday(IFNULL(sb.last_in_at, v.created_at)) AS INTEGER) AS days
            FROM stock_balances sb JOIN product_variants v ON v.id = sb.variant_id JOIN products p ON p.id = v.product_id
            WHERE sb.location_id = ? AND sb.qty_milli > 0 ORDER BY sold ASC, days DESC LIMIT 500", [$fs, $ts, $loc]);
        foreach ($rows as &$r) {
            $d = (int)$r['days'];
            $r['age_bucket'] = $d <= 30 ? '0–30 days' : ($d <= 90 ? '31–90 days' : ($d <= 180 ? '91–180 days' : '180+ days'));
            $r['status'] = (int)$r['sold'] === 0 ? 'No sales in period' : 'Slow';
        }
        unset($r);
        $cols = ['sku' => ['SKU', 'text'], 'product' => ['Product', 'text'], 'stock' => ['In stock', 'qty'], 'sold' => ['Sold in period', 'qty'],
            'days' => ['Days since last stock-in', 'int'], 'age_bucket' => ['Age', 'text'], 'status' => ['Status', 'text'], 'last_out_at' => ['Last sale', 'date']];
        if (\App\Core\Auth::can('product.view_cost')) {
            $cols['value'] = ['Stock value', 'money'];
        }
        return ['columns' => $cols, 'rows' => $rows];
    }

    private function r_purchases(string $fs, string $ts): array
    {
        $rows = $this->db->all("SELECT sp.name AS supplier, COUNT(*) invoices, SUM(p.subtotal_paise) subtotal, SUM(p.tax_paise) tax,
                SUM(p.freight_paise) freight, SUM(p.total_paise) total, SUM(p.amount_paid_paise) paid, SUM(p.total_paise - p.amount_paid_paise) balance
            FROM purchases p JOIN suppliers sp ON sp.id = p.supplier_id WHERE p.status = 'posted' AND p.posted_at BETWEEN ? AND ?
            GROUP BY sp.id ORDER BY total DESC", [$fs, $ts]);
        return ['columns' => ['supplier' => ['Supplier', 'text'], 'invoices' => ['Invoices', 'int'], 'subtotal' => ['Goods value', 'money'],
            'tax' => ['Input tax', 'money'], 'freight' => ['Freight', 'money'], 'total' => ['Total', 'money'], 'paid' => ['Paid', 'money'],
            'balance' => ['Balance', 'money']], 'rows' => $rows];
    }

    private function r_stock_movements(string $fs, string $ts): array
    {
        $rows = $this->db->all("SELECT m.movement_type AS type, l.code AS location, COUNT(*) entries,
                SUM(CASE WHEN m.qty_milli > 0 THEN m.qty_milli ELSE 0 END) AS qty_in, SUM(CASE WHEN m.qty_milli < 0 THEN -m.qty_milli ELSE 0 END) AS qty_out,
                SUM(m.value_paise) AS value
            FROM stock_movements m JOIN locations l ON l.id = m.location_id WHERE m.created_at BETWEEN ? AND ? GROUP BY m.movement_type, l.code ORDER BY type", [$fs, $ts]);
        $cols = ['type' => ['Movement', 'text'], 'location' => ['Location', 'text'], 'entries' => ['Entries', 'int'], 'qty_in' => ['Qty in', 'qty'], 'qty_out' => ['Qty out', 'qty']];
        if (\App\Core\Auth::can('product.view_cost')) {
            $cols['value'] = ['Value at cost (±)', 'money'];
        }
        return ['columns' => $cols, 'rows' => $rows, 'note' => 'Every stock change is an immutable movement. Quantities mix pieces and metres by movement type.'];
    }

    private function r_low_stock(): array
    {
        $loc = Context::locationId('MAIN');
        $rows = $this->db->all("SELECT v.sku, p.name AS product, IFNULL(sz.label,'') AS size, IFNULL(c.name,'') AS colour, p.unit,
                IFNULL(sb.qty_milli, 0) AS stock, COALESCE(v.reorder_level_milli, p.reorder_level_milli) AS reorder
            FROM product_variants v JOIN products p ON p.id = v.product_id
            LEFT JOIN stock_balances sb ON sb.variant_id = v.id AND sb.location_id = ?
            LEFT JOIN sizes sz ON sz.id = v.size_id LEFT JOIN colors c ON c.id = v.color_id
            WHERE p.status = 'active' AND v.status = 'active' AND IFNULL(sb.qty_milli, 0) <= COALESCE(v.reorder_level_milli, p.reorder_level_milli)
              AND COALESCE(v.reorder_level_milli, p.reorder_level_milli) > 0
            ORDER BY (IFNULL(sb.qty_milli,0) * 1.0 / COALESCE(v.reorder_level_milli, p.reorder_level_milli)) ASC LIMIT 1000", [$loc]);
        return ['columns' => ['sku' => ['SKU', 'text'], 'product' => ['Product', 'text'], 'size' => ['Size', 'text'], 'colour' => ['Colour', 'text'],
            'stock' => ['In stock', 'qty'], 'reorder' => ['Reorder level', 'qty']], 'rows' => $rows];
    }

    private function r_valuation(): array
    {
        $rows = $this->db->all("SELECT IFNULL(c.name,'(uncategorised)') AS category, l.code AS location, COUNT(*) skus,
                SUM(sb.qty_milli) AS qty, SUM(sb.qty_milli * sb.avg_cost_paise / 1000) AS cost_value,
                SUM(sb.qty_milli * v.price_paise / 1000) AS retail_value
            FROM stock_balances sb JOIN product_variants v ON v.id = sb.variant_id JOIN products p ON p.id = v.product_id
            JOIN locations l ON l.id = sb.location_id LEFT JOIN categories c ON c.id = p.category_id
            WHERE sb.qty_milli > 0 GROUP BY c.name, l.code ORDER BY l.code DESC, cost_value DESC");
        return ['columns' => ['category' => ['Category', 'text'], 'location' => ['Location', 'text'], 'skus' => ['SKUs', 'int'],
            'qty' => ['Qty', 'qty'], 'cost_value' => ['Value at avg cost', 'money'], 'retail_value' => ['Value at selling price', 'money']],
            'rows' => $rows, 'chart' => ['type' => 'doughnut', 'label' => 'category', 'value' => 'cost_value'],
            'note' => 'Cost method: moving weighted-average cost per SKU (landed cost incl. freight; input GST excluded when claimable). DAMAGED stock is not sellable.'];
    }

    // ------------------------------------------------------------------ finance
    private function r_expenses(string $fs, string $ts): array
    {
        $f = substr($fs, 0, 10);
        $t = substr($ts, 0, 10);
        $rows = $this->db->all("SELECT c.name AS category, COUNT(*) entries, SUM(e.amount_paise) amount FROM expenses e JOIN expense_categories c ON c.id = e.category_id
            WHERE e.status = 'approved' AND e.expense_date BETWEEN ? AND ? GROUP BY c.id ORDER BY amount DESC", [$f, $t]);
        return ['columns' => ['category' => ['Category', 'text'], 'entries' => ['Entries', 'int'], 'amount' => ['Amount', 'money']], 'rows' => $rows,
            'chart' => ['type' => 'doughnut', 'label' => 'category', 'value' => 'amount']];
    }

    public function profit(string $fs, string $ts): array
    {
        $v = fn(string $sql, array $p) => (int)$this->db->value($sql, $p);
        $salesTaxable = $v("SELECT IFNULL(SUM(taxable_paise),0) FROM sales WHERE status = 'completed' AND created_at BETWEEN ? AND ?", [$fs, $ts]);
        $salesTax = $v("SELECT IFNULL(SUM(tax_paise),0) FROM sales WHERE status = 'completed' AND created_at BETWEEN ? AND ?", [$fs, $ts]);
        $discounts = $v("SELECT IFNULL(SUM(line_discount_paise + bill_discount_paise),0) FROM sales WHERE status = 'completed' AND created_at BETWEEN ? AND ?", [$fs, $ts]);
        $salesCogs = $v("SELECT IFNULL(SUM(cogs_paise),0) FROM sales WHERE status = 'completed' AND created_at BETWEEN ? AND ?", [$fs, $ts]);
        $salesRound = $v("SELECT IFNULL(SUM(round_off_paise),0) FROM sales WHERE status = 'completed' AND created_at BETWEEN ? AND ?", [$fs, $ts]);
        $retTaxable = $v("SELECT IFNULL(SUM(taxable_paise),0) FROM returns WHERE return_type <> 'void' AND created_at BETWEEN ? AND ?", [$fs, $ts]);
        $retTax = $v("SELECT IFNULL(SUM(tax_paise),0) FROM returns WHERE return_type <> 'void' AND created_at BETWEEN ? AND ?", [$fs, $ts]);
        $retCogs = $v("SELECT IFNULL(SUM(cogs_reversed_paise),0) FROM returns WHERE return_type <> 'void' AND created_at BETWEEN ? AND ?", [$fs, $ts]);
        $retRound = $v("SELECT IFNULL(SUM(round_off_paise),0) FROM returns WHERE return_type <> 'void' AND created_at BETWEEN ? AND ?", [$fs, $ts]);
        $adjLoss = -$v("SELECT IFNULL(SUM(value_paise),0) FROM stock_movements WHERE movement_type = 'adjustment' AND created_at BETWEEN ? AND ?", [$fs, $ts]);
        $expenses = $v("SELECT IFNULL(SUM(amount_paise),0) FROM expenses WHERE status = 'approved' AND expense_date BETWEEN ? AND ?", [substr($fs, 0, 10), substr($ts, 0, 10)]);
        $netSales = $salesTaxable - $retTaxable;
        $cogs = $salesCogs - $retCogs;
        $gross = $netSales - $cogs;
        $roundOff = $salesRound - $retRound;
        $net = $gross + $roundOff - $adjLoss - $expenses;
        return compact('salesTaxable', 'salesTax', 'discounts', 'salesCogs', 'retTaxable', 'retTax', 'retCogs', 'netSales', 'cogs', 'gross',
            'roundOff', 'adjLoss', 'expenses', 'net') + ['gross_margin_bp' => $netSales ? intdiv($gross * 10000, $netSales) : 0,
            'outputTax' => $salesTax - $retTax];
    }

    private function r_profit(string $fs, string $ts): array
    {
        $p = $this->profit($fs, $ts);
        $rows = [
            ['line' => 'Sales (taxable value, excl. GST, after discounts)', 'amount' => $p['salesTaxable']],
            ['line' => '  less: Returns (taxable value)', 'amount' => -$p['retTaxable']],
            ['line' => '= Net sales', 'amount' => $p['netSales']],
            ['line' => 'Cost of goods sold (cost captured at sale)', 'amount' => -$p['salesCogs']],
            ['line' => '  add back: cost of returned goods', 'amount' => $p['retCogs']],
            ['line' => '= GROSS PROFIT (gross margin ' . bp_pct($p['gross_margin_bp']) . ')', 'amount' => $p['gross']],
            ['line' => 'Bill round-off (net)', 'amount' => $p['roundOff']],
            ['line' => 'Stock adjustments (net loss: damage, shrinkage, corrections)', 'amount' => -$p['adjLoss']],
            ['line' => 'Operating expenses (approved)', 'amount' => -$p['expenses']],
            ['line' => '= NET OPERATING PROFIT (before income tax, depreciation, interest)', 'amount' => $p['net']],
            ['line' => 'For info: GST collected (output tax, net of returns)', 'amount' => $p['outputTax']],
            ['line' => 'For info: discounts given', 'amount' => $p['discounts']],
        ];
        return ['columns' => ['line' => ['', 'text'], 'amount' => ['Amount', 'money']], 'rows' => $rows, 'totals' => [],
            'note' => 'Gross profit is NOT final net profit. Net operating profit only includes expenses recorded in this system. GST collected is a liability, not income. Verify with your accountant.'];
    }
}
