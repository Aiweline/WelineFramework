<?php

declare(strict_types=1);

define('BP', dirname(__DIR__) . DIRECTORY_SEPARATOR);
defined('DS') || define('DS', DIRECTORY_SEPARATOR);
defined('APP_PATH') || define('APP_PATH', BP . 'app' . DIRECTORY_SEPARATOR);
defined('APP_CODE_PATH') || define('APP_CODE_PATH', BP . 'app' . DIRECTORY_SEPARATOR . 'code' . DIRECTORY_SEPARATOR);
defined('APP_ETC_PATH') || define('APP_ETC_PATH', BP . 'app' . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR);
defined('VAR_DIR') || define('VAR_DIR', BP . 'var' . DIRECTORY_SEPARATOR);
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

$src = (string) file_get_contents(BP . 'app/code/Weline/Framework/Setup/Console/Setup/Upgrade.php');
$check(str_contains($src, 'use Weline\\Framework\\Setup\\Kernel\\UpgradeSession;'), 'Upgrade must import UpgradeSession');
$check(str_contains($src, 'closeoutUpgradeSession('), 'Upgrade must close the session through one method');
$check(str_contains($src, 'recordStage('), 'Upgrade must record pipeline stages');
$check(str_contains($src, "'setup' . DIRECTORY_SEPARATOR . 'runs'") || str_contains($src, "'setup/runs'"), 'session run dir must live under var/setup/runs');

$upgradeRef = new \ReflectionClass(\Weline\Framework\Setup\Console\Setup\Upgrade::class);
$upgrade = $upgradeRef->newInstanceWithoutConstructor();
if (!method_exists($upgrade, 'closeoutUpgradeSession')) {
    fwrite(STDERR, 'missing closeoutUpgradeSession()' . PHP_EOL);
    exit(1);
}
$method = new \ReflectionMethod($upgrade, 'closeoutUpgradeSession');
$method->setAccessible(true);
$sessionProp = new \ReflectionProperty($upgrade, 'upgradeSession');
$sessionProp->setAccessible(true);
$maintProp = new \ReflectionProperty($upgrade, 'maintenanceClosed');
$maintProp->setAccessible(true);

$root = '/tmp/weline-kernel-integration-' . bin2hex(random_bytes(4));
$sessionClass = \Weline\Framework\Setup\Kernel\UpgradeSession::class;

// 1. 无 session：原样返回
$sessionProp->setValue($upgrade, null);
$check($method->invoke($upgrade, 0) === 0, 'no session must pass exit code through');

// 2. 维护未关闭：0 -> 1
$sessionProp->setValue($upgrade, new $sessionClass('op-maint', $root));
$maintProp->setValue($upgrade, false);
$check($method->invoke($upgrade, 0) === 1, 'unclosed maintenance must force non-zero exit');

// 3. 正常关闭：0
$sessionProp->setValue($upgrade, new $sessionClass('op-ok', $root));
$maintProp->setValue($upgrade, true);
$check($method->invoke($upgrade, 0) === 0, 'closed maintenance must keep zero exit');

// 4. 已有非零码不被覆盖
$sessionProp->setValue($upgrade, new $sessionClass('op-fail', $root));
$maintProp->setValue($upgrade, true);
$check($method->invoke($upgrade, 1) === 1, 'existing non-zero exit must not be overwritten');

// 5. fatal session：0 -> 1
$s = new $sessionClass('op-fatal', $root);
$s->begin();
$s->fatal('boom');
$sessionProp->setValue($upgrade, $s);
$maintProp->setValue($upgrade, true);
$check($method->invoke($upgrade, 0) === 1, 'fatal session must force non-zero exit');

$sessionProp->setValue($upgrade, null);
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
