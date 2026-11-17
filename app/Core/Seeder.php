<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Reference data required by every installation (idempotent): permissions, system roles,
 * size/color lists, expense categories and EXAMPLE tax rules (to be verified by an accountant).
 * Demo/sample business data lives in database/seeders/DemoSeeder.php and is opt-in only.
 */
final class Seeder
{
    public const PERMISSIONS = [
        // group, code, description
        ['POS', 'pos.sell', 'Create bills at the POS'],
        ['POS', 'pos.discount', 'Give discounts up to role ceiling'],
        ['POS', 'pos.discount_override', 'Approve discounts above role ceiling'],
        ['POS', 'pos.price_override', 'Change selling price on a bill line'],
        ['POS', 'pos.save_no_print', 'Save bill without printing'],
        ['POS', 'pos.reprint', 'Reprint an existing bill'],
        ['POS', 'pos.credit_sale', 'Allow credit (pay later) sales'],
        ['POS', 'pos.oversell', 'Sell beyond available stock (audited)'],
        ['Sales', 'sale.view', 'View invoices'],
        ['Sales', 'sale.void', 'Void a completed bill'],
        ['Returns', 'return.create', 'Process returns and exchanges'],
        ['Returns', 'return.refund', 'Approve money refunds'],
        ['Returns', 'return.override_policy', 'Accept returns outside the return window'],
        ['Catalog', 'product.view', 'View products'],
        ['Catalog', 'product.manage', 'Create/edit/archive products and masters'],
        ['Catalog', 'product.edit_price', 'Edit selling price / MRP'],
        ['Catalog', 'product.view_cost', 'See purchase cost'],
        ['Catalog', 'product.edit_cost', 'Edit purchase cost'],
        ['Catalog', 'product.import', 'Bulk CSV import'],
        ['Inventory', 'stock.view', 'View stock'],
        ['Inventory', 'stock.adjust', 'Post stock adjustments'],
        ['Purchasing', 'purchase.manage', 'Create and post purchases / supplier returns'],
        ['Purchasing', 'supplier.manage', 'Manage suppliers'],
        ['Customers', 'customer.view', 'Look up customers'],
        ['Customers', 'customer.manage', 'Create/edit customers'],
        ['Customers', 'customer.history', 'View customer purchase history'],
        ['Customers', 'customer.receive_payment', 'Receive credit payments'],
        ['Reports', 'report.sales', 'Sales reports'],
        ['Reports', 'report.profit', 'Profit / margin reports (sensitive)'],
        ['Reports', 'report.stock', 'Stock reports'],
        ['Finance', 'expense.manage', 'Record expenses'],
        ['Finance', 'cash.manage', 'Open/close cash counter, cash in/out'],
        ['Admin', 'settings.manage', 'Shop settings'],
        ['Admin', 'tax.manage', 'Tax rules'],
        ['Admin', 'staff.manage', 'Staff and roles'],
        ['Admin', 'sync.run', 'Run manual Sync Now'],
        ['Admin', 'sync.manage', 'Manage sync endpoint and device credentials'],
        ['Admin', 'backup.manage', 'Backup and restore'],
        ['Admin', 'audit.view', 'View audit logs'],
    ];

    public const ROLES = [
        'admin' => ['Admin', 10000, ['*']],
        'manager' => ['Manager', 3000, [
            'pos.sell', 'pos.discount', 'pos.discount_override', 'pos.price_override', 'pos.save_no_print', 'pos.reprint',
            'pos.credit_sale', 'pos.oversell', 'sale.view', 'sale.void', 'return.create', 'return.refund', 'return.override_policy',
            'product.view', 'product.manage', 'product.edit_price', 'product.view_cost', 'product.edit_cost', 'product.import',
            'stock.view', 'stock.adjust', 'purchase.manage', 'supplier.manage', 'customer.view', 'customer.manage',
            'customer.history', 'customer.receive_payment', 'report.sales', 'report.profit', 'report.stock', 'expense.manage',
            'cash.manage', 'sync.run', 'backup.manage', 'audit.view']],
        'cashier' => ['Cashier', 500, [
            'pos.sell', 'pos.discount', 'pos.reprint', 'sale.view', 'return.create', 'product.view', 'stock.view',
            'customer.view', 'customer.manage', 'cash.manage']],
        'inventory' => ['Inventory Staff', 0, [
            'product.view', 'product.manage', 'product.view_cost', 'product.import', 'stock.view', 'stock.adjust',
            'purchase.manage', 'supplier.manage', 'report.stock']],
    ];

    public function __construct(private Database $db)
    {
    }

