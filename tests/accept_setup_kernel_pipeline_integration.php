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
$check(str_contains($src, 'use Weline\\Framework\\Setup\\Kernel\\StagePipeline;'), 'Upgrade must import StagePipeline');
$check(str_contains($src, 'StagePipeline'), 'generateOptimizationCache must use StagePipeline');
$check(str_contains($src, 'optimizeStageKeys'), 'Upgrade must expose optimizeStageKeys() for the pipeline');

$ref = new \ReflectionClass(\Weline\Framework\Setup\Console\Setup\Upgrade::class);
$upgrade = $ref->newInstanceWithoutConstructor();
if (!method_exists($upgrade, 'optimizeStageKeys')) {
    fwrite(STDERR, 'missing optimizeStageKeys()' . PHP_EOL);
    exit(1);
}
$method = new \ReflectionMethod($upgrade, 'optimizeStageKeys');
$method->setAccessible(true);
$keys = $method->invoke($upgrade);
$check($keys === ['classmap', 'psr4', 'extends', 'framework', 'reflection'], 'optimize stage order must be classmap→psr4→extends→framework→reflection, got ' . json_encode($keys));

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'ACCEPT PASS', PHP_EOL;
