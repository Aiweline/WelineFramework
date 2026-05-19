<?php
declare(strict_types=1);

$codeRoot = __DIR__ . '/app/code';
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($codeRoot, FilesystemIterator::SKIP_DOTS));
$files = 0;

foreach ($rii as $f) {
    if (!$f->isFile() || !str_ends_with($f->getPathname(), '.php')) {
        continue;
    }
    $c = file_get_contents($f->getPathname());
    if ($c === false) {
        continue;
    }
    $o = $c;
    $c = str_replace('getMessage(]])', 'getMessage()])', $c);
    $c = str_replace('getMessage(])', 'getMessage()])', $c);
    $c = str_replace('getMessage(]]', 'getMessage()])', $c);
    $c = str_replace(']),);', '])]);', $c);
    if ($c !== $o) {
        file_put_contents($f->getPathname(), $c);
        $files++;
    }
}

echo "fixed_files=$files\n";
