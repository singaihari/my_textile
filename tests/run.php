<?php
declare(strict_types=1);

/**
 * Test runner:  php tests/run.php [filter]
 * Discovers tests/{Unit,Integration}/*Test.php, runs every public test* method with a fresh database.
 */

require dirname(__DIR__) . '/app/bootstrap.php';
require __DIR__ . '/TestCase.php';
require dirname(__DIR__) . '/server-api/app/bootstrap.php';   // server code (separate namespace) for sync tests
require __DIR__ . '/Support/InProcessTransport.php';
date_default_timezone_set(App\Core\Env::get('APP_TIMEZONE', 'Asia/Kolkata'));
App\Core\Env::set('APP_DEBUG', '1');
$_SESSION = [];

$filter = $argv[1] ?? null;
$files = array_merge(glob(__DIR__ . '/Unit/*Test.php') ?: [], glob(__DIR__ . '/Integration/*Test.php') ?: []);
$pass = $fail = $assertions = 0;
$failures = [];
$start = microtime(true);

foreach ($files as $file) {
    require_once $file;
    $class = 'Tests\\' . basename(dirname($file)) . '\\' . basename($file, '.php');
    if (!class_exists($class)) {
        fwrite(STDERR, "Class $class not found in $file\n");
        $fail++;
        continue;
    }
    foreach (get_class_methods($class) as $method) {
        if (!str_starts_with($method, 'test')) {
            continue;
        }
        $name = basename(dirname($file)) . '/' . basename($file, '.php') . '::' . $method;
        if ($filter && !str_contains($name, $filter)) {
            continue;
        }
        /** @var Tests\TestCase $t */
        $t = new $class();
        try {
            $t->setUp();
            $t->$method();
            $pass++;
            echo "  \033[32m✔\033[0m $name\n";
        } catch (Throwable $e) {
            $fail++;
            $failures[] = [$name, $e];
            echo "  \033[31m✘\033[0m $name\n";
        } finally {
            $assertions += $t->assertions;
            try {
                $t->tearDown();
            } catch (Throwable) {
            }
        }
    }
}

foreach ($failures as [$name, $e]) {
    echo "\n\033[31mFAIL\033[0m $name\n  " . get_class($e) . ': ' . $e->getMessage() . "\n  at " . $e->getFile() . ':' . $e->getLine() . "\n";
    foreach (array_slice($e->getTrace(), 0, 4) as $tr) {
        echo '     ' . ($tr['file'] ?? '?') . ':' . ($tr['line'] ?? '?') . ' ' . ($tr['class'] ?? '') . ($tr['type'] ?? '') . ($tr['function'] ?? '') . "\n";
    }
}
printf("\n%d passed, %d failed, %d assertions (%.2fs)\n", $pass, $fail, $assertions, microtime(true) - $start);
exit($fail ? 1 : 0);
