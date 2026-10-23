<?php

declare(strict_types=1);

define('BP', dirname(__DIR__) . DIRECTORY_SEPARATOR);
require BP . 'app/bootstrap.php';

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) {
        $failures[] = $message;
    }
};

$src = (string) file_get_contents(BP . 'app/code/Weline/Deploy/Service/DeployCoreUpdateService.php');
$check(str_contains($src, 'deploy:update:restore'), 'failure must surface the restore command');

if (preg_match('/if \(\$exitCode !== 0\)\s*\{(.*?)\n            \}/s', $src, $m)) {
    $check(str_contains($m[1], 'restore'), 'non-zero update must tell the operator how to roll back');
    $check(str_contains($m[1], 'backupId'), 'rollback hint must reference the backup id when present');
} else {
    $failures[] = 'exitCode failure branch not found';
}

if (preg_match('/catch \(\\\\Throwable \$throwable\)\s*\{(.*?)\n        \}/s', $src, $m2)) {
    $check(str_contains($m2[1], 'restore'), 'exception path must tell the operator how to roll back');
} else {
    $failures[] = 'throwable catch not found';
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'ACCEPT PASS', PHP_EOL;
