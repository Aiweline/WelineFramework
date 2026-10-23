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
foreach (['load', 'completedStages', 'isIdempotentStage'] as $method) {
    if (!method_exists($class, $method)) {
        fwrite(STDERR, 'missing ' . $method . '()' . PHP_EOL);
        exit(1);
    }
}

$root = '/tmp/weline-resume-' . bin2hex(random_bytes(4));
$runDir = $root . '/run-1';
@mkdir($runDir, 0775, true);
file_put_contents($runDir . '/progress.json', json_encode([
    'operation_id' => 'run-1',
    'status' => 'running',
    'started_at' => '2026-10-23T00:00:00+08:00',
    'stages' => [
        ['key' => 'classmap', 'status' => 'ok', 'duration_ms' => 1.0, 'meta' => []],
        ['key' => 'psr4', 'status' => 'failed', 'duration_ms' => 1.0, 'meta' => []],
        ['key' => 'extends', 'status' => 'ok', 'duration_ms' => 1.0, 'meta' => []],
    ],
    'skips' => [],
    'fatal' => [],
], JSON_UNESCAPED_UNICODE));

$loaded = $class::load($runDir);
$check($loaded instanceof $class, 'load must return a session');
if (!$loaded instanceof $class) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}
$check($loaded->completedStages() === ['classmap', 'extends'], 'completedStages must only include ok stages, got ' . json_encode($loaded->completedStages()));

$check($class::isIdempotentStage('classmap') === true, 'classmap must be idempotent');
$check($class::isIdempotentStage('extends') === true, 'extends must be idempotent');
$check($class::isIdempotentStage('schema') === false, 'schema must not be idempotent');
$check($class::isIdempotentStage('migrations') === false, 'migrations must not be idempotent');

$check($class::load($root . '/missing') === null, 'missing progress must return null');

@unlink($runDir . '/progress.json');
@rmdir($runDir);
@rmdir($root);

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'ACCEPT PASS', PHP_EOL;
