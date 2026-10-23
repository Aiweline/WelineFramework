<?php

declare(strict_types=1);

define('BP', dirname(__DIR__) . DIRECTORY_SEPARATOR);
$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) {
        $failures[] = $message;
    }
};
$src = (string) file_get_contents(BP . 'app/code/Weline/Framework/Console/Console/Reflection/Compile.php');
$check(str_contains($src, 'rename('), 'reflection artifacts must be published with rename');
$check((bool) preg_match('/file_put_contents\(\s*\$tmp\w*\s*,/', $src), 'reflection artifacts must be written to a temp file first');
if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}
echo 'ACCEPT PASS', PHP_EOL;
