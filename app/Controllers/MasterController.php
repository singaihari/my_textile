<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Money;
use App\Core\Request;
use App\Core\Response;
use App\Core\ValidationException;

/** Category / brand / size / colour / tax-rule masters. Records are deactivated, never hard-deleted. */
final class MasterController extends Controller
{
    public function index(Request $r): Response
    {
        return $this->view('pages/masters', 'Masters', [
            'tab' => in_array($r->query('tab'), ['categories', 'brands', 'sizes', 'colors', 'tax', 'expense'], true) ? $r->query('tab') : 'categories',
            'categories' => $this->db->all('SELECT c.*, p.name parent, (SELECT COUNT(*) FROM products WHERE category_id = c.id) used FROM categories c LEFT JOIN categories p ON p.id = c.parent_id ORDER BY IFNULL(p.name, c.name), c.parent_id IS NOT NULL, c.name'),
            'brands' => $this->db->all('SELECT b.*, (SELECT COUNT(*) FROM products WHERE brand_id = b.id) used FROM brands b ORDER BY name'),
            'sizes' => $this->db->all('SELECT s.*, (SELECT COUNT(*) FROM product_variants WHERE size_id = s.id) used FROM sizes s ORDER BY size_system, sort_order, label'),
            'colors' => $this->db->all('SELECT c.*, (SELECT COUNT(*) FROM product_variants WHERE color_id = c.id) used FROM colors c ORDER BY name'),
            'taxRules' => $this->db->all('SELECT t.*, (SELECT COUNT(*) FROM products WHERE tax_rule_id = t.id) used FROM tax_rules t ORDER BY code'),
            'expenseCats' => $this->db->all('SELECT * FROM expense_categories ORDER BY name'),
            'parents' => $this->db->all('SELECT id, name FROM categories WHERE parent_id IS NULL ORDER BY name'),
        ]);
    }

    public function save(Request $r): Response
    {
        $type = $r->str('type');
        $id = $r->str('id') ?: null;
        $name = $r->str('name');
        $active = $r->str('is_active', '1') === '1' ? 1 : 0;
        if ($name === '' && $type !== 'toggle') {
            throw new ValidationException('Name is required');
        }
        try {
            switch ($type) {
                case 'category':
                    $row = ['name' => $name, 'parent_id' => $r->str('parent_id') ?: null, 'is_active' => $active, 'updated_at' => now()];
                    if ($row['parent_id'] && $row['parent_id'] === $id) {
                        throw new ValidationException('A category cannot be its own parent');
                    }
                    $id ? $this->db->update('categories', $row, 'id = :id', ['id' => $id])
                        : $this->db->insert('categories', $row + ['id' => uuid(), 'sort_order' => 0, 'created_at' => now()]);
                    break;
                case 'brand':
                    $row = ['name' => $name, 'is_active' => $active, 'updated_at' => now()];
                    $id ? $this->db->update('brands', $row, 'id = :id', ['id' => $id]) : $this->db->insert('brands', $row + ['id' => uuid(), 'created_at' => now()]);
                    break;
                case 'size':
                    $sys = in_array($r->str('size_system'), ['apparel', 'waist', 'age', 'free', 'other'], true) ? $r->str('size_system') : 'other';
                    $row = ['label' => $name, 'size_system' => $sys, 'sort_order' => $r->int('sort_order'), 'is_active' => $active];
                    $id ? $this->db->update('sizes', $row, 'id = :id', ['id' => $id]) : $this->db->insert('sizes', $row + ['id' => uuid()]);
                    break;
                case 'color':
                    $hex = preg_match('/^#[0-9A-Fa-f]{6}$/', $r->str('hex')) ? strtoupper($r->str('hex')) : null;
                    $row = ['name' => $name, 'hex' => $hex, 'is_active' => $active];
                    $id ? $this->db->update('colors', $row, 'id = :id', ['id' => $id]) : $this->db->insert('colors', $row + ['id' => uuid()]);
                    break;
                case 'expense':
                    $row = ['name' => $name, 'is_active' => $active];
                    $id ? $this->db->update('expense_categories', $row, 'id = :id', ['id' => $id]) : $this->db->insert('expense_categories', $row + ['id' => uuid()]);
                    break;
                default:
                    throw new ValidationException('Unknown master type');
            }
        } catch (\PDOException $e) {
            if (str_contains($e->getMessage(), 'UNIQUE')) {
                throw new ValidationException("“$name” already exists");
            }
            throw $e;
        }
        Audit::log('master.saved', $type, $id, ['name' => $name]);
        flash('success', ucfirst($type) . ' saved.');
        return Response::redirect('/masters', ['tab' => $type === 'category' ? 'categories' : ($type === 'expense' ? 'expense' : $type . 's')]);
    }

    public function saveTax(Request $r): Response
    {
        $id = $r->str('id') ?: null;
        $code = strtoupper($r->str('code'));
        if (!preg_match('/^[A-Z0-9_-]{2,30}$/', $code)) {
            throw new ValidationException('Code: 2-30 letters/digits');
        }
        try {
            $rate = Money::parsePercentBp($r->str('rate'));
            $threshold = $r->str('threshold') !== '' ? Money::parse($r->str('threshold')) : null;
            $above = $r->str('above_rate') !== '' ? Money::parsePercentBp($r->str('above_rate')) : null;
        } catch (\InvalidArgumentException $e) {
            throw new ValidationException($e->getMessage());
        }
        if ($rate > 10000 || ($above !== null && $above > 10000)) {
            throw new ValidationException('Rate cannot exceed 100%');
        }
        if (($threshold === null) !== ($above === null)) {
            throw new ValidationException('Enter both threshold and higher rate, or neither');
        }
        $row = ['code' => $code, 'name' => $r->str('name') ?: $code, 'rate_bp' => $rate, 'threshold_paise' => $threshold, 'above_rate_bp' => $above,
            'is_active' => $r->str('is_active', '1') === '1' ? 1 : 0, 'notes' => $r->str('notes') ?: null, 'updated_at' => now()];
        $old = $id ? $this->db->one('SELECT * FROM tax_rules WHERE id = ?', [$id]) : null;
        $id ? $this->db->update('tax_rules', $row, 'id = :id', ['id' => $id]) : $this->db->insert('tax_rules', $row + ['id' => uuid(), 'created_at' => now()]);
        Audit::log('tax.saved', 'tax_rule', $id, ['code' => $code, 'old_rate' => $old['rate_bp'] ?? null, 'new_rate' => $rate]);
        flash('success', 'Tax rule saved. New bills use it immediately; existing bills keep the tax they were saved with.');
        return Response::redirect('/masters', ['tab' => 'tax']);
    }
}
