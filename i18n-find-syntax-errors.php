<?php
declare(strict_types=1);

$root = __DIR__ . '/app/code';
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
$errors = [];
foreach ($rii as $f) {
    if (!$f->isFile()) {
        continue;
    }
    $p = $f->getPathname();
    if (!preg_match('/\.(php|phtml)$/i', $p)) {
        continue;
    }
    if (str_contains($p, '/view/tpl/')) {
        continue;
    }
    $out = [];
    $code = 0;
    exec('php -l ' . escapeshellarg($p) . ' 2>&1', $out, $code);
    if ($code !== 0) {
        $errors[] = $p . ': ' . implode(' ', $out);
    }
}
echo 'errors=' . count($errors) . PHP_EOL;
foreach (array_slice($errors, 0, 40) as $e) {
    echo $e . PHP_EOL;
}
