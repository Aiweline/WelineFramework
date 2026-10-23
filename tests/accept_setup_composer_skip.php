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
$src = (string) file_get_contents(BP . 'app/code/Weline/Framework/Setup/Console/Setup/Upgrade.php');
$check(str_contains($src, "composer_dump"), 'runComposerDump must store a composer_dump fingerprint');
$check((bool) preg_match('/function computeComposerDumpStamp\(/', $src), 'missing computeComposerDumpStamp()');
$check(str_contains($src, 'changeSet') && str_contains($src, 'isEmpty()'), 'skip must require an empty ChangeSet');

$cmd = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Framework\Setup\Console\Setup\Upgrade::class);
$m = new \ReflectionMethod($cmd, 'computeComposerDumpStamp');
$m->setAccessible(true);
$a = $m->invoke($cmd);
$b = $m->invoke($cmd);
$check(is_string($a) && strlen($a) === 64, 'stamp must be sha256');
$check($a === $b, 'stamp must be stable');
if (!is_file(BP . 'composer.json')) {
    $failures[] = 'composer.json missing';
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}
echo 'ACCEPT PASS', PHP_EOL;
