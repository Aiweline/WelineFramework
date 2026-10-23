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
require_once BP . 'app/code/Weline/Framework/Setup/Kernel/UpgradeSession.php';

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) {
        $failures[] = $message;
    }
};

$class = \Weline\Framework\Setup\Kernel\UpgradeSession::class;
$root = '/tmp/weline-source-' . bin2hex(random_bytes(4));

$cli = new $class('op-cli', $root, false);
$cli->begin();
$cli->closeout(true);
$cliReport = json_decode((string) file_get_contents($cli->reportPath()), true);
$check(($cliReport['source'] ?? '') === 'cli', 'default source must be cli');

$core = new $class('op-core', $root, false, 'core_update');
$core->begin();
$core->closeout(true);
$coreReport = json_decode((string) file_get_contents($core->reportPath()), true);
$check(($coreReport['source'] ?? '') === 'core_update', 'core update source must be recorded');

$loaded = $class::load($root . '/op-core');
$check($loaded instanceof $class, 'load must work');

$src = (string) file_get_contents(BP . 'app/code/Weline/Framework/Setup/Console/Setup/Upgrade.php');
$check(str_contains($src, "'from-core-update'"), 'Upgrade must accept --from-core-update');
$check(str_contains($src, '--from-core-update'), 'help must document --from-core-update');

$deploy = (string) file_get_contents(BP . 'app/code/Weline/Deploy/Service/DeployCoreUpdateService.php');
$check(str_contains($deploy, '--from-core-update'), 'backend chain must mark setup as core-update source');

$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
);
foreach ($it as $item) {
    $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
}
@rmdir($root);

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'ACCEPT PASS', PHP_EOL;
