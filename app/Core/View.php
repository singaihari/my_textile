<?php
declare(strict_types=1);

namespace App\Core;

/** Plain-PHP templates in app/Views. Variables are extracted; always escape with e(). */
final class View
{
    /** Page-specific scripts registered by templates (rendered by the layout). */
    public static array $scripts = [];

    public static function script(string $path): void
    {
        self::$scripts[$path] = $path;
    }

    public static function render(string $template, array $data = [], ?string $layout = 'layouts/app'): string
    {
        $content = self::partial($template, $data);
        if ($layout === null) {
            return $content;
        }
        return self::partial($layout, array_merge($data, ['content' => $content]));
    }

    public static function partial(string $template, array $data = []): string
    {
        $file = APP_ROOT . '/app/Views/' . $template . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException("View not found: $template");
        }
        extract($data, EXTR_SKIP);
        ob_start();
        try {
            include $file;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return (string)ob_get_clean();
    }

    public static function page(string $template, string $title, array $data = []): Response
    {
        return Response::html(self::render($template, array_merge($data, ['title' => $title])));
    }

    /** Pagination helper for list pages. */
    public static function paginate(Database $db, string $sql, array $params, int $page, int $perPage = 25): array
    {
        $page = max(1, $page);
        $total = (int)$db->value("SELECT COUNT(*) FROM ($sql) t", $params);
        $rows = $db->all($sql . ' LIMIT ' . (int)$perPage . ' OFFSET ' . (($page - 1) * $perPage), $params);
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage, 'pages' => max(1, (int)ceil($total / $perPage))];
    }
}
