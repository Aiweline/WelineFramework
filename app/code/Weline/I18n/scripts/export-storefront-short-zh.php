<?php
declare(strict_types=1);
$root = dirname(__DIR__, 5);
if (!is_dir($root . '/app/code')) {
    $root = getcwd();
}
$mods = ['Product','Theme','Cart','Checkout','Customer','Search','Wishlist','Compare','Catalog','Currency','I18n'];
$keys = [];
foreach ($mods as $m) {
    $dir = $root . "/app/code/Weline/$m/i18n";
    if (!is_dir($dir)) {
        continue;
    }
    foreach (glob("$dir/zh_Hans_CN.csv") ?: [] as $f) {
        foreach (file($f, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $line = preg_replace('/^\xEF\xBB\xBF/', '', $line) ?? $line;
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (!preg_match('/^("(?:[^"]|"")*"|[^,]+)/u', $line, $m1)) {
                continue;
            }
            $src = trim($m1[1], "\" \t");
            $src = stripcslashes(str_replace('""', '"', $src));
            if ($src === '' || !preg_match('/[\x{4e00}-\x{9fff}]/u', $src)) {
                continue;
            }
            if (mb_strlen($src) > 24) {
                continue;
            }
            $keys[$src] = true;
        }
    }
}
$path = '/tmp/storefront_short_zh.txt';
file_put_contents($path, implode("\n", array_keys($keys)) . "\n");
echo 'storefront_short_zh=' . count($keys) . " path=$path\n";
