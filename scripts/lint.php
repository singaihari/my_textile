<?php
// Usage: php scripts/lint.php   — runs "php -l" on every PHP file in the project.
$root = dirname(__DIR__);
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
$bad = 0;
$n = 0;
foreach ($it as $f) {
    $p = $f->getPathname();
    if ($f->getExtension() !== 'php' || preg_match('#[\\\\/](vendor|node_modules|storage)[\\\\/]#', $p)) {
        continue;
    }
    $n++;
    $out = [];
    exec('php -l ' . escapeshellarg($p) . ' 2>&1', $out, $code);
    if ($code !== 0) {
        $bad++;
        echo implode("\n", $out) . "\n";
    }
}
echo "$n files checked, $bad with errors\n";
exit($bad ? 1 : 0);
