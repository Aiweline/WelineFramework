<?php

declare(strict_types=1);

define('BP', dirname(__DIR__) . DIRECTORY_SEPARATOR);
$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) {
        $failures[] = $message;
    }
};
$src = (string) file_get_contents(BP . 'app/code/Weline/Server/Observer/CliCommandExecutedObserver.php');
$check(str_contains($src, 'setup.reload_timeout'), 'observer must read setup.reload_timeout');
$check((bool) preg_match('/reloadAsync\(\s*null\s*,\s*ControlMessage::RELOAD_TYPE_CODE\s*,\s*\$[a-zA-Z]+/', $src), 'observer must pass the timeout to reloadAsync');
$check(str_contains($src, 'max(0.2') || str_contains($src, 'max(0.2, '), 'timeout must be clamped to a safe minimum');
if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}
echo 'ACCEPT PASS', PHP_EOL;
