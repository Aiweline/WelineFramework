<?php
declare(strict_types=1);

$wrong = 'getMessage()])));';
$right = 'getMessage()])]);';

$codeRoot = __DIR__ . '/app/code';
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($codeRoot, FilesystemIterator::SKIP_DOTS));
$files = 0;

foreach ($rii as $f) {
    if (!$f->isFile() || !str_ends_with($f->getPathname(), '.php')) {
        continue;
    }
    $c = file_get_contents($f->getPathname());
    if ($c === false || !str_contains($c, $wrong)) {
        continue;
    }
    file_put_contents($f->getPathname(), str_replace($wrong, $right, $c));
    $files++;
}

echo "fixed_files=$files\n";
