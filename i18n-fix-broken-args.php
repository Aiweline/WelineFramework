<?php
declare(strict_types=1);

$codeRoot = __DIR__ . '/app/code';
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($codeRoot, FilesystemIterator::SKIP_DOTS));
$files = 0;

foreach ($rii as $f) {
    if (!$f->isFile() || !str_ends_with($f->getPathname(), '.php')) {
        continue;
    }
    $p = $f->getPathname();
    $c = file_get_contents($p);
    if ($c === false) {
        continue;
    }
    $o = $c;

    $c = preg_replace(
        "/__\\(\\s*('(?:\\\\.|[^'])*'|\"(?:\\\\.|[^\"])*\")\\s*\\)\\s*\\[\\s*'([a-zA-Z_][a-zA-Z0-9_]*)'\\s*=>/s",
        "__($1, ['$2' =>",
        $c
    );

    $c = preg_replace(
        "/__\\(\\s*('(?:\\\\.|[^'])*'|\"(?:\\\\.|[^\"])*\")\\s*,\\s*'([a-zA-Z_][a-zA-Z0-9_]*)'\\s*=>\\s*([^)\\]]+?)\\s*\\)/s",
        "__($1, ['$2' => $3])",
        $c
    );

    $c = preg_replace(
        "/getMessage\\(\\]\\]/",
        'getMessage()])',
        $c
    );

    if ($c !== $o) {
        file_put_contents($p, $c);
        $files++;
    }
}

echo "fixed_files=$files\n";
