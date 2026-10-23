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
$check(str_contains($src, "getConfig('setup.optimize.background'"), 'background optimize must be configurable');
$check(str_contains($src, "getConfig('setup.optimize.background', true)"), 'background optimize must default to true');

$ref = new \ReflectionClass(\Weline\Framework\Setup\Console\Setup\Upgrade::class);
$upgrade = $ref->newInstanceWithoutConstructor();
$method = new \ReflectionMethod($upgrade, 'shouldRunBackgroundOptimize');
$method->setAccessible(true);

$check($method->invoke($upgrade, []) === true, 'default must run optimize in background for fast setup');
$check($method->invoke($upgrade, ['background-optimize' => true]) === true, 'explicit background must run in background');
$check($method->invoke($upgrade, ['background-optimize' => true, 'sync' => true]) === false, '--sync must force synchronous');
$check($method->invoke($upgrade, ['background-optimize' => true, 'skip-background-optimize' => true]) === false, '--skip-background-optimize must force synchronous');

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'ACCEPT PASS', PHP_EOL;
