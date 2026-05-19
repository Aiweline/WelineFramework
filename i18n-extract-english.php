<?php
declare(strict_types=1);

$root = __DIR__ . '/app/code';
$pattern = "/__\\(\\s*([\"'])((?:\\\\.|(?!\\1).)*)\\1/s";
$english = [];
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($rii as $f) {
    if (!$f->isFile()) {
        continue;
    }
    $p = $f->getPathname();
    if (!preg_match('/\.(php|phtml)$/i', $p)) {
        continue;
    }
    if (str_contains($p, '/view/tpl/') || str_contains($p, '/Test/')) {
        continue;
    }
    $c = file_get_contents($p);
    if (!preg_match_all($pattern, $c, $m)) {
        continue;
    }
    foreach ($m[2] as $raw) {
        $t = stripcslashes($raw);
        if (preg_match('/[A-Za-z]{2,}/', $t) && !preg_match('/\p{Han}/u', $t)) {
            $english[$t] = true;
        }
    }
}
$keys = array_keys($english);
sort($keys);
file_put_contents(__DIR__ . '/i18n-remaining-english.txt', implode("\n", $keys) . "\n");
echo 'unique_english=' . count($keys) . PHP_EOL;
