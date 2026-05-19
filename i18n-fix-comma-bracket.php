<?php
declare(strict_types=1);

$codeRoot = __DIR__ . '/app/code';
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($codeRoot, FilesystemIterator::SKIP_DOTS));
$files = 0;

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
    $c = file_get_contents($p);
    if ($c === false) {
        continue;
    }
    $o = $c;
    $c = str_replace("'), [", "', [", $c);
    if ($c !== $o) {
        file_put_contents($p, $c);
        $files++;
    }
}

echo "fixed_files=$files\n";
