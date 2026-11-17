<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Context;
use App\Core\Database;

/**
 * Human-readable, device-scoped document numbers: {PREFIX}-{DEVICE}-{FY}-{SEQ}
 * e.g. TX-C1-2627-00042. Sequence rows are incremented inside the business transaction,
 * so a rolled-back bill never consumes a number and two counters can never collide.
 * (GST rules limit invoice numbers to 16 characters: keep prefix ≤ 2 and device code ≤ 2 chars for invoices.)
 */
final class DocNumberService
{
    public function __construct(private Database $db)
    {
    }

    /** Preview the next number WITHOUT consuming it (display only; the real number is assigned at save). */
    public function peek(string $series, string $prefix): string
    {
        $device = Context::device();
        $fy = Context::fyCode();
        $n = (int)($this->db->value('SELECT next_no FROM doc_sequences WHERE store_id = ? AND device_id = ? AND series = ? AND fy = ?',
            [Context::storeId(), $device['id'], $series, $fy]) ?: 1);
        return sprintf('%s-%s-%s-%05d', strtoupper($prefix), $device['code'], $fy, $n);
    }

    public function next(string $series, string $prefix, ?string $date = null): string
    {
        $fy = Context::fyCode($date);
        $storeId = Context::storeId();
        $device = Context::device();
        $row = $this->db->one('SELECT id, next_no FROM doc_sequences WHERE store_id = ? AND device_id = ? AND series = ? AND fy = ?',
            [$storeId, $device['id'], $series, $fy]);
        if (!$row) {
            $this->db->insert('doc_sequences', ['id' => uuid(), 'store_id' => $storeId, 'device_id' => $device['id'],
                'series' => $series, 'fy' => $fy, 'next_no' => 2]);
            $n = 1;
        } else {
            $n = (int)$row['next_no'];
            $this->db->update('doc_sequences', ['next_no' => $n + 1], 'id = :id', ['id' => $row['id']]);
        }
        return sprintf('%s-%s-%s-%05d', strtoupper($prefix), $device['code'], $fy, $n);
    }
}
