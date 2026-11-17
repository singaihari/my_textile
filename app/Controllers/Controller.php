<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;

abstract class Controller
{
    protected Database $db;

    public function __construct()
    {
        $this->db = Database::instance();
    }

    protected function view(string $tpl, string $title, array $data = []): Response
    {
        return View::page($tpl, $title, $data);
    }

    protected function back(string $fallback = '/'): Response
    {
        $ref = $_SERVER['HTTP_REFERER'] ?? '';
        $host = $_SERVER['HTTP_HOST'] ?? '';
        if ($ref && $host && parse_url($ref, PHP_URL_HOST) === parse_url('http://' . $host, PHP_URL_HOST)) {
            return new Response('', 302, ['Location' => $ref]);
        }
        return Response::redirect($fallback);
    }

    protected function ok(array $data = []): array
    {
        return ['ok' => true] + $data;
    }

    protected function find(string $sql, array $params, string $what = 'Record'): array
    {
        return $this->db->one($sql, $params) ?? throw new HttpException(404, "$what not found");
    }

    protected function page(Request $r): int
    {
        return max(1, (int)$r->query('page', 1));
    }
}
