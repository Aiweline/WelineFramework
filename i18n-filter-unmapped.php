<?php
$lines = file(__DIR__ . '/i18n-unmapped-english.txt', FILE_IGNORE_NEW_LINES);
$n = 0;
foreach ($lines as $line) {
    if ($n++ < 4) {
        continue;
    }
    $parts = explode("\t", $line, 2);
    if (count($parts) < 2) {
        continue;
    }
    $t = trim($parts[1]);
    if (strlen($t) > 100) {
        continue;
    }
    if (preg_match('/php bin|sudo|PID|GRANT|github|taskkill|wasi|\\\\|\.cpp|ALTER |Usage:/i', $t)) {
        continue;
    }
    echo $t . PHP_EOL;
}
