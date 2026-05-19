<?php
$root = __DIR__;
$codeRoot = $root . '/app/code';
$englishToChinese = [];
$chineseSources = [];
foreach (glob($codeRoot . '/*/*/i18n/en_US.csv') ?: [] as $csvFile) {
    $handle = fopen($csvFile, 'rb');
    if ($handle === false) continue;
    while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
        if (!isset($row[0], $row[1])) continue;
        $source = trim((string)$row[0]);
        $target = trim((string)$row[1]);
        if ($source === '') continue;
        if (preg_match('/\p{Han}/u', $source)) {
            $chineseSources[$source] = true;
            if (preg_match('/[A-Za-z]{2,}/', $target) && !preg_match('/\p{Han}/u', $target)) {
                $englishToChinese[$target] = $englishToChinese[$target] ?? $source;
            }
        }
        if (preg_match('/[A-Za-z]{2,}/', $source) && !preg_match('/\p{Han}/u', $source) && preg_match('/\p{Han}/u', $target)) {
            $englishToChinese[$source] = $target;
        }
    }
    fclose($handle);
}
echo 'map=' . count($englishToChinese) . PHP_EOL;
$pattern = "/__\\(\\s*([\"'])((?:\\\\.|(?!\\1).)*)\\1\\s*(?:,|\\))/s";
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($codeRoot, FilesystemIterator::SKIP_DOTS));
$changed = 0; $repl = 0;
foreach ($rii as $f) {
    if (!$f->isFile()) continue;
    $p = $f->getPathname();
    if (!preg_match('/\.(php|phtml)$/i', $p)) continue;
    if (str_contains($p, '/view/tpl/')) continue;
    if (str_contains($p, '/Test/')) continue;
    $c = file_get_contents($p); $o = $c;
    $c = preg_replace_callback($pattern, function($m) use ($englishToChinese, $chineseSources, &$repl) {
        $q = $m[1]; $text = stripcslashes($m[2]);
        if ($text === '' || preg_match('/^[A-Z][A-Za-z0-9]*_[A-Z]/', $text)) return $m[0];
        if (preg_match('/\p{Han}/u', $text)) return $m[0];
        if (!preg_match('/[A-Za-z]{2,}/', $text)) return $m[0];
        if (isset($chineseSources[$text])) return $m[0];
        $zh = $englishToChinese[$text] ?? null;
        if ($zh === null) return $m[0];
        $repl++;
        $esc = str_replace(['\\', $q], ['\\\\', '\\'.$q], $zh);
        $suffix = str_ends_with($m[0], ',') ? ',' : ')';
        return '__(' . $q . $esc . $q . $suffix;
    }, $c);
    if ($c !== $o) { file_put_contents($p, $c); $changed++; }
}
echo "changed=$changed repl=$repl\n";
