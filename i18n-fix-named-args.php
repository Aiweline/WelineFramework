<?php
declare(strict_types=1);

$codeRoot = __DIR__ . '/app/code';
$pattern = "/(__\\([^;]*?'[a-zA-Z_][a-zA-Z0-9_]*'\\s*=>\\s*[^\\]]+)\\]\\)/s";
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($codeRoot, FilesystemIterator::SKIP_DOTS));
$files = 0;
$hits = 0;

foreach ($rii as $f) {
    if (!$f->isFile()) {
        continue;
    }
    $p = $f->getPathname();
    if (!preg_match('/\.php$/i', $p)) {
        continue;
    }
    $c = file_get_contents($p);
    $o = $c;
    $c = preg_replace($pattern, '$1)', $c, -1, $count);
    if ($count > 0 && $c !== $o) {
        file_put_contents($p, $c);
        $files++;
        $hits += $count;
    }
}

echo "fixed_files=$files fixed_calls=$hits\n";
