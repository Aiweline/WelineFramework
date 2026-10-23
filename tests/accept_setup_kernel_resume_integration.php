<?php

declare(strict_types=1);

define('BP', dirname(__DIR__) . DIRECTORY_SEPARATOR);
spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'Weline\\')) {
        return;
    }
    $path = BP . 'app/code/' . str_replace('\\', '/', $class) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) {
        $failures[] = $message;
    }
};

$src = (string) file_get_contents(BP . 'app/code/Weline/Framework/Setup/Console/Setup/Upgrade.php');
$check(str_contains($src, "'resume'"), 'Upgrade must accept --resume');
$check(str_contains($src, '--resume=<operation_id>'), 'help must document --resume');
$check(str_contains($src, 'UpgradeSession::load'), 'Upgrade must load the previous run');
$check(str_contains($src, 'shouldRunOptimizeStage'), 'optimize stages must consult resume state');

$ref = new \ReflectionClass(\Weline\Framework\Setup\Console\Setup\Upgrade::class);
$upgrade = $ref->newInstanceWithoutConstructor();
if (!method_exists($upgrade, 'shouldRunOptimizeStage')) {
    fwrite(STDERR, 'missing shouldRunOptimizeStage()' . PHP_EOL);
    exit(1);
}
$method = new \ReflectionMethod($upgrade, 'shouldRunOptimizeStage');
$method->setAccessible(true);
$prop = new \ReflectionProperty($upgrade, 'resumeCompleted');
$prop->setAccessible(true);

$prop->setValue($upgrade, []);
$check($method->invoke($upgrade, 'classmap', []) === true, 'classmap must run without resume');
$check($method->invoke($upgrade, 'reflection', ['skip-reflect' => true]) === false, 'skip-reflect must disable reflection');

$prop->setValue($upgrade, ['classmap', 'extends']);
$check($method->invoke($upgrade, 'classmap', []) === false, 'resume must skip completed classmap');
$check($method->invoke($upgrade, 'extends', []) === false, 'resume must skip completed extends');
$check($method->invoke($upgrade, 'psr4', []) === true, 'resume must still run unfinished psr4');
$check($method->invoke($upgrade, 'framework', ['skip-framework-compile' => true]) === false, 'explicit skip must still win');

$prop->setValue($upgrade, []);

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'ACCEPT PASS', PHP_EOL;
