<?php

declare(strict_types=1);

define('BP', dirname(__DIR__) . DIRECTORY_SEPARATOR);
defined('DS') || define('DS', DIRECTORY_SEPARATOR);
define('VAR_DIR', '/tmp/weline-cache-o1/var/');
@mkdir(VAR_DIR . 'cache/sub', 0775, true);
@mkdir(VAR_DIR . 'cache/frontend_worker', 0700, true);
for ($i = 0; $i < 200; $i++) {
    file_put_contents(VAR_DIR . 'cache/sub/f' . $i . '.txt', 'x');
}
file_put_contents(VAR_DIR . 'cache/frontend_worker/session1', 's');
chmod(VAR_DIR . 'cache/frontend_worker/session1', 0600);

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

$ref = new \ReflectionClass(\Weline\Framework\Setup\Console\Setup\Upgrade::class);
$upgrade = $ref->newInstanceWithoutConstructor();
$method = new \ReflectionMethod($upgrade, 'clearCacheContentsPreservingRuntimeOwner');
$method->setAccessible(true);

$start = microtime(true);
try {
    $method->invoke($upgrade);
} catch (\Throwable $e) {
    $failures[] = 'cleanup threw: ' . $e->getMessage();
}
$elapsed = microtime(true) - $start;

$check(is_dir(VAR_DIR . 'cache'), 'cache dir must be recreated');
$check(!is_dir(VAR_DIR . 'cache/sub'), 'old cache content must be gone from the new cache dir');
$check($elapsed < 1.0, sprintf('cache cleanup must be O(1) under 1s, took %.2fs', $elapsed));
$check(is_dir(VAR_DIR . 'cache/frontend_worker'), 'frontend_worker dir must be recreated');
if (is_dir(VAR_DIR . 'cache/frontend_worker')) {
    $check((fileperms(VAR_DIR . 'cache/frontend_worker') & 0777) === 0700, 'frontend_worker must stay 0700');
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    @exec('rm -rf ' . escapeshellarg('/tmp/weline-cache-o1'));
    exit(1);
}

@exec('rm -rf ' . escapeshellarg('/tmp/weline-cache-o1'));
echo 'ACCEPT PASS', PHP_EOL;