    public function seedReferenceData(): void
    {
        $db = $this->db;
        $db->transaction(function () use ($db) {
            foreach (self::PERMISSIONS as [$grp, $code, $desc]) {
                $db->run('INSERT INTO permissions (code, grp, description) VALUES (?,?,?)
                          ON CONFLICT(code) DO UPDATE SET grp = excluded.grp, description = excluded.description', [$code, $grp, $desc]);
            }
            foreach (self::ROLES as $code => [$name, $maxBp, $perms]) {
                $roleId = $db->value('SELECT id FROM roles WHERE code = ?', [$code]);
                if (!$roleId) {
                    $roleId = uuid();
                    $db->insert('roles', ['id' => $roleId, 'code' => $code, 'name' => $name, 'max_discount_bp' => $maxBp, 'is_system' => 1, 'created_at' => now()]);
                    $codes = $perms === ['*'] ? array_column(self::PERMISSIONS, 1) : $perms;
                    foreach ($codes as $p) {
                        $db->run('INSERT OR IGNORE INTO role_permissions (role_id, permission_code) VALUES (?,?)', [$roleId, $p]);
                    }
                } elseif ($perms === ['*']) {
                    foreach (array_column(self::PERMISSIONS, 1) as $p) {
                        $db->run('INSERT OR IGNORE INTO role_permissions (role_id, permission_code) VALUES (?,?)', [$roleId, $p]);
                    }
                }
            }
            if (!(int)$db->value('SELECT COUNT(*) FROM sizes')) {
                $sizes = [
                    'apparel' => ['XS', 'S', 'M', 'L', 'XL', 'XXL', '3XL'],
                    'waist' => ['28', '30', '32', '34', '36', '38', '40', '42'],
                    'age' => ['0-1Y', '1-2Y', '2-3Y', '3-4Y', '4-5Y', '5-6Y', '6-7Y', '8-9Y', '10-11Y', '12-13Y'],
                    'free' => ['Free Size'],
                ];
                foreach ($sizes as $sys => $labels) {
                    foreach ($labels as $i => $l) {
                        $db->insert('sizes', ['id' => uuid(), 'size_system' => $sys, 'label' => $l, 'sort_order' => $i, 'is_active' => 1]);
                    }
                }
            }
            if (!(int)$db->value('SELECT COUNT(*) FROM colors')) {
                $colors = ['White' => '#FFFFFF', 'Black' => '#111827', 'Blue' => '#2563EB', 'Navy' => '#1E3A8A', 'Sky Blue' => '#7DD3FC',
                    'Red' => '#DC2626', 'Maroon' => '#7F1D1D', 'Pink' => '#EC4899', 'Green' => '#16A34A', 'Olive' => '#65A30D',
                    'Yellow' => '#EAB308', 'Mustard' => '#CA8A04', 'Orange' => '#EA580C', 'Purple' => '#7C3AED', 'Grey' => '#6B7280',
                    'Beige' => '#D6C7A1', 'Brown' => '#78350F', 'Cream' => '#FFF7E0', 'Gold' => '#B8860B', 'Multi' => null];
                foreach ($colors as $n => $hex) {
                    $db->insert('colors', ['id' => uuid(), 'name' => $n, 'hex' => $hex, 'is_active' => 1]);
                }
            }
            if (!(int)$db->value('SELECT COUNT(*) FROM expense_categories')) {
                foreach (['Rent', 'Electricity', 'Salaries', 'Transport', 'Tailoring/Alteration', 'Packaging', 'Maintenance', 'Marketing', 'Bank Charges', 'Miscellaneous'] as $n) {
                    $db->insert('expense_categories', ['id' => uuid(), 'name' => $n, 'is_active' => 1]);
                }
            }
            if (!(int)$db->value('SELECT COUNT(*) FROM tax_rules')) {
                $note = 'EXAMPLE ONLY - verify the current GST rate and HSN with your accountant before going live.';
                $rules = [
                    ['NIL', 'No tax (0%)', 0, null, null, 'Use for exempt items or if not GST-registered.'],
                    ['APPAREL', 'Readymade garments (example slab)', 500, 250000, 1800, $note . ' Per-unit sale value above threshold uses the higher rate.'],
                    ['FABRIC', 'Fabrics / dress material (example)', 500, null, null, $note],
                ];
                foreach ($rules as [$code, $name, $bp, $th, $above, $n]) {
                    $db->insert('tax_rules', ['id' => uuid(), 'code' => $code, 'name' => $name, 'rate_bp' => $bp, 'threshold_paise' => $th,
                        'above_rate_bp' => $above, 'is_active' => 1, 'notes' => $n, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
        });
    }
}
