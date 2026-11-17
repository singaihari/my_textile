<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Money;
use App\Core\Qty;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Services\ReportService;

final class ReportController extends Controller
{
    public function index(Request $r): Response
    {
        $available = array_filter(ReportService::REPORTS, fn($def) => Auth::can($def[1]));
        if (!$available) {
            throw new HttpException(403, 'You do not have access to reports.');
        }
        return $this->view('pages/reports/index', 'Reports', ['reports' => $available]);
    }

    public function show(Request $r): Response
    {
        $key = $r->params['key'];
        $def = ReportService::REPORTS[$key] ?? throw new HttpException(404, 'Unknown report');
        Auth::authorize($def[1]);
        $opt = ['group' => (string)$r->query('group', 'day'), 'sort' => (string)$r->query('sort', '')];
        $report = (new ReportService($this->db))->run($key, (string)$r->query('from', date('Y-m-01')), (string)$r->query('to', today()), $opt);
        $format = $r->query('format');
        if ($format === 'csv') {
            return Response::download($this->csv($report), $key . '_' . $report['from'] . '_' . $report['to'] . '.csv');
        }
        if ($format === 'print') {
            return Response::html(View::render('pages/reports/print', ['title' => $report['title'], 'report' => $report], null));
        }
        return $this->view('pages/reports/show', $report['title'], ['report' => $report, 'opt' => $opt]);
    }

    private function csv(array $rep): string
    {
        $out = fopen('php://temp', 'w+');
        fputcsv($out, [$rep['title'], $rep['from'] . ' to ' . $rep['to']]);
        fputcsv($out, array_map(fn($c) => $c[0], $rep['columns']));
        foreach ($rep['rows'] as $row) {
            $line = [];
            foreach ($rep['columns'] as $k => [$label, $type]) {
                $v = $row[$k] ?? '';
                $line[] = match ($type) {
                    'money' => Money::toDecimal((int)$v),
                    'qty' => Qty::format((int)$v, ($row['unit'] ?? '') === 'm' ? 'm' : 'pcs'),
                    default => (string)$v,
                };
            }
            fputcsv($out, $line);
        }
        rewind($out);
        return "\xEF\xBB\xBF" . stream_get_contents($out);
    }
}
