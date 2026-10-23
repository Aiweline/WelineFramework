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

$file = BP . 'app/code/Weline/Deploy/Service/DeployCoreUpdateService.php';
$src = (string) file_get_contents($file);

$check(str_contains($src, 'core_update.log'), 'backend update must write a log file');

if (!method_exists(\Weline\Deploy\Service\DeployCoreUpdateService::class, 'buildCommandChain')) {
    fwrite(STDERR, 'missing buildCommandChain()' . PHP_EOL);
    exit(1);
}
$method = new \ReflectionMethod(\Weline\Deploy\Service\DeployCoreUpdateService::class, 'buildCommandChain');
$method->setAccessible(true);
$chain = $method->invoke(null, '/tmp/demo-root', 'dev');
$check(is_array($chain), 'buildCommandChain must return an array');
$keys = is_array($chain) ? array_keys($chain) : [];
$check($keys === ['update', 'setup', 'reload'], 'command order must be update -> setup -> reload, got ' . json_encode($keys));
$check(str_contains((string)($chain['update'] ?? ''), 'update:core'), 'update step must call update:core');
$check(str_contains((string)($chain['setup'] ?? ''), 'setup:upgrade'), 'setup step must call setup:upgrade');
$check(!str_contains((string)($chain['setup'] ?? ''), '--keep-maintenance'), 'backend setup must close maintenance itself');
$check(str_contains((string)($chain['reload'] ?? ''), 'server:reload'), 'reload step must call server:reload');

$check((bool) preg_match('/foreach \(\$chain as \$step => \$command\)/', $src), 'run() must execute the chain in order');
$check((bool) preg_match('/setup 未完成|setup:upgrade 未完成/', $src), 'setup failure must tell the operator how to recover');

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'ACCEPT PASS', PHP_EOL;
